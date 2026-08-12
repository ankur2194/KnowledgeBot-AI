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
export default function LoginPage() {
  return (
    <section aria-labelledby="login-heading" className="mx-auto max-w-sm space-y-6">
      <h1 id="login-heading" className="text-2xl font-semibold">
        Sign in
      </h1>
      <div aria-hidden className="space-y-3">
        <div className="bg-muted h-10 animate-pulse rounded-md" />
        <div className="bg-muted h-10 animate-pulse rounded-md" />
        <div className="bg-muted h-10 w-28 animate-pulse rounded-md" />
      </div>
      <p className="text-muted-foreground text-sm">The sign-in form is not implemented yet.</p>
    </section>
  );
}
