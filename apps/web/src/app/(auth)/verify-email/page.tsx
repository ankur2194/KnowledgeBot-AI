import { VerifyEmailNotice } from '@/features/auth/verify-email-notice';

/**
 * Email verification — the link target AND the notice screen, on one route.
 *
 * It lives in `(auth)` but is reached from both sides of the session boundary, which is why the
 * proxy's signed-in bounce fires on `/login` only:
 *
 *  - `/verify-email?token=…` is opened from an EMAIL, very often in a different browser from the one
 *    that registered, so it must render with no session at all. `POST /api/v1/auth/email/verify` is a
 *    guest route for exactly that reason and nothing here is gated on `useSession()`.
 *  - `/verify-email` with no token is the NOTICE case, reached from the unverified banner in
 *    `(admin)/layout.tsx` by a user who IS signed in. Bouncing them anywhere would loop them back
 *    through the banner that sent them.
 *
 * ── THE TOKEN ARRIVES AS `?token=`, AND THE PAGE HANDS IT OVER FOR ONE PAGE LOAD ────────────────
 * An email can only carry a URL, so the token is in the address bar on arrival; it must not stay
 * there. `VerifyEmailNotice` rewrites the URL with `history.replaceState` the moment it has read the
 * value, so the token is not in session history and Back cannot restore it.
 *
 * THE OTHER HALF OF THAT DEFENCE IS A HEADER, AND IT IS ALREADY SET: `next.config.ts`'s ADMIN_PATHS
 * pattern (`/((?!c/|_next/).*)`) covers this route, so the response carries
 * `Referrer-Policy: same-origin` — which is what keeps `?token=…` out of the `Referer` of any
 * outbound request this page makes. NOBODY MAY "TIDY" THAT HEADER LIST: dropping one line there
 * leaks every confirmation link to the first third-party asset a page reaches, and no test in this
 * repo would go red.
 *
 * ── A SERVER COMPONENT THAT PASSES ONE PRIMITIVE DOWN ───────────────────────────────────────────
 * `searchParams` is a Promise in Next 16 and is awaited here; what crosses into the client component
 * is a single `string | null`. Anything a server component hands a client component is serialized
 * into the RSC payload embedded in the HTML — the whole object, not the fields the UI reads — so the
 * narrowing is the point, not the style. `force-static` would make this `searchParams` read return an
 * EMPTY value and the Full Route Cache would then serve one token-less shell to everybody; the
 * layout pins `force-dynamic` and ESLint bans the other literal in this directory.
 *
 * There is no Server Action here and there never will be: an action bypasses Laravel's rate limiter,
 * its quota accounting and its audit log, and both of these endpoints are throttled.
 */
export default async function VerifyEmailPage({
  searchParams,
}: {
  readonly searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;

  return (
    <section aria-labelledby="verify-heading">
      <VerifyEmailNotice token={readToken(params.token)} />
    </section>
  );
}

/**
 * `?token=` -> a single trimmed string, or `null` for the notice case.
 *
 * Three narrowings, and every one of them turns a hostile shape into the notice screen rather than
 * into a request:
 *
 *  - NOT A SINGLE STRING -> null. Next hands back `string[]` for a repeated parameter, so
 *    `?token=a&token=b` must not be read as `'a'`; and an array reaching a `body: {token}` would be
 *    serialized as one, which is a 422 at best.
 *  - LONGER THAN THE CAP -> null. The token is a fixed-width random; a megabyte of query string is
 *    not a typo, and there is no reason to spend a throttled POST proving it.
 *  - EMPTY AFTER TRIM -> null. `?token=` with nothing after it is a link the mail client mangled,
 *    and the notice screen with a resend button is the useful answer to that.
 *
 * NOTHING HERE VALIDATES THE TOKEN'S SHAPE beyond that. The server is the only authority on whether
 * a token is real, and a client-side format check would be a second rule to drift — it would also
 * let a caller distinguish "malformed" from "rejected", which is precisely the distinction the single
 * 404 exists to refuse.
 */
function readToken(raw: string | string[] | undefined): string | null {
  if (typeof raw !== 'string') return null;
  if (raw.length > 512) return null;

  const token = raw.trim();
  return token.length === 0 ? null : token;
}
