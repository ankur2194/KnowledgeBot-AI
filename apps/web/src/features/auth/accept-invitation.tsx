'use client';

import type { SessionResource } from '@kb/contracts';
import { useMutation, useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

import { actionErrorCopy } from './action-error';
import {
  acceptInvitation,
  CONSOLE_ROUTE,
  invitationPreviewQueryOptions,
  INVALID_INVITATION_COPY,
  isInvalidInvitationError,
} from './invitation';
import { InvitationPreviewSummary } from './invitation-preview';
import { LogoutButton } from './logout-button';
import { RegisterForm } from './register-form';
import { browserNavigation, sessionQueryOptions, toSessionState } from './session';
import { useStripTokenFromUrl } from './use-strip-token-from-url';

/**
 * `/invitations/accept?token=…` — ONE ROUTE THAT HAS TO WORK BOTH SIGNED IN AND SIGNED OUT, because
 * the link in the invitation email does not know which the recipient is.
 *
 *   signed out -> they have no account yet, so this delegates to <RegisterForm/> (guest, 422-gated).
 *   signed in  -> `POST /auth/invitations/accept`, which is `auth:sanctum`, adds the membership.
 *
 * `proxy.ts` already exempts this path from BOTH bounces — it is public, so an anonymous visitor is not
 * sent to `/login`, and it is not `/login`, so a visitor who looks signed in is not sent away either.
 * Both exemptions are load-bearing here rather than incidental.
 *
 * ── SO THE SCREEN HAS TO ASK WHO IT IS TALKING TO, AND `useSession()` CANNOT ANSWER ───────────────
 * `<SessionProvider>` mounts in `(admin)/layout.tsx` only. That is deliberate — a signed-out visitor on
 * `/login` must fire ZERO `GET /api/v1/me` requests, so the `(auth)` root layout does not mount it and
 * `useSession()` would throw here. This screen therefore reads the identity query DIRECTLY, through the
 * same `sessionQueryOptions()` and the same pure `toSessionState()` machine the provider uses, so there
 * is one state machine rather than two spellings of one. The extra `/me` request is the price of a route
 * whose whole job is to branch on identity, and it is paid on this route only.
 *
 * A 401 lands on `anonymous`, which is the register branch — exactly right. `unavailable` (a 502, a
 * `rate_limit`, an `internal_dependency`) is NOT folded into it: offering an account-creation form to a
 * signed-in admin because `/me` was briefly unreachable would have them typing a password for an account
 * that already exists.
 *
 * ── THE PREVIEW GATES EVERYTHING, INCLUDING THE REGISTER BRANCH ──────────────────────────────────
 * A dead token gets one sentence and no controls, before either branch is chosen. `<RegisterForm/>`
 * re-reads the same query key, so the delegation costs no second request and cannot disagree with what
 * this component already rendered.
 */
export function AcceptInvitation({ token }: { readonly token: string }) {
  // Idempotent, and called here as well as inside <RegisterForm/> because either may be the outermost
  // component on this route. The second call is a no-op: the parameter is already gone.
  useStripTokenFromUrl();

  const preview = useQuery(invitationPreviewQueryOptions(token));
  const identity = useQuery(sessionQueryOptions());
  const session = toSessionState(identity.status, identity.data, identity.error);

  const accept = useMutation<SessionResource, Error, void>({
    // No `Idempotency-Key` and no retry: accepting twice is a 422 on `token`, because the first call
    // marked the invitation accepted. The double-click guard is `disabled={accept.isPending}`.
    mutationFn: () => acceptInvitation(token),
    onSuccess: () => {
      // `replace`, not `assign`: the entry being overwritten arrived holding `?token=…`. Together with
      // `useStripTokenFromUrl` that is two layers keeping a consumed capability out of history.
      //
      // A FULL DOCUMENT navigation, never `router.push`: this request changed the session's organization
      // set and possibly its `current_organization_id`, and the Client Router Cache is keyed by path —
      // it holds payloads captured before the membership existed. Nothing is invalidated or reset here
      // on purpose: the navigation destroys the QueryClient, every observer and the Router Cache in one
      // step, and a second mechanism for a cache that is about to cease existing reads as a rule
      // somebody will copy where it matters.
      browserNavigation.replace(CONSOLE_ROUTE);
    },
  });

  if (token.length === 0) {
    return <Refused>This invitation link is incomplete.</Refused>;
  }

  if (preview.isPending) {
    return <Loading label="Checking your invitation…" />;
  }

  if (preview.error !== null) {
    // Class, never status. The preview's deny is a 404 mapped to `authorization`; anything else is a
    // fault and must not be reported as a dead invitation.
    return (
      <Refused>
        {isInvalidInvitationError(preview.error)
          ? INVALID_INVITATION_COPY
          : actionErrorCopy(preview.error)}
      </Refused>
    );
  }

  if (session.status === 'loading') {
    return <Loading label="Checking whether you are signed in…" />;
  }

  if (session.status === 'unavailable') {
    return (
      <Alert variant="destructive">
        <AlertTitle>Your account could not be loaded</AlertTitle>
        {/* endUserCopy through `actionErrorCopy`, never the envelope's `message` — that field is
            operator-facing and can carry an internal hostname or raw provider text. */}
        <AlertDescription>{actionErrorCopy(session.error)}</AlertDescription>
      </Alert>
    );
  }

  if (session.status === 'anonymous') {
    // No account yet: registration IS the acceptance. The server's `RegisteredUserController` creates
    // the user, the membership and the audit row in one transaction, so there is no "register then
    // accept" two-step to get half-way through.
    return <RegisterForm token={token} />;
  }

  // Case-INSENSITIVE, because both sides are normalised to lower case server-side (a database CHECK
  // enforces `email = lower(email)` on invitations, and users are unique on `lower(email)`) and a
  // case-sensitive compare here would invent a mismatch warning for an invitation that accepts fine.
  const invitedElsewhere = session.user.email.toLowerCase() !== preview.data.email.toLowerCase();

  return (
    <div className="space-y-6">
      <InvitationPreviewSummary preview={preview.data} />

      <p className="text-muted-foreground text-sm">
        You are signed in as <span className="font-medium">{session.user.email}</span>.
      </p>

      {invitedElsewhere ? (
        <Alert>
          <AlertTitle>This invitation is for a different address</AlertTitle>
          <AlertDescription className="space-y-3">
            <span>
              It was sent to {preview.data.email}. Accepting while signed in as{' '}
              {session.user.email} will be refused. Sign out, sign in as the invited address, then open
              the invitation link from your email again.
            </span>
            {/* The existing sign-out control, IMPORTED rather than re-implemented — it is the four-step
                sequence (cancel, POST, replace the client, full document navigation) and a second copy
                of that is a second place three of those steps get dropped. It depends on <Providers/>
                only, not on <SessionProvider/>, which is why it mounts under this route group at all. */}
            <span className="block">
              <LogoutButton />
            </span>
          </AlertDescription>
        </Alert>
      ) : null}

      {accept.error === null ? null : (
        <Alert variant="destructive">
          {/* `actionErrorCopy` and not `applyAuthError`: there is no form here, so a 422 has no field to
              land on. The server collapses invalid token, address mismatch and "already a member" into
              ONE 422 on `token` — an indistinguishable answer by design — and its message is
              Laravel-translated end-user copy, so it is shown verbatim. */}
          <AlertDescription>{actionErrorCopy(accept.error)}</AlertDescription>
        </Alert>
      )}

      <Button
        type="button"
        // The button is NOT disabled on an address mismatch. Client-side blocking would be
        // enforcement-by-UI, and the server is the authority on whether this token and this identity go
        // together; the warning above is an affordance, not a gate.
        disabled={accept.isPending}
        className="w-full"
        onClick={() => {
          accept.mutate();
        }}
      >
        {accept.isPending ? 'Joining…' : `Join ${preview.data.organization_name}`}
      </Button>
    </div>
  );
}

function Loading({ label }: { readonly label: string }) {
  return (
    <div aria-busy="true" aria-live="polite" className="space-y-3">
      <span className="sr-only">{label}</span>
      <Skeleton className="h-40" />
      <Skeleton className="h-10" />
    </div>
  );
}

/** Shared refusal panel. `role="alert"` comes from `<Alert/>`; it replaces the controls rather than
 *  sitting above them, because a Join button on a dead token can only fail. */
function Refused({ children }: { readonly children: ReactNode }) {
  return (
    <div className="space-y-4">
      <Alert variant="destructive">
        <AlertTitle>This invitation cannot be used</AlertTitle>
        <AlertDescription>{children}</AlertDescription>
      </Alert>
      {/*
        A PLAIN ANCHOR, not `next/link`, and argued rather than defaulted — the same three reasons
        `verify-email-notice.tsx` gives, plus one specific to this route. (No `eslint-disable` is needed:
        `@next/next/no-html-link-for-pages` does not fire on a non-literal href, and a directive nothing
        uses is itself a warning.)
          1. `/` lives under a DIFFERENT ROOT LAYOUT from `(auth)`, and Next serves a navigation across
             root layouts as a full document load however it is expressed — so `<Link>` would buy no
             client-side navigation, which is the only thing the rule protects.
          2. `<Link>` PREFETCHES, which EXECUTES the admin tree's layouts on the Next server for a route
             this visitor may not even be able to open.
          3. THE HEAP HELD BY THIS PAGE CARRIES A CAPABILITY — the `token` prop and its
             `['invitation-preview', token]` cache entry. A full load discards both;
             `useStripTokenFromUrl` only takes the token out of the address bar.
      */}
      <p className="text-muted-foreground text-sm">
        <a href={CONSOLE_ROUTE} className="underline">
          Go to the console
        </a>
      </p>
    </div>
  );
}
