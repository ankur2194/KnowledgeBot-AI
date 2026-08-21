<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Sources\IngestionApplication;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What Laravel tells the data plane it did with one ingestion progress frame.
 *
 * ── A SUPERSEDED FRAME IS STILL A 200, AND THE BODY IS WHERE IT SAYS SO ──────────────────────
 *
 * A stale or out-of-order frame is the GUARD WORKING, not an error. Answering it with a 4xx would
 * put a permanently-failing request in front of a Celery task that is going to re-emit it, and
 * `kb-error-taxonomy` would have the caller retry a frame whose whole meaning is "already
 * superseded". So the status stays 200 and `applied` carries the verdict — which is also what makes
 * the seam observable: a run whose frames are all `out_of_order` is a redelivery storm, and one
 * whose frames are `unknown_item` is a callback aimed at the wrong tenant.
 *
 * THAT IS NOT "EVERY REFUSAL IS A 200", AND THE MISSING CLAUSE WAS PUBLISHED. A MALFORMED frame and
 * an ILLEGAL STATE TRANSITION are both 422s on this route, so a worker author reading the rule
 * stated absolutely would be surprised by the first `queued -> parsing` they sent. The choice is
 * right for the same reason the 200 is: `validation` is non-retryable in the taxonomy, so a Celery
 * task that reads the class will not hammer a 422 either. The distinction is WHICH IS TRUE OF THE
 * FRAME — "you are late" is a 200 with a reason, "this frame cannot be applied at all" is a 422 with
 * an `errors` map naming the field.
 *
 * ── IT ECHOES THE SOURCE'S RESULTING STATUS AND NOTHING ELSE ABOUT THE SOURCE ────────────────
 *
 * The data plane must not learn anything from this response it did not already send. `status` is
 * the value the frame itself asked for when the frame applied, and the value the row already held
 * when it did not — which is the one fact a worker deciding whether to keep going actually needs.
 * A fuller projection would be an internal endpoint quietly becoming a read API for tenant data.
 *
 * @property-read IngestionApplication $resource
 */
final class IngestionAcknowledgementResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(IngestionApplication $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $application = $this->resource;

        return [
            'applied' => $application->applied,
            'reason' => $application->reason,
            'status' => $application->source?->status->value,
            // Whether THIS frame performed the pointer switch. A worker that reported
            // `indexed_verified` and got `activated: false` back has learned that its publication
            // did not take effect — which is otherwise indistinguishable from success, because the
            // upsert it performed succeeded either way.
            'activated' => $application->activated,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'IngestionAcknowledgementResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The result of applying one ingestion progress frame. A SUPERSEDED '
                    .'frame is a 200 with `applied: false`, because a stale or out-of-order frame is '
                    .'the ordering guard working rather than an error, and a 4xx would make a '
                    .'Celery retry hammer it. A MALFORMED frame, and an illegal state transition, '
                    .'are 422 `validation` instead — non-retryable in the taxonomy, so a worker '
                    .'reading the class will not hammer those either.',
                'required' => ['applied', 'reason', 'status', 'activated'],
                'properties' => [
                    'applied' => [
                        'type' => 'boolean',
                        'description' => 'Whether the frame changed anything.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'enum' => [
                            IngestionApplication::APPLIED,
                            IngestionApplication::OUT_OF_ORDER,
                            IngestionApplication::STALE_JOB,
                            IngestionApplication::UNKNOWN_ITEM,
                            IngestionApplication::ITEM_SOURCE_MISMATCH,
                        ],
                        'description' => 'Why. `out_of_order` is expected under redelivery; '
                            .'`stale_job` means a reprocess superseded this run mid-flight; '
                            .'`unknown_item` and `item_source_mismatch` mean the frame describes a '
                            .'row this organization does not have, which is neither and is worth an '
                            .'alert.',
                    ],
                    'status' => [
                        'type' => ['string', 'null'],
                        'description' => 'The source\'s lifecycle state after the frame — or the '
                            .'state it already held when the frame was refused. Null only when the '
                            .'source could not be resolved at all.',
                    ],
                    'activated' => [
                        'type' => 'boolean',
                        'description' => 'Whether THIS frame performed the active-version pointer '
                            .'switch. A worker that reported a verified index and reads `false` '
                            .'here has learned its publication did not take effect, which is '
                            .'otherwise indistinguishable from success.',
                    ],
                ],
            ],
        ];
    }
}
