'use client';

import Link from 'next/link';

import { activeOrganizations } from './session';
import { useSession } from './session-context';

/**
 * The organization the admin is acting in, in the nav strip. READ-ONLY TEXT PLUS A LINK TO
 * `/settings` — never a second switcher.
 *
 * Two reasons it is not one. The switch has a five-step sequence whose first step is "navigate to a
 * neutral shell", and a control mounted in the LAYOUT is mounted on every route including the
 * org-scoped ones, which is exactly the mounted-data-view race step 1 exists to avoid. And a second
 * copy of a five-step sequence is a second place for one of the steps to go missing.
 *
 * It renders nothing at all while the session loads or when there is no current organization, rather
 * than a placeholder: the nav is chrome the server rendered byte-identically for every organization,
 * and a "—" that turns into a name is a layout shift on every page load for no information.
 */
export function CurrentOrgBadge() {
  const session = useSession();
  if (session.status !== 'authenticated' || session.orgId === null) return null;

  const current = activeOrganizations(session).find(
    (organization) => organization.id === session.orgId,
  );
  // The session can name a `current_organization_id` whose membership is no longer active — the
  // server sends the whole list precisely so the client can see that. Rendering the raw id would put a
  // ULID in the nav; rendering nothing is honest, and `/settings` is where the story is told.
  if (current === undefined) return null;

  return (
    <Link
      href="/settings"
      className="text-muted-foreground ml-auto text-sm hover:underline"
      // The role is announced because the name alone ("Acme Research") does not say what the link
      // does. It is not org-scoped chrome in the caching sense: it is rendered in the browser from a
      // browser fetch, and the Next server never produced it.
      aria-label={`Current organization: ${current.name}. Change it in settings.`}
    >
      {current.name}
    </Link>
  );
}
