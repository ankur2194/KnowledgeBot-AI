/**
 * Knowledge sources list. Placeholder chrome only — no fetch, no data, nothing org-scoped.
 *
 * When the table lands: polling uses the FUNCTION form of `refetchInterval` and returns false once
 * every row is terminal (a bare number never stops, and a hidden tab polling for hours is what
 * starts rejecting real requests); `placeholderData` uses the function form and compares
 * `prevQuery.queryKey[1]` to the current orgId, because keepPreviousData renders the previous
 * KEY's data and once orgId is in the key the previous key is the previous organization.
 *
 * Deletion and disable are never optimistic: they are two-phase and verified, and the browser
 * cannot know the purge succeeded.
 */
export default function SourcesPage() {
  return (
    <section aria-labelledby="sources-heading" className="space-y-6">
      <h1 id="sources-heading" className="text-2xl font-semibold">
        Sources
      </h1>
      <div aria-hidden className="space-y-2">
        {[0, 1, 2, 3, 4, 5].map((row) => (
          <div key={row} className="bg-muted h-12 animate-pulse rounded-md" />
        ))}
      </div>
      <p className="text-muted-foreground text-sm">
        Rows load in the browser. Nothing on this page is rendered by the Next.js server.
      </p>
    </section>
  );
}
