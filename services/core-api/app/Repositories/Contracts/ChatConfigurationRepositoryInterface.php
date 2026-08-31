<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Bot;
use App\Models\Organization;
use App\Services\Chat\ChatConnection;
use App\Services\Rerank\RerankDesignation;

/**
 * Every read the chat path makes against the CONFIGURATION tables, behind one interface.
 *
 * ── WHY ONE INTERFACE AND NOT FIVE METHODS SPREAD OVER THE EXISTING REPOSITORIES ─────────────
 *
 * These five reads happen together, once per turn, inside the retrieval budget, and they are the
 * only reads on this surface that answer "what is this bot configured as". Spreading them across
 * `BotRepositoryInterface`, `ProviderConnectionRepositoryInterface` and
 * `OrganizationRepositoryInterface` would mean three constructor dependencies on the resolver and
 * three places to forget an organization argument. Keeping them together also makes the whole
 * configuration read greppable when somebody asks why a turn cost four queries.
 *
 * ── EVERY METHOD TAKES THE ORGANIZATION FIRST AND POSITIONALLY ───────────────────────────────
 *
 * The public runtime surface has no session, so the ambient `TenantContext` that backs
 * `#[ScopedBy(OrganizationScope::class)]` is bound from a Valkey record rather than from an
 * authenticated user. That is a perfectly good backstop and it is not the mechanism: the explicit
 * argument is what still holds when the context is stale, empty, or bound by a queue worker that
 * handled another tenant's job a millisecond ago.
 */
interface ChatConfigurationRepositoryInterface
{
    /**
     * One bot by its INTERNAL id, in one organization, or null.
     *
     * Null covers "no such bot" and "another tenant's bot" identically, and the caller renders both
     * as the same 404 — a 403 on a foreign id is an existence oracle on this surface.
     */
    public function botForOrg(string $organizationId, string $botId): ?Bot;

    /**
     * One bot by its PUBLIC identifier, across every organization, or null.
     *
     * ── THIS IS THE ONE ORG-AGNOSTIC READ ON THE CHAT PATH, AND IT HAS TO BE ────────────────
     *
     * `public_bot_id` is the value printed into a customer's page source. The SDK bootstrap is
     * pre-tenancy by construction: nothing has established an organization yet, and the whole
     * purpose of the lookup is to DISCOVER which one this bot belongs to. There is no organization
     * to scope by, and inventing one from request input is the escalation the surface exists to
     * prevent.
     *
     * It is safe because the identifier authorizes nothing on its own: 128 bits from the CSPRNG
     * under a globally unique index (`PublicBotIdentifier`), and whether the bot answers is the AND
     * of its status, its access mode and the origin allow-list — none of which this string carries.
     * The organization comes OUT of the row and is bound as the tenant context from there.
     *
     * // tenancy-exempt: the public bot identifier is the tenant-DISCOVERY key for an
     * // unauthenticated surface; there is no organization to scope by until this row is read, and
     * // the identifier grants nothing by itself (see the paragraph above).
     */
    public function botByPublicId(string $publicBotId): ?Bot;

    /**
     * The organization row a chat turn is metered and quota-checked against.
     */
    public function organization(string $organizationId): ?Organization;

    /**
     * The bot's own chat connection, joined to the `provider_models` row it names — or null when the
     * bot is unconfigured, or names a row that has since been deleted or disabled.
     *
     * Null is a REFUSAL at the call site, never a fallback to some other model: answering from a
     * model the tenant did not configure is the defect `kb-error-taxonomy`'s "unknown model does not
     * fall back" footnote spends a paragraph on.
     */
    public function chatConnection(string $organizationId, Bot $bot): ?ChatConnection;

    /**
     * §8.7's ordered fallback chain for one bot, by `position`.
     *
     * Empty is a normal state and means "no fallback". A link whose model row has been deleted or
     * disabled is SKIPPED rather than refused: the chain is a degradation path, and refusing the
     * whole turn because the third rung rotted would take a working primary down with it.
     *
     * @return list<ChatConnection>
     */
    public function fallbackConnections(string $organizationId, string $botId): array;

    /**
     * The connection that can embed a query into the space named by `(provider, model)`.
     *
     * ── THE SPACE IS THE INPUT, NOT THE DESIGNATION ─────────────────────────────────────────
     *
     * `$provider` and `$model` are parsed out of the source versions' own
     * `embedding_model_version` (ADR-035), so this answers "who can embed into the space the corpus
     * is actually in" rather than "who does this organization embed with today". A designation that
     * moved after those versions were indexed must not change how their queries are embedded.
     *
     * ── WHEN SEVERAL CONNECTIONS SERVE THE SAME PAIR ────────────────────────────────────────
     *
     * They are interchangeable for this purpose: `(provider, model)` IS the vector space (ADR-031),
     * so two connections agreeing on it produce comparable vectors. `$preferredConnectionId` — the
     * organization's current designation — wins when it matches, and otherwise the oldest row does,
     * deterministically. That is a tiebreak between equals, which is a different thing from
     * ADR-031's refusal, where the two candidates DISAGREED on the pair.
     */
    public function embeddingConnectionForSpace(
        string $organizationId,
        string $provider,
        string $model,
        ?string $preferredConnectionId,
    ): ?ChatConnection;

    /**
     * The organization's designated ranking connection, or null.
     *
     * Null is a normal, common state and NOT an error: reranking is capability-gated since ADR-030,
     * and a bot whose organization has no ranking-capable connection runs stage 12 on branch
     * agreement instead (`bge-reranker`). A designation naming a row that has been deleted is also
     * null — the same degraded path, reached from a different cause.
     */
    public function rerankConnection(string $organizationId, RerankDesignation $designation): ?ChatConnection;
}
