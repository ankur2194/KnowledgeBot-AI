<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Services\Chat\RetrievalScope;

/**
 * The one query that answers "which source versions may this bot search right now".
 *
 * ── WHY IT IS ITS OWN REPOSITORY RATHER THAN A METHOD ON THE SOURCE ONE ──────────────────────
 *
 * It spans three tables that three other repositories each own a slice of —
 * `bot_source_assignments`, `knowledge_sources`, `source_versions` — and it is read on the hottest
 * path in the product, once per chat turn, inside the 1.5 s retrieval budget. Hanging it off
 * `KnowledgeSourceRepositoryInterface` would put a join over the bot graph behind a name that says
 * "source", and the next person needing "the sources a bot can see" would write a second one.
 *
 * ── THE ORGANIZATION IS A REQUIRED POSITIONAL ARGUMENT AND SO IS THE BOT ─────────────────────
 *
 * Both are the scope, not a filter. `#[ScopedBy(OrganizationScope::class)]` on all three models is
 * the backstop; the explicit predicate is the mechanism, and it is what still works when the ambient
 * `TenantContext` is stale — which on this surface is the anonymous-session case, resolved from a
 * Valkey record rather than from a session.
 */
interface RetrievalScopeRepositoryInterface
{
    /**
     * Resolve the scope for one bot in one organization, as of `$now`.
     *
     * ── "ACTIVE RIGHT NOW" IS FOUR PREDICATES AND EVERY ONE OF THEM IS LOAD-BEARING ─────────
     *
     *   1. `bot_source_assignments.enabled` — the operator turned this source off FOR THIS BOT.
     *   2. `knowledge_sources.status` is retrievable and the row is not soft-deleted — the source is
     *      ready, or ready-with-warnings, and has not been disabled or put into deletion.
     *   3. `knowledge_sources.effective_at` / `expires_at` bracket `$now` — a policy document that
     *      has not taken effect yet, or that expired last week, must not answer. NULL on either side
     *      means "no bound", which is the common case.
     *   4. `source_versions.activated_at IS NOT NULL AND retired_at IS NULL` — the version is
     *      PUBLISHED and has not been replaced. This is non-negotiable 5's read side: the previous
     *      version serves until the new one is fully indexed and verified, and a scope that included
     *      a retired version would let it answer beside the version that replaced it.
     *
     * A missing predicate here is not an error anywhere — it is a well-formed citation from a
     * document the tenant thought they had turned off.
     *
     * @param  string  $now  an ISO-8601 instant, passed in rather than read from the clock so a test
     *                       can freeze it and so two calls inside one turn cannot straddle a
     *                       boundary. A string and not a Carbon, because the value is bound into SQL
     *                       and this interface must not decide a timezone: every timestamp in this
     *                       schema is `timestamptz` and the caller formats in UTC.
     */
    public function forBot(string $organizationId, string $botId, string $now): RetrievalScope;
}
