'use client';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

import { useSession } from './session-context';

/**
 * THE ONLY CHANNEL THROUGH WHICH AN UNVERIFIED USER CAN LEARN WHY THE CONSOLE IS REFUSING THEM.
 *
 * That is not a description of a nice-to-have banner; it is a consequence of two decisions on the
 * Laravel side, and it is why this component may not be dropped as chrome:
 *
 *  1. Laravel's `verified` middleware guards every org-scoped route. An unverified user's actions
 *     therefore fail with a 403 rather than with anything shaped like an explanation.
 *  2. The render closure rewrites EVERY `authorization` message to one constant string. So the error
 *     envelope literally cannot distinguish "your email address is unverified" from "your role does
 *     not permit this": both arrive as an identical 403 with identical bytes, and `applyAuthError`
 *     correctly renders the identical class-mapped sentence for both. There is nothing in the
 *     envelope for a client to branch on, and inventing something to branch on would be inventing a
 *     class name to fill a slot.
 *
 * `GET /api/v1/me`'s `user.email_verified` is the ONE signal that separates the two cases — which is
 * exactly why `verified` is deliberately NOT applied to `/me`. Take this banner away and an
 * unverified user sees a generic permission error on every action, with no way whatsoever to discover
 * the cause: not in the response, not in the copy, not in support's logs, because from the server's
 * point of view nothing went wrong.
 *
 * ── IT DOES NOT REDIRECT, AND MUST NOT BE MADE TO ────────────────────────────────────────────────
 * A force-redirect to `/verify-email` was the obvious alternative and it is wrong twice over. The
 * product intent is a PERSISTENT BANNER plus a reachable page, with Laravel's middleware doing the
 * actual gating — a client-side redirect would be a second, weaker gate in the layer that is never
 * the authorization gate. And it would TRAP the user: `GET /api/v1/me` and the resend endpoint have
 * to stay reachable, and a redirect loop over the only screen that can call resend leaves them with
 * no way out and nothing to read.
 *
 * ── WHY IT RENDERS FOR EXACTLY ONE OF THE FOUR SESSION STATES ───────────────────────────────────
 * `useSession()` is a four-member discriminated union, and three of the four must render NOTHING:
 *
 *  - `loading`      — the answer has not arrived. A banner that flashes on every page load and then
 *                     vanishes for a verified user is worse than a banner that arrives late.
 *  - `anonymous`    — Laravel said `authentication`; the provider is already bouncing to `/login`.
 *  - `unavailable`  — a 502, a throttle or an `internal_dependency`. We DO NOT KNOW whether this user
 *                     is verified, and this is the whole reason `unavailable` exists as a state
 *                     instead of collapsing into `anonymous`. Rendering "confirm your email" on a
 *                     server fault tells a verified admin something false about their own account and
 *                     sends them off to resend a mail they do not need. `MembershipNotice` already
 *                     renders the class-mapped sentence for this state.
 *  - `authenticated`— render if and only if `email_verified === false`.
 *
 * The `=== false` is written out rather than `!user.email_verified` on purpose: the field is a
 * BOOLEAN on the wire (a deliberate narrowing of `users.email_verified_at`, so no UI can render the
 * date), and an explicit comparison is a typecheck failure the day it becomes a nullable timestamp
 * instead of a banner that shows for every user whose value is `null`.
 *
 * ── IT IS NOT AN ORG-SCOPED BYTE THE NEXT SERVER PRODUCED ────────────────────────────────────────
 * This renders in the browser from a browser fetch. The layout's RSC payload carries none of it, so
 * none of Next's five caches — every one keyed by URL or arguments, none by the organization — can
 * hold it. The banner is also not org-scoped in the first place: verification is a property of the
 * USER, and it follows them across every organization they switch into.
 */
export function UnverifiedBanner() {
  const session = useSession();

  if (session.status !== 'authenticated') return null;
  if (session.user.email_verified !== false) return null;

  return (
    // role="alert" (the <Alert> default) rather than a polite status, and this is the one place that
    // is the right call: the banner is the explanation for refusals the user is about to run into,
    // and a screen-reader user who never hears it has no other route to the information.
    <Alert className="mx-auto mt-4 max-w-6xl">
      <AlertTitle>Confirm your email address to finish setting up</AlertTitle>
      <AlertDescription>
        {/*
          THE SECOND SENTENCE IS THE LOAD-BEARING ONE. Without it a user reads "confirm your email"
          as housekeeping, hits a permission error ten seconds later, and has no reason to connect
          the two — because the error cannot mention it and support cannot see it.
        */}
        <p>
          Most actions in this console are unavailable until the address on your account is
          confirmed. When one is refused you will see a general permission message: it cannot name
          this as the reason, so this banner is the only place it is said.
        </p>
        {/* A PLAIN ANCHOR, not next/link. `/verify-email` lives under a DIFFERENT ROOT LAYOUT from
            `(admin)`, which Next serves as a full document load regardless of how the navigation is
            expressed — so a router push would buy nothing and would leave this tab's Router Cache
            holding admin payloads captured before the address was confirmed. */}
        <a href="/verify-email" className="underline">
          Confirm your email address
        </a>
      </AlertDescription>
    </Alert>
  );
}
