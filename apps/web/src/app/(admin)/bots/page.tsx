/**
 * Bots list. Placeholder chrome only — no fetch, no data, nothing org-scoped.
 *
 * When the table lands it is SERVER-DRIVEN: `manualSorting` + `manualPagination` + `rowCount` from
 * the envelope, `pageIndex` reset to 0 in the SAME state update as any sort or filter change
 * (under manual mode the table will never do it), and every server-visible sort, filter and page
 * value inside the query key after `['org', orgId, 'bots', …]`.
 */
export default function BotsPage() {
  return (
    <section aria-labelledby="bots-heading" className="space-y-6">
      <h1 id="bots-heading" className="text-2xl font-semibold">
        Bots
      </h1>
      <div aria-hidden className="space-y-2">
        {[0, 1, 2, 3, 4].map((row) => (
          <div key={row} className="bg-muted h-12 animate-pulse rounded-md" />
        ))}
      </div>
      <p className="text-muted-foreground text-sm">
        Rows load in the browser. Nothing on this page is rendered by the Next.js server.
      </p>
    </section>
  );
}
