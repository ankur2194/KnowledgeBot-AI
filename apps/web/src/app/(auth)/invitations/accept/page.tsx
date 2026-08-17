/**
 * Preview an invitation and accept it. The token arrives in the QUERY STRING
 * (`/invitations/accept?token=…`), not as a path segment (decision D2).
 *
 * THAT DECISION IS WHY `PUBLIC_PATHS` HAS NO PREFIXES. A `[token]` segment would have forced
 * `/invitations/` into the proxy as a PREFIX, and a prefix is where the next mistake lives: drop the
 * anchoring slash and `/invitations-evil` becomes a public path; keep it and `/invitations` (not a
 * route) still is not one, so the rule needs an exception nobody remembers. One exact path, no rule.
 *
 * The preview is guest-reachable, so it must not be an enumeration oracle: a bad, expired, accepted,
 * revoked, or suspended-organization token all produce the SAME 404 with a byte-identical body, and the
 * screen renders one sentence for all five. It names no inviter and carries no organization id.
 *
 * This route is NOT bounced by the proxy in either direction — an anonymous visitor is not sent to
 * `/login` (it is public) and a visitor who looks signed in is not sent away (only `/login` is). Both
 * exemptions are load-bearing: accepting REQUIRES a session, registering requires the absence of one,
 * and the link in the email does not know which the recipient has.
 */

import { AcceptInvitation } from '@/features/auth/accept-invitation';
// From `lib/` and not from `features/auth/`: a server component's imports are compiled into the RSC
// graph, `features/auth/invitation.ts` transitively reaches the module that declares `SessionContext`, and
// Turbopack refuses that with "You're importing a module that depends on `createContext` into a React
// Server Component module". Only `pnpm web:build` catches it — typecheck and the test suite both pass.
import { singleToken } from '@/lib/auth/single-token';

/**
 * A SERVER COMPONENT THAT PASSES ONE PRIMITIVE DOWN. `searchParams` is a Promise in Next 16 and is
 * awaited here; what crosses into the client component is a single string. Anything else a server
 * component hands down is serialized into the RSC payload embedded in the HTML, whole.
 *
 * ── THE TOKEN IS IN THE ADDRESS BAR FOR ONE LOAD, AND THE CLIENT STRIPS IT ────────────────────────
 * `useStripTokenFromUrl` rewrites the history entry with `history.replaceState` on mount, and a
 * successful acceptance navigates with `browserNavigation.replace` so the entry is overwritten rather
 * than pushed past. Every request carries the token in a POST body — there is no `{token}` path segment
 * anywhere in `routes/api_auth.php`.
 *
 * WHILE IT IS STILL IN THE URL, `next.config.ts`'s `Referrer-Policy: same-origin` on every non-`/c/`
 * path keeps it out of the `Referer` header of any outbound navigation, and `Cache-Control: private,
 * no-store` from the same block keeps this document out of the shared and disk caches. THAT HEADER LIST
 * IS LOAD-BEARING FOR THIS ROUTE — do not "tidy" it.
 *
 * WHAT THE NEXT SERVER RENDERS HERE IS BYTE-IDENTICAL FOR EVERY VISITOR: a heading and a wrapper. The
 * organization's name, the invited address, the role and the answer to "are you even signed in" all
 * arrive from browser fetches, because not one of Next's five caches is keyed by the session the
 * organization lives in.
 */
export default async function AcceptInvitationPage({
  searchParams,
}: {
  readonly searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  // Exactly one string or the empty string — a repeated `?token=` is not a token — and no local shape
  // check, so a wrong-length token gets the server's one indistinguishable refusal.
  const token = singleToken(params.token);

  return (
    <section aria-labelledby="invitation-heading" className="space-y-6">
      <h1 id="invitation-heading" className="text-2xl font-semibold">
        Join the organization
      </h1>
      <AcceptInvitation token={token} />
    </section>
  );
}
