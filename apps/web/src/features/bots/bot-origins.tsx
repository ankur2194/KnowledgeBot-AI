'use client';

import type { BotDomainResource } from '@kb/contracts';
import {
  BOT_DOMAIN_STATUSES,
  botDomainCreateDefaults,
  botDomainCreateSchema,
  type BotDomainCreateIn,
  type BotDomainCreateOut,
  type BotDomainStatusOut,
} from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { GlobeIcon, LoaderIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';

import {
  DataTableCard,
  DataTableCardField,
  DataTableCards,
  DataTableShell,
} from '@/components/data-table';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyState, ErrorState, SkeletonLines } from '@/components/states';
import { StatusPill } from '@/components/status-pill';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { actionErrorCopy } from '@/features/auth/action-error';
import { applyAuthError } from '@/features/auth/auth-error';
import { formatTimestamp } from '@/features/providers/api';
import { actionableConflictMessage } from '@/lib/api/actionable-conflict';
import { asText } from '@/lib/forms/as-text';

import { useBotEditor } from './bot-editor-context';
import {
  BOT_DOMAIN_CREATE_KNOWN_PATHS,
  botDomainStatusDisplay,
  createBotDomain,
  deleteBotDomain,
  fetchBotDomains,
  updateBotDomainStatus,
} from './bot-origins-api';

/**
 * THE WIDGET ORIGIN ALLOW-LIST — a child collection of the bot, rendered inside the Publishing tab.
 *
 * ── THIS IS A SECURITY CONTROL AND EVERY LINE OF COPY ON IT IS PART OF THE CONTROL ──────────────
 *
 * An `active` row is what lets a page on the public internet boot a chat widget that speaks with
 * this organization's credential, on its corpus, against its quota. Every downstream check AGREES
 * with a row here, because it has been told the origin belongs to this bot; there is no later layer
 * that catches a bad one. Four facts follow, and each is rendered rather than assumed:
 *
 *   1. `active` MEANS AN OPERATOR CONFIRMED IT, NOT THAT THE PLATFORM VERIFIED IT. Nothing anywhere
 *      in this product proves control of an origin — no DNS TXT record is read, no well-known path
 *      is fetched, nothing is resolved. Copy that said "verified", "allowed" or "confirmed by us"
 *      would be a claim the platform cannot make, on the one screen where an operator decides who
 *      may spend their tokens. So the section says it in as many words, once, above the table.
 *
 *   2. AN EMPTY LIST DENIES EVERY ORIGIN, and reading it as "unrestricted" is the exact inversion
 *      that makes this dangerous. The embed decision is "some ACTIVE row matches this exact origin",
 *      which is false for the empty set by construction — never "no row forbids it", which is true
 *      for it. The first-run empty state says the denying half, because that is the half a reader
 *      will otherwise assume the other way round.
 *
 *   3. THE LIST RENDERS THE SERVER'S `origin`, NEVER THE SUBMITTED ONE. `App\Support\Web\ExactOrigin`
 *      normalises on write — scheme and host lower-cased, one trailing slash dropped, a default port
 *      (`:80`, `:443`) dropped because the browser omits it — so `HTTPS://Example.COM:443/` is stored
 *      as `https://example.com`. The runtime comparison is byte equality against the browser's
 *      `Origin` header, so "what was stored" is the entire question this screen answers, and echoing
 *      the input would answer it wrongly. The add form therefore seeds nothing from what was typed:
 *      it resets, the list re-reads, and the receipt above the form quotes the 201 body's `origin`.
 *
 *   4. THERE IS NO WILDCARD GRAMMAR AND A PATH IS REFUSED RATHER THAN TRIMMED. A browser sends the
 *      same `Origin` for every page on a host, so shortening `https://example.com/widget` to its
 *      host would permit the whole site while the operator believed they had allowed one page. The
 *      client schema deliberately does not normalise; it carries the length, the type and the
 *      two-scheme prefix and lets the server refuse everything else with its own sentence.
 *
 * ── ENTERING AN ORIGIN AND MAKING IT USABLE ARE TWO REQUESTS, AND THE UI KEEPS THEM TWO ─────────
 * `POST` creates a `pending` row that grants nothing; `PATCH …/{domain}` promotes it. That is a
 * server-side design — one request must not be able to do both, and `bot.domain.created` and
 * `bot.domain.status_changed` are two audit rows for two decisions — and a console that offered
 * "add and activate" in one control would put both behind one click while the trail still recorded
 * two. So the add form has no status control at all, and the promotion lives on the row.
 *
 * ── THE FOUR STATES, AND WHY ONE OF THEM IS DELIBERATELY NOT ITS OWN RENDER ─────────────────────
 *   Loading    a skeleton at the loaded surface's shape.
 *   Empty      FIRST-RUN only. There is no filter on this collection, so there is no filtered empty
 *              state and there must not be a `<FilteredEmptyState>` here — offering "Clear filters"
 *              to somebody whose allow-list is empty is the bug that split exists to prevent.
 *   Error      the class-mapped sentence plus the `request_id`, with the retry affordance gated on
 *              the envelope's own `retryable`.
 *   Forbidden  THE SAME RENDER AS ERROR, for the reason the shell gives about the bot itself: all
 *              four roles hold `bots.view`, and `App\Enums\Permission::BotsView` names the origin
 *              allow-list explicitly, so an `authorization` failure here is never a role gap. It is
 *              a bot addressed under an organization the admin has left, or a revoked membership —
 *              both 404 at binding time and both render as `authorization`. `<ForbiddenState>` names
 *              a role and who can grant it, which here would point at the wrong door.
 */
export function BotOrigins({
  /**
   * Reported UP to the Publishing panel, which ORs it with its two forms' dirty flags and makes ONE
   * `useUnsavedBotEdits('publishing', …)` call. Two calls with the same panel id would fight: the
   * later effect's write wins and the earlier panel's dirty state is lost, so a half-typed origin
   * would vanish on a tab change with no dialog. One call, one boolean.
   */
  onUnsavedChange,
}: {
  readonly onUnsavedChange?: (unsaved: boolean) => void;
}) {
  const { orgId, botId, botKey, canManage } = useBotEditor();
  const queryClient = useQueryClient();

  /**
   * `['org', orgId, 'bots', botId, 'domains']` — the detail key with one segment appended, built
   * FROM `botKey` rather than from a second `useOrgKey()` call, so a refactor of the bot key carries
   * this one with it and the two cannot disagree about which organization they name.
   *
   * IT IS PREFIX-MATCHED BY `botsListKey`, so `useBotSave`'s `onSettled` invalidates this list too.
   * That is one extra GET per settings save, accepted for the same reason the shell accepts it for
   * the detail: a `predicate` that excluded it by inspecting key shape would encode the key layout
   * in a second place, which is how two spellings start.
   */
  const domainsKey = useMemo(() => [...botKey, 'domains'], [botKey]);

  const domains = useQuery({
    queryKey: domainsKey,
    // `signal` forwarded: cancelling in-flight reads is step 2 of both logout and the organization
    // switch, which is exactly when another organization's allow-list must not resolve.
    queryFn: ({ signal }) => fetchBotDomains(orgId, botId, signal),
  });

  /**
   * THE TWO ROW MUTATIONS LIVE HERE, NOT IN A ROW COMPONENT, and that is what lets the table and
   * the below-768px card stack drive the SAME write. A mutation per row per layout would be two
   * instances for one row, and the one the operator did not use would hold the stale error.
   *
   * `variables` is what identifies the row a pending state or a failure belongs to. It is
   * react-query's own record of the argument in flight, so nothing here keeps a parallel "which row
   * is busy" state that could disagree with it.
   */
  const changeStatus = useMutation<
    BotDomainResource,
    Error,
    { readonly domainId: string; readonly status: BotDomainStatusOut['status'] }
  >({
    mutationFn: ({ domainId, status }) => updateBotDomainStatus(orgId, botId, domainId, { status }),
    onSettled: () => {
      // Invalidate-and-re-read, never a cache write and never optimistic. Promotion is a grant: an
      // optimistic flip would claim one the server may have refused, on the control that decides
      // whether a page on the internet can boot this widget.
      void queryClient.invalidateQueries({ queryKey: domainsKey });
    },
  });

  const [confirming, setConfirming] = useState<BotDomainResource | null>(null);

  const remove = useMutation<unknown, Error, string>({
    mutationFn: (domainId) => deleteBotDomain(orgId, botId, domainId),
    onSettled: () => {
      // CLOSED ON FAILURE TOO: the refusal is rendered in the section, and a modal is
      // `inert`-over-the-background by design — an alert left underneath an open dialog is an alert
      // nobody reads. Closing also drops the typed confirmation, so a second attempt is a second
      // deliberate act.
      setConfirming(null);
      void queryClient.invalidateQueries({ queryKey: domainsKey });
    },
  });

  /** The class-mapped sentence, or the server's own actionable one for the 409 refusals. */
  const rowError = (mutation: { error: Error | null }): string | null =>
    mutation.error === null
      ? null
      : (actionableConflictMessage(mutation.error) ?? actionErrorCopy(mutation.error));

  const statusError = rowError(changeStatus);
  const removeError = rowError(remove);
  const busyId = changeStatus.isPending ? changeStatus.variables?.domainId : undefined;
  /**
   * THE IN-FLIGHT INTENT, READ OFF THE MUTATION — NOT AN OPTIMISTIC UPDATE.
   *
   * `variables` is react-query's own record of the argument currently in flight, so this is the
   * chosen status without a second piece of state that could disagree with it, without a cache
   * write, and without a rollback path. The cache still holds the SERVER's row throughout, and the
   * moment the mutation settles `isPending` goes false and every trigger falls back to `row.status`
   * — so a refusal is displayed as a refusal rather than being papered over by a value we invented.
   *
   * What it fixes: the select is fully controlled on `row.status`, so choosing "Active" used to
   * re-render with the trigger still reading "Pending" for the whole PATCH plus refetch. That reads
   * as a control that ignored the click, on the one screen where the control decides whether a page
   * on the internet may boot this widget.
   */
  const busyStatus = changeStatus.isPending ? changeStatus.variables?.status : undefined;
  const busyOrigin =
    busyId === undefined ? undefined : domains.data?.find((row) => row.id === busyId)?.origin;

  return (
    <section aria-labelledby="bot-origins-heading" className="flex flex-col gap-3">
      <h3 id="bot-origins-heading" className="text-h3">
        Widget origins
      </h3>

      <p className="max-w-prose text-base text-muted-foreground">
        The exact origins a chat widget for this bot may boot on — scheme, host and port, compared
        for byte equality against the browser&apos;s <span className="font-mono">Origin</span>{' '}
        header. There is no wildcard form, and an empty list permits nothing rather than everything.
      </p>

      {/* SAID ONCE, ABOVE THE CONTROLS, and phrased as a fact about this platform rather than as a
          warning. Nothing here checks a DNS record or fetches a well-known path, so "Active" is an
          operator's assertion and the copy may not imply otherwise. */}
      <Alert variant="info">
        <AlertTitle>Turning an origin on is your confirmation, not ours</AlertTitle>
        <AlertDescription>
          This platform does not check that you control an origin — no DNS record is read and no
          file is fetched. Marking one active is a statement by an owner or admin of this
          organization that the site is yours, and it is what lets a page there start a conversation
          on your credential and your quota. Adding an origin, turning one on and removing one are
          each recorded separately in the audit trail.
        </AlertDescription>
      </Alert>

      {canManage ? <AddOriginForm domainsKey={domainsKey} onUnsavedChange={onUnsavedChange} /> : null}

      {/* FIRST LOAD IS A SKELETON AT THE LOADED SHAPE, so the section does not reflow when the rows
          arrive and read as a rendering bug. A refetch is NOT a skeleton — rows already on screen
          and still correct stay on screen, which is why this is gated on `isPending`. */}
      {domains.isPending ? (
        <DataTableShell>
          <SkeletonLines lines={3} className="p-card-pad-md" />
        </DataTableShell>
      ) : null}

      {domains.error === null ? null : (
        <ErrorState
          title={
            domains.data === undefined
              ? 'This bot’s origins could not be loaded'
              : 'This bot’s origins could not be refreshed'
          }
          error={domains.error}
          onRetry={() => void domains.refetch()}
        />
      )}

      {/* The two row mutations' failures, rendered in the SECTION rather than in a row: the delete's
          dialog has already closed, and the status change's row may have been re-read away. Both
          are `role="alert"` through `<Alert>`. */}
      {/* THE IN-FLIGHT SENTENCE, and it is a separate channel from the spinner rather than a
          duplicate of it. `aria-busy` on a trigger is not announced by any screen reader on its own,
          and `disabled` is announced as "unavailable" with no reason — so without this the only
          feedback for a promotion in flight is a visual one. `role="status"` is polite: it is read
          after whatever the operator's selection already announced, and it is empty the rest of the
          time so nothing is re-announced when the row settles. The failure is a separate
          `role="alert"` below; success is the re-read row itself. */}
      <p role="status" className="sr-only">
        {busyStatus === undefined
          ? ''
          : `Setting ${busyOrigin ?? 'this origin'} to ${botDomainStatusDisplay(busyStatus).label}…`}
      </p>

      {statusError === null ? null : (
        <Alert variant="destructive">
          <AlertTitle>That origin&apos;s status did not change</AlertTitle>
          <AlertDescription>{statusError}</AlertDescription>
        </Alert>
      )}

      {removeError === null ? null : (
        <Alert variant="destructive">
          <AlertTitle>That origin was not removed</AlertTitle>
          <AlertDescription>{removeError}</AlertDescription>
        </Alert>
      )}

      {domains.data === undefined ? null : domains.data.length === 0 ? (
        // FIRST-RUN EMPTY, and the copy leads with what the empty list MEANS rather than with an
        // invitation, because the meaning is the thing a reader gets backwards.
        <EmptyState
          glyph={GlobeIcon}
          title="No origins on the allow-list"
          body={
            canManage
              ? 'An empty list permits nothing: a widget for this bot is refused on every site until an origin is added here and turned on. Hosted chat is unaffected — it runs on our own domain.'
              : 'An empty list permits nothing: a widget for this bot is refused on every site. An owner or admin of this organization can add one.'
          }
        />
      ) : (
        <>
          <DataTableShell>
            <Table>
              <caption className="sr-only">
                Origins a chat widget for this bot may boot on
              </caption>
              <TableHeader>
                <TableRow>
                  <TableHead scope="col">Origin</TableHead>
                  <TableHead scope="col">Status</TableHead>
                  <TableHead scope="col">Added</TableHead>
                  {/* The actions column is fixed-width and is never the flexible one (P6). */}
                  <TableHead scope="col" className="w-32">
                    Actions
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {domains.data.map((row) => {
                  const display = botDomainStatusDisplay(row.status);

                  return (
                    <TableRow key={row.id}>
                      <TableCell>
                        <span className="flex flex-col">
                          {/* THE SERVER'S STRING, MONOSPACED, so it can be compared character by
                              character against a browser's address bar. Tenant-supplied text as a
                              JSX child, never markup. */}
                          <span className="font-mono">{row.origin}</span>
                          <PermitsEmbedding row={row} />
                        </span>
                      </TableCell>
                      <TableCell>
                        {canManage ? (
                          <OriginStatusSelect
                            row={row}
                            pendingStatus={busyId === row.id ? busyStatus : undefined}
                            onChange={(status) => changeStatus.mutate({ domainId: row.id, status })}
                          />
                        ) : (
                          // Colour AND a glyph AND the word: a status told by colour alone is
                          // unreadable in greyscale and under CVD.
                          <StatusPill status={display.kind} label={display.label} />
                        )}
                      </TableCell>
                      <TableCell>{formatTimestamp(row.created_at)}</TableCell>
                      <TableCell>
                        {canManage ? (
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={remove.isPending}
                            // The origin is IN the accessible name: a column of identical "Remove"
                            // buttons is unusable with a screen reader and ambiguous to a locator.
                            aria-label={`Remove ${row.origin}`}
                            onClick={() => setConfirming(row)}
                          >
                            Remove
                          </Button>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </DataTableShell>

          {/* BELOW 768px A TABLE BECOMES A STACK OF CARDS, never a horizontal scroller — a scroller
              hides the trailing columns, and the trailing column here is where the destructive
              action lives (P6). Both layouts are switched by `hidden`/`md:hidden`, so `display:
              none` removes the other from the accessibility tree and each origin is announced once.
              The controls are the SAME mutations, hoisted to this component, so a promotion made at
              360px is the identical request as one made at 1280px. */}
          <DataTableCards>
            {domains.data.map((row) => (
              <DataTableCard
                key={row.id}
                title={<span className="font-mono break-all">{row.origin}</span>}
                actions={
                  canManage ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={remove.isPending}
                      aria-label={`Remove ${row.origin}`}
                      onClick={() => setConfirming(row)}
                    >
                      Remove
                    </Button>
                  ) : null
                }
              >
                <DataTableCardField label="Status">
                  {canManage ? (
                    <OriginStatusSelect
                      row={row}
                      pendingStatus={busyId === row.id ? busyStatus : undefined}
                      onChange={(status) => changeStatus.mutate({ domainId: row.id, status })}
                    />
                  ) : (
                    <StatusPill
                      status={botDomainStatusDisplay(row.status).kind}
                      label={botDomainStatusDisplay(row.status).label}
                    />
                  )}
                </DataTableCardField>
                <DataTableCardField label="Widget">
                  <PermitsEmbedding row={row} />
                </DataTableCardField>
                <DataTableCardField label="Added">
                  {formatTimestamp(row.created_at)}
                </DataTableCardField>
              </DataTableCard>
            ))}
          </DataTableCards>
        </>
      )}

      {/* TYPED CONFIRMATION, and the string to type is the ORIGIN. That is heavier than a second
          click on purpose: removing an active row stops every widget on that site mid-conversation,
          and the origin is exactly the value a person has to have read to know which site that is. */}
      {confirming === null ? null : (
        <ConfirmDestructiveDialog
          open
          onOpenChange={(next) => {
            if (!next) setConfirming(null);
          }}
          title="Remove origin"
          resourceName={confirming.origin}
          confirmLabel="Remove origin"
          pending={remove.isPending}
          consequence={
            <>
              A widget served from <span className="font-mono">{confirming.origin}</span> stops
              booting immediately. The removal is recorded in the audit trail with the origin
              itself, so the row can be identified afterwards — but it is not restorable from here.
              To stop permitting it temporarily, set it to <strong>Disabled</strong> instead: that
              keeps the row so it can be turned back on without retyping.
            </>
          }
          onConfirm={() => remove.mutate(confirming.id)}
        />
      )}
    </section>
  );
}

/**
 * WHETHER A WIDGET ON THIS ORIGIN MAY BOOT, READ FROM THE SERVER'S OWN DERIVED FIELD.
 *
 * `permits_embedding` and NOT `status === 'active'`, and the resource says why in as many words: a
 * check written as "not disabled" admits `pending`, and a status added later would be admitted by
 * every negative test in every client. The server derives it; this renders it.
 *
 * It is also only ONE TERM of the runtime decision — the bot's status, its access mode and an exact
 * match on the origin are the others — so the wording is about this row rather than about the
 * outcome, and the section copy above carries the rest.
 */
function PermitsEmbedding({ row }: { readonly row: BotDomainResource }) {
  return (
    <span className="text-sm text-muted-foreground">
      {row.permits_embedding
        ? 'A widget on this origin may boot.'
        : 'A widget on this origin is refused.'}
    </span>
  );
}

/**
 * The per-row lifecycle control.
 *
 * ── A `<Select>` THAT WRITES ON CHANGE, WITH NO SEPARATE APPLY BUTTON ──────────────────────────
 * The alternative — a select plus a per-row submit — puts two controls in a table cell and makes
 * the common case (promote one row) two interactions. Choosing a value from a menu is already a
 * deliberate act rather than a click in the same place twice, the write is audited, and the state
 * is reversible in one more selection. The models catalogue's inline `enabled` switch is the same
 * decision one control simpler.
 *
 * ── THE ACCESSIBLE NAME CARRIES THE ORIGIN, AND IT IS PHRASED SO IT CONTAINS NO OTHER CONTROL'S ─
 * A column of selects all named "Status" is ambiguous for a screen reader and resolves to N
 * elements for a locator. "Allow-list status for https://example.com" also deliberately does not
 * CONTAIN — and is not contained by — the lifecycle select's "Lifecycle status" on the same tab,
 * because role-name matching is a case-insensitive substring in both Playwright and vitest-browser.
 */
function OriginStatusSelect({
  row,
  pendingStatus,
  onChange,
}: {
  readonly row: BotDomainResource;
  /**
   * The status this row's PATCH is currently carrying, or `undefined` when nothing is in flight for
   * it. It is DISPLAY ONLY and lives for exactly the duration of the request: the cache is never
   * written, so whatever the server answers — including a refusal — is what ends up on screen.
   */
  readonly pendingStatus?: BotDomainStatusOut['status'];
  readonly onChange: (status: BotDomainStatusOut['status']) => void;
}) {
  const busy = pendingStatus !== undefined;

  return (
    <Select
      value={pendingStatus ?? row.status}
      disabled={busy}
      onValueChange={(next) => {
        // Narrowed against the declared tuple rather than cast: Radix hands `onValueChange` a bare
        // string, and a cast would let a typo'd `SelectItem` value reach a PATCH body as a status
        // the server has never heard of.
        const target = BOT_DOMAIN_STATUSES.find((member) => member === next);
        if (target === undefined) return;
        if (target === row.status) return;
        onChange(target);
      }}
    >
      {/* `aria-label` IS LOAD-BEARING AND ITS WORDING IS FIXED — role-name matching is a
          case-insensitive SUBSTRING in both Playwright and vitest-browser, so this string is chosen
          to neither contain nor be contained by the lifecycle select's name on the same tab.
          `aria-busy` is the machine-readable half of the spinner; the sentence a screen reader
          actually hears is the section's live region, because a busy trigger is not announced. */}
      <SelectTrigger
        aria-label={`Allow-list status for ${row.origin}`}
        aria-busy={busy}
        className="w-44"
      >
        {busy ? (
          <LoaderIcon aria-hidden className="size-4 animate-spin motion-reduce:animate-none" />
        ) : null}
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {BOT_DOMAIN_STATUSES.map((member) => (
          <SelectItem key={member} value={member}>
            {botDomainStatusDisplay(member).label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/**
 * ADD ONE ORIGIN. One field, and it creates a `pending` row that grants nothing.
 *
 * ── MOUNTED ONLY WITH `bots.manage`, so a reader mounts no `useForm` at all ────────────────────
 * The shell's rule (§5a): a viewer without the permission gets values as read-only text and no
 * control. A disabled input holding a value is a control an operator will keep clicking.
 *
 * ── THE RECEIPT QUOTES THE SERVER'S `origin`, WHICH IS THE POINT OF THE WHOLE SCREEN ───────────
 * `HTTPS://Example.COM:443/` is stored as `https://example.com`. Confirming the submitted string
 * would tell the operator their allow-list contains something it does not, on the one screen where
 * byte equality against a browser header is the entire semantics. So the receipt reads the 201
 * body, and the list below re-reads from the server rather than being written optimistically.
 */
function AddOriginForm({
  domainsKey,
  onUnsavedChange,
}: {
  readonly domainsKey: readonly unknown[];
  readonly onUnsavedChange?: (unsaved: boolean) => void;
}) {
  const { orgId, botId } = useBotEditor();
  const queryClient = useQueryClient();

  // Three generics, input then output: `origin` is a preprocess-free string here, but the pair is
  // written out anyway because one generic pins both and fails to typecheck the day the field gains
  // a `.default()` or a `.trim()`-carrying preprocess.
  const form = useForm<BotDomainCreateIn, unknown, BotDomainCreateOut>({
    resolver: zodResolver(botDomainCreateSchema),
    defaultValues: botDomainCreateDefaults(),
    mode: 'onTouched',
  });

  const dirty = form.formState.isDirty;

  useEffect(() => {
    onUnsavedChange?.(dirty);
    return () => {
      onUnsavedChange?.(false);
    };
  }, [dirty, onUnsavedChange]);

  const add = useMutation({
    mutationFn: (values: BotDomainCreateOut) => createBotDomain(orgId, botId, values),
    onSuccess: () => {
      // Reset so the next add starts empty — and so `isDirty` clears, which is what stops the tab
      // guard asking about an origin that has already been stored.
      form.reset(botDomainCreateDefaults());
    },
    onError: (error) => {
      // Branches on `error_class`, never on a status. A 422 keyed `origin` — a duplicate, or any of
      // `ExactOrigin`'s refusals, each with its own sentence — lands under the input. The 409 for a
      // suspended organization has no field to key to and reaches the banner; `copyFor` is what
      // makes that banner the server's own actionable sentence rather than "Something on our side
      // is unavailable", which would be false twice.
      applyAuthError(form, BOT_DOMAIN_CREATE_KNOWN_PATHS, error, {
        copyFor: (kbError) => actionableConflictMessage(kbError) ?? undefined,
      });
    },
    onSettled: () => {
      // `onSettled` and not `onSuccess`: a duplicate 422 means the list in front of the operator is
      // also out of date — somebody else added that origin.
      void queryClient.invalidateQueries({ queryKey: domainsKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <div className="flex flex-col gap-3 rounded-2xl bg-card p-card-pad-md shadow-md">
      {/* THE RECEIPT LIVES OUTSIDE THE FORM'S ERROR SLOT and quotes the STORED string. `aria-live`
          polite rather than a toast: a message that disappears is the wrong surface for one an
          administrator may want to re-read, and the table below is not a live region — its new row
          is silent. */}
      {add.isSuccess ? (
        <Alert aria-live="polite">
          <AlertTitle>Added to the allow-list</AlertTitle>
          <AlertDescription>
            Stored as <span className="font-mono">{add.data.origin}</span>, which may differ from
            what you typed — a default port and a trailing slash are dropped and the host is
            lower-cased, because that is the form a browser sends. It is <strong>Pending</strong>{' '}
            and grants nothing until you turn it on below.
          </AlertDescription>
        </Alert>
      ) : null}

      {rootError === undefined ? null : (
        <Alert variant="destructive">
          <AlertTitle>That origin was not added</AlertTitle>
          <AlertDescription>{rootError}</AlertDescription>
        </Alert>
      )}

      <Form {...form}>
        <form
          // POST, never the browser's default GET — asserted for every form in this app by
          // tests/unit/form-method.test.ts.
          method="post"
          noValidate
          className="flex flex-col gap-3 sm:flex-row sm:items-start"
          onSubmit={form.handleSubmit((values) => {
            add.mutate(values);
          })}
        >
          <FormField
            control={form.control}
            name="origin"
            render={({ field }) => (
              <FormItem className="flex-1">
                <FormLabel>Origin to allow</FormLabel>
                <FormControl>
                  <Input
                    {...field}
                    value={asText(field.value)}
                    type="text"
                    inputMode="url"
                    autoComplete="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    // An affordance mirroring `max:255`, never the authority: `ExactOrigin`
                    // re-checks the length and every other rule server-side.
                    maxLength={255}
                    placeholder="https://example.com"
                  />
                </FormControl>
                <FormDescription>
                  Scheme, host and — only if it is not the default — port. No path, no wildcard:
                  copy it from the address bar and delete everything after the host.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />

          <Button type="submit" disabled={add.isPending} className="sm:mt-8">
            {add.isPending ? 'Adding…' : 'Add origin'}
          </Button>
        </form>
      </Form>
    </div>
  );
}
