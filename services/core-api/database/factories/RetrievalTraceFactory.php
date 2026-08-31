<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Message;
use App\Models\RetrievalTrace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What retrieval did for one answer.
 *
 * ── IT REQUIRES A RECYCLED MESSAGE AND REFUSES TO RUN OTHERWISE ───────────────────────────────
 *
 * `RetrievalTrace::factory()->recycle($message)`. The message is the tenant fact — this table has no
 * `organization_id` — and `retrieval_traces_message_unique` means a minted parent would also
 * silently succeed where a second trace on the intended message would correctly fail.
 *
 * ── THE DEFAULT `filters` CARRIES ALL FOUR MANDATORY TERMS, AND THAT IS DELIBERATE ────────────
 *
 * `retrieval_traces_filters_name_mandatory_terms` refuses a row whose filters do not NAME `org_id`,
 * `bot_ids`, `source_status` and `source_version_id`. A factory default that omitted one would make
 * every fixture in the suite fail on a constraint, so the default is complete — and
 * `->filteredBy()` is how a test writes the incomplete case it wants the database to refuse.
 *
 * IT DOES NOT RESOLVE THE ORGANIZATION FROM THE MESSAGE. Doing so would need
 * `$message->conversation`, which is a lazy load `Model::shouldBeStrict()` forbids, and it would
 * make the fixture's filters agree with the row by construction — which is exactly the property a
 * test asserting "the trace records the organization it was scoped to" must NOT be handed for free.
 * `->forOrg($organizationId)` states it explicitly where a test needs the agreement.
 *
 * @extends Factory<RetrievalTrace>
 */
final class RetrievalTraceFactory extends Factory
{
    protected $model = RetrievalTrace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $message = $this->requireMessage();

        return [
            'message_id' => $message->id,

            // DISTINGUISHABLE BY DEFAULT, for the reason every text default in this suite is: a
            // shared literal makes a cross-tenant leak compare equal to itself.
            'original_query' => $this->faker->unique()->sentence(),
            'rewritten_query' => null,

            // All four mandatory terms. See the class docblock.
            'filters' => [
                'org_id' => (string) Str::ulid(),
                'bot_ids' => [(string) Str::ulid()],
                'source_status' => ['ready', 'ready_with_warnings'],
                'source_version_id' => [(string) Str::ulid()],
            ],

            'retrieval_configuration_version' => 1,

            // ORDERED LISTS. The built-in `array` cast writes `[]` for these, which is what
            // `retrieval_traces_candidate_summaries_is_array` demands — see the model for why
            // JsonObjectCast would be refused here and is required on `filters`.
            'candidate_summaries' => [],
            'selected_evidence' => [],
            'insufficient_evidence' => false,

            'timing_breakdown' => ['rewrite_ms' => 12, 'dense_ms' => 41, 'rerank_ms' => 88],
        ];
    }

    /**
     * The filters this query actually carried, spelled by the caller.
     *
     * Use it to make the trace AGREE with the fixture's organization, or to write the row the
     * database must refuse. There is no partial merge: a caller passing three terms gets three
     * terms and a constraint violation, which is the point.
     *
     * @param  array<string, mixed>  $filters
     */
    public function filteredBy(array $filters): static
    {
        return $this->state(fn (): array => ['filters' => $filters]);
    }

    /** The common case of the above: the same four terms, with a real organization in `org_id`. */
    public function forOrg(string $organizationId, ?string $botId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'filters' => array_merge(
                is_array($attributes['filters']) ? $attributes['filters'] : [],
                array_filter([
                    'org_id' => $organizationId,
                    'bot_ids' => $botId === null ? null : [$botId],
                ], static fn (mixed $v): bool => $v !== null),
            ),
        ]);
    }

    /**
     * The refusal: nothing cleared the evidence threshold.
     *
     * BOTH COLUMNS TOGETHER, because `retrieval_traces_insufficient_has_no_evidence` refuses a
     * refusal that holds evidence — a stored contradiction §8.23 would count in both directions.
     */
    public function insufficient(): static
    {
        return $this->state(fn (): array => [
            'insufficient_evidence' => true,
            'selected_evidence' => [],
        ]);
    }

    /**
     * A ranked evidence set. Order IS the ranking; the column is a jsonb ARRAY for that reason.
     *
     * @param  list<array<string, mixed>>  $evidence
     */
    public function withEvidence(array $evidence): static
    {
        return $this->state(fn (): array => [
            'selected_evidence' => $evidence,
            'insufficient_evidence' => false,
        ]);
    }

    private function requireMessage(): Message
    {
        $message = $this->getRandomRecycledModel(Message::class);

        if (! $message instanceof Message) {
            throw new RuntimeException(
                'RetrievalTraceFactory requires a recycled message: '
                .'RetrievalTrace::factory()->recycle($message). A trace has NO organization_id — it '
                .'reaches one through `retrieval_traces -> messages -> conversations` — so minting '
                .'a parent here would put this row in a third organization nothing in the test can '
                .'name. It would ALSO defeat `retrieval_traces_message_unique`: a second trace for '
                .'the intended message must fail, and against a minted one it would not.',
            );
        }

        return $message;
    }
}
