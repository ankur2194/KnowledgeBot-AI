/**
 * Registration, INVITATION-GATED. There is no open sign-up: the invitation pins the email address,
 * so this form submits `{token, name, password, password_confirmation}` and never an `email` field —
 * a submitted address would let an invitee register under somebody else's.
 *
 * The token arrives in the QUERY STRING (`/register?token=…`), not as a path segment (decision D2).
 * That is why `PUBLIC_PATHS` in `src/lib/auth/safe-next.ts` is six EXACT paths with no prefixes: a
 * `/register/` prefix would also make `/register-evil` public, and an anchored-prefix rule is one
 * refactor away from an unanchored one.
 *
 * This route is deliberately NOT exempted from the proxy's signed-in bounce out of `/login`, and
 * also NOT bounced itself: an invited user may hold a session for a DIFFERENT account and must still
 * be able to complete the invitation.
 */

import { RegisterForm } from '@/features/auth/register-form';
// From `lib/`, NOT from `features/auth/invitation.ts` where it would sit more naturally. This file is a
// SERVER COMPONENT, so everything it imports is compiled into the RSC graph, and that module transitively
// reaches `features/auth/session.ts`, which declares the `SessionContext`. Turbopack refuses the build
// with "You're importing a module that depends on `createContext` into a React Server Component module" —
// and `web:typecheck` and `web:test` BOTH PASS while it is broken, because neither compiles that graph.
// The only thing crossing into `<RegisterForm/>` is a string.
import { singleToken } from '@/lib/auth/single-token';

/**
 * A SERVER COMPONENT THAT PASSES ONE PRIMITIVE DOWN. `searchParams` is a Promise in Next 16 and is
 * awaited here; the value that crosses into `<RegisterForm/>` is a single string, because anything a
 * server component hands a client component is serialized into the RSC payload embedded in the HTML —
 * the whole object, not the fields the UI reads.
 *
 * ── THE TOKEN IS IN THE ADDRESS BAR FOR ONE LOAD, AND THE CLIENT STRIPS IT ────────────────────────
 * A capability belongs in a POST body, never a URL (laravel-sanctum-auth NN4: access logs, `Referer`,
 * history). But the thing that delivers it is an EMAIL, and an email can only carry a URL — so it is
 * read here once and `useStripTokenFromUrl` rewrites the history entry with `history.replaceState` on
 * mount, before any Back press can put it back in the address bar. Every request this flow makes carries
 * the token in a body: preview, register and accept are all POSTs with no `{token}` segment.
 *
 * WHILE IT IS STILL IN THE URL, `next.config.ts`'s `Referrer-Policy: same-origin` on every non-`/c/`
 * path is what keeps it out of the `Referer` header of any outbound navigation, and
 * `Cache-Control: private, no-store` from the same block keeps the token-bearing document out of the
 * shared and disk caches. THAT HEADER LIST IS LOAD-BEARING FOR THIS ROUTE — do not "tidy" it.
 *
 * NO ORGANIZATION-SCOPED BYTE IS PRODUCED HERE. The heading and the wrapper are byte-identical for
 * every visitor; the organization's NAME arrives from a browser fetch inside the client component, and
 * the Next server — whose five cache keys contain no organization and no token — never sees it.
 * `force-static` would additionally make this `searchParams` read return an EMPTY value and the Full
 * Route Cache would serve one token-less shell to everybody, which is why the group's layout pins
 * `force-dynamic` and ESLint bans the other literal in this directory.
 */
export default async function RegisterPage({
  searchParams,
}: {
  readonly searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  // Exactly one string or the empty string; a repeated `?token=` is not a token. There is deliberately
  // no shape check — a wrong-length token must come back from the server as the one indistinguishable
  // refusal rather than be told locally that it is the wrong shape.
  const token = singleToken(params.token);

  return (
    <section aria-labelledby="register-heading" className="space-y-6">
      <h1 id="register-heading" className="text-2xl font-semibold">
        Accept your invitation
      </h1>
      <RegisterForm token={token} />
    </section>
  );
}
