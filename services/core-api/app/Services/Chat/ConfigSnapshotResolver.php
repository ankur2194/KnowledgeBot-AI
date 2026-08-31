<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Exceptions\KbException;
use App\Models\Bot;
use App\Models\Organization;
use App\Repositories\Contracts\ChatConfigurationRepositoryInterface;
use App\Repositories\Contracts\RetrievalScopeRepositoryInterface;
use App\Services\Rerank\RerankDesignation;
use App\Support\Kb\EmbeddingSpaceIdentity;
use Carbon\CarbonImmutable;

/**
 * Build the whole configuration snapshot one chat turn is executed against.
 *
 * ═══ EVERYTHING HERE IS ASSEMBLED SERVER-SIDE. NO PART OF IT COMES FROM THE CALLER ══════════
 *
 * The relay carries a snapshot Laravel resolved, not parameters the caller chose. A body field
 * naming an organization, a bot, a model, a top-K or a source id would be privilege escalation
 * rather than configuration — the whole reason `docs/06` §11.2 puts the snapshot in the body is that
 * FastAPI trusts its caller by design, so a value a client can set is a value with no check behind
 * it anywhere.
 *
 * ═══ FOUR REFUSALS, AND EACH ONE IS A THING THAT WOULD OTHERWISE BE SILENT ══════════════════
 *
 *   1. THE BOT IS UNCONFIGURED — no connection, no model, or a model row that has been deleted or
 *      disabled. Refusing beats picking another model: `kb-error-taxonomy`'s unknown-model footnote
 *      spends a paragraph on why answering from a model the tenant did not configure is discovered
 *      months later from a bill or a quality complaint.
 *
 *   2. THE RETRIEVAL SCOPE IS EMPTY — the bot has no assigned source, or every one of them is
 *      disabled, expired, or has no published version. That is a real STATE and the honest answer is
 *      a refusal to run rather than an empty filter: `Filter(must=[])` is a confirmed MATCH-ALL in
 *      Qdrant and `MatchAny(any=[])` matches nothing without saying so, so an empty
 *      `allowed_version_ids` is either every tenant's chunks or none, and neither is what "this bot
 *      has no sources" means.
 *
 *   3. THE SCOPE SPANS TWO EMBEDDING SPACES — the assigned versions were indexed under more than one
 *      `embedding_model_version`. One identity is one Qdrant collection (ADR-035), the wire carries
 *      exactly one, and picking one answers from half the corpus with a well-formed citation and no
 *      error anywhere. This is ADR-031's "a disagreement is a refusal, not a tiebreak" one layer
 *      down, and it is a REACHABLE state: an embedding designation change leaves older versions in
 *      their original space until they are reprocessed.
 *
 *   4. NO CONNECTION CAN EMBED INTO THAT SPACE — the connection whose `(provider, model)` matches
 *      the corpus's identity has been deleted, or its model row disabled. Embedding the question
 *      with any other model puts it in a space the passages are not in, which returns zero
 *      candidates or plausible wrong ones.
 *
 * Every one of the four is `validation` (422), because each is a state of the tenant's own
 * configuration that an administrator can fix, and none of them is retryable.
 *
 * ═══ WHAT IS DELIBERATELY NOT A REFUSAL ════════════════════════════════════════════════════
 *
 * NO RANKING CONNECTION. Reranking has been capability-gated since ADR-030, so an organization with
 * no ranking-capable provider is a normal, common state: `rerank_connection` is null,
 * `rerank_top_n` is 0, stage 11 records `disabled_by_configuration`, and stage 12 selects on branch
 * agreement instead. A refusal there would make the degraded mode an outage.
 *
 * A ROTTED FALLBACK RUNG. The chain is a degradation path; refusing the whole turn because the third
 * rung's model was deleted would take a working primary down with it.
 */
final readonly class ConfigSnapshotResolver
{
    public function __construct(
        private ChatConfigurationRepositoryInterface $configuration,
        private RetrievalScopeRepositoryInterface $scopes,
    ) {}

    /**
     * @throws KbException `validation` (422) — see the four refusals in the class docblock
     */
    public function resolve(Organization $organization, Bot $bot, ?CarbonImmutable $at = null): ConfigSnapshot
    {
        $organizationId = $organization->organizationId();
        $at ??= CarbonImmutable::now('UTC');

        // ── 1. the bot's own model ────────────────────────────────────────────────────────────
        $chat = $this->configuration->chatConnection($organizationId, $bot);

        if ($chat === null) {
            throw KbException::validation(
                'This bot has no usable model configured, so it cannot answer. An administrator '
                .'needs to select a provider connection and a model for it.',
            );
        }

        // ── 2 and 3. what it may search, and which space that corpus is in ───────────────────
        //
        // ONE INSTANT FOR THE WHOLE TURN. `$at` is passed down rather than read from the clock
        // inside the query, so a source whose `expires_at` falls between two reads cannot be inside
        // the scope and outside the answer-cache fingerprint at the same time.
        $scope = $this->scopes->forBot($organizationId, (string) $bot->id, $at->toIso8601String());

        if ($scope->isEmpty()) {
            throw KbException::validation(
                'This bot has no knowledge available to answer from. An administrator needs to '
                .'assign at least one ready source to it.',
            );
        }

        if ($scope->isSplitAcrossEmbeddingSpaces()) {
            // THE COUNT, NEVER THE IDENTITIES. The identity string names the provider and the model
            // the tenant embeds with; this sentence is rendered to whoever is chatting, which on the
            // widget surface is a stranger on somebody else's website.
            throw KbException::validation(
                'This bot\'s sources were indexed with '.count($scope->embeddingIdentities)
                .' different embedding models, so they cannot be searched in a single request. An '
                .'administrator needs to reprocess them so they share one.',
            );
        }

        // ── 4. who can embed a question into that space ──────────────────────────────────────
        $identity = EmbeddingSpaceIdentity::parse($scope->embeddingIdentity());

        if ($identity === null) {
            throw KbException::validation(
                'This bot\'s knowledge carries an embedding identity this service cannot read, so '
                .'the question cannot be embedded in the same space. An administrator needs to '
                .'reprocess the affected sources.',
            );
        }

        // The organization's CURRENT designation is a PREFERENCE and not the answer: it breaks a tie
        // between connections that already agree on `(provider, model)`, and it is ignored when it
        // names a different pair. The corpus decides which space; the designation only decides which
        // of several equivalent accounts pays for it.
        $embedding = $this->configuration->embeddingConnectionForSpace(
            $organizationId,
            $identity->provider,
            $identity->model,
            $organization->embedding_connection_id,
        );

        if ($embedding === null) {
            throw KbException::validation(
                'This bot\'s knowledge was indexed with a model that no current provider connection '
                .'offers, so the question cannot be embedded in the same space. An administrator '
                .'needs to restore that connection or reprocess the sources.',
            );
        }

        // ── the two optional halves ──────────────────────────────────────────────────────────
        $designation = RerankDesignation::fromOrganization($organization);
        $rerank = $designation === null
            ? null
            : $this->configuration->rerankConnection($organizationId, $designation);

        $fallbacks = $this->configuration->fallbackConnections($organizationId, (string) $bot->id);

        return new ConfigSnapshot(
            // COPIED AT QUERY TIME so an edit to the bot afterwards cannot rewrite the explanation
            // of an answer that has already been given. It is also what makes a stored
            // `retrieval_traces` row replayable, which is the whole of its value to §21.5's
            // regression gate.
            retrievalConfigurationVersion: max(1, (int) $bot->retrieval_configuration_version),
            connection: $chat,
            embeddingConnection: $embedding,
            rerankConnection: $rerank,
            fallbackConnections: $fallbacks,
            retrieval: RetrievalSettings::forBot($bot, $rerank !== null),
            allowedVersionIds: $scope->versionIds,
            embeddingModelVersion: $scope->embeddingIdentity(),
            // Stage 14's BOT INSTRUCTIONS section. It is CONFIGURATION and therefore inside the
            // hashed snapshot: changing a bot's instructions must move `configuration_version` and
            // invalidate every cached answer, or the bot keeps answering in its old voice for a TTL.
            // It is rendered into its own labelled section on the far side and is never concatenated
            // with retrieved text (non-negotiable 7).
            botInstructions: $this->instructions($bot),
            // THE MODEL'S CEILING, from its `provider_models` row. There is no per-bot output budget
            // column, so this is the whole of the answer today and it is the honest one: the number
            // the vendor will accept. `max(1, …)` because the column defaults to 0 on a row nobody
            // filled in, and the far side bounds the field `ge=1` — a 0 would be a 422 for a bot
            // whose only fault is an unpopulated catalogue entry, which is worse than a one-token
            // answer that names itself in the transcript.
            maxOutputTokens: max(1, $chat->maxOutputTokens),
            // NULL means "the vendor's own default", which is a real state and is different from any
            // number we could pick. `bots` carries no temperature column, so null is the honest
            // answer rather than a value invented here — reported, not defaulted.
            temperature: null,
            // `none` until a bot can express one. The far side's enum has seven levels, two of which
            // exist only because a vendor ships them, and rounding a tenant's intent to the nearest
            // level we happen to model is exactly what that enum's docblock warns about — so the
            // control plane says nothing rather than guessing.
            reasoningEffort: 'none',
        );
    }

    /**
     * The bot's instruction block, as the far side's single `bot_instructions` string.
     *
     * TWO COLUMNS, ONE FIELD, AND THE JOIN IS NAMED HERE. `system_instruction` is what the bot is;
     * `answer_style_instruction` is how it should sound. The wire carries one string, so they are
     * concatenated with a blank line — and the ORDER is fixed, because the string is hashed into
     * `configuration_version` and a reversed pair would be a different configuration for an
     * unchanged bot.
     *
     * Bounded at the far side's own `max_length=32_000`, which is not a product limit but a wire
     * one: exceeding it is a 422 for a body that is otherwise correct, and truncating here at least
     * leaves the bot answering. Both columns are already bounded by the admin FormRequest, so this
     * is the belt.
     */
    private function instructions(Bot $bot): string
    {
        $parts = [];

        foreach ([$bot->system_instruction, $bot->answer_style_instruction] as $part) {
            if (is_string($part) && trim($part) !== '') {
                $parts[] = trim($part);
            }
        }

        return mb_substr(implode("\n\n", $parts), 0, 32_000);
    }
}
