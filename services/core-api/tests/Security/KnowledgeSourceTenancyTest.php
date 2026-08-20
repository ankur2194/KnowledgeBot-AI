<?php

declare(strict_types=1);

use App\Models\BotSourceAssignment;
use App\Models\KnowledgeSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| The one row in the schema that can span two organizations
|--------------------------------------------------------------------------
|
| `bot_source_assignments` is kb-tenancy-isolation NN2: `bot_id` and `source_id` each inherit their
| own organization and nothing in the foreign-key graph forces them to agree. What forces them is
| the DENORMALIZED `organization_id` plus the two composite foreign keys, and this file is where
| that claim is tested rather than asserted.
|
| BUILT ON tenantPair(), AND THAT IS NOT A STYLE CHOICE. A one-organization fixture cannot fail an
| isolation test: with a single tenant there is nothing to leak, so the test passes against a schema
| with no constraint at all — quickly, quietly, and forever. There is deliberately no
| single-organization helper to reach for.
|
| ASSERTED BY CONSTRAINT NAME, NOT BY "SOMETHING THREW". A bare exception assertion passes when the
| write fails for an unrelated reason — a NOT NULL violation on a column the fixture forgot looks
| identical from outside — and that is the failure mode where a guard is quietly gone while its test
| is green.
|
| EVERY WRITE HERE GOES THROUGH THE MODEL, NEVER AN ENDPOINT. These are the constraints that must
| hold for a writer that never ran a FormRequest and never passed a policy: a repair script, a
| console command, a seeder, an ingestion callback, a service nobody has written yet. A test driving
| an HTTP route would prove the FormRequest works and would say nothing about the row.
*/

/**
 * Attempt a callable that writes, and return the QueryException it raised, or null if it succeeded.
 *
 * THE SAVEPOINT IS LOAD-BEARING AND IS NOT DEFENSIVE PROGRAMMING. `RefreshDatabase` wraps each test
 * in one transaction, and in PostgreSQL a statement that raises inside a transaction ABORTS IT:
 * every subsequent statement fails with 25P02, "current transaction is aborted", until a rollback.
 * Without the nested `transaction()` — which Laravel implements as SAVEPOINT / ROLLBACK TO SAVEPOINT
 * when one is already open — the first expected violation would poison every line after it, and the
 * positive control that follows a negative assertion could never run.
 *
 * The same helper, for the same reason, as `saveAndCatch()` in tests/Feature/BotSchemaTest.php.
 */
function assignmentAttempt(\Closure $write): ?QueryException
{
    try {
        // The closure returns a value rather than nothing so the transaction helper's return type
        // is resolvable; the value itself is discarded.
        (new BotSourceAssignment)->getConnection()->transaction(static function () use ($write): bool {
            $write();

            return true;
        });

        return null;
    } catch (QueryException $e) {
        return $e;
    }
}

it('refuses a cross-organization assignment AT THE DATABASE, by name', function (): void {
    $t = tenantPair();

    // THE POSITIVE CONTROL FIRST, AND IT IS THE POINT OF THE TEST RATHER THAN A PREAMBLE. Without
    // it this file also passes against a schema that refuses EVERY assignment — which would break
    // the product completely while making the security assertion look strongest.
    //
    // tenantPair() already created Org B's source assigned to Org B's bot, through
    // `assignedTo()`, which writes `organization_id` explicitly. Its existence IS the control.
    $legal = BotSourceAssignment::withoutGlobalScopes()
        ->where('source_id', $t->sourceB->id)
        ->where('bot_id', $t->botB->id)
        ->first();

    expect($legal)->not->toBeNull('the fixture wrote no legal assignment, so the refusal below proves nothing')
        ->and($legal?->organization_id)->toBe($t->b->id);

    // AND NOW THE ILLEGAL ONE. Org B's source, Org A's bot. `crossOrg()` writes
    // `organization_id` from the RECYCLED organization — Org B's — so the row's tenant agrees with
    // the source and disagrees with the bot, and `bot_source_assignments_bot_same_org` is the
    // constraint that has to raise.
    $exception = assignmentAttempt(static function () use ($t): void {
        KnowledgeSource::factory()->recycle($t->b)->crossOrg($t->botA)->create();
    });

    expect($exception)->toBeInstanceOf(
        QueryException::class,
        'a source in one organization was assigned to a bot in another and the database accepted '
        .'it. That is not a flaky test: it is a permanent cross-tenant leak that every downstream '
        .'filter AGREES with, because you have taught it that Org B\'s source belongs to Org A\'s '
        .'bot.'
    );

    // THE CONSTRAINT, BY NAME. PostgreSQL puts it in the message; asserting on the string is what
    // distinguishes "the composite key refused this" from "the insert failed for some other
    // reason". `str_contains` and not a Pest string matcher because the surrounding text is a
    // driver message this test has no business pinning.
    expect(str_contains((string) $exception?->getMessage(), 'bot_source_assignments_bot_same_org'))
        ->toBeTrue(
            'the write was refused, but not by the composite foreign key this test exists for. '
            .'The actual message was: '.(string) $exception?->getMessage()
        );

    // AND NOTHING LANDED. A constraint that raises after writing is not a constraint.
    expect(
        BotSourceAssignment::withoutGlobalScopes()->where('bot_id', $t->botA->id)->count()
    )->toBe(0);
});

it('refuses the mirror image: Org A\'s source pointed at Org B\'s bot', function (): void {
    $t = tenantPair();

    // THE SAME VIOLATION FROM THE OTHER DIRECTION, and it is not redundant. `crossOrg()` always
    // writes the SOURCE's organization onto the row, so the bot key is the one that fires; a test
    // that only ever ran it one way round would pass with
    // `bot_source_assignments_source_same_org` deleted. Recycling Org A here means the source and
    // the row are Org A's while the bot is Org B's — the same key fires, but on the pair the
    // previous test cannot reach.
    $exception = assignmentAttempt(static function () use ($t): void {
        KnowledgeSource::factory()->recycle($t->a)->crossOrg($t->botB)->create();
    });

    expect($exception)->toBeInstanceOf(QueryException::class);
    expect(str_contains((string) $exception?->getMessage(), 'bot_source_assignments_bot_same_org'))
        ->toBeTrue((string) $exception?->getMessage());
});

it('refuses a row whose organization matches neither parent', function (): void {
    $t = tenantPair();

    // THE THIRD SHAPE, AND IT IS THE ONE `crossOrg()` CANNOT EXPRESS: a row claiming a tenant that
    // owns neither the bot nor the source. `crossOrg()` always agrees with one side by
    // construction, so without this case `bot_source_assignments_source_same_org` is never the
    // constraint under test and could be dropped with both tests above still green.
    //
    // Written field by field rather than through the factory, deliberately: the factory REFUSES to
    // produce this row, and that refusal is itself a fixture-level guard worth not routing around.
    $exception = assignmentAttempt(static function () use ($t): void {
        $row = new BotSourceAssignment;
        $row->organization_id = $t->a->id;   // Org A ...
        $row->bot_id = $t->botB->id;         // ... Org B's bot ...
        $row->source_id = $t->sourceB->id;   // ... and Org B's source.
        $row->priority = 0;
        $row->enabled = true;
        $row->save();
    });

    expect($exception)->toBeInstanceOf(QueryException::class);

    // EITHER KEY MAY FIRE FIRST — PostgreSQL does not promise an order between two violated
    // constraints on one insert — so the assertion names both and requires one of them. Pinning a
    // single name here would be a test that passes on this planner and fails on the next.
    $message = (string) $exception?->getMessage();

    expect(
        str_contains($message, 'bot_source_assignments_bot_same_org')
        || str_contains($message, 'bot_source_assignments_source_same_org')
    )->toBeTrue($message);
});

it('makes the factory refuse to build a cross-organization row by accident', function (): void {
    $t = tenantPair();

    // THE FIXTURE-LEVEL HALF OF THE GUARD. `assignedTo()` given a foreign bot raises a NAMED
    // exception rather than letting SQLSTATE 23503 arrive with a constraint name — which reads like
    // a schema bug and sends the reader to the migration, when in a two-organization fixture it is
    // a one-letter typo.
    expect(static fn () => KnowledgeSource::factory()->recycle($t->b)->assignedTo($t->botA)->create())
        ->toThrow(\RuntimeException::class, 'DIFFERENT organization');
});

it('makes crossOrg() refuse to be a no-op', function (): void {
    $t = tenantPair();

    // A "cross-org" state whose bot is in the SAME organization writes a perfectly legal row, both
    // composite keys pass, and a test asserting a refusal fails — for the right reason with the
    // wrong explanation, which is the shape that gets an assertion deleted rather than fixed. The
    // fixture refuses to be a no-op instead.
    expect(static fn () => KnowledgeSource::factory()->recycle($t->b)->crossOrg($t->botB)->create())
        ->toThrow(\RuntimeException::class, 'SAME organization');
});

it('scopes a knowledge source to its organization on read, and the canary stays where it was planted', function (): void {
    $t = tenantPair();

    // THE ORGANIZATION SCOPE, ASSERTED THROUGH A BOUND CONTEXT. `#[ScopedBy]` is the backstop layer
    // and it FAILS CLOSED: with no context bound it applies `whereRaw('1 = 0')`, so a test run with
    // no context proves nothing about the predicate — everything returns nothing, including the
    // rows that should. Bind Org A and assert Org B's source is invisible; bind Org B and assert it
    // is not.
    $context = app(TenantContext::class);

    // POSITIVE CONTROL FIRST (pest-testing NN2). Without it every assertion below also passes when
    // the read is broken and returns nothing at all — which is exactly what a fail-closed scope
    // does when the context is empty.
    $asB = $context->runFor(
        $t->b->id,
        static fn (): array => KnowledgeSource::query()->pluck('id')->all(),
    );

    expect($asB)->toBe([$t->sourceB->id]);

    $asA = $context->runFor(
        $t->a->id,
        static fn (): array => KnowledgeSource::query()->pluck('id')->all(),
    );

    expect($asA)->toBe([], 'Org A\'s scoped query returned a knowledge source, and Org A has none');

    // And with nothing bound: not a partial answer, not every answer — none. The empty-context
    // branch is the one that decides whether a pooled queue worker leaks or merely breaks.
    expect(KnowledgeSource::query()->count())->toBe(0);
    expect(BotSourceAssignment::query()->count())->toBe(0);

    // AND THE CANARY DID NOT MOVE. It is still in Org B's bot welcome message and NOT on the
    // source, because `KnowledgeSourceFactory::indexed()` is deferred — see tenancy.php. Asserted
    // rather than assumed, because a well-meaning change that put the canary on the source's name
    // would make every §22.5 assertion compare against a string no retrieval path could have
    // leaked: a weaker test that reads as a stronger one.
    //
    // ABSENCE VIA str_contains() AND NEVER ->not->toContain(...), which has already hidden a real
    // tenant-id leak in this repository: on a string subject that matcher has a shape that can pass
    // for the wrong reason, and an absence assertion that can pass for the wrong reason is worse
    // than none.
    expect(str_contains($t->sourceB->name, $t->canary))->toBeFalse();
    expect(str_contains((string) $t->sourceB->description, $t->canary))->toBeFalse();
    expect(str_contains((string) $t->botB->welcome_message, $t->canary))->toBeTrue(
        'the canary is not in Org B\'s bot welcome message, so either it moved without this '
        .'assertion moving with it, or a second one was planted — tenancy.php forbids both.'
    );
});
