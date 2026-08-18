/**
 * Members and invitations for the current organization.
 *
 * ── THIS SERVER COMPONENT PRODUCES NO ORGANIZATION-SCOPED BYTE, AND THAT IS THE POINT ────────────
 * `/settings/members` is ONE URL for every organization an administrator belongs to, because the
 * organization lives in the session cookie and is in none of Next's five cache keys — not the Full Route
 * Cache, not the Data Cache, not the Router Cache, not `unstable_cache`, not `use cache`. So everything
 * rendered here is byte-identical for every tenant: a heading, a sentence, and a client component that
 * renders nothing until a browser fetch answers. The member list, the invitations and the invite form all
 * come from `browser -> Laravel`, keyed in the one cache this app designs itself — the TanStack Query key,
 * which carries `orgId`.
 *
 * There is no `serverFetch` here and there cannot be: `src/lib/api/server.ts` deliberately does not
 * forward the session cookie, because a server-side fetch sends no `Referer`/`Origin`, Sanctum's
 * `fromFrontend()` classifies it third-party, and a perfectly valid cookie is ignored — a 401 that cannot
 * reproduce in devtools. Identity, and therefore the organization, is unreadable from the Next server by
 * construction.
 *
 * `(admin)/layout.tsx` pins `dynamic = 'force-dynamic'` and `revalidate = 0` for every route beneath it,
 * and ESLint bans the `'force-static'` literal in this directory: that value does not ERROR when a route
 * reads request state, it makes the read return an EMPTY value and the Full Route Cache then serves one
 * shell to every organization. Belt, not mechanism — the mechanism is that the browser is the fetcher.
 *
 * ── THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE ───────────────────────────────────────
 * Inviting, revoking and resending are three mutations that Laravel rate-limits (`throttle:admin`, plus
 * `throttle:invitation-resend` keyed on the invitation), authorizes through `members.manage` and
 * `members.manage_owner`, and writes audit rows for. An action would bypass all three: a second business
 * backend by accident. Every mutation is a browser `fetch` to Laravel.
 *
 * ── WHY THE LINK TO THIS PAGE IS UNCONDITIONAL ──────────────────────────────────────────────────
 * `members.view` is held by owner and admin only, so a `knowledge_manager` or an `analyst` who arrives
 * here reads the class-mapped 403 sentence instead of a list. The link on `/settings` is NOT hidden for
 * them: hiding it would require the role, the role is org-scoped, and reading it in a server component is
 * exactly the organization-scoped byte this file must not produce. UI hiding is not authorization anyway —
 * Laravel is the gate, and it answers 403 whether or not the link was rendered.
 */

import { PageHeader } from '@/components/page-header';
import { MembersScreen } from '@/features/members/members-screen';

export default function MembersPage() {
  return (
    <section aria-labelledby="members-page-heading">
      <PageHeader
        title="Members"
        titleId="members-page-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3).
        description="People in this organization, and invitations that have not been accepted yet."
      />
      <MembersScreen />
    </section>
  );
}
