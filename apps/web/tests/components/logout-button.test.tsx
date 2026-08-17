import { useQueryClient, type QueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { LogoutButton } from '@/features/auth/logout-button';
import { browserNavigation } from '@/features/auth/session';

import { envelope, ORIGIN } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * Sign out — FOUR STEPS, EACH FOR A DIFFERENT CACHE, asserted as one ordered sequence.
 *
 * The order is the only thing that matters and none of it is visible in a rendered result: cancelling
 * AFTER the POST lets an authenticated read resolve into a dead session, and `clear()` instead of a
 * REPLACE leaves the same mounted observers alive to refetch into the window before the next document
 * commits. Both failures render authenticated chrome and neither logs anything.
 *
 * What this spec may NOT claim: that the back button renders nothing authenticated afterwards. That is
 * a cross-route navigation assertion, which the vitest-playwright boundary table puts in Playwright's
 * layer, and it is additionally marked UNVERIFIED upstream. It is recorded as unproven.
 */

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

/** Records every DISTINCT QueryClient the tree has been given. Step 3 is "replace the client", so the
 *  identity of this object is the assertion — not the contents of its caches. */
function ClientProbe({ seen }: { readonly seen: QueryClient[] }) {
  const client = useQueryClient();
  useEffect(() => {
    if (!seen.includes(client)) seen.push(client);
  }, [client, seen]);
  return null;
}

describe('the four steps run in order, and step 3 REPLACES the client', () => {
  it('cancels, POSTs, replaces the client, then asks for a full document navigation', async () => {
    const order: string[] = [];
    const seen: QueryClient[] = [];

    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/logout`, () => {
        order.push('logout');
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => {
      order.push('assign');
    });

    const screen = await render(
      <Providers>
        <ClientProbe seen={seen} />
        <LogoutButton />
      </Providers>,
    );

    await vi.waitFor(() => {
      expect(seen).toHaveLength(1);
    });

    // Spied on the REAL client, calling through: `cancelQueries` only aborts anything because every
    // queryFn in this app forwards its signal to fetch. A spy that replaced it would prove nothing.
    const cancelQueries = vi
      .spyOn(seen[0] as QueryClient, 'cancelQueries')
      .mockImplementation(async () => {
        order.push('cancelQueries');
      });

    await screen.getByRole('button', { name: 'Sign out' }).click();

    await vi.waitFor(() => {
      expect(assign).toHaveBeenCalledExactlyOnceWith('/login');
    });

    expect(cancelQueries).toHaveBeenCalledTimes(1);
    expect(order).toEqual(['cancelQueries', 'logout', 'assign']);

    // Step 3, and the reason it is not `queryClient.clear()`: a new client has no observers, so nothing
    // can refetch into the gap between the POST resolving and the next document committing.
    await vi.waitFor(() => {
      expect(seen).toHaveLength(2);
    });
    expect(seen[1]).not.toBe(seen[0]);
  });
});

describe('steps 3 and 4 run from onSettled, so a failed POST still signs you out', () => {
  it('replaces the client and navigates even when logout answers 500', async () => {
    const seen: QueryClient[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/logout`, () =>
        HttpResponse.json(envelope('internal_dependency', { retryable: false }), { status: 500 }),
      ),
    );
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await render(
      <Providers>
        <ClientProbe seen={seen} />
        <LogoutButton />
      </Providers>,
    );
    await screen.getByRole('button', { name: 'Sign out' }).click();

    // A sign-out button that leaves you signed in because the network hiccuped is worse than one that
    // navigates optimistically.
    await vi.waitFor(() => {
      expect(assign).toHaveBeenCalledExactlyOnceWith('/login');
    });
    await vi.waitFor(() => {
      expect(seen).toHaveLength(2);
    });
    // And the operator-facing detail is never rendered on the way out.
    expect(document.body.textContent).not.toContain('api-7.internal');
  });
});

describe('the double-click guard', () => {
  it('disables the button while the POST is in flight', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/logout`, async () => {
        await new Promise((resolve) => setTimeout(resolve, 300));
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await render(
      <Providers>
        <LogoutButton />
      </Providers>,
    );
    await screen.getByRole('button', { name: 'Sign out' }).click();

    await expect.element(screen.getByRole('button', { name: 'Signing out…' })).toBeDisabled();

    // The delayed response is consumed inside this spec: `onSettled` fires whether or not the component
    // is still mounted, so ending here would leak the navigation into the next spec — where `afterEach`
    // has restored the seam and `browserNavigation.assign` is the real `window.location.assign`, which
    // navigates the test page away and kills the run with an error naming nothing.
    await vi.waitFor(() => {
      expect(assign).toHaveBeenCalledExactlyOnceWith('/login');
    });
  });
});
