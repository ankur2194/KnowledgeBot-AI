<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/*
|--------------------------------------------------------------------------
| THE ASSERTION routes/console.php PROMISED AND NOBODY WROTE
|--------------------------------------------------------------------------
|
| routes/console.php's header says the schedule lives in one file "so `php artisan schedule:list`
| can be asserted in CI against the expected entry set — a deleted or renamed entry then fails the
| build instead of silently ceasing to run." Measured 2026-08-11: `schedule:list` appeared in ZERO
| lines of .github/, tests/, Makefile and scripts/. The promise had no implementation, which is the
| same defect shape as an implementation with no transcription — and since `.github/` was deleted on
| 2026-08-17 there is no build for it to fail, so THIS TEST is the entire mechanism.
|
| THIS DOES NOT SHELL OUT TO `schedule:list`, AND THAT IS DELIBERATE. Two measurements from the
| task that added the last entry:
|
|   * `schedule:list` resolves the mutex store to render "Next Due"/overlap state, so it needs
|     Valkey reachable. A test that reads the Schedule object needs neither Valkey nor a subprocess.
|   * `schedule:test` is NOT a substitute for either: it reported DONE for two commands that exited
|     non-zero, because it never reads the child's exit code.
|
| What this catches is exactly what the header claims: an entry deleted, renamed, or ADDED without
| anyone updating the expected set. The last of those is the live one — a command scheduled against
| a table no migration creates lists cleanly, exits 0 under `schedule:test`, and then fails once per
| tick forever.
*/

/**
 * The scheduled events, after booting the console kernel that loads routes/console.php.
 *
 * @return array<int, Event>
 */
function scheduledEvents(): array
{
    app(ConsoleKernel::class)->bootstrap();

    return app(Schedule::class)->events();
}

/**
 * @return array<int, string>
 */
function scheduledEntryNames(): array
{
    $names = array_map(
        static fn (Event $e): string => $e->description ?? $e->command ?? '(unnamed)',
        scheduledEvents(),
    );
    sort($names);

    return $names;
}

it('schedules exactly the expected entry set', function (): void {
    // FOUR LIVE ENTRIES. Everything still commented out in routes/console.php is missing its command
    // class, its table, or — in `sanctum:prune-expired`'s case — a producer for the rows it would
    // prune; each is listed there with what it is waiting for.
    //
    // Adding, renaming or deleting an entry is expected to fail this test. That is the point — update
    // this list in the same commit, and only after checking the entry can actually succeed.
    // `sanctum:prune-expired` was once scheduled against a personal_access_tokens table that no
    // migration creates: it exited 1 with SQLSTATE[42P01] once an hour, and nothing in the repository
    // would have said so. It is now a PERMANENT omission under decision D11 (no token is ever minted
    // on this surface), which is a stronger statement than "waiting for a table" and is why it must
    // not reappear in this list without the four-part change routes/console.php describes.
    //
    // SORTED, because scheduledEntryNames() sorts: this is a set assertion, not an order assertion,
    // and the order entries are declared in routes/console.php is free to change.
    expect(scheduledEntryNames())->toBe([
        'auth:clear-resets',
        'horizon:snapshot',
        'kb:create-audit-partitions',
        'kb:prune-auth-tokens',
    ]);
});

it('keeps the audit partition creator scheduled, because its absence is a timed outage', function (): void {
    // THIS ASSERTION IS NOT REDUNDANT WITH THE SET ABOVE, and it is here because of what its failure
    // would look like in production rather than in CI.
    //
    // `audit_logs` is monthly range-partitioned with NO DEFAULT partition. Every audit insert whose
    // created_at falls outside every existing partition fails with SQLSTATE 23514, and every
    // ON_FAILURE_ABORT audited action — email verification, password-reset completion, every
    // invitation transition, every role change — then returns 500. So deleting this one entry is a
    // total, instant, clock-triggered outage with no failing test, no degraded metric and no
    // dependency to blame, arriving weeks after the commit that caused it.
    //
    // Someone tidying the set assertion above by regenerating it from the current schedule would
    // remove that protection without noticing. This test names the consequence, so the diff that
    // deletes it has to delete the sentence too.
    $names = scheduledEntryNames();

    expect($names)->toContain('kb:create-audit-partitions');

    // AND ITS SIBLING MUST STAY OUT. `kb:prune-audit-partitions` DETACHes and DROPs whole months of
    // the compliance record; scheduling it unattended is a retention policy, not a maintenance task,
    // and no retention window has been decided. If that decision is ever made, this expectation is
    // the place it has to be argued.
    expect($names)->not->toContain('kb:prune-audit-partitions');
});

it('names every entry, because an unnamed job entry shares a mutex with its class', function (): void {
    foreach (scheduledEvents() as $event) {
        expect($event->description)->not->toBeNull(
            'every schedule entry must carry ->name(): Schedule::job() otherwise sets the '
            .'description to the job CLASS NAME, so two dispatches of one class share a mutex and '
            .'the second is skipped every tick, forever, with no error'
        );
    }
});

it('gives every entry a non-default withoutOverlapping TTL', function (): void {
    foreach (scheduledEvents() as $event) {
        expect($event->withoutOverlapping)->toBeTrue(
            "schedule entry '{$event->description}' has no ->withoutOverlapping()"
        );
        // 1440 is Laravel's default TTL. A SIGKILL then strands the lock for the rest of the DAY
        // and every tick is silently SKIPPED — ScheduledTaskSkipped, not ScheduledTaskFailed.
        expect($event->expiresAt)->not->toBe(1440,
            "schedule entry '{$event->description}' uses the 1440-minute default overlap TTL"
        );
    }
});

it('runs the whole schedule on one server only', function (): void {
    // ->onOneServer() is INERT without a shared, non-evicting cache store; that is a deployment
    // property (CACHE_STORE / config/cache.php) this test cannot see. What it CAN see is that the
    // flag was set at all, which is the half that gets lost when an entry is added outside the
    // group in routes/console.php.
    foreach (scheduledEvents() as $event) {
        expect($event->onOneServer)->toBeTrue(
            "schedule entry '{$event->description}' is not ->onOneServer()"
        );
    }
});
