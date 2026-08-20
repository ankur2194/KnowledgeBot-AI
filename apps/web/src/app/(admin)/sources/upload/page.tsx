import { PageHeader } from '@/components/page-header';
import { UploadScreen } from '@/features/sources/upload-screen';

/**
 * Upload files as knowledge sources for the current organization.
 *
 * ── THIS SERVER COMPONENT PRODUCES NO ORGANIZATION-SCOPED BYTE ─────────────────────────────────
 * `/sources/upload` is ONE URL for every organization an administrator belongs to, because the
 * organization lives in the session cookie and is in none of Next's five cache keys — not the Full
 * Route Cache, not the Data Cache, not the Router Cache, not `unstable_cache`, not `use cache`. So
 * everything rendered HERE is byte-identical for every tenant: a heading, one sentence, and a client
 * component that renders a skeleton until a browser fetch answers with the organization's own
 * ceilings. There is no `serverFetch` on this route and there cannot be — `src/lib/api/server.ts`
 * deliberately does not forward the session cookie, so identity, and therefore the organization, is
 * unreadable from the Next server by construction.
 *
 * `(admin)/layout.tsx` pins `dynamic = 'force-dynamic'` and `revalidate = 0` for every route beneath
 * it, and ESLint bans the `'force-static'` literal in this directory: that value does not ERROR when
 * a route reads request state, it makes the read return an EMPTY value and the Full Route Cache then
 * serves one shell to every organization. Belt, not mechanism — the mechanism is that the browser is
 * the fetcher.
 *
 * ── THERE IS NO SERVER ACTION HERE, AND AN UPLOAD IS THE PLACE IT WOULD BE MOST TEMPTING ───────
 * A multipart Server Action is the shape the framework advertises for exactly this screen, and it is
 * the one this app may not use: it would post to the Next server, which is off the `application`
 * network and holds no session for Laravel — so it would be a second business backend, bypassing the
 * rate limiter, the `sources.manage` policy, the suspended-organization refusal, the quota
 * accounting and the audit row. Every byte goes browser -> Laravel, over an `XMLHttpRequest`, because
 * `fetch()` exposes no upload progress and per-file progress is the point of the screen.
 *
 * ── THE PAGE HEADER'S ACTION IS A LINK BACK, NOT A SECOND UPLOAD CONTROL ───────────────────────
 * The primary action on this page is the submit button inside the form, where the thing it acts on
 * is. Rule 3 of the design language allows one loud accent per screen; a `--primary` header button
 * beside a `--primary` submit is two things competing to be the action.
 */
export default function UploadSourcesPage() {
  return (
    <section aria-labelledby="upload-sources-heading">
      <PageHeader
        title="Add files"
        titleId="upload-sources-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3).
        description="Upload documents, spreadsheets, presentations and images for your bots to answer from."
      />

      <UploadScreen />
    </section>
  );
}
