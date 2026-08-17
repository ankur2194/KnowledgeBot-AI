'use client';

import { useQuery } from '@tanstack/react-query';
import { useEffect, type ReactNode } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { endUserCopy } from '@/lib/forms/apply-server-errors';

import {
  activeOrganizations,
  browserNavigation,
  sessionQueryOptions,
  toSessionState,
  type SessionState,
} from './session';
import { SessionContext } from './session-context';

/**
 * The ONE place identity enters the admin tree.
 *
 * WHERE THIS MOUNTS: inside `src/app/(admin)/layout.tsx`, INSIDE `<Providers>`, wrapping the nav and
 * `{children}`. NOT in `providers.tsx` and NOT in `(auth)/layout.tsx`. Every consequence is intended:
 *  - a signed-out visitor on `/login` fires ZERO `GET /api/v1/me` requests, so there is no
 *    401 -> redirect -> render -> 401 loop and no needless traffic against the most rate-limited part
 *    of the product;
 *  - `providers.tsx` needs no edit at all, which keeps it out of the conflict map entirely — its
 *    `useResetQueryClient` and its `useState` client are CONSUMED, never modified;
 *  - the admin surface has exactly one identity source, so "which organization am I in" has one answer.
 *
 * WHY THIS IS NOT A SERVER COMPONENT, AND CANNOT BECOME ONE. Three independent reasons:
 *  1. `'use server'` is banned across apps/web and grepped for in CI — there is no action path here.
 *  2. `src/lib/api/server.ts` deliberately does not forward the session cookie: a server-side fetch
 *     sends no `Referer`/`Origin`, Sanctum's `fromFrontend()` classifies it third-party, and a
 *     perfectly valid cookie is ignored — a 401 that cannot reproduce in devtools. Identity is
 *     UNREADABLE from the Next server by construction.
 *  3. `SessionResource` carries `current_organization_id` and a membership list, so it is an
 *     org-scoped byte, and the Next server must not produce one: not one of its five cache keys
 *     contains the organization.
 *
 * THE ORG ID IS ALSO A PATH SEGMENT ON EVERY OTHER ADMIN ENDPOINT, AND THAT IS NOT A TENANCY
 * VIOLATION. Laravel mounts them under `organizations/{organization}` with `scopeBindings()`, and
 * `TenantContext` re-reads the membership row from PostgreSQL on every request — so the segment is a
 * ROUTING HINT, never a scope. The scope is the session. This is the sentence people get wrong.
 *
 * The state machine, the context and the reader hooks live in `session.ts` rather than here, because
 * the `unit` test project has no JSX transform and both the pure machine and the five-step switch have
 * to stay reachable from it. See that file's header.
 */

/**
 * Guards the session-expiry bounce at MODULE scope rather than per component, so two mounted readers
 * cannot each fire a navigation. A `let` is enough because a document has exactly one of these. Note
 * that each Vitest spec FILE gets a fresh module graph and therefore a fresh flag, so a spec asserting
 * the bounce gets exactly one observation per file — which is why there is exactly one such assertion.
 */
let bouncing = false;

export function SessionProvider({ children }: { readonly children: ReactNode }) {
  // No `retry` property anywhere in this file: the identifier is an ESLint error outside
  // src/lib/query/client.ts, and the global predicate there already refuses to retry `authentication`
  // by class — which is the entire policy this query needs. A 401 is an answer, not a failure.
  const query = useQuery(sessionQueryOptions());
  const state = toSessionState(query.status, query.data, query.error);

  useEffect(() => {
    if (state.status !== 'anonymous' || bouncing) return;
    bouncing = true;

    // A FULL DOCUMENT navigation, matching what browser.ts already does on an exhausted 419.
    // `router.push` is wrong twice over: the Router Cache lives in this tab and is keyed by path, and
    // `/login` lives under a different root layout.
    //
    // The `next` value is OUR OWN current location, not attacker input — and the login page runs it
    // through `safeNext` again on the way back in regardless, because a value that has been through a
    // URL is a value anybody can edit.
    const here = `${window.location.pathname}${window.location.search}`;
    browserNavigation.assign(`/login?next=${encodeURIComponent(here)}`);
  }, [state.status]);

  return (
    <SessionContext.Provider value={state}>
      <MembershipNotice state={state} />
      {children}
    </SessionContext.Provider>
  );
}

/**
 * The two membership states that are NOT errors and still need a sentence, plus the one that is.
 *
 * Rendered as a banner ABOVE `{children}` rather than instead of them, deliberately: the nav strip and
 * the sign-out button live in this subtree, and a panel that replaced the tree would leave a suspended
 * user staring at a dead end with no way to sign out. Every org-scoped screen beneath is already inert,
 * because `useCurrentOrgId()` is `null` and their queries are gated on it.
 *
 * The two sentences differ because the resource deliberately ships non-active memberships WITH their
 * `status` so the client can tell them apart. Collapsing them into "no organizations" would tell a
 * suspended member their account is empty.
 */
function MembershipNotice({ state }: { readonly state: SessionState }) {
  if (state.status === 'unavailable') {
    return (
      <Alert variant="destructive" className="mx-auto mt-4 max-w-6xl">
        <AlertTitle>Your account could not be loaded</AlertTitle>
        {/* endUserCopy, never the envelope's `message`: that field is operator-facing and can carry an
            internal hostname or raw text from an upstream provider. */}
        <AlertDescription>{endUserCopy(state.error)}</AlertDescription>
      </Alert>
    );
  }

  if (state.status !== 'authenticated') return null;
  if (activeOrganizations(state).length > 0) return null;

  const hasInactive = state.organizations.length > 0;
  return (
    <Alert className="mx-auto mt-4 max-w-6xl">
      <AlertTitle>
        {hasInactive ? 'No active organization' : 'You are not a member of any organization'}
      </AlertTitle>
      <AlertDescription>
        {hasInactive
          ? 'Your memberships are not active yet. An organization owner has to activate one before you can work in it.'
          : 'Ask an organization owner to invite you, or accept an invitation you have already received.'}
      </AlertDescription>
    </Alert>
  );
}
