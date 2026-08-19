<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * One `provider_models` row, for a fixture that needs the catalog to already exist.
 *
 * ── IT REQUIRES BOTH PARENTS RECYCLED, AND REFUSES TO RUN OTHERWISE ────────────────────────────
 *
 * `ProviderModelEntry::factory()->recycle($organization)->recycle($connection)`. Neither is
 * optional and neither is minted here, for the reason ProviderConnectionFactory states about its
 * own organization: a factory that mints its own parent puts the row in a THIRD organization, and
 * an isolation test built on such a fixture passes with the tenant filter deleted — the row it was
 * meant to prove was hidden belonged to nobody in the test to begin with.
 *
 * IT IS STRICTER THAN ITS PARENT'S FACTORY BY ONE ARGUMENT, because this row has TWO ownership
 * facts that must agree: `organization_id` and `provider_connection_id`. The composite foreign key
 * `(organization_id, provider_connection_id) -> provider_connections (organization_id, id)` will
 * reject a disagreement — so a missing `recycle()` here is a 23503 rather than a silent
 * cross-tenant row — but the failure arrives as a constraint name inside a factory, which reads
 * like a schema bug. The exception below names the actual mistake and how to fix it.
 *
 * THE TWO RECYCLED MODELS ARE CHECKED AGAINST EACH OTHER, and that check is not paranoia. Handing
 * this factory org A and a connection belonging to org B is exactly the fixture that would make a
 * tenancy test meaningless, and it is a plausible typo in a two-organization fixture where every
 * variable name differs by one letter.
 *
 * ── CAPABILITY FLAGS ARE PASSED EXPLICITLY OR THE ROW CLAIMS NOTHING ───────────────────────────
 *
 * Same rule, and the same reason, as ProviderConnectionFactory::withModel(): the whole of finding
 * C1 turns on which flags a row carries, so a factory that guessed a plausible set would let a
 * readiness or capability test pass for a reason its author never wrote down. The default here is
 * the EMPTY list — "this row claims nothing" — which is a state that can never be mistaken for a
 * deliberate declaration. Use `->supporting([...])` to declare one.
 *
 * ── AND IT WRITES THE ENVELOPE, NEVER A BARE LIST ──────────────────────────────────────────────
 *
 * `capability_flags` is an OBJECT with a `supported` key: `{"supported": ["embedding"]}`. That is
 * what EloquentProviderConnectionRepository::attachModel() writes and what
 * ProviderModelEntry::supportedCapabilities() reads. A bare `["embedding"]` here would make every
 * capability read return `[]` — "claims nothing" — with no error anywhere, which is a readiness
 * bug that surfaces as an organization that mysteriously cannot ingest.
 *
 * ── PRICING IS NULL BY DEFAULT, AND THAT IS THE INTERESTING BRANCH ─────────────────────────────
 *
 * "No price recorded" is the state of almost every real row and is NOT the same as "free". A
 * fixture that priced everything would leave the null branch of the resource and of the CHECK
 * constraints untested, which is the branch that actually ships.
 *
 * @extends Factory<ProviderModelEntry>
 */
final class ProviderModelEntryFactory extends Factory
{
    protected $model = ProviderModelEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = $this->getRandomRecycledModel(Organization::class);
        $connection = $this->getRandomRecycledModel(ProviderConnection::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'ProviderModelEntryFactory requires a recycled organization: '
                .'ProviderModelEntry::factory()->recycle($org)->recycle($connection). Letting it '
                .'mint its own would put the row in a THIRD organization, which is the failure '
                .'that makes an isolation test pass with the tenant filter deleted.',
            );
        }

        if (! $connection instanceof ProviderConnection) {
            throw new RuntimeException(
                'ProviderModelEntryFactory requires a recycled provider connection: '
                .'ProviderModelEntry::factory()->recycle($org)->recycle($connection). A model row '
                .'with no connection is unreachable from every read path in the application, and '
                .'minting one here would create a second connection nobody in the test can name.',
            );
        }

        if ($connection->organization_id !== $organization->id) {
            // The composite foreign key would refuse this row anyway. It is caught here so the
            // failure names the FIXTURE rather than arriving as SQLSTATE 23503 with a constraint
            // name, which reads like a schema bug and sends the reader to the migration.
            throw new RuntimeException(
                'ProviderModelEntryFactory was given an organization and a connection that belong '
                .'to DIFFERENT organizations. In a two-organization fixture that is a one-letter '
                .'typo, and the row it would produce is exactly the cross-tenant row every '
                .'isolation test in this suite exists to prove cannot be reached.',
            );
        }

        return [
            // Denormalized deliberately, and re-checked by the composite foreign key. Taken from
            // the recycled ORGANIZATION and asserted equal to the connection's above, so the two
            // ownership facts on this row cannot silently disagree.
            'organization_id' => $organization->id,
            'provider_connection_id' => $connection->id,

            // DISTINGUISHABLE BETWEEN TWO ORGANIZATIONS BY DEFAULT. A shared literal makes a
            // cross-tenant leak compare equal to itself, which is the failure mode of every
            // isolation assertion written against a faker default. Tests that need the SAME model
            // id in both organizations — the fixture shape that actually proves the tenant
            // predicate exists — set it explicitly.
            'model' => 'fixture-model-'.$this->faker->unique()->numerify('######'),
            'display_name' => $this->faker->unique()->words(2, true),

            // THE ENVELOPE, EMPTY. See the class docblock: a guessed flag set is how a capability
            // test passes for a reason nobody wrote down.
            'capability_flags' => ['supported' => []],

            'context_window' => 8192,
            'max_output_tokens' => 0,
            'enabled' => true,

            // Null on purpose — "no price recorded", which is the majority state and the branch a
            // priced-everything fixture would never exercise.
            'input_price_per_million' => null,
            'output_price_per_million' => null,
            'price_currency' => null,
        ];
    }

    /**
     * Declare the capability flags this row claims.
     *
     * @param  list<string>  $supported
     */
    public function supporting(array $supported): static
    {
        // The `['supported' => ...]` envelope is written HERE and in exactly one other place
        // (EloquentProviderConnectionRepository::attachModel), so no test can construct a row whose
        // shape the reader does not understand.
        return $this->state(fn (): array => ['capability_flags' => ['supported' => $supported]]);
    }

    /**
     * Record a price. Both halves and the currency together, because the
     * `provider_models_price_needs_currency` CHECK refuses a price with no currency and a fixture
     * that could produce that row would fail inside the database rather than in this file.
     */
    public function priced(string $input, string $output, string $currency = 'USD'): static
    {
        return $this->state(fn (): array => [
            'input_price_per_million' => $input,
            'output_price_per_million' => $output,
            'price_currency' => $currency,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
