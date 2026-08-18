'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';

import { MailPlusIcon } from 'lucide-react';

import { DataTableShell } from '@/components/data-table';
import { EmptyState, ErrorState } from '@/components/states';
import { StatusPill } from '@/components/status-pill';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { actionErrorCopy } from '@/features/auth/action-error';
import { roleLabel } from '@/features/auth/roles';
import { useCooldown } from '@/features/auth/use-cooldown';
import { KbError, type InvitationResource } from '@kb/contracts';

import {
  formatTimestamp,
  invitationStatusKind,
  invitationStatusLabel,
  isLiveInvitation,
  resendInvitation,
  revokeInvitation,
} from './api';

/**
 * The organization's invitations, with Revoke and Resend.
 *
 * ── EVERY ROW IS LISTED, INCLUDING THE TERMINAL ONES ─────────────────────────────────────────────
 * `InvitationCollectionResource` returns accepted, revoked and expired rows too, deliberately: `status`
 * is DERIVED server-side from three timestamps, and hiding the terminal rows makes "why can I not
 * re-invite this address" unanswerable from the UI — the answer is usually a pending row for the same
 * address, which the invite form's 422 names but only this list can show. Actions are offered on the
 * live ones only; see `isLiveInvitation`, whose set is derived from the server's own refusals rather
 * than guessed.
 *
 * ── PLAIN MARKUP, SERVER ORDER ───────────────────────────────────────────────────────────────────
 * No client-side sort, filter or pagination (`tanstack-query-table` NN6): the server owns the tenant
 * filter, so ordering is its business, and a client sort over a set the browser cannot see is an
 * ordering that is wrong and looks right.
 *
 * ── NOTHING HERE IS OPTIMISTIC ───────────────────────────────────────────────────────────────────
 * Revoke is a deletion and Resend mints a token; NN7 and the optimistic-update rules put both firmly
 * out of bounds. The browser cannot compute the new derived `status`, cannot compute the renewed
 * `expires_at` (it comes from `config('kb.invitation_ttl_hours')`), and must not claim a capability was
 * re-issued when the mail queue may have refused it. Both actions therefore invalidate and re-read.
 */
export function InvitationList({
  orgId,
  invitationsKey,
  invitations,
  isPending,
  error,
}: {
  readonly orgId: string;
  /** Built by `useOrgKey()` in the parent and passed down, so a row's invalidation cannot address a
   *  different cache entry from the one this list reads. */
  readonly invitationsKey: readonly unknown[];
  readonly invitations: readonly InvitationResource[] | undefined;
  readonly isPending: boolean;
  readonly error: Error | null;
}) {
  return (
    <section aria-labelledby="invitations-heading" className="flex flex-col gap-3">
      <h2 id="invitations-heading" className="text-h2">
        Invitations
      </h2>

      {/* The skeleton mirrors the loaded table rather than being two bars of an unrelated height. */}
      {isPending ? (
        <DataTableShell>
          <div aria-busy="true" className="flex flex-col gap-2 p-card-pad-md">
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-4/5" />
            <Skeleton className="h-4 w-3/5" />
          </div>
        </DataTableShell>
      ) : null}

      {/* Class-mapped copy plus the `request_id`, never the envelope's `message`. A 403 is the normal
          answer for a role without `members.view`, and it is NOT retryable — `ErrorState` reads that
          off the envelope rather than guessing from the sentence. */}
      {error === null ? null : <ErrorState title="Invitations could not be loaded" error={error} />}

      {invitations === undefined ? null : invitations.length === 0 ? (
        <EmptyState
          glyph={MailPlusIcon}
          title="No invitations yet"
          body="Invite someone by email and their invitation will be listed here until they accept it."
        />
      ) : (
        <DataTableShell>
          <Table>
            <caption className="sr-only">Invitations for this organization</caption>
            <TableHeader>
              <TableRow>
                <TableHead scope="col">Email</TableHead>
                <TableHead scope="col">Role</TableHead>
                <TableHead scope="col">Status</TableHead>
                <TableHead scope="col">Expires</TableHead>
                <TableHead scope="col">Invited by</TableHead>
                {/* The actions column is fixed-width and is never the flexible one (P6). */}
                <TableHead scope="col" className="w-64">
                  Actions
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {invitations.map((invitation) => (
                <InvitationRow
                  key={invitation.id}
                  orgId={orgId}
                  invitationsKey={invitationsKey}
                  invitation={invitation}
                />
              ))}
            </TableBody>
          </Table>
        </DataTableShell>
      )}
    </section>
  );
}

/**
 * One invitation, WITH ITS OWN COOLDOWN, and that is the load-bearing part of it being a component.
 *
 * `throttle:invitation-resend` keys on the INVITATION rather than on the actor — 3 per hour — because the
 * flood axis this limiter exists for is a third party's mailbox, not our own capacity: `throttle:admin`
 * is 120/min per (organization, user), which on its own lets one administrator mail one invitee 120 live
 * links a minute with legitimate credentials and nothing else noticing. A screen-level cooldown would
 * therefore be wrong in both directions — it would lock a row that has budget left and unlock one that
 * does not. So the cooldown lives per row, which means one `useCooldown()` per row, which means each row
 * is a component.
 *
 * `retry_after` is SECONDS OFF THE `Retry-After` RESPONSE HEADER (it is not in the JSON envelope). A 429
 * that omits it degrades this to no cooldown at all rather than to a guessed window, because guessing
 * means submitting inside the window we were told to wait and keeping the limiter rejecting.
 */
function InvitationRow({
  orgId,
  invitationsKey,
  invitation,
}: {
  readonly orgId: string;
  readonly invitationsKey: readonly unknown[];
  readonly invitation: InvitationResource;
}) {
  const queryClient = useQueryClient();
  const cooldown = useCooldown();

  // Invalidate-and-re-read, never a cache write: the row the list must show is the one the server
  // derived. `onSettled` and not `onSuccess`, so a 422 ("This invitation can no longer be revoked")
  // also refreshes — that error means the row in front of the user is stale, which is the whole reason
  // the server refused.
  const refreshList = () => {
    void queryClient.invalidateQueries({ queryKey: invitationsKey });
  };

  const revoke = useMutation<unknown, Error, void>({
    mutationFn: () => revokeInvitation(orgId, invitation.id),
    onSettled: refreshList,
  });

  const resend = useMutation<unknown, Error, void>({
    mutationFn: () => resendInvitation(orgId, invitation.id),
    onError: (error) => {
      // The ONLY place this row starts a cooldown. Branching on the class, never on the status: 429 is
      // `rate_limit` and nothing else on this endpoint is.
      if (error instanceof KbError && error.error_class === 'rate_limit') {
        cooldown.start(error.retry_after);
      }
    },
    onSettled: refreshList,
  });

  const live = isLiveInvitation(invitation.status);
  const cooling = cooldown.remaining > 0;
  // ONE slot for both actions' errors: they are mutually exclusive in practice (the buttons disable each
  // other while pending) and two alerts in one table cell is a layout nobody reads.
  const actionError = resend.error ?? revoke.error;

  return (
    <TableRow>
      <TableCell className="font-medium break-all">{invitation.email}</TableCell>
      <TableCell>
        <Badge>{roleLabel(invitation.role)}</Badge>
      </TableCell>
      <TableCell>
        {/* Colour AND a glyph AND the word. An expired invitation is a warning rather than a failure,
            because it can still be resent — a different next step from one that was revoked. */}
        <StatusPill
          status={invitationStatusKind(invitation.status)}
          label={invitationStatusLabel(invitation.status)}
        />
      </TableCell>
      <TableCell>{formatTimestamp(invitation.expires_at)}</TableCell>
      <TableCell className="text-muted-foreground">{invitation.invited_by_name}</TableCell>
      <TableCell>
        {live ? (
          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                // Disabled while EITHER action is in flight, and while the recipient's own limiter window
                // is open. A mutation with no `Idempotency-Key` must never be retried by anything,
                // including a double-click — and a resend is emphatically not idempotent: every call mints
                // a NEW token and kills the previously mailed link.
                disabled={resend.isPending || revoke.isPending || cooling}
                // The accessible name carries the ADDRESS, because a table of six identical "Resend"
                // buttons is unusable with a screen reader and ambiguous in a Playwright locator.
                //
                // `Resend to …` and NOT `Resend invitation to …`: role-name matching is a
                // case-insensitive SUBSTRING in both Playwright and vitest-browser, and the longer form
                // CONTAINS the invite form's own "Send invitation" — so every locator for the submit
                // button would resolve to two elements and fail on strict mode. Measured, in
                // tests/components/members-screen.test.tsx. Accessible names on one screen are a
                // namespace, and overlapping ones cost a reader the same ambiguity they cost a locator.
                aria-label={`Resend to ${invitation.email}`}
                onClick={() => {
                  resend.mutate();
                }}
              >
                {resend.isPending ? 'Sending…' : 'Resend'}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={revoke.isPending || resend.isPending}
                aria-label={`Revoke invitation for ${invitation.email}`}
                onClick={() => {
                  revoke.mutate();
                }}
              >
                {revoke.isPending ? 'Revoking…' : 'Revoke'}
              </Button>
            </div>

            {/* polite, not assertive: it updates once a second and must not interrupt a screen reader
                mid-row. */}
            <p aria-live="polite" className="text-muted-foreground text-caption">
              {cooling ? `Try again in ${cooldown.remaining}s.` : ''}
            </p>
          </div>
        ) : (
          // No control at all rather than a disabled one: an accepted or revoked invitation has nothing
          // left to do, and a button that cannot act is a question the administrator has to answer.
          <span className="text-muted-foreground">—</span>
        )}

        {actionError === null ? null : (
          <Alert variant="destructive" className="mt-2">
            {/* `actionErrorCopy` and not `applyAuthError`: a button has no field to key an error to, and
                the server keys these 422s on `invitation` — a path no form renders. Those strings
                ("This invitation can no longer be resent.") are Laravel-translated end-user copy and are
                shown verbatim, exactly as `applyServerErrors` shows its orphans; everything else gets a
                class-mapped sentence plus the `request_id`. */}
            <AlertDescription>{actionErrorCopy(actionError)}</AlertDescription>
          </Alert>
        )}
      </TableCell>
    </TableRow>
  );
}
