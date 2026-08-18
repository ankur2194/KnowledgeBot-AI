/**
 * Organization settings. Placeholder chrome only — no fetch, no data, nothing org-scoped.
 *
 * The organization switcher lands here, and it is a five-step sequence, in this order: navigate to
 * a neutral shell first (a mounted data view renders the instant its cache has anything); await
 * `queryClient.cancelQueries()`; await the switch mutation (`retry: false` — a replayed switch
 * races itself); REPLACE the QueryClient via `useResetQueryClient()`; then `router.refresh()` to
 * drop the Router Cache. Step 4 is the enforcement; the org-prefixed query key is the invariant.
 *
 * Provider credentials are displayed as `masked_key` TEXT outside the form. A credential field is
 * optional-means-unchanged and is never seeded into defaultValues — prefilling it submits
 * "sk-…4a91" as the new key and every provider call then fails `provider_auth`.
 */

import { UsersIcon } from 'lucide-react';
import Link from 'next/link';

import { PageHeader } from '@/components/page-header';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { OrgSwitcher } from '@/features/auth/org-switcher';

/**
 * `<OrgSwitcher/>` is rendered HERE AND NOWHERE ELSE, which is what makes step 1 above a no-op: the
 * switcher already lives on the neutral route, so there is no in-flight window in which a mounted
 * org-scoped view can render from a cache that is about to be replaced. `router.push` is
 * fire-and-forget and cannot be awaited, so the race is removed by placement rather than narrowed by
 * timing.
 *
 * Everything this server component renders is byte-identical for every organization — a heading, a
 * skeleton, a sentence. The switcher itself is a client component whose option list arrives from a
 * browser fetch, so no organization-scoped byte is produced by the Next server, whose five cache keys
 * do not contain the organization.
 */
export default function SettingsPage() {
  return (
    <section aria-labelledby="settings-heading">
      <PageHeader
        title="Settings"
        titleId="settings-heading"
        description="Who can work in this organization, and how its bots reach their providers."
      />

      {/* Sections are separated by --space-8 and each card has ONE job. */}
      <div className="flex flex-col gap-8">
        <Card>
          <CardHeader>
            <CardTitle as="h2">Organization</CardTitle>
            <CardDescription>
              The organization every screen in the console is scoped to.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <OrgSwitcher />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle as="h2">People</CardTitle>
            <CardDescription>Members of this organization and their pending invitations.</CardDescription>
          </CardHeader>
          <CardContent>
            {/* UNCONDITIONAL, and `next/link` rather than `<a>` (ESLint enforces that for an internal
                route). `members.view` is held by owner and admin only, so a knowledge_manager or an
                analyst who follows this link reads the class-mapped 403 sentence rather than a list —
                and the link is still shown to them, because hiding it would need the viewer's role,
                the role is per organization, and reading it here would make this server component
                produce an organization-scoped byte. UI hiding is not authorization: Laravel answers
                403 whether or not the link was rendered. */}
            <Link
              href="/settings/members"
              className="inline-flex items-center gap-2 text-base text-primary underline-offset-4 hover:underline"
            >
              <UsersIcon aria-hidden className="size-4" />
              Members and invitations
            </Link>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle as="h2">Providers</CardTitle>
            <CardDescription>
              Credentials your bots use to reach their models. Keys are shown masked and are never
              returned in full.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div aria-busy="true" className="flex flex-col gap-3">
              <Skeleton className="h-9" />
              <Skeleton className="h-32" />
            </div>
            <p className="mt-3 text-sm text-muted-foreground">Settings load in the browser.</p>
          </CardContent>
        </Card>
      </div>
    </section>
  );
}
