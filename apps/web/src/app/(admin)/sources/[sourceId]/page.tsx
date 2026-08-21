import { PageHeader } from '@/components/page-header';
import { SourceDetailScreen } from '@/features/sources/source-detail-screen';

/**
 * One knowledge source: what is inside it, how well it was read, and which bots may use it.
 *
 * ── THIS SERVER COMPONENT PRODUCES NO ORGANIZATION-SCOPED BYTE, AND THE ID IN THE URL IS WHAT
 *    MAKES THAT EASY TO GET WRONG ────────────────────────────────────────────────────────────────
 * `/sources/{sourceId}` has an identifier in the path, which makes it the third admin route that
 * LOOKS cacheable. It is not. Every Next cache is keyed by URL or by arguments — the Full Route
 * Cache, the Data Cache, the Router Cache, `unstable_cache`, `use cache` — and the ORGANIZATION
 * lives in the session cookie, which is in none of those keys. A URL-keyed entry here would carry
 * half a key, which is worse than none because it looks specific: two admins in two different
 * organizations resolve the same path, and a source id is a ULID rather than a secret.
 *
 * So everything rendered HERE is byte-identical for every tenant: a heading, one sentence, and a
 * client component that renders a skeleton until a browser fetch answers. THE SOURCE'S NAME IS NOT
 * IN THIS FILE and cannot be — it is the `h2` inside the client component's summary card, from the
 * fetched row, exactly as the bot's name is on `/bots/{botId}`.
 *
 * There is no `serverFetch` on this route and there cannot be: `src/lib/api/server.ts` deliberately
 * does not forward the session cookie, because a server-side fetch sends no `Referer`/`Origin`,
 * Sanctum's `fromFrontend()` classifies it third-party, and a perfectly valid cookie is ignored — a
 * 401 that cannot reproduce in devtools. Identity, and therefore the organization, is unreadable
 * from the Next server by construction.
 *
 * `(admin)/layout.tsx` pins `dynamic = 'force-dynamic'` and `revalidate = 0` for every route beneath
 * it, and ESLint bans the `'force-static'` literal, `generateStaticParams` and the `fetchCache`
 * override in this directory. Belt, not mechanism — the mechanism is that the browser is the
 * fetcher. `generateStaticParams` could not exist here anyway: enumerating the params would mean
 * listing one organization's document ids at build time.
 *
 * ── THE PARAM IS A ROUTING HINT AND NOT A SCOPE ────────────────────────────────────────────────
 * `{sourceId}` travels in the request path, where Laravel resolves it with `->scopeBindings()`
 * through `$organization->knowledgeSources()`. The organization it is scoped to comes from the
 * SESSION — `TenantContext` re-reads the membership row from PostgreSQL on every request — so a
 * foreign id, an unknown id, and an id belonging to the organization the admin just switched away
 * from all 404 at BINDING time, before any policy runs and before the row is in memory.
 * `bootstrap/app.php` renders 404 as `authorization`, which is the same class a genuine 403 carries,
 * and the three are byte-identical on the wire on purpose.
 *
 * Nothing on this page validates the segment, and nothing should: a client-side ULID shape check
 * would only turn a server refusal into a different-looking one, and would answer a question the
 * deny split exists to leave unanswered.
 *
 * ── THE SIDEBAR ALREADY HIGHLIGHTS CORRECTLY AND NOTHING HERE HAD TO CHANGE ────────────────────
 * `lib/nav.ts` gives the Sources item `matchesDescendants: true`, and `isNavItemActive` matches on
 * the `href + '/'` boundary — so this route lights the Sources row and `/sources` alone still does
 * too, without `startsWith` also claiming a hypothetical `/sourcesomething`.
 *
 * ── THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE ─────────────────────────────────────
 * Reprocessing a source, disabling it, deleting it and granting a bot access to it are mutations
 * Laravel rate-limits (`throttle:admin`), authorizes through `sources.manage` / `sources.assign` /
 * `bots.view`, refuses on a suspended organization, and writes audit rows for. An action would
 * bypass all four: a second business backend by accident. Every mutation is a browser `fetch` to
 * Laravel — see `features/sources/api.ts` and `features/sources/source-assignment-api.ts`.
 */
export default async function SourceDetailPage({
  params,
}: {
  /**
   * `params` IS A PROMISE IN NEXT 16 and awaiting it is not optional: the synchronous form was
   * removed, so reading `params.sourceId` directly is a type error rather than a deprecation
   * warning. Awaiting it also makes this route dynamic on its own, independently of the layout's
   * `force-dynamic` — which is belt rather than mechanism, exactly as that pin is.
   */
  readonly params: Promise<{ readonly sourceId: string }>;
}) {
  const { sourceId } = await params;

  return (
    <section aria-labelledby="source-detail-heading">
      <PageHeader
        title="Source"
        titleId="source-detail-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3). It
        // names no source and no organization: this is server-rendered chrome and must be
        // byte-identical for every tenant, so the document's own name is rendered by the client
        // component below, from a browser fetch, as the summary card's h2.
        description="What was read out of this document, and which bots may answer from it."
      />
      <SourceDetailScreen sourceId={sourceId} />
    </section>
  );
}
