/**
 * Organization settings. Placeholder chrome only — no fetch, no data, nothing org-scoped.
 *
 * The organization switcher lands here, and it is a five-step sequence, in this order: navigate to
 * a neutral shell first (a mounted data view renders the instant its cache has anything); await
 * `queryClient.cancelQueries()`; await the switch mutation (`retry: false` — a replayed switch
 * races itself); REPLACE the QueryClient via `useResetQueryClient()`; then `router.refresh()` to
 * drop the Router Cache. Step 4 is the enforcement; the org-prefixed query key is the invariant.
 *
 * Provider credentials are displayed as `masked_key` TEXT outside the form. A credential field is
 * optional-means-unchanged and is never seeded into defaultValues — prefilling it submits
 * "sk-…4a91" as the new key and every provider call then fails `provider_auth`.
 */
export default function SettingsPage() {
  return (
    <section aria-labelledby="settings-heading" className="space-y-6">
      <h1 id="settings-heading" className="text-2xl font-semibold">
        Settings
      </h1>
      <div aria-hidden className="space-y-3">
        <div className="bg-muted h-10 animate-pulse rounded-md" />
        <div className="bg-muted h-32 animate-pulse rounded-md" />
      </div>
      <p className="text-muted-foreground text-sm">Settings load in the browser.</p>
    </section>
  );
}
