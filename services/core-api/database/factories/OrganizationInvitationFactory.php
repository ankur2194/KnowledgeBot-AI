<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\Kb\OpaqueToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The pending membership. Every account-enumeration assertion about the invitation flow starts here.
 *
 * TWO THINGS THIS FACTORY EXISTS TO MAKE IMPOSSIBLE TO GET WRONG:
 *
 * 1. A THIRD ORGANIZATION. The organization is taken from recycle() and the factory REFUSES rather
 *    than minting its own, exactly as UserFactory::orgRole() and ProviderConnectionFactory do. An
 *    invitation that quietly belongs to an organization the test never named is the failure that
 *    makes an isolation test pass with the tenant filter deleted.
 * 2. AN UNUSABLE TOKEN. The row stores only sha256(token); the plaintext is never persisted, so a
 *    test that needs to drive the HTTP path has to know it in advance. definition() therefore mints a
 *    FRESH random token per row (so `organization_invitations_token_hash` cannot collide across a
 *    fixture set) and token() lets a test pin the one row it will POST.
 *
 * `assertDatabaseHas` on `token_hash` will never match a plaintext. Compare against
 * self::hashToken($plaintext), or assert through the endpoint.
 *
 * @extends Factory<OrganizationInvitation>
 */
final class OrganizationInvitationFactory extends Factory
{
    /**
     * The plaintext fixture token, for the one row a test drives over HTTP.
     *
     * Exactly OpaqueToken::LENGTH (64) characters, because the FormRequests validate `size:64`. A
     * literal rather than a derived string because a constant expression cannot call str_repeat();
     * the length is asserted against OpaqueToken::LENGTH by the invitation Feature test.
     *
     * It is deliberately NOT hex and deliberately says what it is: a
     * distinctive literal is greppable by the redaction assertions ("this token appears in no log
     * line and no response body"), and 64 characters of real-looking hex is what makes a secret
     * scanner flag every PR forever. Nothing validates the alphabet, only the length.
     */
    public const FIXTURE_TOKEN = 'kb-fixture-invitation-token-DO-NOT-LOG-0000000000000000000000000';

    protected $model = OrganizationInvitation::class;

    /**
     * The digest actually stored: 32 RAW bytes, not 64 hex characters. The column is `bytea` and
     * BinaryCast hex-encodes on the way to PostgreSQL, so passing hex here would store the hex OF
     * the hex and every lookup would miss.
     *
     * DELEGATES TO OpaqueToken RATHER THAN RECOMPUTING. A fixture that hashed a token its own way
     * would pass every test it wrote while never matching a row the production mint created — the
     * exact drift a second copy of a shared computation always produces, and the reason this is a
     * one-line forward rather than a second `hash('sha256', …)`.
     */
    public static function hashToken(string $plaintextToken): string
    {
        return OpaqueToken::digest($plaintextToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = $this->getRandomRecycledModel(Organization::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'OrganizationInvitationFactory requires a recycled organization: '
                .'OrganizationInvitation::factory()->recycle($org). Letting it mint its own would '
                .'put the invitation in a THIRD organization, which is the failure that makes an '
                .'isolation test pass with the tenant filter deleted.',
            );
        }

        return [
            'organization_id' => $organization->id,
            // LOWERCASED, because `organization_invitations_email_lowercase` is a CHECK constraint:
            // a mixed-case row is unreachable by the normalised lookup that has to find it, so the
            // database refuses to create one. Faker's safeEmail() is lowercase in practice and this
            // does not rely on that.
            'email' => Str::lower((string) $this->faker->unique()->safeEmail()),
            // ANALYST, the least authoritative role, on purpose. A test that forgets to name a role
            // cannot accidentally get a fixture that grants something — and it can never
            // accidentally get `owner`, which needs a separate permission to issue.
            'role' => OrgRole::Analyst,
            'token_hash' => self::hashToken(OpaqueToken::mint()),
            // A CLOSURE, NOT AN EAGER CALL, and the laziness is load-bearing. Factory merges every
            // state over definition() BEFORE expandAttributes() resolves closures, so a test that
            // says ->invitedBy($someone) discards this key before it is ever invoked — no spurious
            // owner user, and no extra row in a member-list assertion. An eager
            // `$this->inviterFor(...)->id` would create that user on every build regardless.
            'invited_by_id' => fn (): string => $this->inviterFor($organization)->id,
            // A FIXTURE default, not the product's TTL. The real expiry is set by the invitation
            // service from config; nothing should read this number as the policy.
            'expires_at' => CarbonImmutable::now()->addDays(7),
        ];
    }

    /**
     * Pin the plaintext token so a test can POST it. Pass self::FIXTURE_TOKEN for the common case.
     */
    public function token(string $plaintextToken): static
    {
        return $this->state(fn (): array => ['token_hash' => self::hashToken($plaintextToken)]);
    }

    public function role(OrgRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    /**
     * Name the inviter explicitly. Worth using whenever the test asserts on `invited_by_name`, or
     * whenever it recycles a user from ANOTHER organization — inviterFor() honours recycle(), which
     * is the right default and the wrong one for a two-organization fixture that recycled org B's
     * admin for its own reasons.
     */
    public function invitedBy(User $inviter): static
    {
        return $this->state(fn (): array => ['invited_by_id' => $inviter->id]);
    }

    /**
     * Past its expiry but never accepted and never revoked — so it is still the row that
     * `organization_invitations_one_pending_per_email` considers live. That is intentional and is the
     * case worth testing: an expired invitation must read as invalid to a token holder AND must not
     * be silently re-invitable without revoking it first.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => CarbonImmutable::now()->subDay()]);
    }

    /**
     * `accepted_by_id` is REQUIRED rather than defaulted to a plausible user, for two reasons. The
     * CHECK constraint `organization_invitations_accepted_has_actor` pairs the two columns, so a
     * state that set one without the other would fail at insert with a constraint name instead of a
     * readable message. And who accepted is precisely what an acceptance test asserts, so a factory
     * that guessed it would let the test pass for a reason nobody wrote down.
     */
    public function accepted(User $acceptedBy): static
    {
        return $this->state(fn (): array => [
            'accepted_at' => CarbonImmutable::now(),
            'accepted_by_id' => $acceptedBy->id,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => CarbonImmutable::now()]);
    }

    /**
     * The accountable actor. Uses a recycled User when the test supplied one, and otherwise mints an
     * OWNER of the recycled organization — the only role that could have issued any invitation,
     * including one for `owner`. Never a bare User::factory(): `invited_by_id` references a real
     * person whose membership is what made the invitation legitimate, and a fixture inviter with no
     * membership is a row that could not exist in production.
     */
    private function inviterFor(Organization $organization): User
    {
        $recycled = $this->getRandomRecycledModel(User::class);

        if ($recycled instanceof User) {
            return $recycled;
        }

        // createOne(), not create(): create() is declared as `TModel|Collection<int, TModel>` and a
        // `: User` return type over it fails PHPStan level 8. createOne() is the single-model form.
        return User::factory()->recycle($organization)->orgRole(OrgRole::Owner)->createOne();
    }
}
