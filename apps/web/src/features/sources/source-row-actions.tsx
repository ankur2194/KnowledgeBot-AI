'use client';

import { KbError, type SourceResource } from '@kb/contracts';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import {
  MoreHorizontalIcon,
  PlayIcon,
  RefreshCwIcon,
  Trash2Icon,
  XCircleIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { actionErrorCopy } from '@/features/auth/action-error';
import {
  SOURCE_DISABLE_TARGET,
  SOURCE_ENABLE_TARGET,
  deleteSource,
  reprocessSource,
  setSourceStatus,
} from '@/features/sources/api';
import { useSourceActions } from '@/features/sources/source-actions-context';
import { actionableConflictMessage } from '@/lib/api/actionable-conflict';

/**
 * THE THREE THINGS AN OPERATOR DOES TO ONE SOURCE — reprocess it, withdraw or restore it, and remove
 * it — plus the refusal each of them can come back with.
 *
 * ── NOT ONE OF THEM IS OPTIMISTIC, AND NOTHING IN `src/` PRE-WRITES A MUTATION'S RESULT ─────────
 * (The TanStack hook for doing that — the one whose name this comment deliberately does not spell —
 * appears nowhere under `apps/web/src`, and a grep for it staying empty is the enforcement.)
 * `tanstack-query-table` names all three by category: DELETION AND DISABLE are two-phase and verified
 * and the browser cannot know a purge succeeded; ANY LIFECYCLE TRANSITION is uncomputable here,
 * because an enable lands in `ready` or `ready_with_warnings` depending on whether this source's live
 * versions carry warnings — a fact about the document that only the server holds. So every one of
 * these invalidates and re-reads, and the row the operator ends up looking at is the server's answer.
 *
 * ── NO `retry`, ANYWHERE, AND THE ABSENCE IS ENFORCED ───────────────────────────────────────────
 * The `retry` identifier is an ESLint error outside `src/lib/query/client.ts`, where mutations are
 * pinned at zero attempts. None of these three carries an `Idempotency-Key` — `reprocess` refuses to
 * honour one by design, since every call is meant to mint a new force nonce — so a replay is a second
 * run, a second version of every item and a second embedding bill. The controls are disabled while
 * pending, which is the double-click half of the same rule.
 *
 * ── WHAT DECIDES WHICH CONTROLS EXIST ───────────────────────────────────────────────────────────
 * Two published facts about the row in hand, and NEITHER is a comparison against `status`:
 *
 *   `deleted_at`               non-null means this source is on its way out and the console's only
 *                              remaining job is to show the purge finishing. No action is offered,
 *                              because every one of them would be refused and the refusal would be
 *                              news to nobody.
 *   `status_permits_retrieval` decides whether the verb is Disable or Enable. It is published
 *                              precisely so a client does not re-derive it: a check spelled "not
 *                              disabled" admits `deleting`, and a sixteenth state would be admitted
 *                              by every negative test in every client.
 *
 * Everything else is left to the server. The transition table has edges this screen cannot compute —
 * a reprocess is refused mid-run, an enable is refused from `failed` — and greying those out would be
 * the state machine reimplemented in a browser, which fails by hiding a move the server would have
 * allowed. The menu item submits; the refusal renders under the row.
 */
export function SourceRowActions({
  source,
  consequence,
}: {
  readonly source: SourceResource;
  /**
   * THE DELETE DIALOG'S CONSEQUENCE, WHEN THE CALLER HAS BETTER NUMBERS THAN THIS ROW DOES.
   *
   * Omitted on the LIST, which is where this component was born and where it stays true prose: `GET
   * .../sources` publishes no counts, on purpose — five extra statements per source means 126
   * queries for a page of 25 — so the list literally cannot say how much a delete removes, and
   * inventing a plausible figure is worse than the prose.
   *
   * Supplied on `/sources/{sourceId}`, where `SourceDetailResource` carries the item, structural and
   * excerpt counts and `kb-ui-patterns`' "state the consequence in specific numbers" is finally
   * answerable. The numbers are built by `deleteConsequenceCounts`, not here: this component takes
   * the whole node so it never learns what a detail resource is, and one screen's copy cannot drift
   * into the other's.
   */
  readonly consequence?: ReactNode;
}) {
  const { orgId, listKey, canManage } = useSourceActions();
  const queryClient = useQueryClient();
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  /**
   * ONE INVALIDATION FOR ALL THREE, on `onSettled` rather than `onSuccess`.
   *
   * A failure is also a reason to re-read: a 422 from the transition table means the row in front of
   * the user says something different from what the database says, which is precisely the case where
   * a stale row is misleading. The key is the LIST PREFIX, so a source that moved to `deleting` — and
   * therefore may have moved between pages under the current sort — is refetched wherever it is.
   */
  const settle = () => {
    void queryClient.invalidateQueries({ queryKey: listKey });
  };

  const reprocess = useMutation<SourceResource, Error, void>({
    mutationFn: () => reprocessSource(orgId, source.id),
    onSettled: settle,
  });

  const toggle = useMutation<SourceResource, Error, void>({
    mutationFn: () =>
      setSourceStatus(
        orgId,
        source.id,
        source.status_permits_retrieval ? SOURCE_DISABLE_TARGET : SOURCE_ENABLE_TARGET,
      ),
    onSettled: settle,
  });

  const remove = useMutation<SourceResource, Error, void>({
    mutationFn: () => deleteSource(orgId, source.id),
    onSettled: () => {
      // CLOSED ON FAILURE TOO. The refusal is rendered in the ROW, and a modal is `inert`-over-the-
      // background by design — an alert left underneath an open dialog is an alert nobody reads.
      // Closing also drops the typed confirmation, so a second attempt is a second deliberate act.
      setConfirmingDelete(false);
      settle();
    },
  });

  const pending = reprocess.isPending || toggle.isPending || remove.isPending;

  /**
   * THE ONE FAILURE LINE FOR THE ROW, in the order the operator would read them.
   *
   * `actionableConflictMessage` first, because the suspended-organization 409 arrives as
   * `internal_dependency` + `retryable: false` — the pair a genuine 500 also carries — and only the
   * envelope's `actionable` flag separates them. Its message is written for this condition and is the
   * one thing that says what to do next.
   *
   * `actionErrorCopy` otherwise, which maps `error_class` to a sentence and never renders the
   * envelope's operator-facing `message`.
   *
   * ── THE 422 IS THE EXCEPTION, AND IT IS AN EXCEPTION TO AN EXCEPTION ────────────────────────────
   * `actionErrorCopy` renders a `validation` envelope's per-field MESSAGES verbatim, on the ground
   * that Laravel validation messages are end-user copy. On these three endpoints they are not: an
   * illegal transition is reported as `ValidationException::withMessages(['status' => …])` carrying
   * `IllegalSourceTransition`'s text, which reads "A row in `deleting` cannot move to `ready`. The
   * legal moves are in App\Enums\SourceState::transitionTable(), which is the only statement of the
   * machine." That names a PHP class to a tenant administrator. So a `validation` failure on this
   * path gets the sentence below — which is true of every refusal that reaches it, since `status` is
   * the only field any of these three requests has — and the operator-facing text is left where the
   * class-mapped path leaves every other one: in the error object, logged, never rendered.
   *
   * Reported upstream rather than papered over: the server-side copy is the thing worth fixing.
   */
  const failure = (error: unknown): string | null => {
    if (error === null || error === undefined) return null;
    const actionable = actionableConflictMessage(error);
    if (actionable !== null) return actionable;
    // `instanceof KbError` — the ONE definition, from `@kb/contracts`. A per-app copy makes every
    // one of these checks false at once, silently.
    if (error instanceof KbError && error.error_class === 'validation') {
      return (
        'This source cannot do that from the state it is in now. Its status may have moved since ' +
        'this page loaded — the list has been refreshed.'
      );
    }
    return actionErrorCopy(error);
  };

  /**
   * THE BANNER BELONGS TO THE ACTION THE USER LAST TOOK, AND TO NO OTHER.
   *
   * This used to read `failure(remove.error) ?? failure(toggle.error) ?? failure(reprocess.error)`,
   * which is every mutation's error forever. TanStack Query clears an error when THAT mutation runs
   * again and nothing clears it when a DIFFERENT one succeeds — so a refused Reprocess left its
   * sentence on screen underneath a Disable that worked, telling the user an action failed when the
   * one they just took did not, about a row whose state has since moved.
   *
   * `submittedAt` and NOT an `onMutate` callback: this app writes no optimistic pre-mutation state
   * anywhere (see `use-file-uploads.ts`'s docblock, which explains why that grep must stay empty).
   * It is `0` while a mutation has never run, so the idle case falls out of the same comparison.
   */
  const latestWrite = [reprocess, toggle, remove].reduce((newest, candidate) =>
    candidate.submittedAt > newest.submittedAt ? candidate : newest,
  );

  const message = latestWrite.submittedAt === 0 ? null : failure(latestWrite.error);

  if (!canManage) {
    // No menu at all rather than one full of disabled items: a viewer who may read this list and
    // change nothing in it should not be given a control to open and discover that. `sources.view`
    // without `sources.manage` is not a combination the platform grants today, so this arm is the
    // fifth-role and changed-grant case rather than an everyday one.
    return <span className="text-muted-foreground">—</span>;
  }

  if (source.deleted_at !== null) {
    /**
     * REMOVAL IS UNDER WAY, SO THERE IS NOTHING LEFT TO OFFER. `deleting` has exactly one legal edge
     * and it goes to `deleted`; every action here would be refused, and a refusal that is news to
     * nobody is a control that should not exist. An em dash rather than three disabled items, matching
     * the read-only row in `features/providers/connection-list.tsx`.
     *
     * WHICH HALF OF THE REMOVAL THIS ROW IS IN IS THE STATUS CELL'S JOB — `deleted_at` says we removed
     * it, `purged_at` says we PROVED it, and `source-columns.tsx` renders that caption under the pill.
     * Saying it here too put the same sentence twice in one row.
     */
    return <span className="text-muted-foreground">—</span>;
  }

  const toggleLabel = source.status_permits_retrieval ? 'Disable' : 'Enable';

  return (
    /**
     * ONE OVERFLOW MENU WITH TEXT ITEMS, NOT THREE BUTTONS IN A ROW — and this is a measurement rather
     * than a preference.
     *
     * `kb-ui-patterns` names the fix in as many words ("the fix is usually one overflow menu with text
     * items"), and the reason showed up at 768px: three text controls plus Name, Type and Status are
     * ~250px wider than the viewport, so the table scrolled inside its container and the FIRST thing
     * scrolled out of sight was this column — the one holding the destructive action, which is exactly
     * what P6 says a horizontal scroller must never hide. Stacking them vertically instead fixed the
     * clipping and tripled every row's height. The menu is ~44px wide at every breakpoint.
     *
     * THE TRIGGER IS ICON-ONLY AND THE DESTRUCTIVE CONTROL IS NOT. `kb-ui-patterns` allows an icon-only
     * control with an accessible name and forbids an icon-only DESTRUCTIVE one; inside the menu every
     * item carries its word, and the trigger names the row it belongs to.
     */
    <div className="flex w-full flex-col items-stretch gap-2 md:items-end">
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          {/* THE ACCESSIBLE NAME CARRIES THE SOURCE'S NAME. Twenty-five triggers called "Actions" is
              twenty-five identical announcements for a screen-reader user and a strict-mode failure
              for every locator. It says the ACTION rather than the icon. */}
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            disabled={pending}
            aria-label={`Actions for ${source.name}`}
            // `ml-auto` at every width: the trailing edge in the table's actions column and in the
            // row-card's action row, which is where `components/data-table.tsx` puts a card's actions
            // (`justify-end`) — so the control is in the same place in both layouts.
            className="ml-auto"
          >
            <MoreHorizontalIcon aria-hidden />
          </Button>
        </DropdownMenuTrigger>
        {/* `align="end"`: the trigger sits at the right edge of the row, and a menu that opens past it
            would be clipped by the table's own scroller. */}
        <DropdownMenuContent align="end">
          <DropdownMenuItem
            onSelect={() => {
              reprocess.mutate();
            }}
          >
            <RefreshCwIcon aria-hidden />
            {reprocess.isPending ? 'Submitting…' : 'Reprocess'}
          </DropdownMenuItem>

          <DropdownMenuItem
            onSelect={() => {
              toggle.mutate();
            }}
          >
            {source.status_permits_retrieval ? (
              <XCircleIcon aria-hidden />
            ) : (
              <PlayIcon aria-hidden />
            )}
            {toggleLabel}
          </DropdownMenuItem>

          {/* `variant="destructive"` is the menu's own treatment — colour AND position AND the word,
              and it opens a dialog rather than performing anything. `onSelect` closes the menu first,
              so focus is not trapped between two overlays. */}
          <DropdownMenuItem
            variant="destructive"
            onSelect={() => setConfirmingDelete(true)}
          >
            <Trash2Icon aria-hidden />
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      {message === null ? null : (
        // AN INLINE BANNER AND NOT A TOAST. This is an outcome the user has to act on — retry, wait
        // for a run to finish, or ask an operator to lift a suspension — and a toast for that is a
        // message that disappears while they are still reading it.
        <Alert variant="destructive" className="text-left">
          <AlertDescription>{message}</AlertDescription>
        </Alert>
      )}

      {confirmingDelete ? (
        <ConfirmDestructiveDialog
          open={confirmingDelete}
          onOpenChange={setConfirmingDelete}
          title="Delete source"
          /**
           * ── THE CONSEQUENCE, AND EVERY NUMBER IN IT IS ONE THE SERVER WILL ACTUALLY HONOUR ──────
           * `kb-ui-patterns` asks for the consequence in specific numbers ("removes 1,204 chunks from
           * 3 bots"). THIS CONSOLE CANNOT SAY THAT AND MUST NOT INVENT IT: no endpoint publishes a
           * chunk, item, version or bot count for a source. The server computes exactly such a
           * summary — `SourceService::delete()` builds one through `childSummary()` — and writes it
           * to the AUDIT ROW, where no client can read it. That gap is reported, not papered over
           * with a plausible figure.
           *
           * So the numbers here are the ones the response supports: TWO PHASES, of which this button
           * performs the FIRST. Everything else is stated as what it is.
           *
           * WHY EACH CLAUSE IS TRUE. "Stops answering immediately" — `destroy` sets one column and
           * the tenant filter matches `source_status` POSITIVELY against the two ready states, so
           * exclusion takes effect on the next query with no job needing to succeed. "The row stays"
           * — the response IS the source, with `status: deleting` and `deleted_at` set, which is why
           * it keeps a place in this list. "Nothing is removed yet" — this request dispatches NO
           * purge; phase 2 is a separate worker, and `purged_at` stays null until it has been
           * verified. "Cannot be undone" — `deleting` has exactly one legal edge and it goes to
           * `deleted`; there is no path back, and a second delete is refused rather than repeated.
           */
          consequence={
            consequence ?? (
              <>
                This is phase 1 of 2. “{source.name}” stops answering questions immediately — that
                takes effect on the next question anyone asks, and no background job has to succeed
                first. Nothing has been removed yet: the row stays in this list as Deleting while a
                background purge removes its text, its vectors and its stored file, and it is marked
                Purge verified only once that has been proven. There is no way back from either
                step.
              </>
            )
          }
          resourceName={source.name}
          confirmLabel="Delete source"
          pending={remove.isPending}
          onConfirm={() => {
            remove.mutate();
          }}
        />
      ) : null}
    </div>
  );
}
