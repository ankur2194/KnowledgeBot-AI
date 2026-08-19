<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BotDomainStatus;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One entry in a bot's widget origin allow-list, for a fixture that needs the list to already
 * exist in a state `BotFactory::withOrigins()` does not produce.
 *
 * ── IT REQUIRES BOTH PARENTS RECYCLED, AND REFUSES TO RUN OTHERWISE ───────────────────────────
 *
 * `BotDomain::factory()->recycle($organization)->recycle($bot)`. Neither is optional and neither is
 * minted here, for the reason ProviderModelEntryFactory states about its own two parents: a factory
 * that mints its own parent puts the row in a THIRD organization, and an isolation test built on
 * such a fixture passes with the tenant filter deleted.
 *
 * The two recycled models are checked AGAINST EACH OTHER as well. The composite foreign key
 * `(organization_id, bot_id) -> bots (organization_id, id)` would refuse a disagreement — so the
 * mistake is a 23503 rather than a silent cross-tenant row — but the failure arrives as a
 * constraint name inside a factory, which reads like a schema bug. The exception below names the
 * actual mistake and how to fix it.
 *
 * ── THE ORIGIN IS EXPLICIT OR IT IS A GENERATED HOST NOBODY WILL COLLIDE WITH ─────────────────
 *
 * The default is `https://fixture-<random>.example` — a syntactically valid, exactly-serialized
 * origin under a reserved TLD, unique per row. It is NOT a plausible customer domain, because the
 * assertion these rows exist for is "an origin that is NOT on the list is refused", and a default
 * that could collide with the origin a test sends would make that assertion depend on luck.
 * `BotFactory::withOrigins()` is the state for a KNOWN list and says so in its own docblock.
 *
 * ── THE DEFAULT STATUS IS `Pending`, WHICH GRANTS NOTHING ─────────────────────────────────────
 *
 * The column default and this default agree on purpose: a row starts unusable and is promoted by a
 * verification path. A factory that defaulted to `Active` would make every "an allowed origin is
 * accepted" test pass whether or not the promotion step exists, which is the branch most worth
 * covering. Use `->active()` to say so out loud.
 *
 * @extends Factory<BotDomain>
 */
final class BotDomainFactory extends Factory
{
    protected $model = BotDomain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$organization, $bot] = $this->requireParents();

        return [
            'organization_id' => $organization->id,
            'bot_id' => $bot->id,
            // `.example` is reserved by RFC 2606 and can never be a real customer origin, and the
            // random label makes two rows in one fixture distinguishable in a failing assertion.
            'origin' => 'https://fixture-'.Str::lower(Str::random(10)).'.example',
            'status' => BotDomainStatus::Pending,
        ];
    }

    /** Verified and live. The one status that permits an embed. */
    public function active(): static
    {
        return $this->state(fn (): array => ['status' => BotDomainStatus::Active]);
    }

    /** Withdrawn. Distinct from `Pending`: this origin WAS verified and was then turned off. */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => BotDomainStatus::Disabled]);
    }

    /**
     * An exact origin, spelled by the caller.
     *
     * `bot_domains_origin_exact` refuses anything that is not scheme + host + optional port in
     * lower case, so a caller passing `https://Example.com/` gets a constraint violation rather than
     * a silently normalized row — which is correct: normalization belongs on the write path, where
     * there is a field to key a 422 on, not in a fixture.
     */
    public function origin(string $origin): static
    {
        return $this->state(fn (): array => ['origin' => $origin]);
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
                'BotDomainFactory requires a recycled organization: '
                .'BotDomain::factory()->recycle($org)->recycle($bot). Letting it mint its own '
                .'would put the row in a THIRD organization, which is the failure that makes an '
                .'isolation test pass with the tenant filter deleted.',
            );
        }

        if (! $bot instanceof Bot) {
            throw new RuntimeException(
                'BotDomainFactory requires a recycled bot: '
                .'BotDomain::factory()->recycle($org)->recycle($bot). An origin row with no bot '
                .'grants nothing to anybody, and minting one here would create a second bot nobody '
                .'in the test can name.',
            );
        }

        if ($bot->organization_id !== $organization->id) {
            throw new RuntimeException(
                'BotDomainFactory was given an organization and a bot that belong to DIFFERENT '
                .'organizations. In a two-organization fixture that is a one-letter typo, and the '
                .'row it would produce is a permanent cross-tenant grant that every downstream '
                .'check AGREES with — because you have taught it that this origin belongs to that '
                .'bot.',
            );
        }

        return [$organization, $bot];
    }
}
