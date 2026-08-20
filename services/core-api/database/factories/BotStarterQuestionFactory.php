<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bot;
use App\Models\BotStarterQuestion;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * One suggested starter question.
 *
 * ── IT REQUIRES BOTH PARENTS RECYCLED, for the reason BotDomainFactory states at length ───────
 *
 * `BotStarterQuestion::factory()->recycle($organization)->recycle($bot)`, and the two are checked
 * against each other so a one-letter typo in a two-organization fixture fails here rather than
 * arriving as SQLSTATE 23503 from a constraint name.
 *
 * ── THE POSITION IS A SEQUENCE, AND THE CALLER USUALLY HAS TO SUPPLY IT ───────────────────────
 *
 * `sort_order` is UNIQUE per bot, so `->count(3)->create()` with a constant default would collide
 * on the second row. The default is therefore a per-instance counter — which is enough for the
 * common `->count(n)` case and is NOT enough for two separate `create()` calls in one test, where
 * the counter restarts. `->at($position)` is the explicit spelling and the one a test asserting on
 * order should use, because a position that came from a counter is a position nobody wrote down.
 *
 * A `Sequence` would be the other spelling — `->state(new Sequence(...))` — and it is not used
 * because it has to be sized at the call site to the count, which is the coupling this counter
 * removes.
 *
 * @extends Factory<BotStarterQuestion>
 */
final class BotStarterQuestionFactory extends Factory
{
    protected $model = BotStarterQuestion::class;

    /**
     * The next position this factory INSTANCE will hand out.
     *
     * Per instance and not static: a static counter would leak across tests, and the value would
     * then depend on execution order — which is the class of flake `--order-by=random` exists to
     * find and nobody wants to find in a fixture.
     */
    private int $nextPosition = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$organization, $bot] = $this->requireParents();

        return [
            'organization_id' => $organization->id,
            'bot_id' => $bot->id,
            // Distinguishable between two organizations, like every other human-readable fixture
            // value: a shared literal makes a cross-tenant leak compare equal to itself.
            'question' => rtrim($this->faker->unique()->sentence(), '.').'?',
            'sort_order' => $this->nextPosition++,
        ];
    }

    /** An explicit position. Use this whenever the test asserts on order. */
    public function at(int $position): static
    {
        return $this->state(fn (): array => ['sort_order' => $position]);
    }

    public function asking(string $question): static
    {
        return $this->state(fn (): array => ['question' => $question]);
    }

    /**
     * @return array{0: Organization, 1: Bot}
     */
    private function requireParents(): array
    {
        $organization = $this->getRandomRecycledModel(Organization::class);
        $bot = $this->getRandomRecycledModel(Bot::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'BotStarterQuestionFactory requires a recycled organization: '
                .'BotStarterQuestion::factory()->recycle($org)->recycle($bot). Letting it mint its '
                .'own would put the row in a THIRD organization, which is the failure that makes '
                .'an isolation test pass with the tenant filter deleted.',
            );
        }

        if (! $bot instanceof Bot) {
            throw new RuntimeException(
                'BotStarterQuestionFactory requires a recycled bot: '
                .'BotStarterQuestion::factory()->recycle($org)->recycle($bot). A starter question '
                .'with no bot is unreachable from every read path in the application.',
            );
        }

        if ($bot->organization_id !== $organization->id) {
            throw new RuntimeException(
                'BotStarterQuestionFactory was given an organization and a bot that belong to '
                .'DIFFERENT organizations. In a two-organization fixture that is a one-letter '
                .'typo, and the row it would produce is exactly the cross-tenant row every '
                .'isolation test in this suite exists to prove cannot be reached.',
            );
        }

        return [$organization, $bot];
    }
}
