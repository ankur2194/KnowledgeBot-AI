'use client';

import { PlusIcon } from 'lucide-react';
import Link from 'next/link';

import { Button } from '@/components/ui/button';
import { useCurrentOrgId, useSession } from '@/features/auth/session-context';
import { canManageSources } from '@/features/sources/api';

/**
 * The `/sources` page header's action cluster — a link to the upload screen, drawn only for a viewer
 * who could actually use it.
 *
 * ── WHY THIS IS A CLIENT COMPONENT FOR ONE LINK ────────────────────────────────────────────────
 * `sources.manage` is withheld from ANALYST, and states.md is explicit: a user who cannot perform an
 * action does not see a disabled button for it — hide it. The role is per-organization and lives in
 * the session, which is fetched by the BROWSER (`nextjs-app-router` NN1: the Next server holds no
 * session and can produce no organization-scoped byte). So the page header's server component cannot
 * decide this, and the decision has to be made where the session is: here.
 *
 * The bytes this contributes to the server render are the same for every tenant — the session is in
 * `loading` during SSR, so it renders nothing at all — which is what keeps `/sources` a route the
 * Full Route Cache could not leak even if it were allowed to hold it.
 *
 * ── THE LABEL IS NOT A SUBSTRING OF THE OTHER TWO ──────────────────────────────────────────────
 * "Add files" here, "Upload files" in the first-run empty state, "Upload N files" on the form's
 * submit. Role/name matching is a case-insensitive SUBSTRING in both `vitest-browser` and Playwright,
 * so two controls whose names nest resolve to two elements and every query for either one fails as a
 * strict-mode violation — which reads as a broken test rather than as a naming problem.
 */
export function AddFilesAction() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status !== 'authenticated' || orgId === null) return null;

  const role =
    session.organizations.find((organization) => organization.id === orgId)?.role ?? null;
  if (!canManageSources(role)) return null;

  return (
    <Button asChild>
      {/* An `<a>`, so middle-click, copy-address and the browser's own affordances all work. A
          button that navigates is a link that lost its powers. */}
      <Link href="/sources/upload">
        <PlusIcon aria-hidden strokeWidth={1.75} />
        Add files
      </Link>
    </Button>
  );
}
