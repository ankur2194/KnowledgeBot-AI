/**
 * The organization's embedding designation, and why it can or cannot ingest a document.
 *
 * ── THIS SERVER COMPONENT PRODUCES NO ORGANIZATION-SCOPED BYTE, AND THAT IS THE POINT ────────────
 * `/settings/embedding` is ONE URL for every organization an administrator belongs to, because the
 * organization lives in the session cookie and is in none of Next's five cache keys — not the Full
 * Route Cache, not the Data Cache, not the Router Cache, not `unstable_cache`, not `use cache`. This
 * route has no dynamic segment at all, so a cache entry for it would be keyed on the path and nothing
 * else: the same shell for every tenant on the platform, by construction. So everything rendered here
 * is byte-identical for every organization — a heading, a sentence, and a client component that
 * renders nothing until a browser fetch answers. The readiness verdict, the eligible and rejected
 * candidates and the designation write all come from `browser -> Laravel`, keyed in the one cache this
 * app designs itself: the TanStack Query key, which carries `orgId`.
 *
 * There is no `serverFetch` here and there cannot be: `src/lib/api/server.ts` deliberately does not
 * forward the session cookie, because a server-side fetch sends no `Referer`/`Origin`, Sanctum's
 * `fromFrontend()` classifies it third-party, and a perfectly valid cookie is ignored — a 401 that
 * cannot reproduce in devtools. Identity, and therefore the organization, is unreadable from the Next
 * server by construction.
 *
 * ON THIS ROUTE THE RULE HAS A SECOND EDGE. The readiness verdict names connection ULIDs, provider
 * keys and vendor model ids, and its `explanation` is a paragraph composed from one organization's
 * configuration. Server-rendering it would put all of that into the RSC payload, which is a cacheable
 * artifact keyed by a URL that does not name the organization. It is a browser fetch, so no Next.js
 * cache ever holds a byte of it, and `browserFetch` sets `cache: 'no-store'` so the BROWSER's own
 * URL-keyed HTTP cache does not either.
 *
 * `(admin)/layout.tsx` pins `dynamic = 'force-dynamic'` and `revalidate = 0` for every route beneath
 * it, and ESLint bans the `'force-static'` literal in this directory: that value does not ERROR when a
 * route reads request state, it makes the read return an EMPTY value and the Full Route Cache then
 * serves one shell to every organization. Belt, not mechanism — the mechanism is that the browser is
 * the fetcher.
 *
 * ── THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE ───────────────────────────────────────
 * Setting and clearing the designation is one mutation that Laravel rate-limits (`throttle:admin`),
 * authorizes through `providers.manage`, refuses outright for a non-Active organization, writes an
 * audit row for, and — before any of that — resolves against the data plane's own rule, which is the
 * only implementation of it. An action would bypass all five: a second business backend by accident,
 * on the one setting that decides which vector space an entire corpus is indexed under. The mutation
 * is a browser `fetch` to Laravel.
 *
 * ── WHY THE LINK TO THIS PAGE IS UNCONDITIONAL ──────────────────────────────────────────────────
 * §6.4 gives `providers.view` to owner, admin AND knowledge_manager — an ingestion operator whose
 * upload is blocked has to be able to read the verdict — while `providers.manage` is owner and admin
 * only, and `EmbeddingConfigurationTest` pins exactly that split on these two endpoints. An analyst
 * who follows the link from `/settings/providers` reads the class-mapped 403 sentence instead of a
 * verdict. The link is not hidden for them: hiding it would require the role, the role is org-scoped,
 * and reading it in a server component is exactly the organization-scoped byte this file must not
 * produce. UI hiding is not authorization anyway — Laravel is the gate.
 */

import { PageHeader } from '@/components/page-header';
import { EmbeddingScreen } from '@/features/embedding/embedding-screen';

export default function EmbeddingPage() {
  return (
    <section aria-labelledby="embedding-page-heading">
      <PageHeader
        title="Embedding"
        titleId="embedding-page-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3). It
        // names no organization and no pair: this is server-rendered chrome and must be
        // byte-identical for every tenant.
        description="Which stored credential pays for embedding, and which model names the vector space this organization's documents are indexed under."
      />
      <EmbeddingScreen />
    </section>
  );
}
