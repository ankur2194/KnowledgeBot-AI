<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BotStarterQuestion;
use App\Services\Bots\BotStarterQuestionService;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bot's starter questions, in the order the operator set.
 *
 * AN OBJECT WRAPPING THE ARRAY, never a bare array, for the mechanical reason
 * `ProviderModelCollectionResource` states: `#[ResponseShape]` maps a response KEY to a resource
 * class and cannot express "an array of", and every published component must carry
 * `additionalProperties: false`, which an array-typed schema cannot. Body is
 * `{"data": {"starter_questions": [...]}}`.
 *
 * @property-read list<BotStarterQuestion> $resource
 */
final class BotStarterQuestionCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  list<BotStarterQuestion>  $resource
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
            'starter_questions' => array_map(
                static fn (BotStarterQuestion $q): array => (new BotStarterQuestionResource($q))->toArray($request),
                $this->resource,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return BotStarterQuestionResource::openApiSchemas() + [
            'BotStarterQuestionCollectionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One bot\'s starter questions. An OBJECT wrapping the array so '
                    .'fields can join it later without moving the list.',
                'required' => ['starter_questions'],
                'properties' => [
                    'starter_questions' => [
                        'type' => 'array',
                        'maxItems' => BotStarterQuestionService::MAX_PER_BOT,
                        'items' => ['$ref' => '#/components/schemas/BotStarterQuestionResource'],
                        'description' => 'Every starter question, ordered by `sort_order` ascending '
                            .'— which is the order they render in. At most '
                            .BotStarterQuestionService::MAX_PER_BOT.', matching the ceiling the '
                            .'chat surface renders (kb-ai-chat-ux: three to six chips), so what is '
                            .'stored is what is shown and the console cannot promise a seventh '
                            .'chip no client draws. An empty array is the default state of every '
                            .'new bot and simply means the first-run screen shows no suggestions.',
                    ],
                ],
            ],
        ];
    }
}
