'use client';

import type { MemberResource } from '@kb/contracts';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import { actionErrorCopy } from '@/features/auth/action-error';
import { roleLabel } from '@/features/auth/roles';

import { formatTimestamp, membershipStatusLabel } from './api';

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
    <section aria-labelledby="members-heading" className="space-y-3">
      <h2 id="members-heading" className="text-lg font-medium">
        Members
      </h2>

      {isPending ? (
        <div aria-busy="true" className="space-y-2">
          <Skeleton className="h-10" />
          <Skeleton className="h-10" />
          <Skeleton className="h-10" />
        </div>
      ) : null}

      {error === null ? null : (
        <Alert variant="destructive">
          <AlertTitle>Members could not be loaded</AlertTitle>
          {/* A class-mapped sentence plus the `request_id`, never the envelope's `message` — that field
              is operator-facing and can carry an internal hostname or raw text from an upstream provider.
              A 403 here is the normal answer for a `knowledge_manager` or an `analyst`: `members.view` is
              held by owner and admin only, because a member list is the org chart and the roles that
              cannot change it have no reason to enumerate it. */}
          <AlertDescription>{actionErrorCopy(error)}</AlertDescription>
        </Alert>
      )}

      {members === undefined ? null : members.length === 0 ? (
        // Unreachable in practice — a member list a member is reading has at least that member in it —
        // and rendered anyway, because an empty table with headers and no body reads as a bug.
        <p className="text-muted-foreground text-sm">No members yet.</p>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm">
            <caption className="sr-only">Members of this organization</caption>
            <thead className="bg-muted/50">
              <tr>
                <th scope="col" className="p-3 text-left font-medium">
                  Name
                </th>
                <th scope="col" className="p-3 text-left font-medium">
                  Email
                </th>
                <th scope="col" className="p-3 text-left font-medium">
                  Role
                </th>
                <th scope="col" className="p-3 text-left font-medium">
                  Status
                </th>
                <th scope="col" className="p-3 text-left font-medium">
                  Joined
                </th>
              </tr>
            </thead>
            <tbody>
              {members.map((member) => (
                // `user_id` is the key, and it is the only identity a membership has: the row's primary
                // key is (organization_id, user_id), which is also why the field is not called `id`.
                <tr key={member.user_id} className="border-t">
                  <td className="p-3">{member.name}</td>
                  <td className="p-3 break-all">{member.email}</td>
                  <td className="p-3">{roleLabel(member.role)}</td>
                  <td className="p-3">{membershipStatusLabel(member.status)}</td>
                  <td className="p-3">{formatTimestamp(member.joined_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
