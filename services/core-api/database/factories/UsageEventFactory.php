<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Provider;
use App\Enums\UsageEventType;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One row of the quota ledger.
 *
 * ── THE ORGANIZATION IS REQUIRED AND RECYCLED; THE BOT IS OPTIONAL AND CHECKED ────────────────
 *
 * `UsageEvent::factory()->recycle($org)` is the minimum. Letting the factory mint its own
 * organization would put the row in a THIRD tenant, which is the failure that makes an isolation
 * test pass with the filter deleted — and on this table it would put a third tenant's metered usage
 * into this one's quota.
 *
 * A recycled bot is used when one is present, and `usage_events_bot_same_org` refuses a bot from
 * another organization. It is checked here anyway so the failure names the FIXTURE: a constraint
 * name arriving from inside a factory reads like a schema bug and sends the reader to the migration.
 *
 * ── THE DEFAULT IS A TOKEN EVENT, BECAUSE THAT IS THE ONE WITH ATTRIBUTION RULES ─────────────
 *
 * `usage_events_provider_attribution_paired` refuses a token event with no provider AND a storage
 * event with one, so the two shapes cannot be produced by overriding one key. `->storageAdded()`
 * and `->storageRemoved()` move the whole set together; that is the only safe way to switch.
 *
 * ── `occurred_at` IS EXPLICIT IN EVERY STATE, AND `dedupe_key` IS UNIQUE PER ROW ─────────────
 *
 * The column has no database default (the migration says why: it is half of the dedupe identity and
 * a clock-derived value silently double-counts a re-derivation). A shared literal `dedupe_key` would
 * make the second row of any two-row fixture fail on `usage_events_dedupe`, which reads as a schema
 * bug rather than as a fixture that reused an identity — hence `unique()`.
 *
 * @extends Factory<UsageEvent>
 */
final class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$organization, $bot] = $this->requireParents();

        return [
            'organization_id' => $organization->id,
            'bot_id' => $bot?->id,

            // INSIDE THE CURRENT MONTH by default, so a fixture lands in the partition the
            // migration created and inside `QuotaMetric::MonthlyTokens`' period. A faker date over
            // "-30 days" would straddle a month boundary on the 1st through the 30th of every
            // month, which is a test that fails one day in thirty for a reason nobody reproduces.
            'occurred_at' => CarbonImmutable::now('UTC')->startOfMonth()->addHours(
                $this->faker->numberBetween(0, 23),
            ),

            'event_type' => UsageEventType::ChatTokensInput,
            'quantity' => $this->faker->numberBetween(200, 4_000),

            // Both present, because the default type is provider-attributed.
            'provider' => Provider::OpenAI,
            'model' => 'gpt-5.1',

            // The identity of the WORK this row meters. Visibly not a ULID so a failing assertion
            // reads, and unique so two rows in one fixture cannot collide on `usage_events_dedupe`.
            'dedupe_key' => 'call_'.$this->faker->unique()->numerify('##########'),

            'aggregation_metadata' => ['source' => 'factory'],
        ];
    }

    /**
     * Output tokens — REASONING INCLUDED, which is the arithmetic the ledger's own docblock is
     * about. Nothing here computes it; the caller passes the total.
     */
    public function output(int $quantity): static
    {
        return $this->state(fn (): array => [
            'event_type' => UsageEventType::ChatTokensOutput,
            'quantity' => $quantity,
        ]);
    }

    public function input(int $quantity): static
    {
        return $this->state(fn (): array => [
            'event_type' => UsageEventType::ChatTokensInput,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Bytes an upload added. Provider and model go to null TOGETHER with the type, because
     * `usage_events_provider_attribution_paired` refuses either half alone.
     *
     * `bot_id` goes to null too: storage is an ORGANIZATION-level fact, and a storage row carrying a
     * bot would make a per-bot breakdown attribute an upload to whichever bot happened to be
     * recycled into the fixture.
     */
    public function storageAdded(int $bytes): static
    {
        return $this->state(fn (): array => [
            'event_type' => UsageEventType::StorageBytesAdded,
            'quantity' => $bytes,
            'provider' => null,
            'model' => null,
            'bot_id' => null,
            'dedupe_key' => 'item_'.Str::lower((string) Str::ulid()),
        ]);
    }

    public function storageRemoved(int $bytes): static
    {
        return $this->storageAdded($bytes)->state(fn (): array => [
            'event_type' => UsageEventType::StorageBytesRemoved,
        ]);
    }

    /**
     * Move the row to an explicit instant — the only way to put a fixture in another period or
     * another partition, and therefore the only way to test that the period boundary binds.
     */
    public function occurredAt(CarbonImmutable $at): static
    {
        return $this->state(fn (): array => ['occurred_at' => $at]);
    }

    /**
     * @return array{0: Organization, 1: Bot|null}
     */
    private function requireParents(): array
    {
        $organization = $this->getRandomRecycledModel(Organization::class);
        $bot = $this->getRandomRecycledModel(Bot::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'UsageEventFactory requires a recycled organization: '
                .'UsageEvent::factory()->recycle($org). Letting it mint its own would put the row '
                .'in a THIRD organization, which is the failure that makes an isolation test pass '
                .'with the tenant filter deleted — and on this table it would put a third tenant\'s '
                .'metered usage into this one\'s quota.',
            );
        }

        if ($bot instanceof Bot && $bot->organization_id !== $organization->id) {
            throw new RuntimeException(
                'UsageEventFactory was given an organization and a bot that belong to DIFFERENT '
                .'organizations. In a two-organization fixture that is a one-letter typo, and the '
                .'row it would produce is this tenant\'s spend attributed to that tenant\'s bot — '
                .'which `usage_events_bot_same_org` refuses, with a constraint name that reads like '
                .'a schema bug.',
            );
        }

        return [$organization, $bot instanceof Bot ? $bot : null];
    }
}
