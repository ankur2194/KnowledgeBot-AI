<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Analytics\AnalyticsSnapshot;
use App\Services\Analytics\AnalyticsWindow;
use App\Services\Analytics\ModelTokenUsage;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * §8.23's dashboard, as JSON.
 *
 * ── THE WINDOW IS ECHOED BACK, AND IT IS NOT DECORATION ─────────────────────────────────────
 *
 * `from` and `until` both have server-side defaults, so a caller that sent neither does not know
 * what period the numbers describe. Echoing the RESOLVED window is what lets a client label the
 * chart, and it is what makes two screenshots comparable.
 *
 * ── NO CREDENTIAL IS REPRESENTABLE HERE, AND THAT IS A PROPERTY OF THE OBJECT UNDERNEATH ────
 *
 * Every field is a count, a sum, a percentile or a rate, plus two vendor identifiers per model row.
 * `AnalyticsSnapshot` has nowhere to put a secret: the repository's projections select
 * `provider_connections.provider` and `provider_models.model` and never a connection id, never
 * `last_four`, never `key_version`. Non-negotiable 9 holds because the aggregate cannot express it,
 * not because this method omits it.
 *
 * ── THE PER-MODEL TILE IS `usage_by_model` AND NOT `tokens_by_model`, WHICH IS NOT A PREFERENCE ─
 *
 * `tests/Contract/OpenApiDocumentTest.php`'s credential sweep refuses any published property name or
 * enum member matching `^(credential|api_key|secret|token|password|ciphertext|kek)`, and it is a
 * PREFIX rule with no notion of a token as a unit of measurement — so `tokens_by_model` is refused
 * and `input_tokens` is not. That guard is deliberately blunter than `AuditLoggerTest`'s
 * `namesABearerCapability()`, because it sweeps the PUBLISHED document rather than an internal map,
 * and blunt is the right posture there: a generated client with a `tokensByModel` field is something
 * a reviewer scanning types for credential leakage stops on every single time.
 *
 * So the field is named for what it is — usage, broken down by model — and this paragraph exists so
 * the next person does not "fix" it back and then widen the sweep to let it through.
 *
 * ── RATES ARE COMPUTED HERE AND SHIPPED BESIDE THEIR COUNTS ─────────────────────────────────
 *
 * A client could divide two integers, and every client would do it slightly differently — in
 * particular around the empty window, where the honest answer is `null` and the natural answer is
 * `0`. `ProviderOutcomeSummary` decides once that no calls means no rate, and this publishes that
 * decision rather than leaving four clients to rediscover it.
 *
 * @property-read AnalyticsSnapshot $resource
 */
final class AnalyticsResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(
        AnalyticsSnapshot $resource,
        private readonly AnalyticsWindow $window,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->resource;

        return [
            'window' => [
                // ISO-8601 with an offset, in UTC, matching every other instant this API emits.
                'from' => $this->window->from->toIso8601String(),
                'until' => $this->window->until->toIso8601String(),
                // Echoed so a client can tell "all bots" from "one bot that has no traffic" — two
                // pages of identical zeroes that mean completely different things.
                'bot_id' => $this->window->botId,
            ],
            'conversations' => $snapshot->conversations,
            'messages' => $snapshot->messages,
            'unique_sessions' => $snapshot->uniqueSessions,
            'latency' => [
                'first_token_p50_ms' => $snapshot->latency->firstTokenP50Ms,
                'first_token_p95_ms' => $snapshot->latency->firstTokenP95Ms,
                'total_p50_ms' => $snapshot->latency->totalP50Ms,
                'total_p95_ms' => $snapshot->latency->totalP95Ms,
            ],
            'provider_calls' => [
                'succeeded' => $snapshot->providerOutcomes->succeeded,
                'failed' => $snapshot->providerOutcomes->failed,
                'error_rate' => $snapshot->providerOutcomes->errorRate(),
                'primary_attempts' => $snapshot->providerOutcomes->primaryAttempts,
                'fallback_attempts' => $snapshot->providerOutcomes->fallbackAttempts,
                'fallback_rate' => $snapshot->providerOutcomes->fallbackRate(),
            ],
            'feedback' => [
                'positive' => $snapshot->feedback->positive,
                'negative' => $snapshot->feedback->negative,
            ],
            'insufficient_evidence_answers' => $snapshot->insufficientEvidenceAnswers,
            'usage_by_model' => array_map(
                static fn (ModelTokenUsage $row): array => [
                    'provider' => $row->provider,
                    'model' => $row->model,
                    'input_tokens' => $row->inputTokens,
                    // REASONING INCLUDED — see App\Services\Usage\UsageArithmetic, which is the one
                    // place that sum is written and the one place the silent under-report is named.
                    'output_tokens' => $row->outputTokens,
                    // A STRING, never a number. `numeric(14, 6)` is exact and a JSON number is a
                    // double in every client this platform has; rendering it as one would put a
                    // rounding error into an invoice reconciliation.
                    'estimated_cost' => $row->estimatedCost,
                    'currency' => $row->currency,
                ],
                $snapshot->tokensByModel,
            ),
            'ingestion' => [
                'succeeded' => $snapshot->ingestion->succeeded,
                'failed' => $snapshot->ingestion->failed,
                'in_flight' => $snapshot->ingestion->inFlight,
            ],
            'storage_bytes_used' => $snapshot->storageBytesUsed,
        ];
    }

    /**
     * The published shape, as JSON Schema 2020-12.
     *
     * Every component is CLOSED (`additionalProperties: false`) and TOTAL (everything declared is
     * required), which is what `tests/Contract/OpenApiDocumentTest.php`'s `schemaViolations()`
     * relies on to detect both an undocumented field and a missing one.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        $nullableInt = static fn (string $description): array => [
            'type' => ['integer', 'null'],
            'description' => $description,
        ];

        return [
            'AnalyticsWindow' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The RESOLVED window every number on this page describes. Echoed '
                    .'because `from` and `until` both have server-side defaults, so a caller that '
                    .'sent neither would not know what period it is looking at.',
                'required' => ['from', 'until', 'bot_id'],
                'properties' => [
                    'from' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Inclusive start. The window is half-open [from, until).',
                    ],
                    'until' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Exclusive end.',
                    ],
                    'bot_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'The bot every tile was restricted to, or null for the '
                            .'whole organization. Present so a client can tell "all bots" from '
                            .'"one bot with no traffic".',
                    ],
                ],
            ],

            'AnalyticsLatency' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'Percentiles over SUCCEEDED provider attempts. Percentiles and not '
                    .'a mean: one 55-second timeout among ninety-nine 900 ms answers averages to a '
                    .'number no user experienced. Discrete percentiles, so each value belongs to a '
                    .'real request and can be found in the log.',
                'required' => [
                    'first_token_p50_ms', 'first_token_p95_ms', 'total_p50_ms', 'total_p95_ms',
                ],
                'properties' => [
                    'first_token_p50_ms' => $nullableInt(
                        'Median time to first token. NULL means nothing to measure — no successful '
                        .'streaming call in the window — and is a different fact from 0.',
                    ),
                    'first_token_p95_ms' => $nullableInt('95th percentile time to first token.'),
                    'total_p50_ms' => $nullableInt('Median total answer time.'),
                    'total_p95_ms' => $nullableInt('95th percentile total answer time.'),
                ],
            ],

            'AnalyticsProviderCalls' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'Provider outcomes. `cancelled` and `pending` attempts are in '
                    .'NEITHER outcome count: a user closing the tab is not a provider error, and '
                    .'counting it as one hides real outages behind ordinary behaviour.',
                'required' => [
                    'succeeded', 'failed', 'error_rate',
                    'primary_attempts', 'fallback_attempts', 'fallback_rate',
                ],
                'properties' => [
                    'succeeded' => ['type' => 'integer', 'description' => 'Attempts that returned an answer.'],
                    'failed' => ['type' => 'integer', 'description' => 'Attempts that returned an error class.'],
                    'error_rate' => [
                        // `number` AND NOT `["number","null"]` PLUS A FORMAT: a rate of exactly 1.0
                        // or 0.0 reaches the wire as the INTEGER `1` or `0`, because PHP drops a
                        // zero fraction unless JSON_PRESERVE_ZERO_FRACTION is passed and Laravel
                        // does not pass it. JSON Schema's `number` admits an integer, so the
                        // contract is correct — but a generated client typed as `float` will see an
                        // int, and that is worth knowing before somebody "fixes" it with a cast.
                        'type' => ['number', 'null'],
                        'description' => 'failed / (succeeded + failed), or null when nothing '
                            .'finished. Null and not 0: "no calls" and "no failures" are different '
                            .'facts, and rendering 0% for an organization that made no calls '
                            .'reports health nobody measured.',
                    ],
                    'primary_attempts' => [
                        'type' => 'integer',
                        'description' => 'Attempts with empty `fallback_metadata` — one per turn, '
                            .'so this IS the turn count and it does not shrink when retention '
                            .'sweeps the conversations.',
                    ],
                    'fallback_attempts' => [
                        'type' => 'integer',
                        'description' => 'Attempts reached after a primary attempt failed.',
                    ],
                    'fallback_rate' => [
                        'type' => ['number', 'null'],
                        'description' => 'fallback_attempts / primary_attempts, or null when there '
                            .'were no turns. It may legitimately exceed 1.0 — a turn that fell back '
                            .'twice writes two attempts — and is deliberately not clamped, because '
                            .'clamping hides exactly the configuration worth looking at.',
                    ],
                ],
            ],

            'AnalyticsFeedback' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The thumbs, split. Two counts and not a ratio: 1/0 and 40000/0 '
                    .'are both "100%" and only one of them is evidence. Comments are deliberately '
                    .'absent — free text from a stranger does not belong in an aggregate.',
                'required' => ['positive', 'negative'],
                'properties' => [
                    'positive' => ['type' => 'integer', 'description' => 'Positive ratings in the window.'],
                    'negative' => ['type' => 'integer', 'description' => 'Negative ratings in the window.'],
                ],
            ],

            'AnalyticsModelUsage' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One (provider, model, currency) row of the token and cost tile. '
                    .'Input and output stay apart because they are priced apart; a single total '
                    .'cannot be costed.',
                'required' => [
                    'provider', 'model', 'input_tokens', 'output_tokens', 'estimated_cost',
                    'currency',
                ],
                'properties' => [
                    'provider' => ['type' => 'string', 'description' => 'The vendor the call was billed to.'],
                    'model' => ['type' => 'string', 'description' => 'The vendor model id, verbatim.'],
                    'input_tokens' => [
                        'type' => 'integer',
                        'description' => 'Total input tokens, CACHE READS AND WRITES INCLUDED — '
                            .'`provider_calls.input_tokens` is already the normalized total and the '
                            .'two cache columns are a breakdown of it, so adding them would '
                            .'double-count every cached token.',
                    ],
                    'output_tokens' => [
                        'type' => 'integer',
                        'description' => 'Output tokens INCLUDING reasoning tokens. Every vendor '
                            .'here bills reasoning at the output rate, so a figure over bare '
                            .'`output_tokens` under-reports a reasoning turn silently.',
                    ],
                    'estimated_cost' => [
                        'type' => ['string', 'null'],
                        'description' => 'Estimated cost as an exact decimal STRING, or null when '
                            .'the model has no recorded price — which is a different fact from a '
                            .'cost of zero. A string and not a number: a JSON number is a double.',
                    ],
                    'currency' => [
                        'type' => ['string', 'null'],
                        'description' => 'ISO 4217 code the cost is expressed in. It travels WITH '
                            .'the number because summing two currencies is a silent wrong answer, '
                            .'which is also why there is no organization-wide cost total.',
                    ],
                ],
            ],

            'AnalyticsIngestion' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'Ingestion outcomes over source VERSIONS created in the window — '
                    .'not sources. A source is ingested many times; counting sources would answer a '
                    .'different question that never changes when an ingestion fails.',
                'required' => ['succeeded', 'failed', 'in_flight'],
                'properties' => [
                    'succeeded' => [
                        'type' => 'integer',
                        'description' => 'Versions that reached `ready` or `ready_with_warnings`. A '
                            .'version with warnings IS searchable, so filing it as a failure would '
                            .'report an outage that is not happening.',
                    ],
                    'failed' => ['type' => 'integer', 'description' => 'Versions that reached `failed`.'],
                    'in_flight' => [
                        'type' => 'integer',
                        'description' => 'Versions still moving. Published so succeeded + failed + '
                            .'in_flight accounts for every version created in the window.',
                    ],
                ],
            ],

            'AnalyticsResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The organization\'s usage and quality dashboard (§8.23). Every '
                    .'tile is one org-scoped aggregate over PostgreSQL; there is no warehouse and '
                    .'no second datastore.',
                'required' => [
                    'window', 'conversations', 'messages', 'unique_sessions', 'latency',
                    'provider_calls', 'feedback', 'insufficient_evidence_answers', 'usage_by_model',
                    'ingestion', 'storage_bytes_used',
                ],
                'properties' => [
                    'window' => ['$ref' => '#/components/schemas/AnalyticsWindow'],
                    'conversations' => [
                        'type' => 'integer',
                        'description' => 'Threads STARTED in the window. A thread started last '
                            .'month and answered today belongs to last month, so two months\' '
                            .'totals never exceed the year\'s.',
                    ],
                    'messages' => [
                        'type' => 'integer',
                        'description' => 'Turns in the window, both roles, all statuses.',
                    ],
                    'unique_sessions' => [
                        'type' => 'integer',
                        'description' => 'Distinct participants — the anonymous session token or '
                            .'the signed-in user, whichever the thread carries. A conversation may '
                            .'hold exactly one of the two, which is what stops a signed-in visitor '
                            .'being counted twice.',
                    ],
                    'latency' => ['$ref' => '#/components/schemas/AnalyticsLatency'],
                    'provider_calls' => ['$ref' => '#/components/schemas/AnalyticsProviderCalls'],
                    'feedback' => ['$ref' => '#/components/schemas/AnalyticsFeedback'],
                    'insufficient_evidence_answers' => [
                        'type' => 'integer',
                        'description' => 'Answers that refused for want of evidence. This measures '
                            .'a CORRECT refusal rather than a failure, which is why it is its own '
                            .'number: a bot whose count is climbing needs sources, not an engineer.',
                    ],
                    'usage_by_model' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/AnalyticsModelUsage'],
                        'description' => 'One row per (provider, model, currency).',
                    ],
                    'ingestion' => ['$ref' => '#/components/schemas/AnalyticsIngestion'],
                    'storage_bytes_used' => [
                        'type' => 'integer',
                        'description' => 'Bytes currently occupied — a LEVEL, not a flow, so it is '
                            .'NOT windowed. It is the same ledger figure the quota gate refuses '
                            .'against, so this page and the quota screen cannot disagree about '
                            .'whether the organization is over its storage limit.',
                    ],
                ],
            ],
        ];
    }
}
