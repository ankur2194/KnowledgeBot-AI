'use client';

import type { ProviderConnectionResource } from '@kb/contracts';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { KeyRoundIcon } from 'lucide-react';
import Link from 'next/link';
import { useState } from 'react';

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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { actionErrorCopy } from '@/features/auth/action-error';
import { actionableConflictMessage } from '@/lib/api/actionable-conflict';

import {
  connectionStatusKind,
  connectionStatusLabel,
  deleteConnection,
  formatTimestamp,
  providerLabel,
} from './api';
import { EditConnectionDialog } from './connection-form';
import { RotateCredentialDialog } from './rotate-credential-dialog';

/**
 * The organization's provider connections, with Edit, Replace key and Delete.
 *
 * ── EVERY ROW IS LISTED, INCLUDING THE DEAD ONES ─────────────────────────────────────────────────
 * `ProviderConnectionCollectionResource` returns revoked and invalid rows too, deliberately: an
 * operator diagnosing "why can I not ingest" has to be able to see the credential that stopped
 * working, and hiding it makes the question unanswerable from the console while the row sits in the
 * table.
 *
 * ── `masked_key` IS A CELL, NEVER AN INPUT ───────────────────────────────────────────────────────
 * It renders here, as monospaced TEXT, in the table — outside every form on the screen. That
 * placement is the design: the value an operator needs to READ (which of my four keys is this?) and
 * the value they must never SUBMIT are the same string, so the safe arrangement is for the reading
 * surface and the writing surface to be different components with no data path between them.
 *
 * ── PLAIN MARKUP, SERVER ORDER ───────────────────────────────────────────────────────────────────
 * No client-side sort, filter or pagination (`tanstack-query-table` NN6): the server owns the tenant
 * filter, so ordering is its business — oldest first, deterministically — and a client sort over a set
 * the browser cannot see is an ordering that is wrong and looks right.
 *
 * ── NOTHING HERE IS OPTIMISTIC ───────────────────────────────────────────────────────────────────
 * Delete is a hard delete and rotation replaces a secret; both invalidate and re-read. The browser
 * cannot compute the new `masked_key` (it comes from the sealed key) and must not claim a key was
 * replaced when the vault may have refused it.
 */
export function ConnectionList({
  orgId,
  connectionsKey,
  connections,
  isPending,
  error,
  canManage,
}: {
  readonly orgId: string;
  /** Built by `useOrgKey()` in the parent and passed down, so a row's invalidation cannot address a
   *  different cache entry from the one this list reads. */
  readonly connectionsKey: readonly unknown[];
  readonly connections: readonly ProviderConnectionResource[] | undefined;
  readonly isPending: boolean;
  readonly error: Error | null;
  /** `providers.manage`, inferred from the viewer's role in THIS organization. An affordance — see
   *  the screen's docblock — and never authorization: Laravel answers 403 whatever this renders. */
  readonly canManage: boolean;
}) {
  return (
    <section aria-labelledby="connections-heading" className="flex flex-col gap-3">
      <h2 id="connections-heading" className="text-h2">
        Connections
      </h2>

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
          403 here is the normal answer for an ANALYST: §6.4 gives them nothing in this catalog, while
          a knowledge_manager holds `providers.view` and reads the list fine. `ErrorState` reads
          `retryable` off the envelope, so no retry affordance appears on a class that cannot be
          retried. */}
      {error === null ? null : (
        <ErrorState title="Provider connections could not be loaded" error={error} />
      )}

      {connections === undefined ? null : connections.length === 0 ? (
        // FIRST-RUN EMPTY, not filtered empty: this list has no filters to clear, and the two are
        // different components on purpose (components/states.tsx). No `action` prop — the create form
        // is already on screen above for anyone who may use it, and for a knowledge_manager who may
        // not, a primary "Add a connection" button here would be an invitation to a 403.
        <EmptyState
          glyph={KeyRoundIcon}
          title="No provider connections yet"
          body={
            canManage
              ? 'Add a key above and it will be listed here. Until one exists, this organization cannot answer a question or index a document.'
              : 'Nobody has added a provider key to this organization yet. An owner or an admin can add one.'
          }
        />
      ) : (
        <>
          <DataTableShell>
            <Table>
              <caption className="sr-only">Provider connections for this organization</caption>
              <TableHeader>
                <TableRow>
                  <TableHead scope="col">Provider</TableHead>
                  <TableHead scope="col">Label</TableHead>
                  <TableHead scope="col">Key</TableHead>
                  <TableHead scope="col">Status</TableHead>
                  <TableHead scope="col">Added</TableHead>
                  {/* The actions column is fixed-width and is never the flexible one (P6). */}
                  <TableHead scope="col" className="w-72">
                    Actions
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {connections.map((connection) => (
                  <ConnectionRow
                    key={connection.id}
                    orgId={orgId}
                    connectionsKey={connectionsKey}
                    connection={connection}
                    canManage={canManage}
                  />
                ))}
              </TableBody>
            </Table>
          </DataTableShell>

          {/* BELOW 768px A TABLE BECOMES A STACK OF CARDS, never a horizontal scroller — a scroller
              hides the trailing columns, and this list's trailing column is where the destructive
              action lives (P6). Both layouts are switched by `hidden`/`md:hidden`, so `display: none`
              removes the other from the accessibility tree and each connection is announced once. */}
          <DataTableCards>
            {connections.map((connection) => (
              <DataTableCard key={connection.id} title={connection.label}>
                <DataTableCardField label="Provider">
                  {providerLabel(connection.provider)}
                </DataTableCardField>
                <DataTableCardField label="Key">
                  <span className="font-mono">{connection.masked_key}</span>
                </DataTableCardField>
                <DataTableCardField label="Status">
                  <StatusPill
                    status={connectionStatusKind(connection.status)}
                    label={connectionStatusLabel(connection.status)}
                  />
                </DataTableCardField>
                <DataTableCardField label="Added">
                  {formatTimestamp(connection.created_at)}
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
 * One connection, and it is a component because it owns three pieces of state no sibling should see:
 * which of its two dialogs is open, and the delete mutation's own error.
 */
function ConnectionRow({
  orgId,
  connectionsKey,
  connection,
  canManage,
}: {
  readonly orgId: string;
  readonly connectionsKey: readonly unknown[];
  readonly connection: ProviderConnectionResource;
  readonly canManage: boolean;
}) {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [rotating, setRotating] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  const remove = useMutation<unknown, Error, void>({
    mutationFn: () => deleteConnection(orgId, connection.id),
    onSettled: () => {
      // CLOSED ON FAILURE TOO, and that is a rendering decision with a reason. The refusal below is
      // rendered in the ROW, and a modal is `inert`-over-the-background by design — an alert left
      // underneath an open dialog is an alert nobody reads. Closing also drops the typed confirmation,
      // so a second attempt is a second deliberate act rather than one click on an armed button; for
      // the 409 that is exactly right, because retrying cannot succeed until the designation is
      // cleared on another screen.
      setConfirmingDelete(false);
      // Invalidate-and-re-read, never a cache write. `onSettled` and not `onSuccess`, so the 409 below
      // also refreshes: "this connection is the designated embedding credential" means the row in
      // front of the user may be stale in a way that matters.
      void queryClient.invalidateQueries({ queryKey: connectionsKey });
    },
  });

  /**
   * THE 409 SENTENCE, RENDERED VERBATIM, and it is the only thing that tells the operator what to do
   * next: clear the embedding designation first, read the readiness verdict, then delete.
   *
   * `actionableConflictMessage` returns null for everything else, so a real failure still gets the
   * class-mapped sentence plus the `request_id` through `actionErrorCopy`. The envelope for this 409
   * carries `error_class: "internal_dependency"` and `retryable: false` — deliberate, documented, and
   * NOT a server fault: there is no retry affordance here, because retrying cannot help while the
   * designation stands.
   */
  const deleteError =
    remove.error === null
      ? null
      : (actionableConflictMessage(remove.error) ?? actionErrorCopy(remove.error));

  return (
    <TableRow>
      <TableCell>
        <Badge>{providerLabel(connection.provider)}</Badge>
      </TableCell>
      <TableCell className="font-medium">
        {/* THE MODEL CATALOGUE, LINKED BEFORE IT EXISTS. `/settings/providers/[connectionId]` is A4a's
            screen and lands next batch; linking it now means that agent does not have to edit this
            file to be reachable, and a link that 404s for one batch is the smaller cost. The label
            says what is there rather than implying it works today — and the sentence under the table
            says so out loud. */}
        <Link
          href={`/settings/providers/${connection.id}`}
          className="text-primary underline-offset-4 hover:underline"
        >
          {connection.label}
        </Link>
      </TableCell>
      <TableCell>
        {/* TEXT. Monospaced so the four characters can be compared against a provider dashboard
            without counting. There is no input on this row and no path from this string into one. */}
        <span className="font-mono text-muted-foreground">{connection.masked_key}</span>
      </TableCell>
      <TableCell>
        {/* Colour AND a glyph AND the word: a status told by colour alone is unreadable in greyscale
            and under CVD. `invalid` is a warning rather than a failure because rotating the key
            repairs it in place; `revoked` is a decision somebody made, so the row is simply inert. */}
        <StatusPill
          status={connectionStatusKind(connection.status)}
          label={connectionStatusLabel(connection.status)}
        />
      </TableCell>
      <TableCell>{formatTimestamp(connection.created_at)}</TableCell>
      <TableCell>
        {canManage ? (
          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              {/* THE ACCESSIBLE NAME CARRIES THE LABEL on every one of these, because a table of
                  identical "Edit" buttons is unusable with a screen reader and ambiguous in a
                  Playwright locator. Role-name matching is a case-insensitive SUBSTRING in both
                  Playwright and vitest-browser, so these are also checked against the other accessible
                  names on this screen: "Replace key for X" does not contain the create form's "Store
                  connection", and "Edit X" does not contain "Edit connection" (the dialog's title is a
                  heading, not a button). */}
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={remove.isPending}
                aria-label={`Edit ${connection.label}`}
                onClick={() => setEditing(true)}
              >
                Edit
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={remove.isPending}
                aria-label={`Replace key for ${connection.label}`}
                onClick={() => setRotating(true)}
              >
                Replace key
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={remove.isPending}
                aria-label={`Delete ${connection.label}`}
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
          // No control at all rather than three disabled ones: a knowledge_manager may read this
          // catalogue and may change nothing in it, and a disabled button is a question they have to
          // answer.
          <span className="text-muted-foreground">—</span>
        )}

        {/* Mounted only while open, so a row carries no dialog state — and, for the rotation dialog,
            no form state holding a secret — while it is closed. */}
        {editing ? (
          <EditConnectionDialog
            orgId={orgId}
            connectionsKey={connectionsKey}
            connection={connection}
            open={editing}
            onOpenChange={setEditing}
          />
        ) : null}

        {rotating ? (
          <RotateCredentialDialog
            orgId={orgId}
            connectionsKey={connectionsKey}
            connection={connection}
            open={rotating}
            onOpenChange={setRotating}
          />
        ) : null}

        {confirmingDelete ? (
          // TYPED CONFIRMATION, not a second click in the same position. The consequence is stated in
          // specifics rather than as "Are you sure?" — deleting a connection takes its whole model
          // catalogue with it, in one transaction, and nothing but the audit row survives.
          <ConfirmDestructiveDialog
            open={confirmingDelete}
            onOpenChange={setConfirmingDelete}
            title="Delete connection"
            consequence={`This deletes ${connection.label} and every model listed under it. Bots configured to use those models stop working immediately.`}
            resourceName={connection.label}
            confirmLabel="Delete connection"
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
