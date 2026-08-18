'use client';

import type { MemberResource } from '@kb/contracts';

import { DataTableCard, DataTableCardField, DataTableCards, DataTableShell } from '@/components/data-table';
import { EmptyState, ErrorState, SkeletonLines } from '@/components/states';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { roleLabel } from '@/features/auth/roles';
import { UsersIcon } from 'lucide-react';

import { formatTimestamp, membershipStatusKind, membershipStatusLabel } from './api';

/**
 * The organization's members: name, address, role, status, join date.
 *
 * ── PLAIN MARKUP, NOT TANSTACK TABLE ─────────────────────────────────────────────────────────────
 * `GET …/members` returns EVERY membership in one unpaginated body, and a table library earns its
 * dependency on the sorting, filtering and pagination this screen must not have: `tanstack-query-table`
 * NN6 says the server owns the tenant filter, so the browser holds one org-scoped page of a set it
 * cannot see, and sorting 25 of 4,000 rows produces an ordering that is wrong and looks right. Rows are
 * rendered IN THE ORDER THE SERVER SENT THEM for the same reason. When this list grows a cursor, the
 * sort and the page both become key segments and go to Laravel — not to a client row model.
 *
 * ── SUSPENDED ROWS ARE SHOWN ─────────────────────────────────────────────────────────────────────
 * `MemberCollectionResource` includes them on purpose: an administrator restoring access has to be able
 * to find the person. `status` is what distinguishes them, which is why the column exists rather than
 * the rows being filtered out — a filtered list makes "why can I not see Bob" unanswerable from the UI.
 *
 * ── EVERY CELL IS TEXT ───────────────────────────────────────────────────────────────────────────
 * Member names are user-supplied. They are interpolated as JSX children, which React escapes; nothing
 * here is HTML and nothing reaches `dangerouslySetInnerHTML`, which is an ESLint error repo-wide.
 */
export function MemberList({
  members,
  isPending,
  error,
}: {
  readonly members: readonly MemberResource[] | undefined;
  readonly isPending: boolean;
  readonly error: Error | null;
}) {
  return (
    <section aria-labelledby="members-heading" className="flex flex-col gap-3">
      <h2 id="members-heading" className="text-h2">
        Members
      </h2>

      {/* FIRST LOAD IS A SKELETON THAT MIRRORS THE LOADED LAYOUT — five rows at the row height, not
          three bars of a different size. A skeleton with the wrong shape makes the page jump when the
          data arrives and gets debugged as a rendering bug. */}
      {isPending ? (
        <DataTableShell>
          <SkeletonLines lines={5} className="p-card-pad-md" />
        </DataTableShell>
      ) : null}

      {/* A class-mapped sentence plus the `request_id`, never the envelope's `message` — that field
          is operator-facing and can carry an internal hostname or raw text from an upstream provider.
          A 403 here is the normal answer for a `knowledge_manager` or an `analyst`: `members.view` is
          held by owner and admin only, because a member list is the org chart and the roles that
          cannot change it have no reason to enumerate it. `ErrorState` reads `retryable` off the
          envelope, so no retry affordance appears on a class that cannot be retried. */}
      {error === null ? null : <ErrorState title="Members could not be loaded" error={error} />}

      {members === undefined ? null : members.length === 0 ? (
        // Unreachable in practice — a member list a member is reading has at least that member in it —
        // and rendered anyway, because an empty table with headers and no body reads as a bug. This is
        // the FIRST-RUN empty rather than the filtered one: this list has no filters to clear.
        <EmptyState
          glyph={UsersIcon}
          title="No members yet"
          body="People you invite will appear here once they accept."
        />
      ) : (
        <>
          <DataTableShell>
            <Table>
              <caption className="sr-only">Members of this organization</caption>
              <TableHeader>
                <TableRow>
                  <TableHead scope="col">Name</TableHead>
                  <TableHead scope="col">Email</TableHead>
                  <TableHead scope="col">Role</TableHead>
                  <TableHead scope="col">Status</TableHead>
                  <TableHead scope="col">Joined</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {members.map((member) => (
                  // `user_id` is the key, and it is the only identity a membership has: the row's
                  // primary key is (organization_id, user_id), which is also why it is not `id`.
                  <TableRow key={member.user_id}>
                    <TableCell className="font-medium">{member.name}</TableCell>
                    <TableCell className="break-all text-muted-foreground">{member.email}</TableCell>
                    <TableCell>
                      <Badge>{roleLabel(member.role)}</Badge>
                    </TableCell>
                    <TableCell>
                      {/* The pill carries a glyph as well as a colour: a status told by colour alone
                          is unreadable in greyscale and under CVD. */}
                      <StatusPill
                        status={membershipStatusKind(member.status)}
                        label={membershipStatusLabel(member.status)}
                      />
                    </TableCell>
                    <TableCell>{formatTimestamp(member.joined_at)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </DataTableShell>

          {/* BELOW 768px A TABLE BECOMES A STACK OF CARDS, never a horizontal scroller — a scroller
              hides the trailing columns, and on the lists that have one that is where the destructive
              action lives (P6).

              THE ROWS ARE RENDERED TWICE AND ONE COPY IS `display: none`. That is a deliberate
              trade-off, not an oversight, so here is both halves of it.

              What it does NOT cost: a screen reader sees exactly one copy. `display: none` removes a
              subtree from the accessibility tree, and both layouts are switched by `hidden`/`md:hidden`
              rather than by opacity or visibility — so no member is announced twice. The hidden copy is
              also never laid out or painted.

              What it DOES cost: React reconciles both, so the node count is 2x. This list is
              unpaginated by server design (see the docblock above), so on a very large organization
              that is a real number. It is acceptable today and should be revisited when this list
              grows a cursor — at which point the page size bounds it anyway.

              The alternative — one `<table>` restyled into cards with `display: block` — was rejected:
              changing `display` on table elements drops their implicit ARIA roles in most browsers, and
              re-adding `role="table"`/`role="row"` by hand is the div-grid reimplementation
              `kb-ui-accessibility` names as losing row and column announcements in at least one screen
              reader. Duplicated markup with correct semantics beats single markup with invented ones. */}
          <DataTableCards>
            {members.map((member) => (
              <DataTableCard key={member.user_id} title={member.name}>
                <DataTableCardField label="Email">
                  <span className="break-all">{member.email}</span>
                </DataTableCardField>
                <DataTableCardField label="Role">{roleLabel(member.role)}</DataTableCardField>
                <DataTableCardField label="Status">
                  <StatusPill
                    status={membershipStatusKind(member.status)}
                    label={membershipStatusLabel(member.status)}
                  />
                </DataTableCardField>
                <DataTableCardField label="Joined">
                  {formatTimestamp(member.joined_at)}
                </DataTableCardField>
              </DataTableCard>
            ))}
          </DataTableCards>
        </>
      )}
    </section>
  );
}
