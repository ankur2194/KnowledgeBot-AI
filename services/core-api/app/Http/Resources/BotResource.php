<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bot;
use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Theme\ThemeVocabulary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `bots` row on the wire, for the ADMIN surface.
 *
 * ── WHAT IS RENDERED HERE AND MUST NOT BE RENDERED ON THE PUBLIC SURFACES ─────────────────────
 *
 * This resource is reached only from `routes/api_admin.php`, behind `auth:sanctum`, `org.member`,
 * `verified` and a `bots.view` policy, and it renders the bot's whole configuration including its
 * SYSTEM INSTRUCTION — to a caller holding `bots.manage`, and only then (next section). That is
 * correct here — the operator wrote it and the edit form has to load it — and it is exactly what
 * the hosted-chat bootstrap, the widget bootstrap and the theme stylesheet must never carry. Those
 * surfaces are Phase B's runtime controllers and they get their OWN resource with their own field
 * list; reusing this one would put a bot's prompt on an unauthenticated endpoint, which is the
 * prompt-disclosure half of `kb-security-baseline`'s layered injection defence.
 *
 * ── `$withInstructions`: THE TWO INSTRUCTION FIELDS ARE A MANAGEMENT-ONLY PROJECTION ──────────
 *
 * `system_instruction` AND `answer_style_instruction` ARE THE REASON THIS FLAG EXISTS, and this
 * paragraph is what a future reader deleting it has to answer first.
 *
 * `bots.view` is the widest permission in the catalog — all four roles hold it (ADR-056) — and the
 * grant's own justification never mentions these two fields. `Permission::BotsView` defends it with
 * "a bot's configuration carries no credential … and no end-user content", which is true and silent
 * about the operator-authored PROMPT; `OrgRole::grants()` and `RolePermissionMatrixTest` justify it
 * with "the assignment screen is a list of bots" and "the name, the model and the answer mode are
 * what make a transcript readable". Rendered unconditionally, this resource handed the full system
 * prompt of every bot in the organization to an ANALYST — a reporting-only role holding `bots.view`
 * and nothing else in the entire catalog — through one `GET …/bots?per_page=100`.
 *
 * THE CODEBASE ALREADY CONTRADICTED ITSELF ABOUT EXACTLY THIS STRING, and the asymmetry is what
 * settles it. `AuditLogger`'s bot allow-list REFUSES `system_instruction` from `details` on the
 * stated ground that it is "the exact string a prompt-injection review is about", in a table that
 * is append-only, long-lived and exportable — read by the organization's own administrators. It is
 * not defensible to withhold a string from the audit table on that reasoning and hand the same
 * string, unredacted, to the narrowest role in the catalog over the API.
 *
 * A NULL AND NOT A MISSING KEY. Both keys stay present in every response: dropping one changes the
 * response SHAPE by caller, which is the one thing a generated client cannot absorb, and
 * `packages/contracts/src/resources/bots.ts` already types both as nullable — so a null costs no
 * contract change, while an absent key would. It is also the honest rendering, because null is
 * already a real value here: the majority of bots have no instruction at all.
 *
 * ── AND `instructions_visible` IS WHY THAT NULL IS NOT AMBIGUOUS ──────────────────────────────
 *
 * THE PROJECTION IS SELF-DESCRIBING, AND THIS FIELD IS THE WHOLE OF IT. A null carries two
 * different facts — "this bot has no instruction" and "you were not shown it" — and the paragraph
 * above deliberately chose a shape in which a client cannot tell them apart from the value alone.
 * That was the right call for the SHAPE and it left a real data-loss path on the CLIENT: a console
 * that re-derives `bots.manage` from the session role by hand, seeds an edit form from the
 * resource, and then PATCHes the form back writes the withheld `null` over an operator-authored
 * prompt — `UpdateBotRequest` rules both fields `sometimes|nullable|string`, so `null` is a
 * legitimate "clear it" and the write returns 200. The row was fetched by a caller the client
 * BELIEVED held `bots.manage`; the server disagreed on that one row, silently, because the only
 * thing that says so is a value that has another meaning.
 *
 * So the server states it. `instructions_visible` is set from the same `$withInstructions` flag
 * that decides the projection — one source, so the two can never disagree — and a client reads
 * "withheld" from it instead of re-deriving a grant map it does not own. False means the two
 * instruction fields in this body are NOT this bot's values and must not be sent back.
 *
 * ADDITIVE, AND THAT IS WHAT MAKES IT SAFE TO ADD. An older client ignores an unknown key; the
 * response shape still does not vary by caller, because this key is present in every rendering
 * with a boolean in it either way. It is the same key-always-present rule the paragraph above
 * argues for, applied to the field that explains the rule.
 *
 * THE FLAG IS DECIDED BY THE CALLER, NOT BY THIS CLASS. `toArray()` must not ask the Gate:
 * `OrgScopedPolicy::permit()` resolves membership per check and is deliberately not memoized across
 * organizations, so a `can()` inside this method is one `organization_users` read PER ROW on a
 * hundred-row page, for an answer that cannot differ between rows of one organization.
 * `BotController` computes it once and passes it in — through `BotCollectionResource` for the list.
 *
 * IT IS A REQUIRED ARGUMENT AND MUST STAY ONE. A default would let a new call site inherit a
 * decision it never made; making it explicit is what forces the sixth caller to answer the question
 * this paragraph is about.
 *
 * ── NOTHING HERE CAN REACH A CREDENTIAL ───────────────────────────────────────────────────────
 *
 * `provider_connection_id` is a ULID and nothing else of the parent connection is rendered — not
 * the label, not the vendor, and above all not `last_four`. A bot listing is not a place to
 * re-render a credential's masked form: it would put the same string in two components for two
 * different reasons, and the day one of them stops being masked the other looks fine. Same call
 * `ProviderModelResource` makes one level up.
 *
 * ── `organization_id` IS NOT RENDERED ─────────────────────────────────────────────────────────
 *
 * The client asked for this row through a URL that already named the organization, so echoing the
 * ownership column adds nothing and puts a tenant identifier into every cached response body. Same
 * call `ProviderConnectionResource` and `ProviderModelResource` both make.
 *
 * ── `public_bot_id` IS RENDERED, AND THAT IS NOT A CONTRADICTION ──────────────────────────────
 *
 * It is a public IDENTIFIER, not a capability: it authorizes nothing on its own, because whether
 * the bot answers is the AND of `BotStatus::isRetrievable()`, `BotAccessMode::allowsAnonymous()`
 * and the origin allow-list. The console has to show it — it is the string a customer pastes into
 * their widget snippet — and there is no state in which it is a secret. What it must never become
 * is the route key of this endpoint; `App\Models\Bot` records why `getRouteKeyName()` is not
 * overridden.
 *
 * @property-read Bot $resource
 */
final class BotResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  bool  $withInstructions  whether the caller holds `bots.manage` on THIS bot's
     *                                  organization — see the class docblock. Required, never
     *                                  defaulted.
     */
    public function __construct(Bot $resource, private readonly bool $withInstructions)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bot = $this->resource;

        return [
            'id' => $bot->id,
            'public_bot_id' => $bot->public_bot_id,
            'name' => $bot->name,
            'slug' => $bot->slug,
            'description' => $bot->description,

            'welcome_message' => $bot->welcome_message,
            'placeholder_text' => $bot->placeholder_text,
            // MANAGEMENT-ONLY, AND NULLED RATHER THAN DROPPED. See the class docblock: `bots.view`
            // is held by all four roles and its justification never covered the operator-authored
            // prompt, and `AuditLogger` already refuses the same string from `details`.
            'system_instruction' => $this->withInstructions ? $bot->system_instruction : null,
            'answer_style_instruction' => $this->withInstructions
                ? $bot->answer_style_instruction
                : null,
            // THE PROJECTION, STATED. Same flag, one line down, so "was it withheld" and "what was
            // rendered" can never disagree — a client that reads a null above without reading this
            // cannot tell "not set" from "not shown to you", and writing that null back is a
            // silent overwrite of an operator-authored prompt. See the class docblock.
            'instructions_visible' => $this->withInstructions,

            'status' => $bot->status->value,
            'access_mode' => $bot->access_mode->value,

            'provider_connection_id' => $bot->provider_connection_id,
            'provider_model_id' => $bot->provider_model_id,

            'answer_mode' => $bot->answer_mode->value,
            'dense_top_k' => $bot->dense_top_k,
            'sparse_top_k' => $bot->sparse_top_k,
            'rerank_candidates' => $bot->rerank_candidates,
            'rerank_retain' => $bot->rerank_retain,
            'evidence_threshold' => $bot->evidence_threshold,
            'evidence_threshold_scale' => $bot->evidence_threshold_scale?->value,
            'retrieval_configuration_version' => $bot->retrieval_configuration_version,

            'allow_general_answers' => $bot->allow_general_answers,
            // ALWAYS AN OBJECT, NEVER AN ARRAY. `JsonObjectCast` is what guarantees it: PHP cannot
            // tell an empty array from an empty map, so the built-in `array` cast would serialize an
            // unthemed bot — the majority — as `[]` and a themed one as `{}`. One field, two JSON
            // types, decided by whether anybody has themed the bot, is a client-side type error
            // waiting for the first customer who has not.
            'theme' => (object) $bot->theme,

            'rate_limit_per_minute' => $bot->rate_limit_per_minute,
            'rate_limit_per_day' => $bot->rate_limit_per_day,
            'retention_days' => $bot->retention_days,
            'collect_end_user_data' => $bot->collect_end_user_data,
            'consent_text' => $bot->consent_text,

            'created_at' => $bot->created_at?->toIso8601String(),
            'updated_at' => $bot->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The published shape.
     *
     * THE THREE ENUM FIELDS ARE PUBLISHED AS CLOSED `enum`s AND THE CAPABILITY-STYLE OPEN ARRAY
     * ARGUMENT DOES NOT APPLY TO THEM. `ProviderModelResource` publishes `supported` as an open
     * string array because that vocabulary belongs to the DATA PLANE and a closed copy here would
     * break a generated client the day the data plane adds a member. `status`, `access_mode` and
     * `answer_mode` are the opposite case: the vocabulary is this service's, it is generated from
     * the same `values()` calls the CHECK constraints are generated from, and a client that knows
     * the five statuses can render five different chips instead of a raw string.
     *
     * `evidence_threshold` IS A JSON NUMBER AND THE PRICES ON `ProviderModelResource` ARE STRINGS,
     * and the difference is what each number is for. A price is an exact decimal quantity summed
     * over a month, so an IEEE 754 double drifts by an amount nobody can reproduce. A threshold is
     * a CUTOFF compared once against a score the provider returned as a double; a decimal string
     * would force a conversion back to a double at every comparison and claim a precision the score
     * itself does not have.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'BotResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One bot as the ADMIN surface sees it: identity, voice, lifecycle, '
                    .'model selection, retrieval configuration, appearance, limits and consent. It '
                    .'can carry the bot\'s SYSTEM INSTRUCTION and is therefore an '
                    .'authenticated-only shape — the hosted-chat, widget and stylesheet surfaces '
                    .'publish their own, much smaller, resource. The two instruction fields are '
                    .'further narrowed to callers holding `bots.manage` and are `null` for the '
                    .'rest; every key is present in every response, so the shape does not vary by '
                    .'caller, and `instructions_visible` says which of the two readings a null '
                    .'carries. Nothing of the parent provider connection appears here beyond its '
                    .'ULID: no vendor, no label, and no masked credential.',
                'required' => [
                    'id', 'public_bot_id', 'name', 'slug', 'description',
                    'welcome_message', 'placeholder_text', 'system_instruction',
                    'answer_style_instruction', 'instructions_visible', 'status', 'access_mode',
                    'provider_connection_id', 'provider_model_id',
                    'answer_mode', 'dense_top_k', 'sparse_top_k', 'rerank_candidates',
                    'rerank_retain', 'evidence_threshold', 'evidence_threshold_scale',
                    'retrieval_configuration_version', 'allow_general_answers', 'theme',
                    'rate_limit_per_minute', 'rate_limit_per_day', 'retention_days',
                    'collect_end_user_data', 'consent_text', 'created_at', 'updated_at',
                ],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID of the bot, and the key the ADMIN surface addresses '
                            .'it by. Never handed to an end user: it is also a term in every vector '
                            .'query issued on this bot\'s behalf, and its leading characters are a '
                            .'millisecond timestamp.',
                    ],
                    'public_bot_id' => [
                        'type' => 'string',
                        'description' => 'The opaque token a hosted-chat URL, a widget snippet and a '
                            .'theme stylesheet request carry. Globally unique, because it is '
                            .'resolved with no organization in hand. Server-minted once and never '
                            .'editable — changing it would break every live embed on the customer\'s '
                            .'own site. It authorizes nothing on its own.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Operator-supplied name. Tenant-controlled text: escape it '
                            .'on render.',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'The human handle, lower-case with internal hyphens, unique '
                            .'PER ORGANIZATION. Another organization using the same handle is not a '
                            .'conflict — which is why every cache key and rate-limit counter derived '
                            .'from it carries the organization id first.',
                    ],
                    'description' => [
                        'type' => ['string', 'null'],
                        'description' => 'Internal note for the console. Null means not set; the '
                            .'empty string is not a second spelling of it.',
                    ],
                    'welcome_message' => [
                        'type' => ['string', 'null'],
                        'description' => 'The first-run greeting. Null renders the platform default, '
                            .'which is a real state and not a missing one.',
                    ],
                    'placeholder_text' => [
                        'type' => ['string', 'null'],
                        'description' => 'The composer\'s placeholder. Null renders the platform '
                            .'default.',
                    ],
                    'system_instruction' => [
                        'type' => ['string', 'null'],
                        'description' => 'The bot\'s own system prompt. AUTHENTICATED SURFACES '
                            .'ONLY, and within them MANAGEMENT ONLY: it is rendered to a caller '
                            .'holding `bots.manage` and is `null` for every other caller, so a '
                            .'client cannot read a null as "this bot has no instruction" — the '
                            .'majority of bots genuinely have none, and a reporting-only role sees '
                            .'the same null either way. It never appears on the hosted-chat, widget '
                            .'or stylesheet endpoints at all, and retrieved source text can never '
                            .'alter it — that boundary is the data plane\'s and is not expressible '
                            .'in this document.',
                    ],
                    'answer_style_instruction' => [
                        'type' => ['string', 'null'],
                        'description' => 'Tone and formatting guidance, kept apart from the system '
                            .'instruction so a voice change is not a change to the grounding rules. '
                            .'Carries the same management-only projection as `system_instruction` '
                            .'and for the same reason: it is operator-authored prompt text.',
                    ],
                    'instructions_visible' => [
                        'type' => 'boolean',
                        'description' => 'Whether the two instruction fields in THIS body carry '
                            .'their stored values. False means they were withheld — the caller does '
                            .'not hold `bots.manage` on this bot\'s organization — and the `null` '
                            .'you are reading is the projection rather than the bot\'s state. It '
                            .'exists because a null otherwise carries two facts a client cannot '
                            .'tell apart, and the wrong reading is destructive: PATCHing a form '
                            .'seeded from a withheld body sends `null`, which the edit endpoint '
                            .'accepts as "clear it" and answers 200. A client MUST NOT send '
                            .'`system_instruction` or `answer_style_instruction` back on a body '
                            .'that arrived with this false, and MUST NOT re-derive the answer from '
                            .'a role name it holds locally: the grant is resolved per record, '
                            .'against the record\'s own organization.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'testing', 'published', 'paused', 'archived'],
                        'description' => 'Lifecycle. Only `published` is reachable by an end user on '
                            .'a public channel; `testing` is the admin playground only. `paused` and '
                            .'`archived` both answer nobody and differ in intent — a paused bot is '
                            .'expected back — and `archived` is TERMINAL: an archived bot is '
                            .'read-only, including its status.',
                    ],
                    'access_mode' => [
                        'type' => 'string',
                        'enum' => ['public', 'private'],
                        'description' => 'Whether an anonymous end user may converse. NOT the origin '
                            .'allow-list and not a substitute for it: a public bot with an empty '
                            .'allow-list is reachable from hosted chat and from no embedded widget.',
                    ],
                    'provider_connection_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'ULID of the provider connection whose credential answers '
                            .'for this bot. A REFERENCE and nothing more — the key itself is '
                            .'envelope-encrypted and is decrypted only inside the request that uses '
                            .'it, never here. Null on a bot that has not been configured yet, which '
                            .'is the first state every bot is in.',
                    ],
                    'provider_model_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'ULID of the catalog row this bot answers with, which must '
                            .'be registered under `provider_connection_id`. Null until configured. A '
                            .'model with no connection is refused: a catalog row names a credential '
                            .'only through its parent.',
                    ],
                    'answer_mode' => [
                        'type' => 'string',
                        'enum' => ['strict', 'rag_first'],
                        'description' => 'Whether general model knowledge may be used at all. '
                            .'`strict` answers only from active knowledge sources and refuses when '
                            .'nothing clears the evidence gate. It does NOT by itself permit general '
                            .'answers in `rag_first` — `allow_general_answers` is the switch.',
                    ],
                    'dense_top_k' => [
                        'type' => 'integer',
                        'description' => 'Candidates from the dense arm. A starting point rather than '
                            .'a finding: it moves through an evaluation run with an immutable '
                            .'configuration snapshot, never by intuition.',
                    ],
                    'sparse_top_k' => [
                        'type' => 'integer',
                        'description' => 'Candidates from the BM25 arm, fused with the dense arm by '
                            .'RRF.',
                    ],
                    'rerank_candidates' => [
                        'type' => 'integer',
                        'description' => 'How many fused candidates are sent to the reranker.',
                    ],
                    'rerank_retain' => [
                        'type' => 'integer',
                        'description' => 'How many survive reranking into the context window. Never '
                            .'more than `rerank_candidates`: reranking can only reorder what '
                            .'retrieval handed it.',
                    ],
                    'evidence_threshold' => [
                        'type' => ['number', 'null'],
                        'description' => 'The score below which evidence is treated as insufficient '
                            .'and the bot refuses. NULL, WITH NO PLATFORM DEFAULT, and that is not '
                            .'an omission: the scale is a property of the (provider, model) pair, '
                            .'0.30 is a valid float on every scale, and applying one scale\'s number '
                            .'to another moves only the refusal rate, only in aggregate, and raises '
                            .'nothing anywhere. Always read beside `evidence_threshold_scale`; '
                            .'either without the other is not a state this row can hold.',
                    ],
                    'evidence_threshold_scale' => [
                        'type' => ['string', 'null'],
                        'enum' => ['logit', 'sigmoid', 'unit_interval', null],
                        'description' => 'What the threshold is measured in. `logit` is unbounded '
                            .'and signed; the other two are bounded 0-1 and are NOT '
                            .'interchangeable — only the bounds transfer, and the bounds are not the '
                            .'calibration. There is deliberately no `uncalibrated` member: that is '
                            .'the statement that no characterization exists, which makes a stored '
                            .'threshold a contradiction rather than a value.',
                    ],
                    'retrieval_configuration_version' => [
                        'type' => 'integer',
                        'description' => 'Increments when — and only when — a retrieval knob\'s VALUE '
                            .'changes. It travels into the configuration snapshot and into every '
                            .'retrieval trace, and a trace without it cannot be replayed. Re-sending '
                            .'an unchanged value does not move it, so a console that submits its '
                            .'whole form on every save does not mint a new configuration identity '
                            .'for a configuration that did not move.',
                    ],
                    'allow_general_answers' => [
                        'type' => 'boolean',
                        'description' => 'The one field an operator must set on purpose before a bot '
                            .'may answer from anything but its sources. A separate field from '
                            .'`answer_mode` so that "RAG-first but not yet cleared to publish" stays '
                            .'expressible; publishing the pair as `rag_first` + false is refused.',
                    ],
                    // INLINE AND NOT A COMPONENT OF ITS OWN, and the reason is a rule this file's
                    // own contract test enforces: `OpenApiDocumentTest` requires every published
                    // resource COMPONENT to require every property it declares, because that is
                    // what lets `schemaViolations()` compare a key set both ways with one
                    // equality. A theme is a genuinely PARTIAL map — `{}` is the normal state and
                    // each key is independently optional — so it can never satisfy that rule, and
                    // publishing it as a component would either break the gate or force three
                    // nullable keys onto a column whose CHECK constraint refuses null values.
                    // Inline, the optionality is expressed where it is true and the totality rule
                    // still holds for every named component.
                    'theme' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'description' => 'The tenant-supplied theme: token VALUES, never CSS. A '
                            .'fixed key set of scalars, every one of them OPTIONAL and every one '
                            .'validated against an exact grammar on write. Always an OBJECT — `{}` '
                            .'when unthemed, which is the normal state and means "render the '
                            .'platform theme" — and never an empty array. Every other custom '
                            .'property the renderer writes (the whole `-foreground` and accent-ramp '
                            .'family) is DERIVED from these at render time and is never settable, '
                            .'because contrast is derived and never chosen.',
                        'properties' => [
                            'primary' => [
                                'type' => 'string',
                                'description' => 'The brand accent, as a CSS `oklch()` triple — for '
                                    .'example `oklch(0.525 0.235 264)`. The whole accent ramp '
                                    .'(hover, active, soft, and every `-foreground`) is derived '
                                    .'from it. A colour whose lightness lands in the band where '
                                    .'NEITHER platform text colour clears 4.5:1 is REFUSED on write '
                                    .'rather than stored: the renderer would drop it and serve the '
                                    .'platform accent, and the customer would have been told it was '
                                    .'accepted.',
                            ],
                            'accent' => [
                                'type' => 'string',
                                'description' => 'The neutral hover wash, as a CSS `oklch()` triple. '
                                    .'NOT a second brand colour and independent of the accent ramp; '
                                    .'its foreground is derived, so it carries the same contrast '
                                    .'refusal.',
                            ],
                            'radius' => [
                                'type' => 'string',
                                'enum' => ThemeVocabulary::RADII,
                                'description' => 'The corner radius, matched EXACTLY against the '
                                    .'values the design tokens publish. The renderer compares this '
                                    .'string against that set and drops anything else, so an '
                                    .'arbitrary CSS length is refused on write rather than silently '
                                    .'ignored on render.',
                            ],
                        ],
                    ],
                    'rate_limit_per_minute' => [
                        'type' => ['integer', 'null'],
                        'description' => 'Per-bot message limit. NULL means the platform default '
                            .'applies, which is a different fact from a configured limit that '
                            .'happens to equal it. Zero is not expressible: a limit of zero is a bot '
                            .'that answers nobody and is a plausible typo for "no limit".',
                    ],
                    'rate_limit_per_day' => [
                        'type' => ['integer', 'null'],
                        'description' => 'Per-bot daily message limit, same reading as the per-minute '
                            .'one.',
                    ],
                    'retention_days' => [
                        'type' => ['integer', 'null'],
                        'description' => 'Conversation retention override in days. NULL means the '
                            .'organization\'s own retention policy decides.',
                    ],
                    'collect_end_user_data' => [
                        'type' => 'boolean',
                        'description' => 'Whether the chat surface asks an end user for identifying '
                            .'details. True is only storable together with `consent_text`.',
                    ],
                    'consent_text' => [
                        'type' => ['string', 'null'],
                        'description' => 'The disclosure rendered before the first message when '
                            .'`collect_end_user_data` is true. Non-null whenever that flag is true; '
                            .'permitted on its own, which is the state of a bot whose operator wrote '
                            .'the disclosure before switching collection on.',
                    ],
                    'created_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. Null only for a record whose '
                            .'timestamp was never set.',
                    ],
                    'updated_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset.',
                    ],
                ],
            ],
        ];
    }
}
