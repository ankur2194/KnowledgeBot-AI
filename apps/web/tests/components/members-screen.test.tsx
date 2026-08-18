import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import type { InvitationResource, MemberResource } from '@kb/contracts';
import { MembersScreen } from '@/features/members/members-screen';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * The members and invitations screen — the FIRST consumer of `useOrgKey()`.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * It proves that the query keys ARE org-namespaced and that the request paths carry the organization. It
 * proves NOTHING about isolation: a component test that mocks the API cannot fail an isolation test, and
 * per the vitest-playwright boundary table it must never be cited as isolation coverage. "Organization A's
 * rows never appear after switching to B, including during the in-flight window" is Playwright's, and is
 * recorded as unproven by this batch.
 *
 * ── THE HANDLERS FOR THESE FIVE ENDPOINTS LIVE HERE, NOT IN tests/msw/handlers.ts ─────────────────
 * That file is shared by every component spec and is another unit's in this batch; adding to it mid-batch
 * is a conflict rather than a contribution. `worker.use(...)` inside a spec is the documented override
 * mechanism and `afterEach`'s `resetHandlers()` keeps it from leaking into the next file.
 */

/** `sessionFixture()`'s current organization, and the namespace every key below must carry. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the same fixture. Present so the cache-key assertion is about the
 *  CURRENT organization rather than about the only one there is — a one-organization fixture cannot fail
 *  a namespacing test. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

const MEMBERS: readonly MemberResource[] = [
  {
    user_id: '01JUSERAAAAAAAAAAAAAAAAAAA',
    name: 'Ada Lovelace',
    email: 'ada@example.test',
    role: 'owner',
    status: 'active',
    joined_at: '2026-01-04T09:00:00+00:00',
  },
  {
    user_id: '01JUSERBBBBBBBBBBBBBBBBBBB',
    name: 'Sub Pended',
    email: 'sub@example.test',
    role: 'analyst',
    // Suspended rows are LISTED on purpose: an administrator restoring access has to find the person.
    status: 'suspended',
    joined_at: null,
  },
];

const PENDING: InvitationResource = {
  id: '01JINVAAAAAAAAAAAAAAAAAAAA',
  email: 'first@example.test',
  role: 'analyst',
  status: 'pending',
  expires_at: '2026-08-20T09:00:00+00:00',
  created_at: '2026-08-13T09:00:00+00:00',
  invited_by_name: 'Ada Lovelace',
};

const SECOND_PENDING: InvitationResource = {
  ...PENDING,
  id: '01JINVBBBBBBBBBBBBBBBBBBBB',
  email: 'second@example.test',
};

const ACCEPTED: InvitationResource = {
  ...PENDING,
  id: '01JINVCCCCCCCCCCCCCCCCCCCC',
  email: 'joined@example.test',
  status: 'accepted',
};

const membersUrl = (orgId: string) => `${ORIGIN}/api/v1/organizations/${orgId}/members`;
const invitationsUrl = (orgId: string) => `${ORIGIN}/api/v1/organizations/${orgId}/invitations`;

/** Both list bodies nest the array under a NAMED KEY — `{"data":{"members":[…]}}` — because
 *  `ResponseShape` cannot express "an array of" for a response key and every published component must be
 *  `additionalProperties: false`. A fixture that returned `{"data":[…]}` would make these specs pass
 *  against a shape the server never sends. */
const listHandlers = (
  invitations: readonly InvitationResource[] = [PENDING],
  members: readonly MemberResource[] = MEMBERS,
) => [
  http.get(membersUrl(ORG_A), () => HttpResponse.json({ data: { members } })),
  http.get(invitationsUrl(ORG_A), () => HttpResponse.json({ data: { invitations } })),
];

/** Captures the tree's QueryClient so the KEYS themselves can be asserted, not just the request URLs. The
 *  organization is not in the URL of this screen — that is the whole hazard — so the key is the artifact
 *  worth reading.
 *
 *  The write happens in an EFFECT and not during render: `react-hooks/globals` is an ERROR on reassigning a
 *  module-scope variable from a render body, and it is right — a side effect during render fires whenever
 *  React happens to re-render. */
let captured: QueryClient | null = null;
function CaptureClient() {
  const queryClient = useQueryClient();
  useEffect(() => {
    captured = queryClient;
  }, [queryClient]);
  return null;
}

const renderScreen = () =>
  render(
    <Providers>
      <SessionProvider>
        <CaptureClient />
        <MembersScreen />
      </SessionProvider>
    </Providers>,
  );

const cacheKeys = (): unknown[][] =>
  (captured?.getQueryCache().getAll() ?? []).map((query) => [...query.queryKey]);

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token';
  captured = null;
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('every organization-scoped key comes from useOrgKey()', () => {
  it('reads both lists under ["org", currentOrgId, …] and never a bare key', async () => {
    worker.use(...listHandlers());

    const screen = await renderScreen();
    await expect.element(screen.getByText('Ada Lovelace').first()).toBeVisible();

    await vi.waitFor(() => {
      const keys = cacheKeys();
      expect(keys).toContainEqual(['org', ORG_A, 'members']);
      expect(keys).toContainEqual(['org', ORG_A, 'invitations']);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`, and
    // namespacing the identity document by the organization it announces would be circular. Everything
    // else in this tree is prefixed.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in.
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
  });

  it('addresses the org in the PATH too, which is a routing hint and not the scope', async () => {
    const urls: string[] = [];
    worker.use(
      http.get(membersUrl(ORG_A), ({ request }) => {
        urls.push(new URL(request.url).pathname);
        return HttpResponse.json({ data: { members: MEMBERS } });
      }),
      http.get(invitationsUrl(ORG_A), ({ request }) => {
        urls.push(new URL(request.url).pathname);
        return HttpResponse.json({ data: { invitations: [PENDING] } });
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByText('Ada Lovelace').first()).toBeVisible();

    await vi.waitFor(() => {
      expect(urls).toHaveLength(2);
    });
    // Mounted under `organizations/{organization}` with `->scopeBindings()`; `TenantContext` re-reads the
    // membership row per request and Laravel would ignore a client-supplied organization. The segment is a
    // routing hint — a foreign id 404s at binding time — and the SCOPE is the session.
    expect(urls.sort()).toEqual([
      `/api/v1/organizations/${ORG_A}/invitations`,
      `/api/v1/organizations/${ORG_A}/members`,
    ]);
  });
});

describe('the two lists render what the server derived', () => {
  it('shows members including suspended ones, and invitations including terminal ones', async () => {
    worker.use(...listHandlers([PENDING, ACCEPTED]));

    const screen = await renderScreen();

    await expect.element(screen.getByText('Ada Lovelace').first()).toBeVisible();
    // Suspended rows are shown WITH their status rather than filtered out: a filtered list makes "why can I
    // not see Bob" unanswerable from the UI.
    // Scoped to the TABLE. Below 768px the same rows render as a stack of cards (P6: a table does
    // not become a horizontal scroller on a phone, because a scroller hides the trailing columns),
    // so the name appears in two layouts of which CSS shows exactly one — `display: none` also
    // removes the other from the accessibility tree, so a reader hears each member once. Playwright
    // locators match hidden elements too, which is why the assertion names which layout it means.
    await expect.element(screen.getByRole('cell', { name: 'Sub Pended' })).toBeVisible();
    await expect.element(screen.getByRole('cell', { name: 'Suspended' })).toBeVisible();

    // Terminal invitations are listed too — `status` is derived server-side, and hiding accepted rows makes
    // "why can I not re-invite this address" unanswerable.
    await expect.element(screen.getByText('joined@example.test')).toBeVisible();
    await expect.element(screen.getByText('Accepted')).toBeVisible();

    // …and they carry NO controls. An accepted invitation has nothing left to do, and a button that cannot
    // act is a question the administrator has to answer.
    expect(
      screen.getByRole('button', { name: 'Resend to joined@example.test' }).elements(),
    ).toHaveLength(0);
    expect(
      screen.getByRole('button', { name: 'Revoke invitation for joined@example.test' }).elements(),
    ).toHaveLength(0);
  });

  it('never renders a token, because the resource carries none in any form', async () => {
    worker.use(...listHandlers());

    const screen = await renderScreen();
    await expect.element(screen.getByText('first@example.test')).toBeVisible();

    // `InvitationResource` publishes no plaintext, no digest and no prefix of either: an admin list is a
    // screen, a log line and a browser cache away from being a place a capability leaks. Re-delivering a
    // link is a RESEND, which mints a new token.
    expect(document.body.textContent).not.toContain('token');
  });
});

describe('the invite form maps a 422 onto the field that owns it', () => {
  it('renders the server message under Email, verbatim, and does not navigate or clear', async () => {
    worker.use(
      ...listHandlers(),
      http.post(invitationsUrl(ORG_A), () =>
        HttpResponse.json(
          envelope('validation', {
            errors: {
              email: ['An invitation for this address is already pending. Resend it instead.'],
            },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();
    await screen.getByLabelText('Email').fill('first@example.test');
    await screen.getByRole('button', { name: 'Send invitation', exact: true }).click();

    // Validation MESSAGES are Laravel-translated end-user copy and are shown verbatim; the envelope's
    // `message` is operator-facing and never is.
    await expect
      .element(
        screen.getByText('An invitation for this address is already pending. Resend it instead.'),
      )
      .toBeVisible();
    await expect.element(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true');
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('POSTs {email, role} with no ownership column, and re-reads the list', async () => {
    const bodies: unknown[] = [];
    let invitationReads = 0;
    // FLIPPED BY THE POST, so what the list returns depends on whether the invite HAPPENED rather than
    // on how many times the list has been read. A read counter is the wrong discriminator here — see the
    // block above the final assertions.
    let invited = false;
    worker.use(
      http.get(membersUrl(ORG_A), () => HttpResponse.json({ data: { members: MEMBERS } })),
      http.get(invitationsUrl(ORG_A), () => {
        invitationReads += 1;
        // SECOND_PENDING is a row the client cannot possibly invent: the POST reply below carries
        // `new@example.test`, so an optimistic update or a `setQueryData` patch from the mutation
        // response would render THAT address and never this one. Serving it on every post-invite read
        // — not on "read number 2" — is what makes the assertion independent of the read count.
        return HttpResponse.json({
          data: { invitations: invited ? [PENDING, SECOND_PENDING] : [PENDING] },
        });
      }),
      http.post(invitationsUrl(ORG_A), async ({ request }) => {
        bodies.push(await request.json());
        invited = true;
        return HttpResponse.json({ data: { ...PENDING, email: 'new@example.test' } }, { status: 201 });
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByText('first@example.test')).toBeVisible();
    // NEGATIVE CONTROL, and it is what stops the post-invite assertion passing for the wrong reason: the
    // mount read happened (the row above is on screen) and the server row is NOT there yet. Without this,
    // a stray read arriving BEFORE the click could seed the row and the re-read would go unproven.
    expect(document.body.textContent).not.toContain('second@example.test');
    const readsBeforeInvite = invitationReads;
    await screen.getByLabelText('Email').fill('  New@Example.test  ');
    await screen.getByRole('button', { name: 'Send invitation', exact: true }).click();

    await vi.waitFor(() => {
      // TRIMMED for parity with the body the server validates, and NOT lowercased: the server lowercases
      // unconditionally in `prepareForValidation()`, so doing it here would only mean the user watches
      // their own input change. NO `organization_id` — it is an ownership column and a URL segment; a body
      // field would be a tenant key the caller can set, which is an authorization bug with a 201.
      expect(bodies).toEqual([{ email: 'New@Example.test', role: 'analyst' }]);
    });

    // The confirmation shows the address the SERVER normalised, which is how the lowercasing is visible.
    await expect.element(screen.getByText('Invitation sent to new@example.test.')).toBeVisible();
    // Not optimistic, and not `setQueryData`: `status` and `expires_at` are both server-derived, so the row
    // is re-read rather than invented. ASSERTED BY ITS RESULT — the server's row reaching the screen —
    // rather than by a request count, and that change fixed a real flake.
    //
    // THIS USED TO BE `expect(invitationReads).toBe(2)` INSIDE `vi.waitFor`, WHICH IS A ONE-WAY RATCHET.
    // `waitFor` retries until the assertion holds, so an exact count that OVERSHOOTS can never come back:
    // one stray read makes it fail for the whole timeout and report `expected 3 to be 2`. Measured on
    // 2026-08-14: it failed once in ~28 full-suite runs and passed 3/3 in isolation, and six mechanisms
    // were tested and refuted — window-focus refetch (`refetchOnWindowFocus: false`), a remount refetch
    // (`staleTime: 30_000` covers it), a cache shared between tests (`Providers` builds a client per
    // mount), a cold-Vite page reload (a wiped `node_modules/.vite` run produced zero reload warnings),
    // a late response from the previous test (MSW matches handlers at request INTERCEPTION, so a response
    // arriving later cannot reach the next test's handler), and a third read that normally lands after the
    // assertion (there is none, even 1500 ms later). The stray read's origin is UNPROVEN; what is proven is
    // that the count is not the property this test exists to guard.
    //
    // What replaces it cannot be satisfied by an invented row and cannot be broken by an extra read, and
    // it is STRICTLY STRONGER than the count: the count said "a request was made", this says "the list on
    // screen came from the server's answer to it".
    await expect.element(screen.getByText('second@example.test')).toBeVisible();
    // Kept, and monotonic on purpose: it states the intent — a read happened AFTER the invite — in a form
    // no additional read can invalidate. `toBeGreaterThan` never un-satisfies once true.
    expect(invitationReads).toBeGreaterThan(readsBeforeInvite);
  });

  it('offers `owner` only to an owner, because that is the one act the role cannot undo', async () => {
    // The escalation guard is a SECOND permission server-side (`members.manage_owner`), which only `owner`
    // holds. Hiding the option removes a choice that would 403; it does not make the decision.
    worker.use(
      ...listHandlers(),
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({
          data: sessionFixture({
            organizations: [
              { id: ORG_A, name: 'Acme Research', slug: 'acme', role: 'admin', status: 'active' },
              { id: ORG_B, name: 'Brightwater Legal', slug: 'bw', role: 'owner', status: 'active' },
            ],
          }),
        }),
      ),
    );

    const screen = await renderScreen();
    await screen.getByRole('combobox', { name: 'Role' }).click();

    // The role is read PER ORGANIZATION: this viewer is an owner of the OTHER organization and an admin
    // here, and it is the current one that decides.
    await expect.element(screen.getByRole('option', { name: 'Admin' })).toBeVisible();
    expect(screen.getByRole('option', { name: 'Owner' }).elements()).toHaveLength(0);
    expect(screen.getByRole('option').elements()).toHaveLength(3);
  });
});

describe('resend is limited per RECIPIENT, so the cooldown is per row', () => {
  it('disables only the throttled row after a 429 and counts down from Retry-After', async () => {
    worker.use(
      ...listHandlers([PENDING, SECOND_PENDING]),
      http.post(`${invitationsUrl(ORG_A)}/${PENDING.id}/resend`, () =>
        HttpResponse.json(envelope('rate_limit', { retryable: true }), {
          status: 429,
          // SECONDS, off the RESPONSE HEADER — `retry_after` is not in the JSON envelope. A 429 without
          // this header degrades the cooldown to nothing, silently.
          headers: { 'Retry-After': '30' },
        }),
      ),
    );

    const screen = await renderScreen();
    await screen
      .getByRole('button', { name: `Resend to ${PENDING.email}` })
      .click();

    await expect.element(screen.getByText('Try again in 30s.')).toBeVisible();
    await expect
      .element(screen.getByRole('button', { name: `Resend to ${PENDING.email}` }))
      .toBeDisabled();
    // `throttle:invitation-resend` keys on the INVITATION, not on the actor: the budget belongs to the
    // recipient's mailbox. A screen-level cooldown would lock a row that still has budget.
    await expect
      .element(screen.getByRole('button', { name: `Resend to ${SECOND_PENDING.email}` }))
      .toBeEnabled();
  });

  it('renders a 422 on `invitation` verbatim, a key no form renders', async () => {
    worker.use(
      ...listHandlers(),
      http.post(`${invitationsUrl(ORG_A)}/${PENDING.id}/resend`, () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { invitation: ['This invitation can no longer be resent.'] },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();
    await screen
      .getByRole('button', { name: `Resend to ${PENDING.email}` })
      .click();

    // Through `actionErrorCopy`, not `applyAuthError`: a button has no field to key an error to, and
    // `ERROR_COPY.validation` ("Some details need fixing before this can be saved.") would be nonsense
    // beside a Resend button.
    await expect
      .element(screen.getByText('This invitation can no longer be resent.'))
      .toBeVisible();
    expect(document.body.textContent).not.toContain('Some details need fixing');
  });
});

describe('revoke deletes by id and re-reads rather than writing the cache', () => {
  it('DELETEs under the organization path and invalidates the list', async () => {
    const deleted: string[] = [];
    let invitationReads = 0;
    worker.use(
      http.get(membersUrl(ORG_A), () => HttpResponse.json({ data: { members: MEMBERS } })),
      http.get(invitationsUrl(ORG_A), () => {
        invitationReads += 1;
        return HttpResponse.json({
          data: {
            invitations: invitationReads === 1 ? [PENDING] : [{ ...PENDING, status: 'revoked' }],
          },
        });
      }),
      http.delete(`${invitationsUrl(ORG_A)}/${PENDING.id}`, ({ request }) => {
        deleted.push(new URL(request.url).pathname);
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderScreen();
    await screen
      .getByRole('button', { name: `Revoke invitation for ${PENDING.email}` })
      .click();

    await vi.waitFor(() => {
      expect(deleted).toEqual([`/api/v1/organizations/${ORG_A}/invitations/${PENDING.id}`]);
    });

    // The re-read is the only way the screen learns the new DERIVED status — the response is an
    // acknowledgement, and `status` is computed from three timestamps the browser cannot see.
    await expect.element(screen.getByText('Revoked')).toBeVisible();
    expect(
      screen.getByRole('button', { name: `Revoke invitation for ${PENDING.email}` }).elements(),
    ).toHaveLength(0);
  });
});

describe('a 403 is the normal answer for a role without members.view', () => {
  it('renders the class-mapped sentence and no invite form at all', async () => {
    worker.use(
      http.get(membersUrl(ORG_A), () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
      http.get(invitationsUrl(ORG_A), () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('Members could not be loaded')).toBeVisible();
    await expect.element(screen.getByText('Invitations could not be loaded')).toBeVisible();
    // The form is gated on the READ having succeeded, which is Laravel's own word that this person may also
    // manage members: owner and admin hold `members.view` and `members.manage` together, and
    // knowledge_manager and analyst hold neither. A server answer, not a cached role.
    expect(screen.getByRole('button', { name: 'Send invitation', exact: true }).elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain('api-7.internal');
  });
});

describe('with no current organization it makes no org-scoped request at all', () => {
  it('says so, and builds no key from a null organization', async () => {
    // `useOrgKey()` THROWS on a null organization rather than returning `['org', undefined, …]`, which would
    // be ONE shared cache namespace for every org-less state on the platform. The gate is expressed as a
    // MOUNT condition, so the throw is unreachable rather than merely unlikely.
    const requests: string[] = [];
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({
          data: sessionFixture({
            current_organization_id: null,
            organizations: [
              { id: ORG_B, name: 'Brightwater Legal', slug: 'bw', role: 'analyst', status: 'invited' },
            ],
          }),
        }),
      ),
      http.get(membersUrl(ORG_A), () => {
        requests.push('members');
        return HttpResponse.json({ data: { members: MEMBERS } });
      }),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('No organization selected')).toBeVisible();
    expect(requests).toEqual([]);
    expect(cacheKeys().filter((key) => key[0] === 'org')).toEqual([]);
  });
});
