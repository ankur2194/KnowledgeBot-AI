<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Auto-discovered: App\Models\Conversation -> App\Policies\ConversationPolicy. No `Gate::policy()`
 * call and no provider edit — the `Surface` OrgScopedPolicy needs is constructor-injected by the
 * container binding and set per request by the `surface:*` middleware.
 *
 * ── ONE ABILITY, AND IT IS THE ADMIN REVIEW SURFACE ONLY ──────────────────────────────────────
 *
 * This class does NOT authorize the public runtime. A visitor reading their own transcript is
 * authorized by OWNERSHIP OF THE SESSION, not by an organization role: `ConversationRepository
 * Interface::findForParticipant()` refuses to return a row without an `anonymous_session_id` or a
 * `user_id` predicate, and its "no participant, no row" branch is the whole of that gate. Routing
 * the runtime through this policy instead would be a category error in the dangerous direction —
 * every member of an organization would become able to read every visitor's conversation, which is
 * the leak an ordinary user can perform by changing a ULID in a URL.
 *
 * So the two surfaces answer two different questions and neither is a fallback for the other:
 *
 *   runtime   "does the calling SESSION own this thread?"        participant predicate, 404 on no
 *   admin     "is the caller a member of THIS ROW'S organization "  this class, 403 on no
 *              holding `conversations.view`?"
 *
 * ── THE ORGANIZATION COMES FROM THE ROW, WHICH IS WHY THERE IS A CLASS AT ALL ────────────────
 *
 * `Conversation` is the only model in the six-table graph that holds `organization_id` directly, so
 * it is also the only one that can answer `organizationId()` without a loaded parent — which makes
 * it the one record `OrgScopedPolicy::permit()` can resolve membership from. `Message`,
 * `RetrievalTrace`, `Citation` and `Feedback` all throw rather than answer, deliberately (see
 * tests/Arch/ConversationDoctrineTest.php), and that is why the transcript is authorized ONCE at
 * its conversation rather than per row.
 *
 * ── A PASSING POLICY DOES NOT LICENSE AN UNSCOPED QUERY ─────────────────────────────────────
 *
 * Layer 3 of seven. The admin route nests `{conversation}` under `{organization}` with
 * `->scopeBindings()`, so a foreign or unknown id 404s at BINDING time — before this class is
 * constructed — and every repository method below the conversation still takes `organization_id` as
 * a required positional argument and joins up to `conversations` with it. What this policy refuses
 * is the one case neither of those can see: the row IS in this organization and the caller's role
 * is wrong.
 *
 * ── WHY THERE IS NO `delete`, NO `export` AND NO `end` ─────────────────────────────────────
 *
 * Phase 6a is READ-ONLY. Retention deletion is a scheduled sweep with no actor and no policy (the
 * `conversations_retention_due` claim query is org-agnostic by construction and carries its own
 * `tenancy-exempt` marker); an operator-initiated erasure is `deletion-engineer`'s and will need
 * its own permission rather than an ability on this class, because it destroys a record §18.11
 * treats as evidence.
 */
final class ConversationPolicy extends OrgScopedPolicy
{
    public function view(?User $user, Conversation $conversation): Response
    {
        return $this->permit($user, $conversation, Permission::ConversationsView);
    }
}
