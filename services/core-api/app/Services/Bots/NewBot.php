<?php

declare(strict_types=1);

namespace App\Services\Bots;

use App\Enums\BotAccessMode;
use App\Enums\BotAnswerMode;
use App\Enums\EvidenceThresholdScale;

/**
 * The validated input to a bot CREATE, as a type rather than an array.
 *
 * ── THE THREE COLUMNS THAT HAVE NO MEMBER HERE, AND WHY EACH ONE IS ABSENT ────────────────────
 *
 * `organization_id`   never validated, never posted, never carried in a DTO. It comes from the
 *                     authenticated context as a positional argument on every repository method.
 *                     Over-posting a tenant key is an authorization bug with a 200 response
 *                     (laravel-rbac-policies NN5).
 * `public_bot_id`     SERVER-MINTED, ONCE, AND NOT EDITABLE AT ALL. It is the token every published
 *                     widget snippet, every hosted-chat bookmark and every cached theme stylesheet
 *                     already carries. A client that could SET it could collide with another
 *                     organization's token — the global unique index would refuse the write, which
 *                     turns the column into an existence oracle over the whole platform — and a
 *                     client that could CHANGE it would break every live embed on the customer's own
 *                     site with a 200. Expressing that as a missing validation rule would leave it
 *                     one careless line away from being true again; expressing it as a missing
 *                     MEMBER means the service that performs the create has nowhere to put one.
 * `status`            a bot is created `draft`, always. `BotFactory` states the same thing from the
 *                     fixture side: "created, not configured, not exposed." Creating a bot directly
 *                     into `published` would have to run the publish guard on a row that does not
 *                     exist yet, against a source assignment that cannot exist yet — so the create
 *                     path would carry a second, weaker copy of a check the PATCH already owns.
 *                     Publishing is a state transition and it has a route: `PATCH /bots/{bot}`.
 *
 * `retrieval_configuration_version` is absent for a fourth reason: it is DERIVED. It starts at 1
 * from the column default and moves only when `BotService` writes a retrieval knob, so a client
 * that could set it could make two different configurations claim the same version — which is
 * precisely the identity the §21.5 regression gate replays against.
 *
 * ── EVERY DEFAULT BELOW IS RESTATED FROM THE COLUMN DEFAULT RATHER THAN LEFT TO IT ────────────
 *
 * Deliberately, and for the reason `BotFactory` gives for restating the same numbers: a row created
 * through this path must be describable in full BEFORE it is written, so the created resource can
 * be rendered from the object that was saved rather than from a re-read. The database default is
 * still the authority for every writer that is not this one — a seeder, a repair script — and the
 * two agreeing is asserted rather than assumed.
 *
 * The one number that is NOT restated is `evidence_threshold`, because there is no portable value
 * to restate: the scale is a property of the `(provider, model)` pair and `0.30` is a valid float
 * on every scale, so a default here would move only the refusal rate, only in aggregate, and fail
 * no test. Null, with no default, matching the column.
 */
final readonly class NewBot
{
    /**
     * @param  array<string, string>  $theme  the closed key set of App\Support\Theme\ThemeVocabulary
     */
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $description = null,
        public ?string $welcomeMessage = null,
        public ?string $placeholderText = null,
        public ?string $systemInstruction = null,
        public ?string $answerStyleInstruction = null,
        // Fail-closed, in the column and here: a bot created by a form that forgot the field must
        // not be answerable by the internet.
        public BotAccessMode $accessMode = BotAccessMode::Private,
        public ?string $providerConnectionId = null,
        public ?string $providerModelId = null,
        // docs/07 §12.19 as written: "The default should be strict RAG mode because the project is
        // intended to demonstrate grounded answers."
        public BotAnswerMode $answerMode = BotAnswerMode::Strict,
        // docs/07 §12.7-12.12 as written. STARTING POINTS rather than findings: every one of them
        // moves through an evaluation run with an immutable configuration snapshot, never by
        // intuition (kb-rag-query-contract).
        public int $denseTopK = 20,
        public int $sparseTopK = 20,
        public int $rerankCandidates = 20,
        public int $rerankRetain = 6,
        public ?float $evidenceThreshold = null,
        public ?EvidenceThresholdScale $evidenceThresholdScale = null,
        // The publish guard's only escape hatch, and a separate column from `answer_mode` so that
        // "RAG-first but not yet cleared to publish" stays expressible.
        public bool $allowGeneralAnswers = false,
        public array $theme = [],
        // NULL means "the platform default applies", which is a different fact from a configured
        // limit that happens to equal it.
        public ?int $rateLimitPerMinute = null,
        public ?int $rateLimitPerDay = null,
        public ?int $retentionDays = null,
        public bool $collectEndUserData = false,
        public ?string $consentText = null,
    ) {}
}
