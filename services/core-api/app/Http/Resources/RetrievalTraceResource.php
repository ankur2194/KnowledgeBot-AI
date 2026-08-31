<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RetrievalTrace;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Why one answer said what it said: the two queries, the resolved filter, what came back, what was
 * used, and where the time went.
 *
 * ═══ THIS TABLE IS READABLE HERE BY DECISION, AND THE DECISION IS RECENT ═════════════════════
 *
 * ADR-074 took `retrieval_traces` OFF the data plane's write allow-list on 2026-08-27, before
 * anything had written it, and the reason names this surface: an admin endpoint reading the table
 * makes the public API a reader of it, which is ADR-033's property 2 — "no public API path reads or
 * writes it" — failing. A data-plane writer would then have sat beside a Laravel reader with no
 * policy, no audit row and no framework-applied scope. `app/rag/runner.py` still BUILDS the whole
 * trace and emits it as the `retrieval.trace` frame; Phase 4's relay finalizer persists it in the
 * same transaction as the message row, its citations and its usage row.
 *
 * §6.5 is the other half of the justification and it is explicit: an Analyst's responsibilities
 * include "Review source citations and retrieval traces". That is why this projection carries the
 * whole row rather than a summary — a trace that omitted the candidates could not answer "why was
 * the right document not used", which is the only question anybody opens it for.
 *
 * ═══ `filters` IS THE RESOLVED FILTER AND PUBLISHING IT IS THE POINT ═════════════════════════
 *
 * `kb-tenancy-isolation`: *"without `retrieval_traces.filters` you cannot even bound which past
 * answers were affected"* by a leak. The column's own CHECK requires all four mandatory terms to be
 * NAMED — `org_id`, `bot_ids`, `source_status`, `source_version_id` — and the values are this
 * organization's own ids, resolved at query time. It is not a credential and it is not another
 * tenant's data; it is the evidence that the four filters were applied to this turn.
 *
 * READ THE COLUMN'S OWN CAVEAT WITH IT: the constraint proves the trace RECORDS four terms, not
 * that the query CARRIED them. A trace is a record, not an enforcement point.
 *
 * ═══ `candidate_summaries` IS THE LARGEST FIELD ON THIS SURFACE, AND IT IS BOUNDED UPSTREAM ══
 *
 * Not here — this resource projects whatever the row holds. What bounds the RESPONSE is
 * `ConversationReader::MAX_MESSAGES`, which caps how many traces one transcript can contain. If a
 * single trace ever grows unbounded that is a defect in the writer rather than something a
 * projection should paper over, because a silently truncated diagnostic is worse than a large one.
 *
 * ═══ EVERY STRING IN HERE IS UNTRUSTED ══════════════════════════════════════════════════════
 *
 * `original_query` is what a visitor typed. `rewritten_query` is model output derived from it.
 * `candidate_summaries` and `selected_evidence` carry text out of uploaded and crawled documents.
 * All of it renders through the same sanitizer as an answer, on every surface including the admin
 * console, and none of it may be interpolated into a prompt.
 */
final class RetrievalTraceResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(RetrievalTrace $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $trace = $this->resource;

        return [
            'original_query' => $trace->original_query,
            'rewritten_query' => $trace->rewritten_query,
            'filters' => $trace->filters,
            'retrieval_configuration_version' => $trace->retrieval_configuration_version,
            'candidate_summaries' => $trace->candidate_summaries,
            'selected_evidence' => $trace->selected_evidence,
            'insufficient_evidence' => $trace->insufficient_evidence,
            'timing_breakdown' => $trace->timing_breakdown,
            'created_at' => $trace->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'RetrievalTraceResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The retrieval diagnostics behind one answer. At most one per '
                    .'message, written in the same transaction as the message it explains, so it '
                    .'can neither outlive nor precede it.',
                'required' => [
                    'original_query', 'rewritten_query', 'filters',
                    'retrieval_configuration_version', 'candidate_summaries', 'selected_evidence',
                    'insufficient_evidence', 'timing_breakdown', 'created_at',
                ],
                'properties' => [
                    'original_query' => [
                        'type' => 'string',
                        'description' => 'What the visitor actually typed. UNTRUSTED END-USER TEXT.',
                    ],
                    'rewritten_query' => [
                        'type' => ['string', 'null'],
                        'description' => 'The query the retriever ran, when it differed. NULL means '
                            .'the rewriting stage left the query alone, which is a real outcome and '
                            .'is different from a rewrite that happened to produce the same string. '
                            .'MODEL OUTPUT: untrusted.',
                    ],
                    'filters' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => 'The RESOLVED tenant filter this query ran under — real '
                            .'organization, real bot, real active-version ids. All four mandatory '
                            .'terms are always named (`org_id`, `bot_ids`, `source_status`, '
                            .'`source_version_id`); the column\'s CHECK refuses a row that omits '
                            .'one. It is the record that lets an incident bound which past answers '
                            .'were affected — and it proves the trace RECORDED four terms, not that '
                            .'the query carried them.',
                    ],
                    'retrieval_configuration_version' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'The bot\'s retrieval configuration version at query time, '
                            .'copied here so a later edit cannot rewrite the explanation of an '
                            .'answer that has already been given. Without it the trace cannot be '
                            .'replayed, which is what the evaluation regression gate needs.',
                    ],
                    'candidate_summaries' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'additionalProperties' => true],
                        'description' => 'What retrieval returned, IN RANKED ORDER — the order IS '
                            .'the ranking, which is why it is an array and not an object. Empty on '
                            .'a query that matched nothing. CONTAINS TENANT SOURCE TEXT: untrusted.',
                    ],
                    'selected_evidence' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'additionalProperties' => true],
                        'description' => 'The subset that survived the threshold and the packer and '
                            .'was actually shown to the model, in packed order — the same order the '
                            .'citation labels are assigned in. Always empty when '
                            .'`insufficient_evidence` is true; the column\'s CHECK enforces it, '
                            .'because a refusal made while holding evidence is a stored '
                            .'contradiction.',
                    ],
                    'insufficient_evidence' => [
                        'type' => 'boolean',
                        'description' => 'Whether the bot declined to answer for want of evidence. '
                            .'This is a NORMAL outcome and not an error — it is the refusal '
                            .'behaviour working — and it is the figure §8.23 counts.',
                    ],
                    'timing_breakdown' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => 'Per-stage milliseconds. THE KEY SET IS THE DATA PLANE\'S '
                            .'and is not enumerated on this side, so read defensively: a stage that '
                            .'did not run is an absent KEY rather than a zero.',
                    ],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
        ];
    }
}
