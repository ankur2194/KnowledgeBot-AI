<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProviderCallStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\ProviderCall;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One attempt against one provider.
 *
 * ── FOUR RECYCLED PARENTS, ALL REQUIRED, ALL CHECKED AGAINST EACH OTHER ───────────────────────
 *
 * `ProviderCall::factory()->recycle($org)->recycle($bot)->recycle($connection)->recycle($model)`.
 * Three composite foreign keys guard this row — bot, connection and model each against
 * `(organization_id, id)` of their parent — so a disagreement is a 23503 rather than a silent
 * cross-tenant row. It is caught here anyway so the failure names the FIXTURE: a constraint name
 * arriving from inside a factory reads like a schema bug and sends the reader to the migration.
 *
 * THE CONNECTION AND THE MODEL ARE CHECKED AGAINST EACH OTHER TOO, and that pairing is refused by
 * NOTHING in the database — `provider_calls_connection_same_org` and `provider_calls_model_same_org`
 * each check their reference against the ORGANIZATION and neither checks them against the other.
 * `BotService::assertModelSelection()` states the same three-way distinction for the same pair, and
 * `BotFactory::usingModel()` refuses the same pairing in fixtures with the same sentence.
 *
 * ── THE CONVERSATION AND THE MESSAGE ARE OPTIONAL, AND THAT IS THE SCHEMA SPEAKING ────────────
 *
 * Both columns are nullable and both are `ON DELETE SET NULL`, because retention removes CONTENT and
 * not COST. A row with neither is therefore a REAL and important state — it is what every provider
 * call looks like after the thread it paid for has been swept — so the default produces one, and
 * `->forTurn($message)` attaches it to a transcript. A factory that always attached a conversation
 * would make every "the cost report still adds up after retention" test unwritable.
 *
 * ── THE DEFAULT SUCCEEDED, AND CARRIES NO `error_class` ───────────────────────────────────────
 *
 * `provider_calls_error_class_paired_with_status` refuses a `succeeded` row that carries one and a
 * `failed` row that does not, so `->failed()` moves both together. There is no state that sets one
 * alone: a fixture whose only use is proving the constraint fires belongs in the schema test.
 *
 * @extends Factory<ProviderCall>
 */
final class ProviderCallFactory extends Factory
{
    protected $model = ProviderCall::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$organization, $bot, $connection, $model] = $this->requireParents();

        $inputTokens = $this->faker->numberBetween(200, 4_000);
        $outputTokens = $this->faker->numberBetween(50, 800);

        return [
            'organization_id' => $organization->id,
            'bot_id' => $bot->id,
            'provider_connection_id' => $connection->id,
            'model_id' => $model->id,

            // The state after retention has swept the thread. See the class docblock.
            'conversation_id' => null,
            'message_id' => null,

            // The VENDOR's identifier, visibly not a ULID so a failing assertion is readable.
            'provider_request_id' => 'req_'.Str::lower(Str::random(20)),

            'status' => ProviderCallStatus::Succeeded,
            'error_class' => null,

            // `input_tokens` is the TOTAL input, cache INCLUDED — the normalization every adapter
            // owes. `provider_calls_cache_within_input` refuses a breakdown larger than its total,
            // so these three cannot be set to the Anthropic-shaped values by accident.
            'input_tokens' => $inputTokens,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => 0,

            // A STRING, not a float. `numeric(16, 8)` is exact and the arithmetic belongs in the
            // database; a float default here would put the binary rounding error the pricing
            // migration refuses into every fixture.
            'estimated_cost' => '0.00420000',
            'estimated_cost_currency' => 'USD',

            'first_token_latency_ms' => $this->faker->numberBetween(180, 900),
            'total_latency_ms' => $this->faker->numberBetween(1_000, 6_000),

            'fallback_metadata' => [],
        ];
    }

    /**
     * Attach this attempt to a turn, and to that turn's conversation.
     *
     * BOTH COLUMNS TOGETHER, from the message's own `conversation_id`, so the two cannot name
     * different threads. Nothing in the database refuses that pairing — the two keys are independent
     * — which makes this the same class of guard `retryOf()` provides on `MessageFactory`.
     */
    public function forTurn(Message $message): static
    {
        return $this->state(fn (): array => [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
        ]);
    }

    /** Attach to a thread but to no particular turn — a call made before the answer row settled. */
    public function inConversation(Conversation $conversation): static
    {
        return $this->state(fn (): array => ['conversation_id' => $conversation->id]);
    }

    /**
     * A failure, with its class. Both together — see the class docblock.
     *
     * The class must be one of the 18 in `App\Support\Kb\ErrorTaxonomy::RETRYABLE`, which
     * `provider_calls_error_class_check` is generated from and which
     * tests/Contract/ErrorTaxonomyParityTest.php pins against the data plane.
     */
    public function failed(string $errorClass = 'provider_temporary'): static
    {
        return $this->state(fn (): array => [
            'status' => ProviderCallStatus::Failed,
            'error_class' => $errorClass,
            // A failed attempt still bills its input. Output and cost are what did not happen.
            'output_tokens' => 0,
            'first_token_latency_ms' => null,
        ]);
    }

    /** The caller went away. NOT a failure — see ProviderCallStatus. */
    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => ProviderCallStatus::Cancelled]);
    }

    /**
     * The SECOND row of a fallback pair: the attempt that was reached after another one failed.
     *
     * It records the ordinal and what it fell back FROM, which is the whole reason the column
     * exists — a fallback row with an empty `fallback_metadata` is indistinguishable from a primary
     * attempt, and §8.23's fallback rate counts exactly this.
     */
    public function fallbackFrom(ProviderCall $primary, int $attempt = 2): static
    {
        return $this->state(fn (): array => [
            'fallback_metadata' => [
                'attempt' => $attempt,
                'from_provider_call_id' => $primary->id,
                'from_model_id' => $primary->model_id,
                'reason' => $primary->error_class,
            ],
        ]);
    }

    /** No usage frame ever arrived — a stream that died. Distinct from zero. */
    public function withoutUsage(): static
    {
        return $this->state(fn (): array => [
            'input_tokens' => null,
            'cache_read_tokens' => null,
            'cache_write_tokens' => null,
            'output_tokens' => null,
            'reasoning_tokens' => null,
            'estimated_cost' => null,
            'estimated_cost_currency' => null,
        ]);
    }

    /**
     * @return array{0: Organization, 1: Bot, 2: ProviderConnection, 3: ProviderModelEntry}
     */
    private function requireParents(): array
    {
        $organization = $this->getRandomRecycledModel(Organization::class);
        $bot = $this->getRandomRecycledModel(Bot::class);
        $connection = $this->getRandomRecycledModel(ProviderConnection::class);
        $model = $this->getRandomRecycledModel(ProviderModelEntry::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'ProviderCallFactory requires a recycled organization: '
                .'ProviderCall::factory()->recycle($org)->recycle($bot)->recycle($connection)'
                .'->recycle($model). Letting it mint its own would put the row in a THIRD '
                .'organization, which is the failure that makes an isolation test pass with the '
                .'tenant filter deleted — and on this table it would also put a THIRD tenant\'s '
                .'spend into this one\'s cost report.',
            );
        }

        if (! $bot instanceof Bot) {
            throw new RuntimeException(
                'ProviderCallFactory requires a recycled bot. `bot_id` is NOT NULL and never nulls '
                .'out, because it is what every §8.23 aggregate groups by after retention has '
                .'removed the conversation.',
            );
        }

        if (! $connection instanceof ProviderConnection) {
            throw new RuntimeException(
                'ProviderCallFactory requires a recycled provider connection. It is the credential '
                .'that was billed, it is NOT NULL, and this row makes it undeletable — so a minted '
                .'one would be a connection nobody in the test can name and cannot clean up.',
            );
        }

        if (! $model instanceof ProviderModelEntry) {
            throw new RuntimeException(
                'ProviderCallFactory requires a recycled provider model: '
                .'ProviderCall::factory()->recycle($org)->recycle($bot)->recycle($connection)'
                .'->recycle($model).',
            );
        }

        foreach (['bot' => $bot, 'connection' => $connection, 'model' => $model] as $label => $parent) {
            if ($parent->organization_id !== $organization->id) {
                throw new RuntimeException(
                    "ProviderCallFactory was given an organization and a {$label} that belong to "
                    .'DIFFERENT organizations. In a two-organization fixture that is a one-letter '
                    .'typo, and the row it would produce is this tenant\'s questions billed to that '
                    .'tenant\'s provider account.',
                );
            }
        }

        if ($model->provider_connection_id !== $connection->id) {
            // REFUSED BY NOTHING IN THE DATABASE — see the class docblock. The two composite keys
            // check each reference against the organization and neither checks them against each
            // other, so this is the authority.
            throw new RuntimeException(
                'ProviderCallFactory was given a model that is registered under a DIFFERENT '
                .'connection of the same organization. Nothing in the database refuses that row: '
                .'the two composite foreign keys each check their reference against the '
                .'organization and neither checks them against the other. A call that names a '
                .'model under connection A and bills connection B is a cost report that cannot be '
                .'reconciled with either vendor\'s invoice.',
            );
        }

        return [$organization, $bot, $connection, $model];
    }
}
