'use client';

import { KbError, type SessionResource } from '@kb/contracts';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { usePathname, useRouter } from 'next/navigation';
import { useCallback } from 'react';

import { useResetQueryClient } from '@/components/providers';
import { sessionCredential } from '@/lib/api/browser';

import {
  activeOrganizations,
  browserFetchData,
  NEUTRAL_ROUTE,
  performOrganizationSwitch,
  SESSION_KEY,
  SWITCH_ORGANIZATION_PATH,
} from './session';
import { useSession } from './session-context';

/**
 * The organization switch — the highest-consequence interaction in this app, because the organization
 * is not in the URL and therefore not in a single one of Next's five cache keys.
 *
 * The algorithm is the docblock at `src/app/(admin)/settings/page.tsx:4-8`, implemented literally.
 * That docblock is the spec; this file is not allowed to improve on it.
 *
 * The five steps themselves — `NEUTRAL_ROUTE`, `SwitchOrganizationSteps`, `performOrganizationSwitch` —
 * live in `session.ts`, and the reason is mechanical rather than aesthetic: THIS file imports
 * `useResetQueryClient` from `src/components/providers.tsx`, which contains JSX, and the `unit` Vitest
 * project has no JSX transform (see session.ts's header). A spec importing the pure sequence from here
 * would fail to PARSE providers.tsx. So the part that must be asserted by call order lives in the
 * JSX-free module and this file is the React wiring around it.
 */

export interface SwitchOrganization {
  readonly switchTo: (organizationId: string) => Promise<void>;
  readonly isPending: boolean;
  /** For the caller to render through `endUserCopy` — never the envelope's `message`. */
  readonly error: Error | null;
}

export function useSwitchOrganization(): SwitchOrganization {
  const session = useSession();
  const queryClient = useQueryClient();
  const resetQueryClient = useResetQueryClient();
  const router = useRouter();
  const pathname = usePathname();

  /**
   * ── WHY THIS BODY CARRIES `organization_id` AND IT IS NOT AN `OWNERSHIP_KEYS` VIOLATION ────────
   * `organization_id` is in `OWNERSHIP_KEYS`, and no FORM SCHEMA may carry it — which is why this is
   * not a form and gets no Zod schema. The ban is a rule about form state: a client that posts an
   * ownership column through a settings form is attempting privilege escalation for a silent 200.
   *
   * This one endpoint is the legitimate exception, for the reason the rule exists at all: its entire
   * subject IS the ownership relation. It re-checks membership server-side before changing anything,
   * and the value posted is one the server itself handed us in `GET /api/v1/me`, filtered to ACTIVE
   * memberships and re-checked by step 0 above. Every OTHER endpoint takes its organization from the
   * `{organization}` path segment with `TenantContext` re-reading the membership row.
   */
  const mutation = useMutation<SessionResource, Error, string>({
    mutationFn: async (organizationId) =>
      browserFetchData<SessionResource>({
        path: SWITCH_ORGANIZATION_PATH,
        method: 'POST',
        body: { organization_id: organizationId },
        credential: await sessionCredential(),
      }),
    onError: (error) => {
      // THE ONE PLACE AN ERROR TRIGGERS AN INVALIDATE, and only for `authorization`. A 403 here is
      // the "you were removed from the organization you just tried to switch into" case, which means
      // the membership list we rendered the control from is STALE — so it has to go, or the user
      // keeps picking an option that keeps failing. Branching on the class, never on the status: 403
      // is `authorization` on an admin surface and the same class renders 404 on a public one.
      if (error instanceof KbError && error.error_class === 'authorization') {
        void queryClient.invalidateQueries({ queryKey: SESSION_KEY });
      }
    },
  });

  const { mutateAsync } = mutation;

  const switchTo = useCallback(
    async (organizationId: string): Promise<void> => {
      // Derived INSIDE the callback: a membership array built during render is a fresh identity every
      // render, which makes this callback's dependencies change every render for no behavioural
      // reason. It is also read as late as possible on purpose — the list must be the one the session
      // holds at the moment of the click, not at the moment the handler was created.
      const active = session.status === 'authenticated' ? activeOrganizations(session) : [];

      try {
        await performOrganizationSwitch(
          {
            isMember: (id) => active.some((organization) => organization.id === id),
            navigateToNeutralRoute: () => {
              if (pathname !== NEUTRAL_ROUTE) router.push(NEUTRAL_ROUTE);
            },
            cancelQueries: () => queryClient.cancelQueries(),
            switchOrganization: (id) => mutateAsync(id),
            resetQueryClient,
            refreshRouterCache: () => {
              router.refresh();
            },
          },
          organizationId,
        );
      } catch {
        // Already recorded on `mutation.error`, which the switcher renders through `endUserCopy`.
        // Rethrowing would surface as an unhandled rejection from a change handler, which no user and
        // no operator can act on.
      }
    },
    [session, mutateAsync, pathname, queryClient, resetQueryClient, router],
  );

  return { switchTo, isPending: mutation.isPending, error: mutation.error };
}
