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

import Link from 'next/link';

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
    <section aria-labelledby="settings-heading" className="space-y-6">
      <h1 id="settings-heading" className="text-2xl font-semibold">
        Settings
      </h1>
      <OrgSwitcher />
      {/* UNCONDITIONAL, and `next/link` rather than `<a>` (ESLint enforces that for an internal route).
          `members.view` is held by owner and admin only, so a knowledge_manager or an analyst who follows
          this link reads the class-mapped 403 sentence rather than a list — and the link is still shown to
          them, because hiding it would need the viewer's role, the role is per organization, and reading
          it here would make this server component produce an organization-scoped byte. UI hiding is not
          authorization: Laravel answers 403 whether or not the link was rendered. */}
      <p className="text-sm">
        <Link href="/settings/members" className="underline">
          Members and invitations
        </Link>
      </p>
      <div aria-hidden className="space-y-3">
        <Skeleton className="h-10" />
        <Skeleton className="h-32" />
      </div>
      <p className="text-muted-foreground text-sm">Settings load in the browser.</p>
    </section>
  );
}
