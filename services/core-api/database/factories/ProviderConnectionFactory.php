<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Provider;
use App\Enums\ProviderConnectionStatus;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Support\Crypto\CredentialVault;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * The credential. Every secret-redaction test in the §22.5 suite starts here.
 *
 * THE ASSERTION SHAPE THIS FACTORY HAS TO SUPPORT: assert the fixture credential ACTUALLY REACHED
 * the path under test, and only THEN assert it appears in no log line, no span attribute, no audit
 * `details`, and no response body. A redaction test that passes because the key was never used is
 * not a redaction test.
 *
 * So the fixture key is a distinctive, greppable LITERAL rather than a faker value: the assertion
 * is a substring search over a full response capture, the log output, and any persisted snapshot.
 * It is deliberately not shaped like a live `sk-` key either, or secret scanners flag it on every
 * PR forever.
 *
 * `assertDatabaseHas` on `credential_ciphertext` will NEVER match — the column holds
 * envelope-encrypted bytes under a per-credential data key, so the plaintext is not in the row.
 * Assert through CredentialVault::open(), and assert the MASKED form on the API Resource.
 *
 * @extends Factory<ProviderConnection>
 */
final class ProviderConnectionFactory extends Factory
{
    /**
     * The plaintext fixture credential. Distinctive on purpose: redaction assertions grep for it.
     */
    public const FIXTURE_CREDENTIAL = 'kb-fixture-credential-DO-NOT-LOG-01JQZ';

    protected $model = ProviderConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = $this->getRandomRecycledModel(Organization::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'ProviderConnectionFactory requires a recycled organization: '
                .'ProviderConnection::factory()->recycle($org). Letting it mint its own would put '
                .'the connection in a THIRD organization, which is the failure that makes an '
                .'isolation test pass with the tenant filter deleted.',
            );
        }

        $sealed = app(CredentialVault::class)->seal(self::FIXTURE_CREDENTIAL);

        return [
            'organization_id' => $organization->id,
            'provider' => Provider::OpenAI,
            // DISTINGUISHABLE between the two organizations of a pair — a shared literal here
            // makes a cross-tenant leak compare equal to itself.
            'label' => $this->faker->unique()->words(2, true),
            'credential_ciphertext' => $sealed['credential_ciphertext'],
            'data_key_ciphertext' => $sealed['data_key_ciphertext'],
            'key_version' => $sealed['key_version'],
            'last_four' => $sealed['last_four'],
            'status' => ProviderConnectionStatus::Active,
        ];
    }

    public function provider(Provider $provider): static
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['status' => ProviderConnectionStatus::Revoked]);
    }

    /**
     * Register a `provider_models` row under this connection.
     *
     * `$supported` is passed EXPLICITLY by the caller and never defaulted to something plausible.
     * The whole of finding C1 turns on which flags a row carries, so a factory that guessed them
     * would let a readiness test pass for a reason the test author never wrote down.
     *
     * @param  list<string>  $supported
     */
    public function withModel(string $model, array $supported, int $contextWindow = 8192): static
    {
        return $this->afterCreating(function (ProviderConnection $connection) use (
            $model,
            $supported,
            $contextWindow,
        ): void {
            $row = new ProviderModelEntry;
            // Denormalized from the CONNECTION's organization, which the composite foreign key
            // then re-checks. Never from a recycled model resolved a second time: two lookups that
            // can disagree is how a fixture ends up spanning two organizations.
            $row->organization_id = $connection->organization_id;
            $row->provider_connection_id = $connection->id;
            $row->model = $model;
            $row->display_name = $model;
            $row->capability_flags = ['supported' => $supported];
            $row->context_window = $contextWindow;
            $row->max_output_tokens = 0;
            $row->enabled = true;
            $row->save();
        });
    }
}
