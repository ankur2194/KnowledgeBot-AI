'use client';

import type { AcknowledgementResource } from '@kb/contracts';
import { useMutation } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { useForm, type FieldValues } from 'react-hook-form';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { sessionCredential } from '@/lib/api/browser';

import { applyAuthError } from './auth-error';
import { browserFetchData } from './session';
import { useCooldown } from './use-cooldown';

/**
 * The email-verification screen, which is TWO screens sharing one component because they share one
 * error surface and are told apart by a single primitive.
 *
 *  - `token !== null`  — the emailed link was opened. Consume it once on mount, then render either
 *                        the success state or THE SINGLE FAILURE STATE.
 *  - `token === null`  — the NOTICE case, reached from the unverified banner in `(admin)/layout.tsx`.
 *                        Explain what is being withheld and offer a resend.
 *
 * ── THE LINK ROUTE IS A GUEST ROUTE, AND THAT IS THE CONSTRAINT EVERYTHING ELSE FOLLOWS FROM ─────
 * `POST /api/v1/auth/email/verify` is reachable with no session, deliberately: an email can only
 * carry a URL, and the URL is very often opened in a DIFFERENT BROWSER from the one that registered —
 * a phone's mail client, a work laptop, a webmail tab in another profile. So nothing on this path
 * reads `useSession()`, nothing is gated on being signed in, and the failure state offers a link to
 * `/login` rather than a Resend button: resend is authenticated, and a Resend button that 401s for
 * precisely the users who most need it is worse than no button at all.
 *
 * ── THE TOKEN IS IN THE ADDRESS BAR FOR EXACTLY ONE PAGE LOAD ────────────────────────────────────
 * `stripTokenFromUrl()` rewrites the URL with `history.replaceState` the moment the token has been
 * read, so it does not persist in session history, is not restored by Back, and is not read back out
 * of `location.search` by anything that runs later. The other half of that defence is a header:
 * `next.config.ts` sets `Referrer-Policy: same-origin` on every non-`/c/` path, which is what keeps
 * the token out of the `Referer` of any outbound navigation from this page. NOBODY MAY "TIDY" THAT
 * HEADER LIST — one line there and every confirmation link leaks to the first third-party asset the
 * page reaches. `replaceState` also preserves `window.history.state`, because the App Router keeps
 * its own routing state there and clobbering it breaks every subsequent back/forward.
 *
 * ── SUCCESS IS IDEMPOTENT, WHICH IS A UI REQUIREMENT AND NOT A TRIVIA ───────────────────────────
 * An already-verified user gets the same 200. That is what makes a mail client's link-preview
 * prefetch, a double click, and a reload harmless: a second consumption is a SUCCESS, not an error.
 * The `started` ref is still there — StrictMode invokes effects twice in development and refs survive
 * that simulated remount — because "harmless" is not the same as "free": each one is a request
 * against a throttled endpoint.
 *
 * ── ALL FOUR FAILURE MODES ARE ONE 404 WITH ONE BODY, SO THE COPY CLAIMS NOTHING ────────────────
 * Unknown token, expired token, already-consumed token, and a token issued for a different address
 * are indistinguishable on the wire, by design — telling them apart would answer questions an
 * unauthenticated caller has no business asking. The copy below therefore never says "expired": we
 * do not know that. It names all four as possibilities and points at the one remedy that fixes every
 * one of them.
 *
 * ── ONE FIELD-LESS `useForm`, BECAUSE THE SHARED HANDLER IS THE POINT ───────────────────────────
 * Neither of this screen's requests has a schema — `/email/verify` submits a token nobody typed, and
 * the resend endpoint takes an EMPTY body and has no FormRequest to mirror. But both have an ERROR
 * surface, and that surface is `applyAuthError`'s: one `error_class` branch table for all six auth
 * screens, so the rule cannot drift into a seventh copy here. `applyAuthError` writes to
 * `root.serverError`, so the form exists to hold that slot and nothing else. `knownPaths` is `[]`
 * because this screen renders no field a 422 key could land on — every key is routed to the banner,
 * which is exactly right for a form with nothing to focus.
 */

/**
 * Spelled here rather than in `session.ts` because these two are this file's only consumers, and
 * `session.ts` is another agent's file this batch. WHEN A SECOND CONSUMER APPEARS, MOVE THEM THERE
 * beside `ME_PATH`/`LOGIN_PATH` rather than copying them — a path literal at two call sites is a path
 * literal that drifts on the next rename.
 */
export const VERIFY_EMAIL_PATH = '/api/v1/auth/email/verify';
export const RESEND_VERIFICATION_PATH = '/api/v1/auth/email/verification-notification';

/*
 * `AcknowledgementResource` WAS DECLARED HERE — the fifth hand-written copy of `{acknowledged: boolean}` in
 * apps/web. It is now `AcknowledgementResource` from `@kb/contracts`, which is the published component
 * these bodies actually are. Same reasoning as the `asText` extraction (D41) and the resource-type move
 * (D49): a shape re-typed per call site is a shape nothing compares to the server.
 */

export function VerifyEmailNotice({ token }: { readonly token: string | null }) {
  // The banner slot, and nothing else. See the header: this screen has no schema and no fields, but
  // it does have `applyAuthError`'s error surface, and that writes to `root.serverError`.
  const banner = useForm<FieldValues, unknown, FieldValues>();
  const cooldown = useCooldown();

  const verify = useMutation<AcknowledgementResource, Error, string>({
    mutationFn: async (value) =>
      browserFetchData<AcknowledgementResource>({
        path: VERIFY_EMAIL_PATH,
        method: 'POST',
        body: { token: value },
        // A GUEST call that is still a POST, so it still needs a CSRF token. `sessionCredential()`
        // reads the cookie when the document already has one and pays exactly one
        // `GET /sanctum/csrf-cookie` when it does not — which is the normal case here, because the
        // normal case is a browser that has never talked to us.
        credential: await sessionCredential(),
      }),
    onError: (error) => {
      applyAuthError(banner, NO_RENDERED_PATHS, error, {
        // THE ONE COPY OVERRIDE IN THIS FEATURE, and the reason is the taxonomy being right while the
        // generic sentence is wrong. An unusable link answers `authorization`/404 — correct, because the
        // capability addresses nothing the caller may act on — but ERROR_COPY.authorization reads "You
        // do not have access to this.", which tells someone who clicked a link in their own inbox that
        // they lack a permission, and directly contradicts the paragraph this page renders underneath.
        //
        // Every other class keeps its default sentence: a 429 really is a throttle and a 502 really is
        // our fault, and inventing link-shaped copy for either would be claiming a cause the server did
        // not state. `(ref …)` is preserved because an operator may still need the request id.
        copyFor: (error) =>
          error.error_class === 'authorization'
            ? `This confirmation link cannot be used.${error.request_id === null ? '' : ` (ref ${error.request_id})`}`
            : undefined,
      });
    },
  });

  const resend = useMutation<AcknowledgementResource, Error, void>({
    mutationFn: async () =>
      browserFetchData<AcknowledgementResource>({
        path: RESEND_VERIFICATION_PATH,
        method: 'POST',
        // NO BODY, AND NO SCHEMA — deliberately. The endpoint derives the address from the session,
        // so there is nothing to send and nothing to validate; inventing a `{email}` field here would
        // turn an authenticated no-argument action into an unauthenticated mail-sending oracle.
        credential: await sessionCredential(),
      }),
    onError: (error) => {
      applyAuthError(banner, NO_RENDERED_PATHS, error, {
        // A cooldown, NEVER a retry: the client does not reattempt on the user's behalf, it disables
        // the button for the window the `Retry-After` HEADER named and tells them how long. A 429
        // without that header degrades to no cooldown at all, silently.
        onRateLimit: (seconds) => cooldown.start(seconds),
      });
    },
  });

  // `mutate` is referentially stable in TanStack Query v5, so this effect runs once per token rather
  // than once per render.
  const { mutate: consume } = verify;
  const started = useRef(false);

  useEffect(() => {
    if (token === null || started.current) return;
    started.current = true;
    // Strip FIRST. If the request throws synchronously the token must still be gone from the URL.
    stripTokenFromUrl();
    consume(token);
  }, [token, consume]);

  const rootError = banner.formState.errors.root?.serverError?.message;
  const cooling = cooldown.remaining > 0;

  return (
    <div className="space-y-6">
      <h1 id="verify-heading" className="text-h1">
        {headingFor(token !== null, verify.isSuccess, verify.isError)}
      </h1>

      {/* `<Alert>` carries role="alert" already. It is rendered for BOTH mutations because the two
          states are mutually exclusive — a screen consuming a token never shows the resend control —
          so one slot cannot show one request's failure under the other's heading. */}
      {rootError === undefined ? null : (
        <Alert variant="destructive">
          <AlertDescription>{rootError}</AlertDescription>
        </Alert>
      )}

      {token !== null ? renderLinkOutcome() : renderNotice()}
    </div>
  );

  function renderLinkOutcome() {
    if (verify.isSuccess) {
      return (
        <div className="space-y-4">
          <p className="text-sm">
            You can go back to the console. Everything that was being held back is available now.
          </p>
          {/*
            A PLAIN ANCHOR, not next/link, and not a router push — with the lint rule disabled ON
            PURPOSE and argued rather than silenced. Three reasons, and the third is the one that
            makes `<Link>` actively wrong here:
              1. `/` lives under a DIFFERENT ROOT LAYOUT from this page, and Next serves a navigation
                 across root layouts as a full document load however it is expressed — so `<Link>`
                 would buy no client-side navigation, which is the only thing the rule protects.
              2. A full load is what this transition NEEDS: `GET /api/v1/me` is refetched cold, so the
                 unverified banner is gone on arrival rather than one stale cache entry later.
              3. `<Link>` PREFETCHES, and a prefetch EXECUTES the target's layouts and pages on the
                 Next server for a route the user may never open. Doing that from an unauthenticated
                 page, to the admin tree, is work and auth checks for nobody.
          */}
          {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
          <a href="/" className="text-sm underline">
            Continue to the console
          </a>
        </div>
      );
    }

    if (verify.isError) {
      return (
        <div className="space-y-4">
          {/*
            THE SINGLE FAILURE STATE. Read the conditional framing before editing a word of it: the
            first sentence does not assert that the link is what failed, because this state is also
            reached by a 502 or a throttle, and the banner above already carries the class-mapped
            sentence for those. What it must never do is claim the link EXPIRED — expiry is one of
            four indistinguishable answers, and the server refuses to say which on purpose.
          */}
          <p className="text-sm">
            A confirmation link that does not work tells us nothing more than that. It may never have
            been valid, it may already have been used, it may have been issued for a different
            address, or it may simply be too old — those four are indistinguishable from here, and
            every one of them is fixed the same way.
          </p>
          <p className="text-sm">
            Sign in and send yourself a new link from the banner at the top of the console.
          </p>
          {/* Full document load for the same reason as above: `/login` is under another root layout.
              And if this visitor IS already signed in, the proxy's UX bounce sends them straight to
              the console, where the banner is waiting with the resend button. */}
          <a href="/login" className="text-sm underline">
            Go to sign in
          </a>
        </div>
      );
    }

    // In flight. polite, not assertive: it is a progress note, not an interruption.
    return (
      <p aria-live="polite" className="text-muted-foreground text-sm">
        Confirming your email address…
      </p>
    );
  }

  function renderNotice() {
    return (
      <div className="space-y-4">
        {/*
          WHY THIS SCREEN EXISTS AT ALL, in the words a user needs: Laravel's `verified` middleware
          guards the org-scoped routes and its 403 cannot say why — the render closure rewrites every
          `authorization` message to one constant string, so "your email is unverified" and "your role
          does not permit this" arrive as the identical error. This page and the banner that links to
          it are the whole explanation channel. See unverified-banner.tsx.
        */}
        <p className="text-sm">
          Until this address is confirmed, the console will refuse most actions — and the refusal
          cannot tell you that this is the reason, which is why you were sent here. Open the link in
          the email we sent you to finish.
        </p>

        {resend.isSuccess ? (
          // role="status", NOT the <Alert> default of role="alert". `alert` is implicitly
          // aria-live="assertive" and interrupts whatever a screen reader is saying; a confirmation
          // that a mail is on its way is not an interruption. And it is INLINE, never a toast: a
          // toast disappears, and this surface can carry a `request_id` a user needs to copy for
          // support.
          <Alert role="status" aria-live="polite">
            <AlertTitle>A new link is on its way</AlertTitle>
            <AlertDescription>
              Check your inbox, and your spam folder if it is not there in a minute.
            </AlertDescription>
          </Alert>
        ) : null}

        <Button
          type="button"
          // The double-click guard is this attribute, not a retry. There is no `Idempotency-Key` on
          // this request, so nothing may replay it — a second POST is a second email.
          disabled={resend.isPending || cooling}
          onClick={() => {
            resend.mutate();
          }}
        >
          {resend.isPending ? 'Sending…' : 'Send a new link'}
        </Button>

        <p aria-live="polite" className="text-muted-foreground text-sm">
          {cooling ? `Try again in ${cooldown.remaining}s.` : ''}
        </p>
      </div>
    );
  }
}

/**
 * `[]` — this screen renders no field, so no 422 key has a control to land on.
 *
 * That is not a shortcut around `known-paths.ts`: `knownPaths` is "the paths this form RENDERS", and
 * for a form with nothing to render the honest answer is the empty set. `applyServerErrors` then
 * routes every key to a single `root.serverError` write, which is the banner above.
 */
const NO_RENDERED_PATHS: readonly string[] = [];

/**
 * Takes a BOOLEAN rather than the token itself, and that is not a style choice: an
 * `if (token === null)` here trips `security/detect-possible-timing-attacks`, which pattern-matches
 * on the identifier. The warning is a false positive — a heading is not a secret comparison — but
 * suppressing it would train the next reader to suppress the rule where it is right, and the caller
 * already has the boolean.
 */
function headingFor(hasToken: boolean, confirmed: boolean, failed: boolean): string {
  if (!hasToken) return 'Confirm your email address';
  if (confirmed) return 'Your email address is confirmed';
  if (failed) return 'We could not confirm your email address';
  return 'Confirming your email address';
}

/**
 * Remove `token` from the address bar without touching anything else about the location.
 *
 * `history.state` is passed through rather than replaced with `null`: the App Router keeps its own
 * routing state in there, and a `null` write breaks every subsequent back/forward in the tab. The
 * `has()` guard keeps this a no-op on a URL that never carried a token — which is the case in every
 * component spec, where the token arrives as a prop.
 */
function stripTokenFromUrl(): void {
  if (typeof window === 'undefined') return;

  const url = new URL(window.location.href);
  if (!url.searchParams.has('token')) return;

  url.searchParams.delete('token');
  window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
}
