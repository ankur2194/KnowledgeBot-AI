<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bot;
use App\Models\BotFallbackEntry;
use App\Models\Organization;
use App\Models\ProviderModelEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * One link in a bot's fallback model chain.
 *
 * ── IT REQUIRES THREE RECYCLED PARENTS, WHICH IS ONE MORE THAN ANY OTHER FACTORY HERE ─────────
 *
 * `BotFallbackEntry::factory()->recycle($organization)->recycle($bot)->recycle($model)`, and that
 * is not ceremony: this row has THREE ownership facts that must agree, and it is the only row in
 * the schema so far where two independently-owned entities meet. The two composite foreign keys
 * `bot_fallback_models_bot_same_org` and `bot_fallback_models_model_same_org` refuse a
 * disagreement, so a missing `recycle()` is a 23503 rather than a silent cross-tenant row — but
 * the failure arrives as a constraint name inside a factory, which reads like a schema bug.
 *
 * A CROSS-TENANT ROW HERE WOULD BE THE WORST KIND. It is not a read leak: a bot whose fallback
 * chain names another organization's model row means THIS tenant's conversations answered on THAT
 * tenant's credential — billed to them, readable in their provider dashboard — with every
 * downstream layer agreeing, because it has been told whose model answers. Which is exactly why
 * the chain is a table with foreign keys and not a jsonb array of ids; the migration records that
 * argument in full.
 *
 * ── THE POSITION IS A PER-INSTANCE COUNTER, for the reason BotStarterQuestionFactory states ────
 *
 * `position` is UNIQUE per bot, so a constant default would collide on `->count(2)->create()`.
 * `->at($position)` is the explicit spelling and the one a test asserting on chain order should
 * use.
 *
 * @extends Factory<BotFallbackEntry>
 */
final class BotFallbackEntryFactory extends Factory
{
    protected $model = BotFallbackEntry::class;

    /** Per instance and not static — a static counter would leak across tests. */
    private int $nextPosition = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$organization, $bot, $model] = $this->requireParents();

        return [
            'organization_id' => $organization->id,
            'bot_id' => $bot->id,
            'provider_model_id' => $model->id,
            'position' => $this->nextPosition++,
        ];
    }

    /** An explicit position. Use this whenever the test asserts on chain order. */
    public function at(int $position): static
    {
        return $this->state(fn (): array => ['position' => $position]);
    }

    /**
     * @return array{0: Organization, 1: Bot, 2: ProviderModelEntry}
     */
    private function requireParents(): array
    {
        $organization = $this->getRandomRecycledModel(Organization::class);
        $bot = $this->getRandomRecycledModel(Bot::class);
        $model = $this->getRandomRecycledModel(ProviderModelEntry::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'BotFallbackEntryFactory requires a recycled organization: '
                .'BotFallbackEntry::factory()->recycle($org)->recycle($bot)->recycle($model). '
                .'Letting it mint its own would put the row in a THIRD organization, which is the '
                .'failure that makes an isolation test pass with the tenant filter deleted.',
            );
        }

        if (! $bot instanceof Bot) {
            throw new RuntimeException(
                'BotFallbackEntryFactory requires a recycled bot: '
                .'BotFallbackEntry::factory()->recycle($org)->recycle($bot)->recycle($model).',
            );
        }

        if (! $model instanceof ProviderModelEntry) {
            throw new RuntimeException(
                'BotFallbackEntryFactory requires a recycled provider model row: '
                .'BotFallbackEntry::factory()->recycle($org)->recycle($bot)->recycle($model). '
                .'Minting one would create a model in a connection nobody in the test can name, '
                .'and the chain would point at it.',
            );
        }

        if ($bot->organization_id !== $organization->id || $model->organization_id !== $organization->id) {
            throw new RuntimeException(
                'BotFallbackEntryFactory was given a bot or a model belonging to a DIFFERENT '
                .'organization than the recycled one. That row is not a read leak — it is this '
                .'tenant\'s conversations answered on another tenant\'s credential, with every '
                .'downstream layer agreeing.',
            );
        }

        return [$organization, $bot, $model];
    }
}
