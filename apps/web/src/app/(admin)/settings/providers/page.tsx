/**
 * Provider connections for the current organization.
 *
 * ── THIS SERVER COMPONENT PRODUCES NO ORGANIZATION-SCOPED BYTE, AND THAT IS THE POINT ────────────
 * `/settings/providers` is ONE URL for every organization an administrator belongs to, because the
 * organization lives in the session cookie and is in none of Next's five cache keys — not the Full
 * Route Cache, not the Data Cache, not the Router Cache, not `unstable_cache`, not `use cache`. So
 * everything rendered here is byte-identical for every tenant: a heading, a sentence, and a client
 * component that renders nothing until a browser fetch answers. The connection list, the create form
 * and all three mutations come from `browser -> Laravel`, keyed in the one cache this app designs
 * itself — the TanStack Query key, which carries `orgId`.
 *
 * There is no `serverFetch` here and there cannot be: `src/lib/api/server.ts` deliberately does not
 * forward the session cookie, because a server-side fetch sends no `Referer`/`Origin`, Sanctum's
 * `fromFrontend()` classifies it third-party, and a perfectly valid cookie is ignored — a 401 that
 * cannot reproduce in devtools. Identity, and therefore the organization, is unreadable from the Next
 * server by construction.
 *
 * ON THIS ROUTE THE RULE HAS A SECOND EDGE. A server-rendered provider list would put `masked_key` —
 * per-organization data derived from a credential — into the RSC payload, which is a cacheable
 * artifact keyed by a URL that does not name the organization. The list is a browser fetch, so no
 * Next.js cache ever holds a byte of it, and `browserFetch` sets `cache: 'no-store'` so the BROWSER's
 * own URL-keyed HTTP cache does not either.
 *
 * `(admin)/layout.tsx` pins `dynamic = 'force-dynamic'` and `revalidate = 0` for every route beneath
 * it, and ESLint bans the `'force-static'` literal in this directory: that value does not ERROR when a
 * route reads request state, it makes the read return an EMPTY value and the Full Route Cache then
 * serves one shell to every organization. Belt, not mechanism — the mechanism is that the browser is
 * the fetcher.
 *
 * ── THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE ───────────────────────────────────────
 * Creating a connection, editing it, rotating its key and deleting it are four mutations that Laravel
 * rate-limits (`throttle:admin`, plus `throttle:credential-rotation` on the rotation), authorizes
 * through `providers.manage`, re-authenticates for (§18.3), and writes audit rows for. An action would
 * bypass all four: a second business backend by accident, and on the one surface in this console that
 * handles key material. Every mutation is a browser `fetch` to Laravel.
 *
 * ── WHY THE LINK TO THIS PAGE IS UNCONDITIONAL ──────────────────────────────────────────────────
 * §6.4 gives `providers.view` to owner, admin AND knowledge_manager — an ingestion operator has to be
 * able to see whether the organization can embed at all — while `providers.manage` is owner and admin
 * only. An analyst who follows the link from `/settings` reads the class-mapped 403 sentence instead
 * of a list. The link is not hidden for them: hiding it would require the role, the role is org-scoped,
 * and reading it in a server component is exactly the organization-scoped byte this file must not
 * produce. UI hiding is not authorization anyway — Laravel is the gate.
 */

import { PageHeader } from '@/components/page-header';
import { ProvidersScreen } from '@/features/providers/providers-screen';

export default function ProvidersPage() {
  return (
    <section aria-labelledby="providers-page-heading">
      <PageHeader
        title="Providers"
        titleId="providers-page-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3).
        description="The credentials this organization's bots use to reach their models, and which of them pays for embedding."
      />
      <ProvidersScreen />
    </section>
  );
}
