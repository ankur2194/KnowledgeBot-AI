'use client';

import type { AnalyticsResource, Role } from '@kb/contracts';
import { useQuery } from '@tanstack/react-query';
import {
  ActivityIcon,
  DatabaseIcon,
  GaugeIcon,
  MessagesSquareIcon,
  SearchXIcon,
  ShuffleIcon,
  TriangleAlertIcon,
  UsersIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { CHART_SERIES, ProportionBar } from '@/components/proportion-bar';
import { ErrorState, ForbiddenState } from '@/components/states';
import { StatTile } from '@/components/stat-tile';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import { KbError } from '@kb/contracts';
import {
  ANALYTICS_VIEW_ROLE,
  EM_DASH,
  canViewAnalytics,
  fetchAnalytics,
  formatBytes,
  formatCount,
  formatDuration,
  formatRate,
  formatWindow,
} from '@/features/analytics/api';

/**
 * `/` — the organization's usage and quality dashboard (§8.23).
 *
 * ── THE GEOMETRY IS THE SAME AS THE SKELETON IT REPLACED, BOX FOR BOX ───────────────────────────
 * `(admin)/page.tsx` used to render three placeholder tiles: `grid gap-4 sm:grid-cols-2
 * xl:grid-cols-4 xl:gap-5`, each `flex flex-col gap-2 rounded-xl bg-card p-card-pad-md shadow-sm`
 * with a caption row and an `h-10` block where the metric goes. `<StatTile>` is that box exactly —
 * same wrapper, same caption row, and `--text-metric` is 2.25rem/2.5rem, which is `h-10`. So the
 * loading state below reuses the identical wrapper rather than a shorter one, and the row does not
 * reflow when the numbers land. That was the point of the skeleton mirroring the tile in the first
 * place, and it is the one thing this change must not undo.
 *
 * ── THE ORGANIZATION IS IN THE SESSION AND IN NONE OF NEXT'S CACHE KEYS ─────────────────────────
 * `/` is ONE URL for every organization an administrator belongs to, and it is the route most
 * obviously "just a dashboard" — which is what makes it the most tempting one to cache. Not one byte
 * of these numbers is produced by the Next server. They arrive from a browser fetch keyed in the one
 * cache this app designs itself:
 *
 *     ['org', orgId, 'analytics', { bot_id?, from?, until? }]
 *
 * ── ON AN ORGANIZATION SWITCH THIS SCREEN IS A HARD RESET, NOT A REFETCH ────────────────────────
 * `useResetQueryClient()` REPLACES the QueryClient and the session re-enters `loading`, so this
 * subtree unmounts and the query goes with it. The `orgId` prefix is what still holds if a future
 * refactor skips that step, and `placeholderData` is the one place that discipline could be undone
 * silently — which is why this screen deliberately does not use it. There is no paging here, so
 * there is nothing for it to smooth, and a dashboard that briefly renders the PREVIOUS
 * organization's totals is a leak wearing a KPI tile.
 *
 * ── NO POLLING ──────────────────────────────────────────────────────────────────────────────────
 * These are windowed aggregates over PostgreSQL; the window is a day or longer by default and the
 * numbers move on the order of minutes. A `refetchInterval` here is twenty admin tabs turning one
 * screen into permanent traffic against `throttle:admin`, each request paying a session lookup, a
 * membership re-check and eleven aggregates. The 30 s `staleTime` from `lib/query/client.ts` is what
 * this screen wants, and a tab returning to focus deliberately does not refetch either.
 *
 * ── FOUR STATES, SHIPPED WITH THE SUCCESS STATE ─────────────────────────────────────────────────
 * Loading (tile-shaped skeletons at the loaded geometry), EMPTY (a window in which nothing happened
 * — a real state, not a first-run one, so it is a sentence beside real zeroes rather than an
 * onboarding hero), error, and FORBIDDEN — which this screen genuinely needs: `analytics.view` is
 * withheld from the KNOWLEDGE MANAGER, and the sidebar offers `/` to everybody.
 */
export function DashboardScreen() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    return <DashboardSkeleton />;
  }

  if (session.status !== 'authenticated') {
    // `anonymous` and `unavailable`, and NOTHING is rendered for either — `<SessionProvider>` above
    // owns both: it bounces to `/login` for `anonymous` and renders the class-mapped "your account
    // could not be loaded" panel for `unavailable`.
    return null;
  }

  if (orgId === null) {
    // Reachable BY DESIGN: login succeeds with `current_organization_id: null` for a user whose
    // memberships are all `invited` or `suspended`. `useOrgKey()` would THROW here, because a key
    // built from `undefined` is ONE shared namespace for every org-less state on the platform — so
    // the gate is a mount condition rather than an `enabled` flag, and the throw is unreachable.
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          These numbers belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  const membership = session.organizations.find((organization) => organization.id === orgId);

  return (
    <DashboardForOrganization
      orgId={orgId}
      // The role the VIEWER holds HERE, read from the membership list the server itself handed us,
      // rather than from a global "am I an admin" flag: a person can be an owner of one organization
      // and a knowledge manager in the next.
      viewerRole={membership?.role ?? null}
      organizationName={membership?.name}
    />
  );
}

function DashboardForOrganization({
  orgId,
  viewerRole,
  organizationName,
}: {
  readonly orgId: string;
  readonly viewerRole: Role | null;
  readonly organizationName?: string;
}) {
  const orgKeyFor = useOrgKey();

  /**
   * NO WINDOW PARAMETERS ARE SENT, AND THE EMPTY OBJECT IS STILL IN THE KEY.
   *
   * `from` and `until` both have server-side defaults, so the honest first render asks for the
   * server's window and LABELS ITSELF FROM THE ECHO — `data.window`, never from what this client
   * asked for. The empty object is in the key anyway so that the day a date filter lands, the key
   * shape does not change and a stale entry cannot answer a narrower question.
   */
  const params: Readonly<Record<string, string>> = {};

  const analytics = useQuery({
    queryKey: orgKeyFor('analytics', params),
    // `signal` forwarded: `queryClient.cancelQueries()` is a no-op against a queryFn that drops it,
    // and cancelling in-flight reads is step 2 of both logout and the organization switch.
    queryFn: ({ signal }) => fetchAnalytics(orgId, params, signal),
  });

  const forbidden =
    analytics.error instanceof KbError && analytics.error.error_class === 'authorization';

  if (forbidden && !canViewAnalytics(viewerRole)) {
    /**
     * NAMED ONLY FOR THE VIEWER WHO IS ACTUALLY MISSING THE ROLE.
     *
     * An `authorization` failure has three indistinguishable causes on the wire — the deny split
     * makes 403, a foreign id and an unknown id byte-identical — so naming a role unconditionally
     * would tell an owner with a stale organization id to go and ask for a promotion. But
     * `analytics.view` is genuinely withheld from the KNOWLEDGE MANAGER while the sidebar offers `/`
     * to everybody, so an ingestion operator landing here is the common case and the role really is
     * the reason. Every other viewer gets the class-mapped sentence, which is correct for all three
     * of their causes.
     */
    return <ForbiddenState requiredRole={ANALYTICS_VIEW_ROLE} organizationName={organizationName} />;
  }

  if (analytics.error !== null && analytics.data === undefined) {
    return (
      <ErrorState
        error={analytics.error}
        title="This dashboard could not be loaded"
        onRetry={() => void analytics.refetch()}
      />
    );
  }

  if (analytics.data === undefined) {
    return <DashboardSkeleton />;
  }

  return (
    <DashboardTiles
      data={analytics.data}
      // A refetch that failed with correct numbers still on screen is NOT the error state — it is a
      // note beside them. Blanking a dashboard because a background request blipped throws away what
      // the reader was looking at over a request they did not make.
      refetchError={analytics.error}
      onRetry={() => void analytics.refetch()}
    />
  );
}

/** The tile row, declared as data so the loading state and the loaded state cannot drift in COUNT —
 *  the skeleton renders one block per entry here, which is what keeps the grid the same height. */
interface TileSpec {
  readonly caption: string;
  readonly glyph: LucideIcon;
  readonly value: (data: AnalyticsResource) => string;
  /**
   * REQUIRED AND NEVER DEFAULTED. "Up" is not "good": latency, error rate, fallback rate, refusals
   * and storage consumed are all WORSE when they rise, and a default of `true` silently paints half
   * this page's bad news green. `<StatTile>` makes it required for exactly that reason, and this
   * table is where each decision is written down.
   */
  readonly higherIsBetter: boolean;
}

const TILES: readonly TileSpec[] = [
  {
    caption: 'Conversations',
    glyph: MessagesSquareIcon,
    value: (data) => formatCount(data.conversations),
    // Threads STARTED in the window. More conversations is more of the product being used.
    higherIsBetter: true,
  },
  {
    caption: 'Participants',
    glyph: UsersIcon,
    value: (data) => formatCount(data.unique_sessions),
    higherIsBetter: true,
  },
  {
    caption: 'Median answer time',
    glyph: GaugeIcon,
    value: (data) => formatDuration(data.latency.total_p50_ms),
    // Slower is worse, and this is the tile a `higherIsBetter` default would get wrong first.
    higherIsBetter: false,
  },
  {
    caption: '95th pct first token',
    glyph: ActivityIcon,
    value: (data) => formatDuration(data.latency.first_token_p95_ms),
    higherIsBetter: false,
  },
  {
    caption: 'Provider error rate',
    glyph: TriangleAlertIcon,
    value: (data) => formatRate(data.provider_calls.error_rate),
    higherIsBetter: false,
  },
  {
    caption: 'Fallback rate',
    glyph: ShuffleIcon,
    // MAY EXCEED 100%: a turn that fell back twice writes two attempts, and the server deliberately
    // does not clamp it because clamping hides exactly the configuration worth looking at.
    value: (data) => formatRate(data.provider_calls.fallback_rate),
    higherIsBetter: false,
  },
  {
    caption: 'Answered from no evidence',
    glyph: SearchXIcon,
    value: (data) => formatCount(data.insufficient_evidence_answers),
    /**
     * FALSE, AND THIS IS THE ONE THAT NEEDS ITS REASONING WRITTEN DOWN.
     *
     * A refusal is CORRECT BEHAVIOUR — a grounded system declining to answer is the feature — so
     * this tile is not a health metric and must not be read as one. But for the reader who asked the
     * question, more refusals is a worse product, and the action it implies is "add a source": that
     * is a statement about the corpus, not about an outage. `false` is what makes a rise render as a
     * problem the operator can act on; the copy is what keeps it from reading as a fault.
     */
    higherIsBetter: false,
  },
  {
    caption: 'Storage used',
    glyph: DatabaseIcon,
    // A LEVEL, not a flow, so it is NOT windowed — the same ledger figure the quota gate refuses
    // against, which is why this page and the quota screen cannot disagree about it.
    value: (data) => formatBytes(data.storage_bytes_used),
    higherIsBetter: false,
  },
];

function DashboardTiles({
  data,
  refetchError,
  onRetry,
}: {
  readonly data: AnalyticsResource;
  readonly refetchError: unknown;
  readonly onRetry: () => void;
}) {
  // An EMPTY WINDOW is a real state and not a first-run one: nothing was asked of any bot in the
  // period. It gets a sentence beside real zeroes rather than an onboarding hero, because the
  // numbers are correct and the reader's next action is to widen the window or wait.
  const quiet = data.conversations === 0 && data.messages === 0;

  return (
    <div className="flex flex-col gap-8">
      {refetchError === null ? null : (
        <ErrorState
          title="These numbers could not be refreshed"
          error={refetchError}
          onRetry={onRetry}
        />
      )}

      <p className="text-base text-muted-foreground">
        {/* THE RESOLVED WINDOW, ECHOED BY THE SERVER — never what this client asked for. `from` and
            `until` both have server-side defaults, so labelling the page from the request would put
            a date range on screen that the numbers may not be for. */}
        {formatWindow(data.window.from, data.window.until)}
        {data.window.bot_id === null ? ' · all bots' : ' · one bot'}
      </p>

      {quiet ? (
        <Alert variant="info">
          <AlertTitle>Nothing was asked in this period</AlertTitle>
          <AlertDescription>
            Every figure below is a real zero rather than a missing number. Publish a bot, or widen
            the window, and they will fill in.
          </AlertDescription>
        </Alert>
      ) : null}

      {/* One column on mobile, two at tablet, four at desktop — a KPI row does not squeeze. The
          identical grid the placeholder used. */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 xl:gap-5">
        {TILES.map((tile) => (
          <StatTile
            key={tile.caption}
            caption={tile.caption}
            value={tile.value(data)}
            glyph={tile.glyph}
            higherIsBetter={tile.higherIsBetter}
            // NO DELTA. This endpoint publishes ONE window and no comparison period, so there is
            // nothing to compare against — and a tile with no comparison renders no delta rather
            // than "+0%", which would be a claim that nothing changed.
          />
        ))}
      </div>

      <section aria-labelledby="dashboard-quality" className="flex flex-col gap-4">
        <h2 id="dashboard-quality" className="text-h2">
          Quality and reliability
        </h2>
        <div className="grid gap-4 lg:grid-cols-2 xl:gap-5">
          <Card>
            <CardHeader>
              <CardTitle as="h3">Reader feedback</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              {/* TWO COUNTS AND NOT A RATIO: 1/0 and 40000/0 are both "100%" and only one of them
                  is evidence. The bar shows the split; the legend is the data table. */}
              <ProportionBar
                caption="Thumbs"
                empty="Nobody has rated an answer in this period."
                segments={[
                  {
                    label: 'Helpful',
                    value: data.feedback.positive,
                    ...(CHART_SERIES[0] ?? { fill: '', swatch: '' }),
                  },
                  {
                    label: 'Not helpful',
                    value: data.feedback.negative,
                    ...(CHART_SERIES[3] ?? { fill: '', swatch: '' }),
                  },
                ]}
              />
              <ProportionBar
                caption="Provider attempts"
                empty="No provider call finished in this period."
                segments={[
                  {
                    label: 'Succeeded',
                    value: data.provider_calls.succeeded,
                    ...(CHART_SERIES[0] ?? { fill: '', swatch: '' }),
                  },
                  {
                    label: 'Failed',
                    value: data.provider_calls.failed,
                    ...(CHART_SERIES[3] ?? { fill: '', swatch: '' }),
                  },
                ]}
              />
              <p className="text-caption text-muted-foreground">
                Cancelled and in-flight attempts are in neither count: a reader closing a tab is not
                a provider error.
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle as="h3">Ingestion</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              <ProportionBar
                caption="Source versions in this period"
                empty="No source was ingested in this period."
                segments={[
                  {
                    label: 'Succeeded',
                    value: data.ingestion.succeeded,
                    ...(CHART_SERIES[0] ?? { fill: '', swatch: '' }),
                  },
                  {
                    label: 'Failed',
                    value: data.ingestion.failed,
                    ...(CHART_SERIES[3] ?? { fill: '', swatch: '' }),
                  },
                  {
                    label: 'In flight',
                    value: data.ingestion.in_flight,
                    ...(CHART_SERIES[5] ?? { fill: '', swatch: '' }),
                  },
                ]}
              />
              <p className="text-caption text-muted-foreground">
                Counted over source <em>versions</em>, not sources: a source is ingested many times,
                and counting sources would answer a question that never changes when an ingestion
                fails. A version with warnings is a success — it is searchable.
              </p>
            </CardContent>
          </Card>
        </div>
      </section>

      <UsageByModel rows={data.usage_by_model} />
    </div>
  );
}

/**
 * Token and cost usage, per `(provider, model, currency)`.
 *
 * A REAL `<table>` WITH `<th scope>`, not a grid of divs with `role="table"` — that is a
 * reimplementation which loses row and column announcements in at least one screen reader.
 *
 * ── THERE IS NO ORGANIZATION-WIDE COST TOTAL, AND THAT IS DELIBERATE SERVER-SIDE ────────────────
 * `currency` travels WITH each row's number because summing two currencies is a silent wrong answer.
 * A footer row adding a USD figure to a EUR one would be exactly that, computed in a browser, which
 * is why this table has none — and why `estimated_cost` is rendered as the exact decimal STRING the
 * server sent rather than parsed into a float first.
 */
function UsageByModel({ rows }: { readonly rows: AnalyticsResource['usage_by_model'] }) {
  return (
    <section aria-labelledby="dashboard-usage" className="flex flex-col gap-4">
      <h2 id="dashboard-usage" className="text-h2">
        Usage by model
      </h2>
      <div className="overflow-hidden rounded-2xl bg-card shadow-md">
        {rows.length === 0 ? (
          <p className="p-card-pad-md text-base text-muted-foreground">
            No model answered in this period.
          </p>
        ) : (
          <div className="w-full overflow-x-auto">
            <Table>
              <caption className="sr-only">Token usage and estimated cost, by model</caption>
              <TableHeader>
                <TableRow className="bg-card-inset hover:bg-card-inset">
                  <TableHead scope="col" className="px-card-pad-md">
                    Provider
                  </TableHead>
                  <TableHead scope="col" className="px-card-pad-md">
                    Model
                  </TableHead>
                  <TableHead scope="col" className="px-card-pad-md text-right">
                    Input
                  </TableHead>
                  <TableHead scope="col" className="px-card-pad-md text-right">
                    Output
                  </TableHead>
                  <TableHead scope="col" className="px-card-pad-md text-right">
                    Estimated cost
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={`${row.provider}:${row.model}:${row.currency ?? ''}`} className="h-13">
                    <TableCell className="px-card-pad-md">{row.provider}</TableCell>
                    {/* Vendor-authored text, and a JSX child. `--font-mono` says "machine handle"
                        without relying on colour. */}
                    <TableCell className="px-card-pad-md font-mono text-sm">{row.model}</TableCell>
                    <TableCell className="px-card-pad-md text-right">
                      {formatCount(row.input_tokens)}
                    </TableCell>
                    <TableCell className="px-card-pad-md text-right">
                      {formatCount(row.output_tokens)}
                    </TableCell>
                    <TableCell className="px-card-pad-md text-right">
                      {/* THE EXACT DECIMAL STRING, NEVER PARSED. `numeric(14,6)` does not survive a
                          round trip through a double, and an error in the sixth decimal per call is
                          an error in the second by the end of a month. `null` is "no recorded
                          price", which is not a cost of zero. */}
                      {row.estimated_cost === null
                        ? EM_DASH
                        : `${row.estimated_cost} ${row.currency ?? ''}`.trim()}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </div>
      <p className="text-caption text-muted-foreground">
        Input includes cached tokens and output includes reasoning tokens, because every vendor here
        bills them at those rates.
      </p>
    </section>
  );
}

/**
 * THE FIRST-LOAD SKELETON, AT THE LOADED GEOMETRY.
 *
 * The wrapper is `<StatTile>`'s box verbatim — `flex flex-col gap-2 rounded-xl bg-card
 * p-card-pad-md shadow-sm`, the caption row, and an `h-10` block where `--text-metric` (2.25rem /
 * 2.5rem line-height) renders. One block per TILES entry, so the grid cannot come out a different
 * height from the loaded one. Watch the transition rather than reading the code: that is the check.
 *
 * `aria-busy` on each block's container and `aria-hidden` on the blocks themselves (`<Skeleton>`
 * sets its own). A skeleton is not announced.
 */
function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-8">
      <Skeleton className="h-5 w-64" />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 xl:gap-5">
        {TILES.map((tile) => (
          <div
            key={tile.caption}
            className="flex flex-col gap-2 rounded-xl bg-card p-card-pad-md shadow-sm"
          >
            <div className="flex items-start justify-between gap-2">
              <p className="truncate text-caption text-muted-foreground uppercase">{tile.caption}</p>
              <tile.glyph aria-hidden className="size-5 shrink-0 text-muted-foreground" />
            </div>
            <div aria-busy="true">
              <Skeleton className="h-10 w-20" />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
