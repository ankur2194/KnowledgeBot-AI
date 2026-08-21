<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Sources\SourceWarningCount;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One advisory parser/OCR warning code, and how many of a source's live versions reported it.
 *
 * ── THE CODE IS PUBLISHED AND THE VALUE BEHIND IT IS NOT, DELIBERATELY ───────────────────────
 *
 * `source_versions.warning_summary` is a jsonb OBJECT whose key set the DATA PLANE owns. Nothing on
 * this side enumerates those keys or schemas their values, and a warning about an unplaced table
 * naturally contains the table — so publishing the values would put an unbounded, unschema'd,
 * tenant-derived blob on an administrator's page. The keys are bounded, enumerable and renderable;
 * the values are the data plane's to expose through its own contract if they are ever needed.
 *
 * ── A WARNING IS NEVER A RETRIEVAL PREDICATE ─────────────────────────────────────────────────
 *
 * §8.11: `ready` and `ready_with_warnings` are IDENTICAL for retrieval. A console must not render
 * this list as a reason a document is not answering — it is a reason a document may answer BADLY,
 * which is a different sentence and a different remedy.
 *
 * @property-read SourceWarningCount $resource
 */
final class SourceWarningResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(SourceWarningCount $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->code,
            'versions' => $this->resource->versions,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'SourceWarningResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One advisory parser or OCR warning reported while this source\'s '
                    .'live content was produced. Advisory ALWAYS: it never gates retrieval, and a '
                    .'`ready_with_warnings` source is identical to a `ready` one for every query.',
                'required' => ['code', 'versions'],
                'properties' => [
                    'code' => [
                        'type' => 'string',
                        'description' => 'The warning key, as the ingestion pipeline wrote it. THE '
                            .'SET IS OPEN AND IS NOT ENUMERATED IN THIS DOCUMENT: it belongs to the '
                            .'ingestion service and grows with the parsers, so treat an unknown '
                            .'code as a code you have no copy for rather than as an error, and '
                            .'render it verbatim. The value behind the key is deliberately not '
                            .'published — it is unschema\'d and can contain document content.',
                    ],
                    'versions' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'How many of this source\'s LIVE versions reported this '
                            .'code. It is a count of VERSIONS and not of occurrences — how many '
                            .'pages inside a version were affected is inside the value this shape '
                            .'does not read — so for a single-file source it is always 1 and for a '
                            .'crawl it says how many pages carry the problem.',
                    ],
                ],
            ],
        ];
    }
}
