'use client';

import { KbError, type Role, type SourceDetailResource } from '@kb/contracts';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon } from 'lucide-react';
import Link from 'next/link';
import { useMemo } from 'react';

import { DegradedNote, ErrorState, ForbiddenState, SkeletonLines } from '@/components/states';
import { StatusPill } from '@/components/status-pill';
import { TONE, type ToneClasses } from '@/components/tone';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import { formatTimestamp } from '@/features/providers/api';
import {
  SOURCE_VIEW_ROLE,
  canManageSources,
  canViewSources,
  fetchSourceDetail,
  sourcePollInterval,
  sourceStatusDisplay,
  sourceTypeDisplay,
} from '@/features/sources/api';
import { SourceBotAssignments } from '@/features/sources/source-bot-assignments';
import {
  contentCounts,
  deleteConsequenceCounts,
  formatCount,
  itemNoun,
  liveState,
  warningCopy,
  warningScope,
} from '@/features/sources/source-detail';
import { SourceActionsContext } from '@/features/sources/source-actions-context';
import { SourceRowActions } from '@/features/sources/source-row-actions';
import { cn } from '@/lib/utils';

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  `/sources/{sourceId}` — ONE KNOWLEDGE SOURCE, WHAT IS INSIDE IT, AND WHICH BOTS MAY USE IT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ── THIS SCREEN PRODUCES NO ORGANIZATION-SCOPED BYTE ON THE NEXT SERVER ────────────────────────
 * `/sources/{sourceId}` has an identifier in the path, which makes it the third admin route that
 * LOOKS cacheable. It is not. Every Next cache is keyed by URL or by arguments — the Full Route
 * Cache, the Data Cache, the Router Cache, `unstable_cache`, `use cache` — and the ORGANIZATION
 * lives in the session cookie, which is in none of those keys. A URL-keyed entry here would carry
 * half a key, which is worse than none because it looks specific: a source id is a ULID rather than
 * a secret, and two admins in two organizations resolve the same path.
 *
 * So the row arrives from a browser fetch, in the one cache this app designs itself:
 *
 *     ['org', orgId, 'sources', sourceId]                        this resource
 *     ['org', orgId, 'sources', sourceId, 'assignments', botId]  one bot's grant on it
 *
 * THE SECOND IS A PREFIX EXTENSION OF THE FIRST, deliberately: invalidating the source invalidates
 * every child collection hanging off it, so a delete or a reprocess cannot leave the assignment
 * panel describing a document that has moved. The reverse also holds — `['org', orgId, 'sources']`
 * is a prefix of BOTH, so the list's own invalidation reaches this screen.
 *
 * THERE ARE NO OTHER CHILD KEYS, and the absence is the interesting part. Versions, warnings and
 * the structural counts would each be a `[...sourceKey, 'versions' | 'warnings' | 'chunks']` under
 * that rule, and none of them exists because none of them is a separate request: `GET
 * .../sources/{source}` returns them all on one resource. One query, one set of four states, five
 * cards. If a child endpoint ever lands, its key is that shape and nothing else.
 *
 * ── ON AN ORGANIZATION SWITCH THIS SCREEN IS A HARD RESET, NOT A REFETCH ───────────────────────
 * The URL does not change when the organization does, and the id in it belongs to the tenant the
 * admin just left. `useResetQueryClient()` REPLACES the QueryClient (a `clear()` keeps the same
 * observers, which immediately refetch and can still resolve an in-flight request into the new
 * cache) and the session re-enters `loading`, so this subtree unmounts and both the query and its
 * poll timer go with it. The id then 404s at binding time under the new organization and the
 * operator reads the class-mapped refusal rather than another tenant's document. The `orgId` prefix
 * is what still holds if a future refactor skips that step.
 *
 * ── THE PARAM IS A ROUTING HINT AND NOT A SCOPE ────────────────────────────────────────────────
 * `{sourceId}` travels in the request path, where Laravel resolves it with `->scopeBindings()`
 * through `$organization->knowledgeSources()`. A foreign id, an unknown id and an id belonging to
 * the organization the admin just switched away from all 404 at BINDING time, before any policy
 * runs, and `bootstrap/app.php` renders that 404 as `authorization` — the same class a genuine 403
 * carries. Nothing here validates the segment, and nothing should: a client-side ULID shape check
 * would only turn a server refusal into a different-looking one, and would answer a question the
 * deny split exists to leave unanswered.
 *
 * ── THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE ─────────────────────────────────────
 * Reprocess, disable, delete and every assignment grant are mutations Laravel rate-limits
 * (`throttle:admin`), authorizes through `sources.manage` / `sources.assign` / `bots.view`, refuses
 * on a suspended organization, and writes audit rows for. An action would bypass all four.
 */
export function SourceDetailScreen({ sourceId }: { readonly sourceId: string }) {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    return <SourceDetailSkeleton />;
  }

  if (session.status !== 'authenticated') {
    // `anonymous` and `unavailable`, and NOTHING is rendered for either — `<SessionProvider>` above
    // owns both: it bounces to `/login` for `anonymous` and renders the class-mapped "your account
    // could not be loaded" panel for `unavailable`.
    return null;
  }

  if (orgId === null) {
    // Reachable BY DESIGN: login succeeds with `current_organization_id: null` for a user whose
    // memberships are all `invited` or `suspended`. `useOrgKey()` would THROW below, because a key
    // built from `undefined` is ONE shared namespace for every org-less state on the platform — so
    // the gate is a mount condition rather than an `enabled` flag, and the throw is unreachable.
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          Knowledge sources belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  const membership = session.organizations.find((organization) => organization.id === orgId);

  return (
    <SourceDetailForOrganization
      orgId={orgId}
      sourceId={sourceId}
      // The role the VIEWER holds HERE, from the membership list the server itself handed us: the
      // same person can be an owner in one tenant and an analyst in the next.
      viewerRole={membership?.role ?? null}
      organizationName={membership?.name}
    />
  );
}

/** Mounted only with a real organization. */
function SourceDetailForOrganization({
  orgId,
  sourceId,
  viewerRole,
  organizationName,
}: {
  readonly orgId: string;
  readonly sourceId: string;
  readonly viewerRole: Role | null;
  readonly organizationName?: string;
}) {
  const orgKeyFor = useOrgKey();
  // MEMOIZED, because both arrays reach a context value and a `useMemo` dependency: `orgKeyFor`
  // returns a FRESH ARRAY each call, so an unstable key would give the actions context a new
  // identity every render — and with a five-second poll that is a re-render of every mutation and
  // dialog on this page every five seconds. `orgKeyFor` itself is a `useCallback` keyed on the
  // organization, so both recompute exactly when the tenant changes.
  const sourceKey = useMemo(() => orgKeyFor('sources', sourceId), [orgKeyFor, sourceId]);
  // THE LIST PREFIX, and it is what the row actions invalidate. It prefix-matches this screen's own
  // key as well as every page of the list, so one invalidation re-reads both — which is required
  // rather than convenient: a reprocess started here moves `status` on a row the list is also
  // showing, and a delete moves it between pages under the current sort.
  const listKey = useMemo(() => orgKeyFor('sources'), [orgKeyFor]);

  const detail = useQuery({
    queryKey: sourceKey,
    // `signal` forwarded: `queryClient.cancelQueries()` is a no-op against a queryFn that drops it,
    // and cancelling in-flight reads is step 2 of both logout and the organization switch.
    queryFn: ({ signal }) => fetchSourceDetail(orgId, sourceId, signal),
    /**
     * THE SAME POLL PREDICATE THE LIST USES, over a one-row page — imported rather than re-derived,
     * so the two screens cannot disagree about which states move on their own.
     *
     * `SourceDetailResource extends SourceResource`, so the detail row IS a row as far as
     * `sourcePollInterval` is concerned. It returns `false` — not `0`, which TanStack Query treats
     * as "as fast as possible" — the moment this source is not going anywhere, and a bare number
     * never stops: a tab left open here would poll for hours, each request paying a session lookup,
     * a membership re-check and a policy evaluation against `throttle:admin`.
     *
     * This screen needs it for the same two reasons the list does: six pipeline stages go past after
     * an upload or a reprocess, and `deleting` only becomes `deleted` when a background purge
     * finishes — which §8.17 requires an administrator to be able to SEE.
     */
    refetchInterval: (query) =>
      sourcePollInterval(query.state.data === undefined ? undefined : [query.state.data]),
  });

  const canManage = canManageSources(viewerRole);
  const canView = canViewSources(viewerRole);

  const actions = useMemo(() => ({ orgId, listKey, canManage }), [orgId, listKey, canManage]);

  /**
   * FORBIDDEN, AND IT IS OFFERED ONLY TO THE VIEWER WHO IS ACTUALLY MISSING THE ROLE.
   *
   * An `authorization` failure has three indistinguishable causes on the wire — the deny split makes
   * a 403, a foreign id and an unknown id byte-identical — so naming a role unconditionally would
   * tell an owner following a stale link to go and ask for a promotion. But `sources.view` is
   * withheld from ANALYST while the sidebar offers `/sources` to everybody, so an analyst reaching
   * this page is the common case and the role really is the reason. Every other viewer gets the
   * class-mapped sentence, which is correct for all three of their causes.
   */
  const forbidden =
    detail.error instanceof KbError &&
    detail.error.error_class === 'authorization' &&
    !canView &&
    detail.data === undefined;

  return (
    // Sections are separated by --space-8, never by a divider (the composition law).
    <div className="flex flex-col gap-8">
      {/* THE WAY BACK IS EXPLICIT, and it renders in all four states. This route is one level deep
          and the browser's back button is not an affordance a screen may rely on — an operator who
          arrived from a bookmark has nothing to go back through. It matters most in the failure
          state, which is the one an unknown id lands on. */}
      <p>
        <Link
          href="/sources"
          className="inline-flex items-center gap-2 text-base text-link underline-offset-4 hover:underline"
        >
          <ArrowLeftIcon aria-hidden className="size-4" />
          All sources
        </Link>
      </p>

      {detail.isPending ? <SourceDetailSkeleton /> : null}

      {forbidden ? (
        <ForbiddenState requiredRole={SOURCE_VIEW_ROLE} organizationName={organizationName} />
      ) : detail.error === null ? null : (
        /* THE TITLE DEPENDS ON WHETHER THERE IS STILL A ROW ON SCREEN, and the distinction is not
           cosmetic. With a five-second poll a failed refetch over a correct page is the common case,
           and telling the operator their document failed to load while they are looking at it is a
           lie about a request they did not make. */
        <ErrorState
          title={
            detail.data === undefined
              ? 'This source could not be loaded'
              : 'This source could not be refreshed'
          }
          error={detail.error}
          onRetry={() => void detail.refetch()}
        />
      )}

      {detail.data === undefined ? null : (
        <SourceActionsContext.Provider value={actions}>
          <SourceSummaryCard detail={detail.data} canManage={canManage} />
          <SourceContentsCard detail={detail.data} />
          <SourceWarningsCard detail={detail.data} />
          <SourceBotAssignments
            orgId={orgId}
            source={detail.data}
            sourceKey={sourceKey}
            viewerRole={viewerRole}
            organizationName={organizationName}
          />
          <SourcePreviewCard detail={detail.data} />
        </SourceActionsContext.Provider>
      )}
    </div>
  );
}

/**
 * FIRST LOAD. It mirrors the loaded layout — a tall summary card and two shorter ones — rather than
 * being three identical boxes, or the page reflows when the data lands and reads as a rendering bug.
 * A refetch is NOT this: rows already on screen and still correct stay on screen.
 */
function SourceDetailSkeleton() {
  return (
    <div aria-busy="true" className="flex flex-col gap-8">
      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <Skeleton className="h-7 w-64" />
          <SkeletonLines lines={2} />
        </CardContent>
      </Card>
      <Card>
        {/* The same grid as the loaded card, or the page reflows when the counts arrive. */}
        <CardContent className="grid grid-cols-2 gap-4 pt-6 xl:grid-cols-4">
          {[0, 1, 2, 3].map((tile) => (
            <Skeleton key={tile} className="h-20" />
          ))}
        </CardContent>
      </Card>
    </div>
  );
}

/** The tone classes, looked up through a `Map` rather than `TONE[name]`: the key is derived from a
 *  value off the wire, and indexing a plain object by a server-supplied value is the
 *  `security/detect-object-injection` sink. Built from `TONE`'s own entries, so the six tints are
 *  still stated in exactly one place. Same construction as `source-columns.tsx`. */
const TONE_CLASSES = new Map<string, ToneClasses>(Object.entries(TONE));

/**
 * WHAT THIS SOURCE IS — its name, what kind of thing it is, where it came from, and its lifecycle.
 *
 * ── EVERY STRING HERE IS TENANT-AUTHORED AND IS A JSX CHILD ────────────────────────────────────
 * `name`, `description`, `origin_url` and every tag were typed by an operator. React escapes a
 * child; `dangerouslySetInnerHTML` is banned repo-wide by ESLint. `origin_url` in particular is a
 * URL A STRANGER CHOSE — the field that answers "which sources make this platform issue outbound
 * requests" — so it renders as TEXT and is not a link, exactly as in the list. Making it clickable
 * is a decision about following a tenant-supplied URL from an authenticated admin origin, and this
 * screen does not take it either.
 *
 * ── THE ACTIONS ARE THE LIST'S, WITH THE DETAIL'S NUMBERS IN THE DELETE DIALOG ─────────────────
 * `<SourceRowActions>` is reused whole rather than re-implemented: it owns the three mutations, the
 * `deleted_at` gate, the Disable/Enable verb, the 422-from-the-transition-table sentence and the
 * typed confirmation. What this screen adds is the one thing the list could not have — a
 * consequence stated in numbers, because `SourceDetailResource` carries the counts and
 * `SourceResource` deliberately does not.
 */
function SourceSummaryCard({
  detail,
  canManage,
}: {
  readonly detail: SourceDetailResource;
  readonly canManage: boolean;
}) {
  const status = sourceStatusDisplay(detail.status);
  const type = sourceTypeDisplay(detail.type);
  const tone = TONE_CLASSES.get(type.tone) ?? TONE.slate;

  return (
    <Card>
      <CardHeader>
        {/* `flex-wrap` WITH A `basis` ON THE TITLE COLUMN. The name is `break-words`, so its
            min-content width is one WORD; the action cluster beside it is `shrink-0`. Without the
            basis, a 375px viewport gives the actions what they need and the title what is left,
            which is how a filename comes to render as a vertical column of letters (the measured
            failure in `upload-file-row.tsx`). */}
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
          <div className="flex min-w-0 flex-1 basis-64 flex-col gap-2">
            <CardTitle className="text-h2 break-words">{detail.name}</CardTitle>
            <div className="flex flex-wrap items-center gap-2">
              {/* A DECORATIVE, CATEGORICAL tint (P7) plus the word — the tint never carries the
                  meaning on its own. Deliberately not a second `<StatusPill>`: two coloured pills
                  would put two competing status channels beside each other. */}
              <span
                className={cn(
                  'inline-flex items-center rounded-full px-2 py-0.5 text-caption',
                  tone.surface,
                )}
              >
                {type.label}
              </span>
              {/* Colour AND glyph AND the word. `ready_with_warnings` keeps its own tone here for
                  the reason it does in the list: collapsing it into `ready` loses the only signal
                  that says "this parsed badly and published anyway". */}
              <StatusPill status={status.kind} label={status.label} />
              {detail.deleted_at === null ? null : (
                /* TWO DIFFERENT CLAIMS: `deleted_at` says we removed it, `purged_at` says we PROVED
                   it. §8.17 requires an administrator to see deletion completion, and with the poll
                   above this caption is where they watch it happen. */
                <span className="text-caption text-muted-foreground">
                  {detail.purged_at === null ? 'Purge in progress' : 'Purge verified'}
                </span>
              )}
            </div>
          </div>

          <div className="flex shrink-0 items-start">
            <SourceRowActions
              source={detail}
              /* THE CONSEQUENCE, IN NUMBERS THE SERVER WILL ACTUALLY HONOUR. Every clause is true of
                 what `DELETE .../sources/{source}` does: it sets one column, the tenant filter
                 matches the ready states POSITIVELY so exclusion takes effect on the next question
                 with no job needing to succeed, the row survives as `deleting`, this request
                 dispatches NO purge, and `deleting` has exactly one legal edge. The counts are the
                 live ones — see `deleteConsequenceCounts`, including why there is no bot count. */
              consequence={
                <>
                  “{detail.name}” stops answering questions immediately — that takes effect on the
                  next question anyone asks, and no background job has to succeed first.{' '}
                  {deleteConsequenceCounts(detail)} This is phase 1 of 2: nothing has been removed
                  yet. The row stays in the list as Deleting while a background purge removes the
                  text, the excerpts and the stored file, and it is marked Purge verified only once
                  that has been proven. There is no way back from either step.
                </>
              }
            />
          </div>
        </div>

        {detail.description === null ? null : (
          <CardDescription className="break-words">{detail.description}</CardDescription>
        )}
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {detail.origin_url === null ? null : (
          <div className="flex flex-col gap-1">
            <span className="text-caption text-muted-foreground uppercase">Crawled from</span>
            {/* `break-all` on a URL, and it is the ONLY `break-all` on this screen: a 2,048-character
                URL with no spaces has no other break opportunity, and it sits alone in its own block
                rather than beside a flexible sibling — which is the arrangement that made a filename
                collapse to one character per line at 375px. */}
            <span className="font-mono text-sm break-all">{detail.origin_url}</span>
          </div>
        )}

        {detail.tags.length === 0 ? null : (
          <ul className="flex flex-wrap gap-2" aria-label="Tags">
            {detail.tags.map((tag) => (
              <li
                key={tag}
                className="rounded-full bg-card-inset px-2 py-0.5 text-caption break-words"
              >
                {tag}
              </li>
            ))}
          </ul>
        )}

        <dl className="grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
          <div className="flex flex-col">
            <dt className="text-caption text-muted-foreground uppercase">Added</dt>
            <dd className="text-base" title={detail.created_at ?? undefined}>
              {formatTimestamp(detail.created_at)}
            </dd>
          </div>
          <div className="flex flex-col">
            <dt className="text-caption text-muted-foreground uppercase">Last change</dt>
            {/* `updated_at` MOVES ON EVERY LIFECYCLE TRANSITION, including the ones the ingestion
                pipeline reports — so it is a freshness signal rather than an edit log, and the label
                says "last change" rather than "last edited by". */}
            <dd className="text-base" title={detail.updated_at ?? undefined}>
              {formatTimestamp(detail.updated_at)}
            </dd>
          </div>
        </dl>

        {canManage ? null : (
          // SAID ONCE, as a fact about this organization rather than as an error. A viewer who may
          // read this source and change nothing gets no menu at all (the row actions render an em
          // dash), and a control that is hidden with no explanation is a puzzle.
          <Alert variant="info">
            <AlertTitle>You can read this source but not change it</AlertTitle>
            <AlertDescription>
              Reprocessing, disabling and deleting a source need the knowledge manager role in this
              organization.
            </AlertDescription>
          </Alert>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * HOW MUCH DOCUMENT IS HERE, AND HOW MUCH OF IT IS LIVE.
 *
 * ── THESE ARE NOT `<StatTile>`s, AND THAT IS A DECISION ────────────────────────────────────────
 * P4's tile requires `higherIsBetter`, which has no default precisely so a new tile cannot be
 * written without somebody deciding — and for a content count there is nothing to decide: a document
 * with more pages is not better or worse than one with fewer, and there is no comparison period, so
 * every tile would carry a required prop answering a question that does not apply and render no
 * delta. What these are is labelled numbers inside one card, on the recessed `--card-inset` strip,
 * which is content on a card rather than a KPI floating on the canvas.
 *
 * ── EVERY NUMBER IS OVER THE LIVE VERSIONS AND NONE OF THEM IS PROGRESS ────────────────────────
 * A source mid-ingestion reports zeroes even though rows for the unpublished version already exist.
 * That is right for the question this card answers — what is reachable — and it is why the card
 * leads with the live state rather than with the numbers when nothing is live at all.
 */
function SourceContentsCard({ detail }: { readonly detail: SourceDetailResource }) {
  const counts = contentCounts(detail);
  const live = liveState(detail);

  return (
    <Card>
      <CardHeader>
        <CardTitle>What is in this source</CardTitle>
        <CardDescription>
          Everything here is counted over the content that is currently live. A source that is still
          being processed reports zero until its first version is published — the previous one keeps
          answering until then.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {live === 'none' ? (
          /* NOT a `<DegradedNote>`: this is not "it worked but not fully", it is "none of this is
             reachable", which is the answer to "why is my bot not using this document". `warning`
             rather than `destructive` because it is the ordinary state of a source that is still
             ingesting, and the sentence says so. */
          <Alert variant="warning">
            <AlertTitle>Nothing in this source is live yet</AlertTitle>
            <AlertDescription>
              No bot can answer from it, whatever its status says. That is normal while it is being
              processed, and it is what a failed run leaves behind — reprocessing is the way to try
              again.
            </AlertDescription>
          </Alert>
        ) : null}

        {/* TWO COLUMNS UNTIL 1280, FOUR ABOVE IT — and the deviation from the KPI row's 1/2/4 is
            deliberate. These are not `--text-metric` tiles: the number is `--text-h2` and the whole
            block is four words wide, so one column on a phone is a long scroll of five short
            numbers. MEASURED at 768 with four columns: each tile was 173px, "Searchable excerpts"
            wrapped to two lines and its note to four, which is the ragged shape the two-column form
            avoids. */}
        <dl className="grid grid-cols-2 gap-3 xl:grid-cols-4">
          {counts.map((count) => (
            <div
              key={count.id}
              className="flex flex-col gap-1 rounded-lg bg-card-inset p-card-pad-sm"
            >
              <dt className="text-caption text-muted-foreground uppercase">{count.label}</dt>
              {/* `tabular-nums` on every number that can change: with a five-second poll behind this
                  card, proportional digits make the row jitter horizontally on every refresh and it
                  gets debugged as a layout-thrash bug. */}
              <dd className="text-h2 tabular-nums">{formatCount(count.value)}</dd>
              {count.note === undefined ? null : (
                <dd className="text-caption text-muted-foreground">{count.note}</dd>
              )}
            </div>
          ))}
        </dl>

        <LiveVersion detail={detail} live={live} />
      </CardContent>
    </Card>
  );
}

/**
 * "THE CURRENT VERSION" — WHEN THERE IS ONE, AND THE HONEST ANSWER WHEN THERE IS NOT.
 *
 * ── THE `null` CASE IS THE COMMON ONE AND IT IS DESIGNED FOR FIRST ─────────────────────────────
 * `active_version` is populated ONLY when the source has exactly one item — one uploaded file, one
 * paste. It is `null` for every crawl and every multi-file upload, including a four-hundred-page
 * site with every page serving, because activation is a pointer on the ITEM and "the current
 * version" of a multi-item source is a SET rather than a value. There is no honest scalar to
 * publish, so this renders the pair of counts instead: a screen that showed the pointer as "the
 * source's version" would be correct on single-file uploads and silently wrong on everything else.
 *
 * ── THE VERSION'S `status` IS NOT THE SOURCE'S, AND CONFLATING THEM IS THE OBVIOUS BUG ─────────
 * The source shows the state of the run IN FLIGHT; this shows the state of what is currently
 * SERVING. A source reading `parsing` above a version reading `ready` is the normal, correct state
 * during a reprocess — the previous version answers every query until the new one is indexed AND
 * verified — so the two pills are labelled differently and never merged.
 */
function LiveVersion({
  detail,
  live,
}: {
  readonly detail: SourceDetailResource;
  readonly live: 'single' | 'many' | 'none';
}) {
  if (live === 'none') return null;

  if (live === 'many' || detail.active_version === null) {
    return (
      <p className="text-base text-muted-foreground">
        {formatCount(detail.active_version_count)} of{' '}
        {formatCount(detail.item_count)} {itemNoun(detail.type, detail.item_count)} are answering.
        Each one is processed and published on its own, so there is no single version to name for the
        whole source.
      </p>
    );
  }

  const version = detail.active_version;
  const versionStatus = sourceStatusDisplay(version.status);

  return (
    <div className="flex flex-col gap-3 rounded-lg bg-card-inset p-card-pad-sm">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <span className="text-base font-medium tabular-nums">Version {version.version_number}</span>
        <StatusPill status={versionStatus.kind} label={versionStatus.label} />
        <span className="text-caption text-muted-foreground" title={version.activated_at ?? undefined}>
          Answering since {formatTimestamp(version.activated_at)}
        </span>
      </div>

      <p className="text-sm text-muted-foreground">
        A version number can skip: one that was created and never published leaves a gap, which is
        what a failed run looks like afterwards.
      </p>

      {/* THE FOUR CONFIGURATION STRINGS ARE INGEST-KEY COMPONENTS, NOT DIAGNOSTICS. They are the
          honest answer to "why did re-uploading the same file do something this time" — a reprocess
          produces a NEW version rather than deduplicating against this one when any of them has
          moved. They render as OPAQUE identifiers: nothing in this app parses one, and splitting an
          embedding identity on `:` to show a friendlier model name would be reading a format the
          data plane owns. */}
      <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <ConfigValue label="Parser" value={version.parser_cfg_version} />
        <ConfigValue label="OCR" value={version.ocr_cfg_version} />
        <ConfigValue label="Chunker" value={version.chunker_cfg_version} />
        <ConfigValue label="Embedding" value={version.embedding_model_version} />
      </dl>
    </div>
  );
}

/** One opaque configuration identifier. `break-all` because these are colon-delimited machine
 *  strings with no space in them, and each sits alone in its own grid cell rather than beside a
 *  flexible sibling — the arrangement the 375px collapse came from. */
function ConfigValue({ label, value }: { readonly label: string; readonly value: string }) {
  return (
    <div className="flex flex-col">
      <dt className="text-caption text-muted-foreground uppercase">{label}</dt>
      <dd className="font-mono text-caption break-all">{value}</dd>
    </div>
  );
}

/**
 * THE ADVISORY PARSER AND OCR WARNINGS — DEGRADED, IN PLACE, AND NEVER AS A FAULT.
 *
 * ── A WARNING IS NEVER A RETRIEVAL PREDICATE ───────────────────────────────────────────────────
 * `ready` and `ready_with_warnings` are identical for every query, so this list is NEVER the answer
 * to "why is this source not answering" — that question is `active_version_count`, one card up.
 * This is a quality signal beside the content: something was read badly and published anyway, and
 * the operator who needs to re-scan a page would otherwise never learn of it. `<DegradedNote>` is
 * the component for exactly that distinction, and this is not an `<Alert role="alert">`: a live
 * region would announce a fact that has been true since page load.
 *
 * ── THERE IS NO EMPTY STATE, AND ITS ABSENCE IS DELIBERATE ─────────────────────────────────────
 * This is a projection of the detail query rather than a collection of its own — loading, error and
 * forbidden all belong to the one query in the shell above — and "no warnings" is not a state a
 * reader needs told. A card headed "Warnings" containing "None" is a card that makes an operator
 * look for a problem that is not there. So an empty list renders nothing at all.
 */
function SourceWarningsCard({ detail }: { readonly detail: SourceDetailResource }) {
  if (detail.warnings.length === 0) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle>How well this was read</CardTitle>
        <CardDescription>
          This source is answering questions normally. These are notes about the quality of what was
          extracted, and they may explain an answer that misses something you can see in the
          original.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        {detail.warnings.map((warning) => {
          const copy = warningCopy(warning.code);
          return (
            <DegradedNote key={warning.code}>
              <span className="flex min-w-0 flex-col gap-0.5">
                {/* THE COPY WHEN THIS BUILD HAS ONE, AND THE RAW KEY WHEN IT DOES NOT. The code set
                    is the ingestion service's and grows with the parsers, so an unrecognised value
                    is a code we have no copy for rather than an error — and the honest render is the
                    string the pipeline actually wrote, which is also the one support can grep. */}
                {copy === null ? (
                  <span className="font-mono break-all">{warning.code}</span>
                ) : (
                  <span className="break-words">{copy}</span>
                )}
                <span className="text-caption">{warningScope(detail, warning.versions)}</span>
              </span>
            </DegradedNote>
          );
        })}

        {detail.warnings_truncated ? (
          /* "There is more of this kind of thing", not "something is hidden from you". The code set
             is open, so the list is capped rather than unbounded. */
          <p className="text-caption text-muted-foreground">
            More kinds of note were recorded than are listed here.
          </p>
        ) : null}
      </CardContent>
    </Card>
  );
}

/**
 * A BOUNDED EXCERPT OF WHAT WAS EXTRACTED — AND THE LEAST OBVIOUSLY UNTRUSTED FIELD ON THE WHOLE
 * RESOURCE.
 *
 * ── IT IS DOCUMENT TEXT SOMEBODY ELSE WROTE, AND IT READS LIKE OUR OWN OUTPUT ──────────────────
 * That is exactly why it is dangerous: it arrives on our resource, in our card, and looks like
 * something the platform produced. It is not. It is the content of an uploaded file or a crawled
 * page — non-negotiable 7, untrusted data — and the server does not escape it, deliberately, which
 * makes the escaping this renderer's job. So it is an ordinary JSX CHILD: React escapes it, there is
 * no `dangerouslySetInnerHTML` (ESLint bans the attribute repo-wide), it never reaches a `style`
 * attribute — a CSP nonce does not cover `style=""` and never covered `dangerouslySetInnerHTML` —
 * and it is never interpolated into a prompt.
 *
 * `whitespace-pre-wrap` preserves the blank lines the server put BETWEEN elements without any
 * parsing: the excerpt is elements in document order separated by a blank line, and rendering it
 * pre-wrapped shows that structure without ever treating the text as markup.
 *
 * ── NULL AND "" ARE DIFFERENT FACTS AND THE CONTRACT KEEPS THEM APART ──────────────────────────
 * `content_preview` is `null` when nothing has been extracted yet, and never an empty string,
 * because "no content" and "the first element is blank" are different and a renderer that conflated
 * them would show a blank panel for both.
 */
function SourcePreviewCard({ detail }: { readonly detail: SourceDetailResource }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>What was extracted</CardTitle>
        <CardDescription>
          The beginning of the text your bots can search, exactly as it was read out of the original.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {detail.content_preview === null ? (
          <p className="text-base text-muted-foreground">
            Nothing has been extracted from this source yet. Text appears here once a version has
            been processed and published.
          </p>
        ) : (
          <>
            {/* `break-words` and NOT `break-all`: this is prose, and breaking inside every word
                turns a paragraph into a wall. It is also `max-h-96 overflow-y-auto` rather than
                unbounded, so a long excerpt cannot push the assignment controls off the screen. */}
            <p className="max-h-96 overflow-y-auto rounded-lg bg-card-inset p-card-pad-sm text-base whitespace-pre-wrap break-words">
              {detail.content_preview}
            </p>
            {detail.content_preview_truncated ? (
              // TRUE IS THE ORDINARY CASE for anything longer than a page, so this is an ellipsis
              // rather than a warning, and it never implies the document is as short as the excerpt.
              <p className="pt-2 text-caption text-muted-foreground">
                … this is the beginning only. The whole document is searchable.
              </p>
            ) : null}
          </>
        )}
      </CardContent>
    </Card>
  );
}
