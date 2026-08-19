'use client';

import type { ProviderModelResource } from '@kb/contracts';
import { providerModelEditDefaults } from '@kb/contracts/forms';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CpuIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import {
  DataTableCard,
  DataTableCardField,
  DataTableCards,
  DataTableShell,
} from '@/components/data-table';
import { EmptyState, ErrorState, SkeletonLines } from '@/components/states';
import { StatusPill } from '@/components/status-pill';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { actionErrorCopy } from '@/features/auth/action-error';
import { deleteConflictMessage, formatTimestamp } from '@/features/providers/api';

import {
  capabilityLabel,
  deleteModel,
  formatPrice,
  formatTokenCount,
  updateModel,
} from './api';
import { EditModelDialog } from './model-form';

/**
 * One connection's model catalog, with an inline enable toggle, Edit and Delete.
 *
 * ── EVERY ROW IS LISTED, INCLUDING THE DISABLED ONES ────────────────────────────────────────────
 * `ProviderModelCollectionResource` returns them all, ordered by model identifier, deliberately: an
 * operator asking "why is this model missing from the bot's dropdown" has to be able to find it, and
 * hiding it makes the question unanswerable from the console while the row sits in the table.
 *
 * ── PLAIN MARKUP, SERVER ORDER ──────────────────────────────────────────────────────────────────
 * No client-side sort, filter or pagination (`tanstack-query-table` NN6): the server owns the tenant
 * filter, so ordering is its business — by model identifier, deterministically, so two reads of an
 * unchanged set are byte-identical — and a client sort over a set the browser cannot see is an
 * ordering that is wrong and looks right. There is therefore no FILTERED empty state on this surface
 * and there must not be a `<FilteredEmptyState>` here: the two are different components on purpose,
 * and offering "Clear filters" to somebody whose catalog is empty is the bug that split exists to
 * prevent.
 *
 * ── NOTHING HERE IS OPTIMISTIC ──────────────────────────────────────────────────────────────────
 * The enable toggle re-reads rather than flipping local state, and the reason is specific rather than
 * doctrinal: the PUT it sends is a REPLACEMENT, and the server answers with the row as stored — the
 * prices come back at the column's scale (`'0.02'` -> `'0.020000'`) and a concurrent edit by somebody
 * else is visible in that answer. An optimistic flip would claim a write that may have been refused,
 * on the one control an operator uses to take a broken model out of service.
 */
export function ModelList({
  orgId,
  connectionId,
  modelsKey,
  models,
  isPending,
  error,
  canManage,
  action,
}: {
  readonly orgId: string;
  readonly connectionId: string;
  /** Built by `useOrgKey()` in the parent and passed down, so a row's invalidation cannot address a
   *  different cache entry from the one this list reads. */
  readonly modelsKey: readonly unknown[];
  readonly models: readonly ProviderModelResource[] | undefined;
  readonly isPending: boolean;
  readonly error: Error | null;
  /** `providers.manage`, inferred from the viewer's role in THIS organization. An affordance, never
   *  authorization: Laravel answers 403 whatever this renders. */
  readonly canManage: boolean;
  /**
   * The section's primary action — `<CreateModelDialog>`, passed in rather than mounted here.
   *
   * IT IS A PROP BECAUSE THE COMPOSITION LAW RUNS ONE WAY: a list renders rows and their four
   * states, and the thing that creates a row is the SECTION's affordance rather than the list's. The
   * screen owns it, so a surface that has no create path (a read-only report over the same rows)
   * renders this component with no action instead of a list that knows about a form.
   */
  readonly action?: ReactNode;
}) {
  return (
    <section aria-labelledby="models-heading" className="flex flex-col gap-3">
      {/* THE ACTION SITS BESIDE THE HEADING, which is where P6 puts a table's primary action — never
          floating, and never repeated in the empty state below: two controls with the same
          accessible name on one screen resolve to two elements for a locator and are two
          indistinguishable choices for a screen-reader user. */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="models-heading" className="text-h2">
          Models
        </h2>
        {action ?? null}
      </div>

      {/* FIRST LOAD IS A SKELETON THAT MIRRORS THE LOADED LAYOUT — rows at the row height, not three
          bars of a different size. A skeleton with the wrong shape makes the page jump when the data
          arrives and gets debugged as a rendering bug. Refetch is NOT a skeleton: data already on
          screen and still correct stays on screen. */}
      {isPending ? (
        <DataTableShell>
          <SkeletonLines lines={4} className="p-card-pad-md" />
        </DataTableShell>
      ) : null}

      {/* A class-mapped sentence plus the `request_id`, never the envelope's `message` — that field is
          operator-facing and can carry an internal hostname or raw text from an upstream provider. A
          403 here is the normal answer for an ANALYST; a knowledge_manager holds `providers.view` and
          reads the catalogue fine. `ErrorState` reads `retryable` off the envelope, so no retry
          affordance appears on a class that cannot be retried. */}
      {error === null ? null : (
        <ErrorState title="This connection’s models could not be loaded" error={error} />
      )}

      {models === undefined ? null : models.length === 0 ? (
        // FIRST-RUN EMPTY, and this list has no filters to clear. No `action` prop on the state
        // itself — the section header already carries "Register model" for anyone who may use it,
        // and a second control with the same name is ambiguous for a locator and for a screen
        // reader alike. For a knowledge_manager, who holds `providers.view` and nothing else, the
        // header renders no button at all and the copy below says who can add one, rather than
        // offering a primary action that is an invitation to a 403.
        <EmptyState
          glyph={CpuIcon}
          title="No models registered on this connection"
          body={
            canManage
              ? 'Register one with the button above and it will be listed here. Until a model is registered, this credential can pay for nothing — a connection on its own answers no question and indexes no document.'
              : 'Nobody has registered a model under this credential yet. An owner or an admin can add one.'
          }
        />
      ) : (
        <>
          <DataTableShell>
            <Table>
              <caption className="sr-only">
                Models registered under this provider connection
              </caption>
              <TableHeader>
                <TableRow>
                  <TableHead scope="col">Model</TableHead>
                  <TableHead scope="col">Capabilities</TableHead>
                  <TableHead scope="col">Context</TableHead>
                  <TableHead scope="col">Max output</TableHead>
                  {/* The unit is in the HEADER, once, rather than repeated in twenty cells. */}
                  <TableHead scope="col">Price / 1M in</TableHead>
                  <TableHead scope="col">Price / 1M out</TableHead>
                  <TableHead scope="col">Available</TableHead>
                  {/* The actions column is fixed-width and is never the flexible one (P6). */}
                  <TableHead scope="col" className="w-40">
                    Actions
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {models.map((row) => (
                  <ModelRow
                    key={row.id}
                    orgId={orgId}
                    connectionId={connectionId}
                    modelsKey={modelsKey}
                    row={row}
                    canManage={canManage}
                  />
                ))}
              </TableBody>
            </Table>
          </DataTableShell>

          {/* BELOW 768px A TABLE BECOMES A STACK OF CARDS, never a horizontal scroller — a scroller
              hides the trailing columns, and this table's trailing column is where the destructive
              action lives (P6). Both layouts are switched by `hidden`/`md:hidden`, so `display: none`
              removes the other from the accessibility tree and each model is announced once. */}
          <DataTableCards>
            {models.map((row) => (
              <DataTableCard
                key={row.id}
                title={
                  <span className="flex flex-wrap items-baseline gap-2">
                    <span>{row.display_name}</span>
                    <span className="font-mono text-sm text-muted-foreground">{row.model}</span>
                  </span>
                }
              >
                <DataTableCardField label="Capabilities">
                  <CapabilityFlags supported={row.supported} />
                </DataTableCardField>
                <DataTableCardField label="Context">
                  {formatTokenCount(row.context_window)}
                </DataTableCardField>
                <DataTableCardField label="Max output">
                  {formatTokenCount(row.max_output_tokens)}
                </DataTableCardField>
                <DataTableCardField label="Price / 1M in">
                  {formatPrice(row.input_price_per_million, row.price_currency)}
                </DataTableCardField>
                <DataTableCardField label="Price / 1M out">
                  {formatPrice(row.output_price_per_million, row.price_currency)}
                </DataTableCardField>
                <DataTableCardField label="Available">
                  {/* THE PILL AND NOT THE SWITCH in the card layout: a toggle in a compact card has
                      no room for the pending/error affordances the table row gives it, and a control
                      that silently fails is worse than a value that is only read. The switch is one
                      breakpoint away, and Edit reaches it at any width. */}
                  <StatusPill
                    status={row.enabled ? 'ready' : 'disabled'}
                    label={row.enabled ? 'Available' : 'Not offered'}
                  />
                </DataTableCardField>
                <DataTableCardField label="Added">
                  {formatTimestamp(row.created_at)}
                </DataTableCardField>
              </DataTableCard>
            ))}
          </DataTableCards>
        </>
      )}
    </section>
  );
}

/**
 * The capability flags of one row.
 *
 * `capabilityLabel` renders an UNRECOGNISED flag verbatim rather than dropping it: the vocabulary is
 * the data plane's and the wire type is an open string array, so a flag this build has never heard of
 * is a real claim the platform will act on. Hiding it would make the row unreadable in exactly the
 * case where somebody needs to read it.
 *
 * An EMPTY list is `None` in words rather than a blank cell — a blank cell reads as a rendering bug,
 * and "this row claims nothing" is also what a malformed stored value renders as.
 */
function CapabilityFlags({ supported }: { readonly supported: readonly string[] }) {
  if (supported.length === 0) {
    return <span className="text-muted-foreground">None</span>;
  }

  return (
    <span className="flex flex-wrap gap-1">
      {supported.map((flag) => (
        <Badge key={flag} variant="outline">
          {capabilityLabel(flag)}
        </Badge>
      ))}
    </span>
  );
}

/**
 * One catalog row, and it is a component because it owns three pieces of state no sibling should see:
 * whether its edit dialog is open, whether its delete confirmation is open, and the two mutations'
 * own errors.
 */
function ModelRow({
  orgId,
  connectionId,
  modelsKey,
  row,
  canManage,
}: {
  readonly orgId: string;
  readonly connectionId: string;
  readonly modelsKey: readonly unknown[];
  readonly row: ProviderModelResource;
  readonly canManage: boolean;
}) {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  /**
   * THE INLINE TOGGLE, AND IT SENDS ALL EIGHT ATTRIBUTES.
   *
   * `PUT …/models/{model}` is a REPLACE: `UpdateProviderModelRequest` rules every field
   * `required` or `present`, so a body carrying only `{enabled}` is a 422 on seven fields the
   * operator never saw. `providerModelEditDefaults(row)` returns the edit schema's OUTPUT type, so
   * the spread below is a complete replacement that cannot have dropped a capability flag or
   * re-scaled a price by omission — the return type says so, and a hand-built literal here is
   * exactly where one of the eight goes missing.
   *
   * THE PRICES GO BACK OUT AS THE STRINGS THEY CAME IN AS. `'0.020000'` is re-sent verbatim, never
   * parsed into a float and re-serialized, which is the difference between a no-op edit and an audit
   * row recording a price change nobody made.
   */
  const toggle = useMutation<unknown, Error, boolean>({
    mutationFn: (next) =>
      updateModel(orgId, connectionId, row.id, {
        ...providerModelEditDefaults(row),
        enabled: next,
      }),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: modelsKey });
    },
  });

  const remove = useMutation<unknown, Error, void>({
    mutationFn: () => deleteModel(orgId, connectionId, row.id),
    onSettled: () => {
      // CLOSED ON FAILURE TOO. The refusal below is rendered in the ROW, and a modal is
      // `inert`-over-the-background by design — an alert left underneath an open dialog is an alert
      // nobody reads. Closing also drops the typed confirmation, so a second attempt is a second
      // deliberate act; for the 409 that is exactly right, because retrying cannot succeed until the
      // designation is cleared on another screen.
      setConfirmingDelete(false);
      // Invalidate-and-re-read, never a cache write. `onSettled` and not `onSuccess`, so the 409 also
      // refreshes: "this row is the designated embedding model" means the row in front of the
      // operator may be stale in a way that matters.
      void queryClient.invalidateQueries({ queryKey: modelsKey });
    },
  });

  /**
   * THE 409 SENTENCE, RENDERED VERBATIM, and it is the only thing that tells the operator what to do
   * next: clear the embedding designation first, read the readiness verdict, then delete.
   *
   * `deleteConflictMessage` IS IMPORTED FROM `features/providers/api.ts` AND IS NOT A SECOND COPY —
   * that is the point, and it is worth the cross-feature import. The inference it performs is
   * subtle: `KbError` carries no HTTP status, the taxonomy has no 409 row (409 is a RENDERING of
   * `internal_dependency` for an unclassified 4xx our own code raised), and `retryable` is false for
   * both a deliberate refusal and a genuine 500 — so the only thing separating them is that every
   * status >= 500 carries one FIXED message, byte-identical in both planes (ADR-029). A second
   * spelling of that reasoning would drift from the first the day the sentinel changes, and the
   * failure mode is an internal hostname rendered to a tenant.
   *
   * Everything else falls through to `actionErrorCopy`, which is the class-mapped sentence plus the
   * `request_id`. There is no retry affordance on either: `internal_dependency` with
   * `retryable: false` is not retryable, and for the 409 specifically retrying cannot help while the
   * designation stands.
   */
  const deleteError =
    remove.error === null
      ? null
      : (deleteConflictMessage(remove.error) ?? actionErrorCopy(remove.error));

  /** The toggle's own failure. It has no 409 case of its own, so it is always the class-mapped copy. */
  const toggleError = toggle.error === null ? null : actionErrorCopy(toggle.error);

  return (
    <TableRow>
      <TableCell className="font-medium">
        <span className="flex flex-col">
          <span>{row.display_name}</span>
          {/* THE IDENTIFIER, MONOSPACED, so it can be compared against a vendor dashboard in another
              tab without counting characters. It is immutable and there is no control for it
              anywhere on this screen. */}
          <span className="font-mono text-sm text-muted-foreground">{row.model}</span>
        </span>
      </TableCell>
      <TableCell>
        <CapabilityFlags supported={row.supported} />
      </TableCell>
      <TableCell>{formatTokenCount(row.context_window)}</TableCell>
      <TableCell>{formatTokenCount(row.max_output_tokens)}</TableCell>
      <TableCell>{formatPrice(row.input_price_per_million, row.price_currency)}</TableCell>
      <TableCell>{formatPrice(row.output_price_per_million, row.price_currency)}</TableCell>
      <TableCell>
        {canManage ? (
          <div className="flex flex-col gap-1">
            {/* THE ACCESSIBLE NAME CARRIES THE MODEL, because a table of identical switches is
                unusable with a screen reader and ambiguous in a locator. `role="switch"` plus
                `aria-checked` comes from the primitive, so the state is announced without a second
                label. */}
            <Switch
              checked={row.enabled}
              disabled={toggle.isPending}
              aria-label={`Available to bots: ${row.display_name}`}
              onCheckedChange={(next) => {
                toggle.mutate(next);
              }}
            />
            {toggleError === null ? null : (
              <span role="alert" className="text-sm text-destructive">
                {toggleError}
              </span>
            )}
          </div>
        ) : (
          // A READ-ONLY pill for a viewer who may not write. Colour AND a glyph AND the word: a
          // status told by colour alone is unreadable in greyscale and under CVD.
          <StatusPill
            status={row.enabled ? 'ready' : 'disabled'}
            label={row.enabled ? 'Available' : 'Not offered'}
          />
        )}
      </TableCell>
      <TableCell>
        {canManage ? (
          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              {/* Role-name matching is a case-insensitive SUBSTRING in both Playwright and
                  vitest-browser, so these names are checked against the other accessible names on
                  this screen: "Edit GPT-5.6 Sol" does not contain "Edit model" (the dialog's title is
                  a heading, not a button) and "Delete GPT-5.6 Sol" does not contain "Delete model". */}
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={remove.isPending}
                aria-label={`Edit ${row.display_name}`}
                onClick={() => setEditing(true)}
              >
                Edit
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={remove.isPending}
                aria-label={`Delete ${row.display_name}`}
                onClick={() => setConfirmingDelete(true)}
              >
                {remove.isPending ? 'Deleting…' : 'Delete'}
              </Button>
            </div>

            {deleteError === null ? null : (
              <Alert variant="destructive" className="mt-2">
                <AlertDescription>{deleteError}</AlertDescription>
              </Alert>
            )}
          </div>
        ) : (
          // No control at all rather than two disabled ones: a knowledge_manager may read this
          // catalogue and may change nothing in it, and a disabled button is a question they have to
          // answer.
          <span className="text-muted-foreground">—</span>
        )}

        {/* Mounted only while open, so a row carries no dialog state — and no eight-field form state
            seeded from a row that may since have changed — while it is closed. */}
        {editing ? (
          <EditModelDialog
            orgId={orgId}
            connectionId={connectionId}
            modelsKey={modelsKey}
            row={row}
            open={editing}
            onOpenChange={setEditing}
          />
        ) : null}

        {confirmingDelete ? (
          // TYPED CONFIRMATION, not a second click in the same position. The consequence is stated in
          // specifics rather than as "Are you sure?": a bot configured to use this model stops
          // working, and the delete is refused outright while the row is the organization's
          // designated embedding model.
          <ConfirmDestructiveDialog
            open={confirmingDelete}
            onOpenChange={setConfirmingDelete}
            title="Delete model"
            consequence={`This removes ${row.display_name} (${row.model}) from this connection’s catalogue. Bots configured to use it stop working immediately, and anything already embedded through it stays indexed under an identifier this organization no longer offers.`}
            resourceName={row.model}
            confirmLabel="Delete model"
            pending={remove.isPending}
            onConfirm={() => {
              remove.mutate();
            }}
          />
        ) : null}
      </TableCell>
    </TableRow>
  );
}
