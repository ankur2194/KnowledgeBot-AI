import Link from 'next/link';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { ResetPasswordForm } from '@/features/auth/reset-password-form';

/**
 * Set a new password from an emailed link. The single-use token arrives in the QUERY STRING
 * (`/reset-password?token=…&email=…`), not as a path segment (decision D2).
 *
 * THREE CONSEQUENCES OF THE TOKEN BEING IN THE URL, and all three are easy to undo by accident:
 *  - `next.config.ts` sets `Referrer-Policy: same-origin` on every path in this group, which keeps the
 *    token out of the `Referer` header of any outbound navigation. IT IS NOT DECORATION — do not
 *    "tidy" that header list; it is half of the defence and the client cannot replace it.
 *  - `safeNext` rejects any `?next=` whose pathname is one of the public `(auth)` paths, so a reset
 *    URL can never be laundered through a `Location` header into somebody's proxy logs.
 *  - The client component strips `token` from the visible URL with `history.replaceState` on mount,
 *    and on success navigates with `browserNavigation.replace('/login')` — REPLACE, not assign, so the
 *    entry carrying a single-use credential leaves the history stack instead of being pushed past.
 *
 * A SERVER COMPONENT THAT PASSES TWO PRIMITIVES DOWN. `searchParams` is a Promise in Next 16 and is
 * awaited here; what crosses into `<ResetPasswordForm/>` is two strings, because anything a server
 * component hands a client component is serialized into the RSC payload embedded in the HTML — the
 * whole object, not the fields the UI reads. Nothing is fetched here and nothing could be: the token is
 * a credential for LARAVEL, and the Next server has no business holding it, presenting it, or caching a
 * byte derived from it. `force-static` would additionally make this `searchParams` read return EMPTY
 * and the Full Route Cache would serve one token-less shell to everybody, which is why the layout pins
 * `force-dynamic` and ESLint bans the other literal in this directory.
 *
 * THE MISSING-PARAMETER BRANCH IS NOT A VALIDATION OF THE TOKEN, and must never become one. It asks
 * only "did the link carry the two values the form needs" — a client that inspected the token's shape
 * would tell its holder something the server's single, deliberately indistinguishable answer exists to
 * withhold. It exists so that the form never mounts with an unfillable field: a resolver error keyed to
 * the hidden `token` input has nowhere to render, which is the "submit into silence" loop.
 *
 * It is also the state a REFRESH lands in, by design: the token was stripped from the URL on mount and
 * a reset link is single-use, so a form that appeared to still work after a reload would be a lie.
 */
export default async function ResetPasswordPage({
  searchParams,
}: {
  readonly searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  // A repeated parameter arrives as an array and Next guarantees nothing about which one a consumer
  // "meant", so neither does this: an array is treated as no value at all.
  const token = single(params.token);
  const email = single(params.email);

  if (token === '' || email === '') {
    return (
      <section aria-labelledby="reset-heading" className="space-y-6">
        <h1 id="reset-heading" className="text-2xl font-semibold">
          Choose a new password
        </h1>
        <Alert variant="destructive">
          <AlertDescription>
            This password reset link is incomplete. Reset links can be used once, and the link is
            cleared from the address bar as soon as the page opens — so a refresh lands here too.
          </AlertDescription>
        </Alert>
        <p className="text-muted-foreground text-sm">
          <Link href="/forgot-password" className="underline">
            Request a new reset link
          </Link>
        </p>
      </section>
    );
  }

  return (
    <section aria-labelledby="reset-heading" className="space-y-6">
      <h1 id="reset-heading" className="text-2xl font-semibold">
        Choose a new password
      </h1>
      <ResetPasswordForm token={token} email={email} />
      <p className="text-muted-foreground text-sm">
        <Link href="/forgot-password" className="underline">
          Request a new reset link
        </Link>
      </p>
    </section>
  );
}

/** One search param as a string, or `''`. */
function single(value: string | string[] | undefined): string {
  return typeof value === 'string' ? value : '';
}
