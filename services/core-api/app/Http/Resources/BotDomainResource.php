<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\BotDomainStatus;
use App\Models\BotDomain;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a bot's widget origin allow-list, on the wire.
 *
 * ── THE ROW IS A GRANT, SO THE RESOURCE PUBLISHES THE TWO FIELDS THAT DECIDE WHETHER IT GRANTS ─
 *
 * `origin` and `status`, and neither is decoration: `BotDomainStatus::permitsEmbedding()` is true
 * for `active` alone, and the comparison the runtime surface will make is BYTE EQUALITY against the
 * browser's `Origin` header. So the console has to be able to show the operator the exact string
 * that was stored — not the one they typed — which is why the stored value is the RFC 6454
 * serialisation and why `ExactOrigin` refuses rather than trims anything that would change what the
 * row permits.
 *
 * `permits_embedding` IS PUBLISHED AS ITS OWN BOOLEAN even though a client could derive it from
 * `status === 'active'`. Deriving it is exactly the mistake the enum's docblock warns about: the
 * check must be written positively, and a client that computes `status !== 'disabled'` gets a
 * different answer for `pending` and reads as correct. One authoritative field means the console,
 * the widget console preview and any future client all read the same predicate, and a fourth status
 * added here changes their answer without changing their code.
 *
 * ── WHAT IS NOT RENDERED ──────────────────────────────────────────────────────────────────────
 *
 * `organization_id` — the client asked for this row through a URL that already named the
 * organization, so echoing the ownership column adds nothing and puts a tenant identifier into
 * every cached response body. The same call `ProviderModelResource` makes.
 *
 * `bot_id` — likewise in the path, and it is the ownership edge inside the tenant: a client that
 * reads it back is one step from posting it, and there is no endpoint that accepts it.
 *
 * @property-read BotDomain $resource
 */
final class BotDomainResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(BotDomain $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $domain = $this->resource;

        return [
            'id' => $domain->id,
            'origin' => $domain->origin,
            'status' => $domain->status->value,
            // Through the enum's own predicate, never recomputed here — see the class docblock.
            'permits_embedding' => $domain->status->permitsEmbedding(),
            'created_at' => $domain->created_at?->toIso8601String(),
            'updated_at' => $domain->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'BotDomainResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One origin on a bot\'s widget allow-list. The row is a SECURITY '
                    .'CONTROL rather than a preference: it is what lets a page on the public '
                    .'internet boot a chat widget that speaks with this organization\'s '
                    .'credential, on its corpus, against its quota.',
                'required' => ['id', 'origin', 'status', 'permits_embedding', 'created_at', 'updated_at'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID of the allow-list entry.',
                    ],
                    'origin' => [
                        'type' => 'string',
                        'description' => 'The exact origin, in the serialisation a browser sends: '
                            .'scheme, host and non-default port, lower-cased, with no path, no '
                            .'query, no fragment and no trailing slash. THERE IS NO WILDCARD '
                            .'GRAMMAR — the comparison is byte equality, and a pattern would turn '
                            .'it into a matcher. What was sent may differ from what is stored: the '
                            .'scheme and host are lower-cased, a single trailing slash is dropped, '
                            .'and a default port (`:80` for http, `:443` for https) is dropped '
                            .'because the browser omits it. A non-empty path is REFUSED rather '
                            .'than trimmed, because trimming it would widen the grant from one '
                            .'page to a whole host.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => BotDomainStatus::values(),
                        'description' => 'Lifecycle of the entry. `pending` is where every row '
                            .'starts and it grants nothing — whether an origin is under the '
                            .'operator\'s control is not something the entry form knows. `active` '
                            .'is the ONE value that permits an embed. `disabled` is a withdrawn '
                            .'row kept so it can be turned back on without retyping and so audit '
                            .'entries naming it still resolve.',
                    ],
                    'permits_embedding' => [
                        'type' => 'boolean',
                        'description' => 'Whether a widget served from this origin may boot, as '
                            .'far as THIS ROW is concerned. True for `active` and nothing else. '
                            .'Read this field rather than comparing `status` yourself: a check '
                            .'written as "not disabled" admits `pending`, and a status added later '
                            .'would be admitted by every negative test in every client. It is also '
                            .'only one term of the runtime decision — the bot\'s status, its '
                            .'access mode and an exact match on the origin are the others, and an '
                            .'EMPTY allow-list denies every origin.',
                    ],
                    'created_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. When the origin was added.',
                    ],
                    'updated_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. Moves when the status changes; the '
                            .'origin itself is immutable, so this is a status-change timestamp in '
                            .'practice.',
                    ],
                ],
            ],
        ];
    }
}
