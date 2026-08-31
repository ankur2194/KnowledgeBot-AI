import { PageHeader } from '@/components/page-header';
import { DashboardScreen } from '@/features/analytics/dashboard-screen';

/**
 * Admin overview at `/` — §8.23's usage and quality dashboard.
 *
 * A server component that fetches NOTHING: it renders the route structure and one sentence, both
 * byte-identical for every organization. Every number arrives from the browser
 * (`nextjs-app-router` NN1) — `/` is ONE URL for every organization an administrator belongs to, and
 * it is the route most obviously "just a dashboard", which is exactly what makes it the most
 * tempting one to cache. Every Next cache is keyed by URL or by arguments and the organization is in
 * the session cookie, which is in none of them.
 *
 * ── WHAT WAS HERE BEFORE, AND WHAT THIS CHANGE HAD TO PRESERVE ──────────────────────────────────
 * This file used to render three placeholder tiles with a note saying the counts would arrive when
 * the dashboard query landed, and a comment stating that THE SKELETON MIRRORS THE LOADED TILE BOX
 * FOR BOX — same padding, same caption line, same `h-10` metric block — because a skeleton of a
 * different size makes the row reflow the moment the counts land, and that reads as a rendering bug
 * rather than as loading.
 *
 * The geometry is unchanged and the skeleton MOVED rather than being deleted: it is
 * `DashboardSkeleton` in `features/analytics/dashboard-screen.tsx`, built from the same `TILES`
 * table as the loaded row so the two cannot come out different heights. It has to live there
 * because it is now a state of a fetched surface rather than a placeholder for one, and the surface
 * is a client component.
 */
export default function OverviewPage() {
  return (
    <section aria-labelledby="overview-heading">
      <PageHeader
        title="Overview"
        titleId="overview-heading"
        description="Everything this organization has taught its bots, and how they are answering."
      />
      <DashboardScreen />
    </section>
  );
}
