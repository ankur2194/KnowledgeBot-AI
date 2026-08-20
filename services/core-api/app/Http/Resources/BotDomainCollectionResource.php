<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BotDomain;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bot's widget origin allow-list.
 *
 * AN OBJECT WRAPPING THE ARRAY, never a bare array, for the mechanical reason
 * `ProviderModelCollectionResource` states: `#[ResponseShape]` maps a response KEY to a resource
 * class, so it cannot express "an array of"; and tests/Contract/OpenApiDocumentTest.php requires
 * every published component to carry `additionalProperties: false`, which an array-typed schema
 * cannot. Body is `{"data": {"domains": [...]}}`.
 *
 * ── AN EMPTY ARRAY IS A MEANINGFUL ANSWER AND IT MEANS "DENY EVERY ORIGIN" ────────────────────
 *
 * This is the one thing a client must not read the natural way. "No allow-list configured" reads
 * as "unrestricted" — it is how allow-lists are misread everywhere — and here that reading is an
 * embed on any site on the internet, because `published` + `access_mode = public` is precisely what
 * makes a bot answerable ANONYMOUSLY on the organization's credential and against its quota. The
 * rule, stated once in `BotDomainStatus::permitsEmbedding()` and repeated in the schema below: the
 * decision is "SOME ACTIVE ROW MATCHES THIS EXACT ORIGIN", which is false for the empty set by
 * construction — never "no row forbids it", which is true for it for the same reason.
 *
 * @property-read list<BotDomain> $resource
 */
final class BotDomainCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  list<BotDomain>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'domains' => array_map(
                static fn (BotDomain $domain): array => (new BotDomainResource($domain))->toArray($request),
                $this->resource,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return BotDomainResource::openApiSchemas() + [
            'BotDomainCollectionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One bot\'s widget origin allow-list. An OBJECT wrapping the array '
                    .'so pagination fields can join it later without moving the list or versioning '
                    .'the endpoint.',
                'required' => ['domains'],
                'properties' => [
                    'domains' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/BotDomainResource'],
                        'description' => 'Every entry on the allow-list, PENDING AND DISABLED ONES '
                            .'INCLUDED. Filtering them out would make "why is my widget refused on '
                            .'this site" unanswerable from the console while the row sat in the '
                            .'table. Ordered by origin, deterministically, so two reads of an '
                            .'unchanged set are byte-identical — the order carries NO precedence, '
                            .'because an allow-list is a set and the lookup is an exact match '
                            .'rather than a first-match walk. AN EMPTY ARRAY DENIES EVERY ORIGIN: '
                            .'the embed decision is "some active row matches this exact origin", '
                            .'which is false for the empty set. Never read it as "unrestricted".',
                    ],
                ],
            ],
        ];
    }
}
