<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bot;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bot as an ANONYMOUS VISITOR may see it — the public runtime projection.
 *
 * ═══ IT IS A DIFFERENT RESOURCE FROM `BotResource` AND MUST STAY ONE ═══════════════════════
 *
 * `BotResource` is the admin projection and carries the configuration: the provider connection, the
 * model, the retrieval depths, the evidence threshold, the rate limits, the retention policy. Every
 * one of those is a fact about how the tenant spends money and what their corpus is, and none of it
 * belongs on a page a stranger loads.
 *
 * The tempting economy — one resource with a `$this->when($isAdmin, …)` per field — is the shape
 * this file exists to refuse. It puts the decision on every field instead of on the class, so the
 * next field added is public by default and nothing says so; and the flag it branches on has to be
 * threaded through a Resource, which is the layer least able to establish it.
 *
 * ═══ WHAT IS PUBLISHED AND WHY EACH ONE IS SAFE ════════════════════════════════════════════
 *
 * The name, the voice fields and the theme are what the customer PUT on their own page: they are
 * already in the embed snippet's neighbourhood and are rendered to exactly the audience they were
 * written for. The starter questions are the same. `public_bot_id` is the identifier the caller
 * already used to get here.
 *
 * `id` IS DELIBERATELY ABSENT. The internal ULID is what the ADMIN surface authorizes against and it
 * leaks the bot's creation time in its leading characters; a public projection that carried it would
 * hand every visitor the key to a different surface's URLs.
 *
 * ═══ EVERY STRING HERE IS TENANT-AUTHORED AND IS NOT ESCAPED HERE ══════════════════════════
 *
 * Escaping happens at the RENDERER, in every client, because only the renderer knows the context the
 * value is entering (`kb-security-baseline` §18.9). Escaping at the boundary and again at the
 * renderer is how a welcome message ends up displaying `&amp;lt;`.
 */
final class RuntimeBotResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  list<array{question: string}>  $starterQuestions
     */
    public function __construct(Bot $resource, private readonly array $starterQuestions = [])
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
            'public_bot_id' => $bot->public_bot_id,
            'name' => $bot->name,
            'description' => $bot->description,
            'welcome_message' => $bot->welcome_message,
            'placeholder_text' => $bot->placeholder_text,
            // The tenant's palette, already a closed key set enforced by `bots_theme_keys` — so a
            // client can render it without deciding what an unknown key means.
            'theme' => $bot->theme,
            // WHETHER THE VISITOR MUST BE SHOWN A CONSENT NOTICE, and the text to show. The two
            // travel together because a flag with no text means "agree to whatever the bot says
            // today", which is worthless the first time the wording changes.
            'consent_required' => $bot->collect_end_user_data && $bot->consent_text !== null,
            'consent_text' => $bot->consent_text,
            'starter_questions' => array_map(
                static fn (array $row): string => $row['question'],
                $this->starterQuestions,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'RuntimeBotResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The public projection of a bot, as an anonymous visitor sees it. '
                    .'It carries no configuration: no provider connection, no model, no retrieval '
                    .'parameters, no limits, and not the internal ULID.',
                'required' => [
                    'public_bot_id', 'name', 'description', 'welcome_message', 'placeholder_text',
                    'theme', 'consent_required', 'consent_text', 'starter_questions',
                ],
                'properties' => [
                    'public_bot_id' => [
                        'type' => 'string',
                        'description' => 'The public identifier from the embed snippet. It '
                            .'identifies the bot and authorizes nothing: whether the bot answers is '
                            .'the AND of its status, its access mode and the origin allow-list.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'TENANT-AUTHORED TEXT. Escape at render, in every client.',
                    ],
                    'description' => [
                        'type' => ['string', 'null'],
                        'description' => 'Tenant-authored. Null means unset, never an empty string.',
                    ],
                    'welcome_message' => [
                        'type' => ['string', 'null'],
                        'description' => 'Shown before the first question. Null means the client '
                            .'renders its own default, which is a real state and not a missing one.',
                    ],
                    'placeholder_text' => [
                        'type' => ['string', 'null'],
                        'description' => 'The composer placeholder. Null means the client default.',
                    ],
                    'theme' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => 'The bot palette, from a closed key set the database '
                            .'enforces. Empty object when the tenant set none.',
                    ],
                    'consent_required' => [
                        'type' => 'boolean',
                        'description' => 'True only when the bot collects end-user data AND has '
                            .'consent text to show. A flag with no text is not a consent record, so '
                            .'the two are reported as one fact.',
                    ],
                    'consent_text' => [
                        'type' => ['string', 'null'],
                        'description' => 'Exactly what must be shown. It is snapshotted onto the '
                            .'conversation at creation, so a later edit cannot rewrite what a '
                            .'visitor agreed to.',
                    ],
                    'starter_questions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Suggested chips, in the order the operator set. '
                            .'Tenant-authored; escape at render.',
                    ],
                ],
            ],
        ];
    }
}
