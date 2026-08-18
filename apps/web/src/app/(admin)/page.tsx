import { BotIcon, LibraryIcon, MessagesSquareIcon } from 'lucide-react';

import { PageHeader } from '@/components/page-header';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Admin overview at `/`. A server component that fetches NOTHING: it renders the route structure
 * and a loading skeleton, both byte-identical for every organization. The numbers arrive from the
 * browser (nextjs-app-router NN1).
 *
 * THE SKELETON MIRRORS THE LOADED TILE BOX FOR BOX — same padding, same caption line, same metric
 * height — because a skeleton of a different size makes the row reflow the moment the counts land,
 * and that reads as a rendering bug rather than as loading. The tiles become `<StatTile>` with a
 * declared `higherIsBetter` when the dashboard query lands; the geometry does not change.
 */
export default function OverviewPage() {
  const TILES = [
    { label: 'Bots', glyph: BotIcon },
    { label: 'Sources', glyph: LibraryIcon },
    { label: 'Conversations', glyph: MessagesSquareIcon },
  ];

  return (
    <section aria-labelledby="overview-heading">
      <PageHeader
        title="Overview"
        titleId="overview-heading"
        description="Everything this organization has taught its bots, and how they are answering."
      />

      {/* One column on mobile, two at tablet, four at desktop — a KPI row does not squeeze. */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 xl:gap-5">
        {TILES.map(({ label, glyph: Glyph }) => (
          <div key={label} className="flex flex-col gap-2 rounded-xl bg-card p-card-pad-md shadow-sm">
            <div className="flex items-start justify-between gap-2">
              <p className="truncate text-caption text-muted-foreground uppercase">{label}</p>
              <Glyph aria-hidden className="size-5 shrink-0 text-muted-foreground" />
            </div>
            <div aria-busy="true">
              <Skeleton className="h-10 w-20" />
            </div>
          </div>
        ))}
      </div>

      <div className="mt-8">
        <Card>
          <CardHeader>
            <CardTitle as="h2">Recent activity</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-base text-muted-foreground">
              Counts and recent runs load in the browser once the dashboard query lands. Nothing on
              this page is rendered by the Next.js server.
            </p>
          </CardContent>
        </Card>
      </div>
    </section>
  );
}
