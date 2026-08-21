import { PageHeader } from '@/components/page-header';
import { AddFilesAction } from '@/features/sources/add-files-action';
import { SourcesScreen } from '@/features/sources/sources-screen';

/**
 * Knowledge sources for the current organization.
 *
 * ── THIS SERVER COMPONENT PRODUCES NO ORGANIZATION-SCOPED BYTE, AND THAT IS THE POINT ───────────
 * `/sources` is ONE URL for every organization an administrator belongs to, because the organization
 * lives in the session cookie and is in none of Next's five cache keys — not the Full Route Cache, not
 * the Data Cache, not the Router Cache, not `unstable_cache`, not `use cache`. So everything rendered
 * HERE is byte-identical for every tenant: a heading, one sentence, and a client component that
 * renders a skeleton until a browser fetch answers. There is no `serverFetch` on this route and there
 * cannot be — `src/lib/api/server.ts` deliberately does not forward the session cookie, so identity,
 * and therefore the organization, is unreadable from the Next server by construction.
 *
 * `(admin)/layout.tsx` pins `dynamic = 'force-dynamic'` and `revalidate = 0` for every route beneath
 * it, and ESLint bans the `'force-static'` literal in this directory: that value does not ERROR when a
 * route reads request state, it makes the read return an EMPTY value and the Full Route Cache then
 * serves one shell to every organization. Belt, not mechanism — the mechanism is that the browser is
 * the fetcher.
 *
 * ── WHAT THE CLIENT COMPONENT BELOW IS REQUIRED TO DO, AND WHERE EACH PART LIVES ────────────────
 * The table is SERVER-DRIVEN: `manualSorting` + `manualPagination` + `rowCount` from the envelope
 * (`components/server-data-table.tsx`), `pageIndex` reset to 0 in the SAME state update as any sort or
 * filter change (`lib/table/use-table-params.ts`), and every server-visible sort, filter and page
 * value inside the query key after `['org', orgId, 'sources', …]` (`features/sources/sources-screen.tsx`).
 *
 * THE POLL IS THIS SCREEN'S DISTINGUISHING FEATURE and the reason it needed more care than the bot
 * list: a source walks fifteen lifecycle states, most of them transient, so the list has to move on
 * its own — and `refetchInterval` is therefore a FUNCTION returning `false` once no row on the page is
 * going anywhere (`features/sources/api.ts` → `sourcePollInterval`). A bare number never stops, and a
 * hidden tab polling for hours is what starts rejecting real requests at the admin limiter.
 *
 * The four states ship WITH the success state — and first-run empty is a different COMPONENT from
 * filtered empty (`components/states.tsx`), because offering "upload your first document" to somebody
 * whose search matched nothing is the bug that split exists to prevent. Forbidden is real here rather
 * than theoretical: an ANALYST does not hold `sources.view` and the sidebar offers this route to them
 * anyway.
 *
 * ── THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE ──────────────────────────────────────
 * Disable, enable, reprocess and delete are mutations Laravel rate-limits (`throttle:admin`),
 * authorizes through `sources.manage`, refuses on a suspended organization, and writes audit rows for.
 * An action would bypass all four: a second business backend by accident. Every mutation is a browser
 * `fetch` to Laravel, and none of them is optimistic — deletion is two-phase and verified, and a
 * lifecycle transition is not computable in a browser.
 *
 * ── THE PAGE HEADER'S ACTION CLUSTER LANDED, AND IT IS A CLIENT COMPONENT FOR A REASON ─────────
 * This paragraph used to read "no action cluster yet": the upload screen was a later batch's, and a
 * `--primary` button that goes nowhere is worse than no button. `/sources/upload` exists now, so the
 * control is here — and in the first-run empty state, which took it in the same change, with names
 * that are not substrings of one another ("Add files" / "Upload files" / "Upload N files").
 *
 * It is `<AddFilesAction>` rather than a `<Link>` written inline because `sources.manage` is withheld
 * from ANALYST and a control a user may not use is HIDDEN rather than disabled (states.md). The role
 * is per-organization and lives in the session, which only the browser holds — this server component
 * cannot read it, by construction — so the decision is made in a client component and this file stays
 * byte-identical for every tenant.
 */
export default function SourcesPage() {
  return (
    <section aria-labelledby="sources-heading">
      <PageHeader
        title="Sources"
        titleId="sources-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3).
        description="Documents, spreadsheets, presentations and crawled sites your bots can answer from."
        actions={<AddFilesAction />}
      />

      <SourcesScreen />
    </section>
  );
}
