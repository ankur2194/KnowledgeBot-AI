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
 *
 * That rule now has an implementation rather than only a statement: `/settings/providers` renders the
 * mask in a table cell, keeps `credential` out of every `defaultValues` in the feature, and gives the
 * edit form a defaults factory whose parameter type has two members — so `reset({...connection})` is a
 * typecheck failure rather than a review question. See `src/features/providers/connection-form.tsx`.
 */

import { KeyRoundIcon, SlidersHorizontalIcon, UsersIcon } from 'lucide-react';
import Link from 'next/link';

import { PageHeader } from '@/components/page-header';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { OrgSwitcher } from '@/features/auth/org-switcher';

/**
 * `<OrgSwitcher/>` is rendered HERE AND NOWHERE ELSE, which is what makes step 1 above a no-op: the
 * switcher already lives on the neutral route, so there is no in-flight window in which a mounted
 * org-scoped view can render from a cache that is about to be replaced. `router.push` is
 * fire-and-forget and cannot be awaited, so the race is removed by placement rather than narrowed by
 * timing.
 *
 * Everything this server component renders is byte-identical for every organization — a heading, three
 * cards, four links and a sentence. The switcher itself is a client component whose option list arrives
 * from a browser fetch, so no organization-scoped byte is produced by the Next server, whose five cache keys
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
              className="inline-flex items-center gap-2 text-base text-link underline-offset-4 hover:underline"
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
          <CardContent className="flex flex-col gap-2">
            {/* UNCONDITIONAL, mirroring the People card above, and the role split here is WIDER than
                that one: §6.4 gives `providers.view` to owner, admin AND knowledge_manager, because an
                ingestion operator has to be able to see whether the organization can embed at all,
                while `providers.manage` is owner and admin. An analyst who follows this link reads the
                class-mapped 403 sentence rather than a list — and the link is still shown to them,
                because hiding it would need the viewer's role, the role is per organization, and
                reading it here would make this server component produce an organization-scoped byte.
                UI hiding is not authorization: Laravel answers 403 whether or not the link was
                rendered.

                THE SKELETON THAT USED TO SIT HERE IS GONE. It was a placeholder for a settings panel
                that was going to live on this page; the panel became `/settings/providers`, and a
                skeleton that never resolves is a loading state for nothing. */}
            <Link
              href="/settings/providers"
              className="inline-flex items-center gap-2 text-base text-link underline-offset-4 hover:underline"
            >
              <KeyRoundIcon aria-hidden className="size-4" />
              Provider connections
            </Link>
            {/* THE "NOT AVAILABLE YET" SENTENCE THAT USED TO SIT UNDER THIS LINK IS GONE, AND ITS
                REMOVAL IS THE POINT RATHER THAN A TIDY-UP. The link was rendered a batch before
                `(admin)/settings/embedding/page.tsx` existed, deliberately, so that the agent
                building the screen would not have to edit this file to be reachable — and the
                sentence was what made a 404 read as "not yet" instead of as a broken console. The
                route exists now, so the sentence had become a product-visible false statement:
                telling an administrator that a working screen is unavailable is worse than the 404
                it was written to explain. The same sentence sat on `/settings/providers` and went
                with it. */}
            <Link
              href="/settings/embedding"
              className="inline-flex items-center gap-2 text-base text-link underline-offset-4 hover:underline"
            >
              <SlidersHorizontalIcon aria-hidden className="size-4" />
              Embedding designation
            </Link>
          </CardContent>
        </Card>
      </div>
    </section>
  );
}
