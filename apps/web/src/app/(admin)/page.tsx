/**
 * Admin overview at `/`. A server component that fetches NOTHING: it renders the route structure
 * and a loading skeleton, both byte-identical for every organization. The numbers arrive from the
 * browser (nextjs-app-router NN1).
 */
export default function OverviewPage() {
  return (
    <section aria-labelledby="overview-heading" className="space-y-6">
      <h1 id="overview-heading" className="text-2xl font-semibold">
        Overview
      </h1>
      <div className="grid gap-4 sm:grid-cols-3">
        {['Bots', 'Sources', 'Conversations'].map((label) => (
          <div key={label} className="rounded-lg border p-4">
            <p className="text-muted-foreground text-sm">{label}</p>
            <div aria-hidden className="bg-muted mt-3 h-7 w-16 animate-pulse rounded-sm" />
          </div>
        ))}
      </div>
      <p className="text-muted-foreground text-sm">
        Counts load in the browser once the dashboard query lands.
      </p>
    </section>
  );
}
