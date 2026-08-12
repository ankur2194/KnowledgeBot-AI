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
| lines of .github/, tests/, Makefile and scripts/ (control: `artisan` in ci.yml -> 7). The promise
| had no implementation, which is the same defect shape as an implementation with no transcription.
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
    // ONE LIVE ENTRY. Everything else in routes/console.php is commented out because its command
    // class or its TABLE does not exist yet; each is listed there with what it is waiting for.
    //
    // Uncommenting an entry is expected to fail this test. That is the point — update this list in
    // the same commit, and only after checking the entry can actually succeed. `sanctum:prune-expired`
    // was scheduled here against a personal_access_tokens table that no migration creates: it exited
    // 1 with SQLSTATE[42P01] once an hour, and nothing in the repository would have said so.
    expect(scheduledEntryNames())->toBe([
        'horizon:snapshot',
    ]);
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
