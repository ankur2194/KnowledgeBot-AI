'use client';

import type { InvitationPreview } from '@kb/contracts';

import { roleLabel } from './roles';

/**
 * What the holder of an invitation token is shown before they act on it.
 *
 * ONE COMPONENT, TWO SCREENS. `/register` (guest -> create an account) and `/invitations/accept`
 * (signed in -> join) show the identical summary, and the whole value of the summary is RECOGNITION:
 * the guest is about to type a password into a page they reached from an email, so the page has to
 * name the organization, the address the invitation is pinned to, and the role — enough for them to
 * say "yes, that is the invitation I was expecting", and nothing more.
 *
 * ── WHAT THE SERVER DELIBERATELY DOES NOT SEND, SO THERE IS NOTHING HERE TO RENDER ────────────────
 * No organization ID (it would let a token holder address a tenant they are not yet in), and no
 * inviter identity (a person's name disclosed to somebody who is not yet inside the tenant). The
 * resource is `{organization_name, email, role, expires_at}` and this component reads all four.
 *
 * ── EVERY VALUE HERE IS TEXT ─────────────────────────────────────────────────────────────────────
 * `organization_name` is tenant-supplied. It is interpolated as a JSX child, which React escapes;
 * nothing on this path is HTML and nothing reaches `dangerouslySetInnerHTML` (which is an ESLint
 * error repo-wide anyway).
 */
export function InvitationPreviewSummary({
  preview,
}: {
  readonly preview: InvitationPreview;
}) {
  return (
    <dl className="flex flex-col gap-3 rounded-lg bg-card-inset p-card-pad-sm text-sm">
      <div className="space-y-1">
        <dt className="text-muted-foreground">Organization</dt>
        <dd className="font-medium">{preview.organization_name}</dd>
      </div>
      <div className="space-y-1">
        <dt className="text-muted-foreground">Invited address</dt>
        {/* The address is DISPLAYED and never SUBMITTED. `registerSchema` has no `email` field at all,
            precisely so an invitee cannot register under somebody else's address — the server reads it
            off the invitation row. Showing it is what lets the holder notice they are about to accept
            an invitation meant for a different mailbox. */}
        <dd className="font-medium break-all">{preview.email}</dd>
      </div>
      <div className="space-y-1">
        <dt className="text-muted-foreground">Role</dt>
        <dd className="font-medium">{roleLabel(preview.role)}</dd>
      </div>
      <div className="space-y-1">
        <dt className="text-muted-foreground">Invitation expires</dt>
        <dd className="font-medium">{formatExpiry(preview.expires_at)}</dd>
      </div>
    </dl>
  );
}

/**
 * ISO 8601 -> something a human reads, in the VIEWER's locale and timezone.
 *
 * An ABSOLUTE instant, not "in 3 days": a relative label needs a ticking clock to stay true and is
 * wrong the moment the tab is left open, and this is the one number that decides whether the person
 * should act now.
 *
 * There is no hydration hazard in using a locale-dependent format even though the server rendered this
 * route: the preview arrives from a BROWSER fetch, so this subtree does not exist in the RSC payload
 * and there is no server-rendered string for it to disagree with.
 *
 * A malformed timestamp falls back to the raw value instead of rendering "Invalid Date". The field is a
 * straight carry off the wire and the wire is not ours to trust twice.
 */
function formatExpiry(isoTimestamp: string): string {
  const at = new Date(isoTimestamp);
  if (Number.isNaN(at.getTime())) return isoTimestamp;

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(at);
}
