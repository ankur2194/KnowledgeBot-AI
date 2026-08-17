/**
 * The sign-in route. The form itself is a client component that talks to Laravel directly:
 * `GET /sanctum/csrf-cookie` first, then the login POST with the URL-DECODED XSRF-TOKEN echoed as
 * `X-XSRF-TOKEN` — app.<domain> -> api.<domain> is same-SITE but not same-ORIGIN, so Laravel 13's
 * PreventRequestForgery falls through to token validation on every mutation.
 *
 * There is no Server Action here and there never will be: an action bypasses Laravel's rate
 * limiter, its quota accounting and its audit log, and login is the single most rate-limited
 * endpoint in the product (throttled per account AND per IP together).
 *
 * On success the app performs a FULL DOCUMENT navigation rather than a router push, for the same
 * reason logout does: the Router Cache lives in the tab and is keyed by path, so a soft navigation
 * can render payloads captured under the previous identity.
 */

import { LoginForm } from '@/features/auth/login-form';
import { safeNext } from '@/lib/auth/safe-next';

/**
 * MOVED from `(admin)/login/page.tsx` in Batch 1. The docblock above is unchanged and is the flow
 * spec; the form that implements it is Batch 3's.
 *
 * The move is the fix for a real bug rather than a tidy-up: `(admin)/layout.tsx` renders the
 * Overview/Bots/Sources/Settings nav unconditionally, so a signed-out visitor at `/login` was shown
 * four links that every one of them 307s straight back to `/login`. This group's root layout has no
 * nav at all. `(admin)/login/page.tsx` had to be DELETED in the same change — two pages resolving to
 * the same path is a build error, not a warning.
 *
 * `?next=` arrives here as a search param and is sanitized by `safeNext` before it becomes a client
 * prop; the proxy sanitizes it a second time before it becomes a `Location` header. Same function,
 * two callers.
 *
 * A SERVER COMPONENT THAT PASSES ONE PRIMITIVE DOWN. `searchParams` is a Promise in Next 16 and is
 * awaited here; the value that crosses into `<LoginForm/>` is a single sanitized string, because
 * anything a server component hands a client component is serialized into the RSC payload embedded in
 * the HTML — the whole object, not the fields the UI reads. There is nothing org-scoped on this route
 * and there is nothing to cache: `force-static` would make this `searchParams` read return an EMPTY
 * value and the Full Route Cache would then serve one shell to everybody, which is why the layout
 * pins `force-dynamic` and ESLint bans the other literal in this directory.
 */
export default async function LoginPage({
  searchParams,
}: {
  readonly searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  // `safeNext` always returns a safe path and never throws: '/' on any doubt. It rejects absolute and
  // protocol-relative URLs, backslash and control-character normalization tricks, percent-encoded
  // re-parses, the `/c/` chat namespace, and every public `(auth)` path — a `next=/login` is a
  // redirect loop and a `next=/reset-password?token=…` is a token-laundering surface.
  const next = safeNext(params.next);

  return (
    <section aria-labelledby="login-heading" className="space-y-6">
      <h1 id="login-heading" className="text-2xl font-semibold">
        Sign in
      </h1>
      <LoginForm next={next} />
    </section>
  );
}
