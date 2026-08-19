/**
 * The model catalog of one provider connection.
 *
 * ── THIS SERVER COMPONENT PRODUCES NO ORGANIZATION-SCOPED BYTE, AND ON THIS ROUTE THAT IS EASIER
 *    TO GET WRONG THAN ON ANY OTHER ─────────────────────────────────────────────────────────────
 * `/settings/providers/{connectionId}` has an identifier IN THE URL, which makes it the first admin
 * route that looks like it could be cached. It cannot. Every Next cache is keyed by URL or by
 * arguments — the Full Route Cache, the Data Cache, the Router Cache, `unstable_cache`, `use cache` —
 * and the ORGANIZATION lives in the session cookie, which is in none of those keys. A URL-keyed cache
 * entry here would carry half a key, which is worse than none because it looks specific: two admins
 * in two different organizations resolve the same path, and a connection id is a ULID rather than a
 * secret.
 *
 * So everything rendered here is byte-identical for every tenant: a heading, a sentence, and a client
 * component that renders nothing until a browser fetch answers. The connection row, its model
 * catalogue and all four mutations come from `browser -> Laravel`, keyed in the one cache this app
 * designs itself — the TanStack Query key, which carries `orgId` AND `connectionId`.
 *
 * There is no `serverFetch` here and there cannot be: `src/lib/api/server.ts` deliberately does not
 * forward the session cookie, because a server-side fetch sends no `Referer`/`Origin`, Sanctum's
 * `fromFrontend()` classifies it third-party, and a perfectly valid cookie is ignored — a 401 that
 * cannot reproduce in devtools. Identity, and therefore the organization, is unreadable from the Next
 * server by construction.
 *
 * `(admin)/layout.tsx` pins `dynamic = 'force-dynamic'` and `revalidate = 0` for every route beneath
 * it, and ESLint bans the `'force-static'` literal in this directory: that value does not ERROR when
 * a route reads request state, it makes the read return an EMPTY value and the Full Route Cache then
 * serves one shell to every organization. Belt, not mechanism — the mechanism is that the browser is
 * the fetcher. There is no `generateStaticParams` here either, and there could not be: enumerating
 * the params would mean listing one organization's connection ids at build time.
 *
 * ── THE PARAM IS A ROUTING HINT AND NOT A SCOPE ────────────────────────────────────────────────
 * `{connectionId}` is passed to the client component and travels in the request path, where Laravel
 * resolves it with `->scopeBindings()` through `$organization->providerConnections()`. The
 * organization it is scoped to comes from the SESSION — `TenantContext` re-reads the membership row
 * from PostgreSQL on every request — so a foreign id 404s at BINDING time, before any policy runs and
 * before the row is in memory. Nothing on this page validates the segment, and nothing should: a
 * client-side shape check would only turn a server refusal into a different-looking one.
 *
 * The same is true one level down, and it is the case a cross-tenant test cannot see: a model
 * belonging to a DIFFERENT connection of the SAME organization also 404s, because the second binding
 * hop is scoped too.
 *
 * ── THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE ──────────────────────────────────────
 * Registering a model, replacing one, toggling its availability and deleting it are four mutations
 * that Laravel rate-limits (`throttle:admin`), authorizes through `providers.manage`, and writes
 * audit rows for — and the delete is refused outright while the row is the organization's designated
 * embedding model, a check the database cannot make because `embedding_model` is a bare text column
 * with no foreign key. An action would bypass all of it: a second business backend by accident. Every
 * mutation is a browser `fetch` to Laravel.
 *
 * ── WHY THE LINK TO THIS PAGE IS UNCONDITIONAL ─────────────────────────────────────────────────
 * §6.4 gives `providers.view` to owner, admin AND knowledge_manager — an ingestion operator has to be
 * able to see which models claim `embedding` — while `providers.manage` is owner and admin only. An
 * analyst who follows the link from `/settings/providers` reads the class-mapped 403 sentence instead
 * of a catalogue. The link is not hidden for them: hiding it would require the role, the role is
 * org-scoped, and reading it in a server component is exactly the organization-scoped byte this file
 * must not produce. UI hiding is not authorization anyway — Laravel is the gate.
 */

import { PageHeader } from '@/components/page-header';
import { ModelsScreen } from '@/features/models/models-screen';

/**
 * `params` IS A PROMISE IN NEXT 16 and awaiting it is not optional: the synchronous form was removed,
 * so reading `params.connectionId` directly is a type error rather than a deprecation warning.
 *
 * Awaiting it also makes this route dynamic on its own, independently of the layout's
 * `force-dynamic` — which is belt rather than mechanism, exactly as that pin is.
 */
export default async function ProviderModelsPage({
  params,
}: {
  readonly params: Promise<{ readonly connectionId: string }>;
}) {
  const { connectionId } = await params;

  return (
    <section aria-labelledby="models-page-heading">
      <PageHeader
        title="Models"
        titleId="models-page-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3). It
        // names no organization and no connection: this is server-rendered chrome and must be
        // byte-identical for every tenant, so the connection's own label is rendered by the client
        // component below, from a browser fetch.
        description="Which models this credential may be used with, what each one can do, and what it costs."
      />
      <ModelsScreen connectionId={connectionId} />
    </section>
  );
}
