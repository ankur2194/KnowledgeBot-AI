<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ProviderModelDeletion;
use App\Models\ProviderModelEntry;
use App\Repositories\Contracts\ProviderModelRepositoryInterface;
use App\Services\Providers\NewProviderModelEntry;
use App\Services\Providers\ProviderModelEdit;
use Closure;

/**
 * The catalog repository, real in every respect, with ONE competing writer wedged into the gap
 * between the duplicate PRE-FLIGHT and the INSERT.
 *
 * ── WHAT THIS EXISTS TO REACH, AND WHY NOTHING ELSE REACHES IT ─────────────────────────────────
 *
 * `ProviderModelService::create()` guards a duplicate model identifier twice. The pre-flight is an
 * org-scoped `modelExists()` read that produces the readable 422; the `catch (QueryException)` on
 * SQLSTATE 23505 against `provider_models_org_connection_model` is what happens when two
 * administrators register the same identifier in the same instant and the pre-flight loses. In a
 * single-threaded test the pre-flight ALWAYS wins, so that catch — the `violates()` helper, the
 * SQLSTATE comparison, and the mapping onto the same ValidationException — had never once
 * executed. A branch nothing has ever run is a branch nobody knows works: `getCode()` returning an
 * int rather than the SQLSTATE string, or a constraint rename, would both surface as a 500 in
 * production and as a green suite here.
 *
 * ── THE TECHNIQUE, AND WHY IT IS HONEST RATHER THAN A STUB ─────────────────────────────────────
 *
 * Nothing here fakes a verdict. `modelExists()` runs the REAL org-scoped query and returns its
 * REAL answer; the competing row is created AFTERWARDS, before the call returns, so the pre-flight
 * genuinely saw a table with no conflicting row and genuinely returned false. That is exactly the
 * true state of the world in the race being simulated — the other administrator's transaction had
 * not committed yet when our SELECT ran, and had by the time our INSERT did. `create()` is then
 * delegated to the real EloquentProviderModelRepository, so the INSERT is real, the transaction is
 * real, and the exception comes from PostgreSQL's unique index rather than from this class.
 *
 * The alternative shapes were both worse. Calling the private mapper directly would prove the
 * mapping and nothing about whether the database can reach it. Returning a hard-coded `false` from
 * `modelExists()` would mean the pre-flight's own query was never exercised, so a broken pre-flight
 * and a working catch would look identical to a working pre-flight and a working catch.
 *
 * ── THE CAPTURED STATE IS THE POSITIVE CONTROL ─────────────────────────────────────────────────
 *
 * `$preflights` and `$preflightSaw` exist so the test can assert the pre-flight ran ONCE and
 * answered FALSE. Without that, a test asserting only "422 with an errors.model key" would pass
 * identically if the pre-flight had caught the duplicate after all — which is the ordinary path,
 * already covered, and would leave this file proving nothing.
 *
 * It lives under tests/Support rather than inside the test file for the reason
 * AuditLogRepositorySpy states: `Tests\` is PSR-4-mapped to ./tests, so a class declared inside a
 * test file makes every `composer dump-autoload` print a PSR-4 warning forever.
 */
final class RacingProviderModelRepository implements ProviderModelRepositoryInterface
{
    /** How many times the pre-flight ran. Exactly one is the shape this test asserts. */
    public int $preflights = 0;

    /**
     * What the pre-flight's REAL query answered. False is the whole point: it means the 422 the
     * caller received came out of the 23505 catch and not out of the pre-flight.
     */
    public ?bool $preflightSaw = null;

    private bool $competitorLanded = false;

    /**
     * @param  ProviderModelRepositoryInterface  $inner  the real repository; every method delegates
     * @param  Closure(): void  $competitor  writes the conflicting row. Invoked ONCE, after the
     *                                       pre-flight's query has run and before its answer is
     *                                       returned — the instant the losing writer's SELECT and
     *                                       the winning writer's COMMIT interleave
     */
    public function __construct(
        private readonly ProviderModelRepositoryInterface $inner,
        private readonly Closure $competitor,
    ) {}

    /**
     * @return list<ProviderModelEntry>
     */
    public function forConnection(string $organizationId, string $connectionId): array
    {
        return $this->inner->forConnection($organizationId, $connectionId);
    }

    public function modelExists(string $organizationId, string $connectionId, string $model): bool
    {
        // THE REAL QUERY, FIRST AND UNALTERED. Its answer is what the caller gets.
        $exists = $this->inner->modelExists($organizationId, $connectionId, $model);

        $this->preflights++;
        $this->preflightSaw = $exists;

        // AND NOW THE OTHER ADMINISTRATOR COMMITS. Once only: `create()` calls this exactly once,
        // but a guard here means a future second call cannot quietly insert a second row and turn
        // a 23505 into a different failure.
        if (! $this->competitorLanded) {
            $this->competitorLanded = true;

            ($this->competitor)();
        }

        return $exists;
    }

    /**
     * @param  Closure(ProviderModelEntry): void  $audit
     */
    public function create(
        string $organizationId,
        string $connectionId,
        NewProviderModelEntry $input,
        Closure $audit,
    ): ProviderModelEntry {
        // DELEGATED, so the INSERT, the transaction and the unique-index violation are all real.
        return $this->inner->create($organizationId, $connectionId, $input, $audit);
    }

    /**
     * @param  Closure(ProviderModelEntry): void  $audit
     */
    public function update(
        string $organizationId,
        string $connectionId,
        string $modelId,
        ProviderModelEdit $edit,
        Closure $audit,
    ): ?ProviderModelEntry {
        return $this->inner->update($organizationId, $connectionId, $modelId, $edit, $audit);
    }

    /**
     * @param  Closure(ProviderModelEntry): void  $audit
     */
    public function delete(
        string $organizationId,
        string $connectionId,
        string $modelId,
        Closure $audit,
    ): ProviderModelDeletion {
        return $this->inner->delete($organizationId, $connectionId, $modelId, $audit);
    }
}
