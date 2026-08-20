<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BotAccessMode;
use App\Enums\BotAnswerMode;
use App\Enums\BotDomainStatus;
use App\Enums\BotStatus;
use App\Enums\EvidenceThresholdScale;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The retrieval scope. `bot_ids` is one of the four mandatory Qdrant filter terms, so a bot fixture
 * that is quietly attached to the wrong organization defeats an isolation assertion without
 * touching a single line of production code.
 *
 * ── IT REQUIRES A RECYCLED ORGANIZATION AND REFUSES TO RUN OTHERWISE ──────────────────────────
 *
 * `Bot::factory()->for($orgA)` fixes ONE edge. Every NESTED factory this definition resolves —
 * the provider connection, the model row, the origin allow-list, the starter questions — still
 * mints its own organization unless it is pinned. `recycle($orgA)` is what pins them all, and the
 * symptom of getting it wrong is an isolation test that passes with the tenant filter deleted.
 * So the definition below resolves NOTHING it cannot take from the recycled organization, and it
 * raises rather than minting one. Same rule, same wording, and the same reason as
 * ProviderModelEntryFactory.
 *
 * ── THE DEFAULT BOT IS A DRAFT WITH NO MODEL, BECAUSE THAT IS THE FIRST REAL STATE ────────────
 *
 * `status: draft`, `access_mode: private`, `provider_connection_id: null`,
 * `provider_model_id: null`. A factory that produced a fully-configured published bot would leave
 * the half-configured branch — the one every bot passes through and the one the publish guard
 * exists for — untested, and would make every `MATCH SIMPLE` foreign key in the schema exercised
 * only in its populated form. `usingModel()` is the state that configures one, and it takes the
 * connection and the model row EXPLICITLY so the call site says which organization's they are.
 *
 * ── EVERY HUMAN-READABLE COLUMN IS DISTINGUISHABLE BETWEEN TWO ORGANIZATIONS ──────────────────
 *
 * `name`, `slug`, `welcome_message` and `public_bot_id` are all unique per row. A factory producing
 * `name: 'Test'` for both organizations of a pair makes a cross-tenant leak compare equal to
 * itself: the response body genuinely contains Org B's row, the assertion compares it against Org
 * A's name, and they are the same string. The whole isolation suite is built on the assumption that
 * a leaked value LOOKS like a value from the wrong org.
 *
 * ── THE EVIDENCE THRESHOLD IS NULL AND THERE IS NO STATE THAT SETS ONE CASUALLY ───────────────
 *
 * `evidence_threshold` and `evidence_threshold_scale` are both null by default, which is the
 * production state for every bot nobody has calibrated — and calibration is per `(provider, model)`
 * and deliberately absent from this platform today. `thresholdedAt()` writes both together, because
 * either alone is refused by `bots_evidence_threshold_paired` and a fixture that could produce that
 * row would fail inside the database rather than in this file.
 *
 * @extends Factory<Bot>
 */
final class BotFactory extends Factory
{
    protected $model = Bot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = $this->requireOrganization();
        $name = $this->faker->unique()->company().' Bot';

        return [
            // Taken from the RECYCLED organization and from nowhere else. `organization_id` is not
            // in the model's $fillable, so nothing outside a factory or a repository can set it.
            'organization_id' => $organization->id,

            // OPAQUE, GLOBALLY UNIQUE, AND VISIBLY NOT A ULID. The `pub_` prefix is there so a
            // reader of a failing assertion can tell at a glance which identifier they are looking
            // at — a bare 26-character random string next to a ULID is not distinguishable by eye,
            // and the entire point of this column is that the two are different keys.
            'public_bot_id' => 'pub_'.Str::lower(Str::random(24)),

            'name' => $name,
            // Unique PER ORGANIZATION in the schema, so the random suffix is not strictly required
            // — it is here because a fixture whose two organizations hold the SAME slug is the one
            // that proves the uniqueness is per-org, and that fixture has to set the slug
            // explicitly. A colliding default would make the same test pass by accident.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => $this->faker->unique()->sentence(),

            // WHERE THE CANARY GOES. tenantPair() plants a fresh per-test string in Org B's
            // welcome message and every isolation test asserts it is absent from Org A's raw
            // response body — so a canary hiding in a cached theme payload, a bootstrap response or
            // an export cell still trips the assertion. Distinguishable by default for the reason
            // above.
            'welcome_message' => 'Welcome to '.$name.'.',
            'placeholder_text' => 'Ask '.$name.' a question',
            'system_instruction' => null,
            'answer_style_instruction' => null,

            // The first real state: created, not configured, not exposed.
            'status' => BotStatus::Draft,
            'access_mode' => BotAccessMode::Private,
            'provider_connection_id' => null,
            'provider_model_id' => null,

            // docs/07 §12.7-12.12 as written. Restating them here rather than relying on the column
            // defaults is deliberate: a test that asserts the shipped depths would otherwise be
            // asserting that the DATABASE has them, which is a different claim and one that a
            // `->create(['dense_top_k' => 5])` elsewhere in the same file cannot invalidate.
            'answer_mode' => BotAnswerMode::Strict,
            'dense_top_k' => 20,
            'sparse_top_k' => 20,
            'rerank_candidates' => 20,
            'rerank_retain' => 6,

            // NULL ON PURPOSE — see the class docblock. There is no portable default and
            // CALIBRATIONS is empty by design.
            'evidence_threshold' => null,
            'evidence_threshold_scale' => null,

            'retrieval_configuration_version' => 1,

            'allow_general_answers' => false,
            // The empty object, which is "this bot renders the platform theme". A themed fixture
            // would leave the unthemed branch — the majority state — untested.
            'theme' => [],

            'rate_limit_per_minute' => null,
            'rate_limit_per_day' => null,
            'retention_days' => null,

            // False, with no consent text. `bots_consent_text_present_when_collecting` refuses the
            // other combination, so `collecting()` writes both together.
            'collect_end_user_data' => false,
            'consent_text' => null,
        ];
    }

    /**
     * Point this bot at a connection and a model row.
     *
     * BOTH ARE EXPLICIT AND NEITHER IS MINTED. The composite foreign keys `bots_connection_same_org`
     * and `bots_model_same_org` refuse a disagreement, so a fixture that got this wrong would
     * arrive as SQLSTATE 23503 with a constraint name — which reads like a schema bug and sends the
     * reader to the migration. The check below names the actual mistake instead, exactly as
     * ProviderModelEntryFactory does for its own two parents.
     */
    public function usingModel(ProviderConnection $connection, ProviderModelEntry $model): static
    {
        return $this->state(function () use ($connection, $model): array {
            $organization = $this->requireOrganization();

            if ($connection->organization_id !== $organization->id
                || $model->organization_id !== $organization->id) {
                throw new RuntimeException(
                    'BotFactory::usingModel() was given a connection or a model belonging to a '
                    .'DIFFERENT organization than the recycled one. In a two-organization fixture '
                    .'that is a one-letter typo, and the row it would produce is exactly the '
                    .'cross-tenant row every isolation test in this suite exists to prove cannot '
                    .'be reached.',
                );
            }

            if ($model->provider_connection_id !== $connection->id) {
                // Not refused by any constraint on `bots`: the two composite keys check each
                // reference against the ORGANIZATION and neither checks them against each other.
                // A bot naming connection A and a model row registered under connection B is a
                // fixture describing a configuration the service layer would never write, and a
                // test built on it asserts nothing about production.
                throw new RuntimeException(
                    'BotFactory::usingModel() was given a model row that is not registered under '
                    .'the given connection. No constraint on `bots` refuses that pairing, which is '
                    .'precisely why the fixture must.',
                );
            }

            return [
                'provider_connection_id' => $connection->id,
                'provider_model_id' => $model->id,
            ];
        });
    }

    /**
     * Give this bot a known widget origin allow-list.
     *
     * NEVER RANDOMIZE THIS LIST. The origin-rejection tests it exists for assert that an origin NOT
     * on the allow-list is refused; a faker-generated list would make that assertion depend on
     * faker never colliding with the origin the test sends. The caller passes origins explicitly,
     * and they must already be in the exact serialized form `bot_domains_origin_exact` accepts —
     * scheme, host, optional port, lower case, no trailing slash, no wildcard.
     *
     * The rows are created `Active`, because a `Pending` row grants nothing and a fixture built on
     * one would make an "allowed origin is accepted" assertion fail for a reason unrelated to what
     * it is testing. A test that needs the pending branch says so with BotDomain::factory().
     *
     * @param  list<string>  $origins
     */
    public function withOrigins(array $origins): static
    {
        return $this->afterCreating(function (Bot $bot) use ($origins): void {
            foreach ($origins as $origin) {
                $domain = new BotDomain;
                // Written field by field rather than through BotDomain::factory(), because
                // `organization_id` and `bot_id` are both outside $fillable — deliberately, since
                // they are the ownership edges — and the factory for a child would need the same
                // two arguments this closure already holds.
                $domain->organization_id = $bot->organization_id;
                $domain->bot_id = $bot->id;
                $domain->origin = $origin;
                $domain->status = BotDomainStatus::Active;
                $domain->save();
            }
        });
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => BotStatus::Published]);
    }

    /**
     * A bot an anonymous end user may converse with. NOT the same as published, and not the same as
     * having an origin allow-list — all three are separate facts and a bot is reachable only when
     * every one of them agrees.
     */
    public function publiclyAccessible(): static
    {
        return $this->state(fn (): array => ['access_mode' => BotAccessMode::Public]);
    }

    public function status(BotStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * Record an evidence threshold together with the scale it is measured on.
     *
     * BOTH HALVES, ALWAYS. `bots_evidence_threshold_paired` refuses either alone, and a fixture
     * that could produce that row would fail inside the database rather than in this file. The
     * scale is a value object argument rather than a string so a test cannot write `uncalibrated`,
     * which is a member of the data plane's RerankScale and deliberately not a case here.
     */
    public function thresholdedAt(float $threshold, EvidenceThresholdScale $scale): static
    {
        return $this->state(fn (): array => [
            'evidence_threshold' => $threshold,
            'evidence_threshold_scale' => $scale,
        ]);
    }

    /**
     * Collect end-user data, with the consent text that discloses it.
     *
     * Both together, for the same reason `thresholdedAt()` takes both: collecting without a
     * disclosure is refused by `bots_consent_text_present_when_collecting`.
     */
    public function collecting(string $consentText): static
    {
        return $this->state(fn (): array => [
            'collect_end_user_data' => true,
            'consent_text' => $consentText,
        ]);
    }

    /**
     * The recycled organization, or a failure that names the fixture mistake.
     *
     * `getRandomRecycledModel()` and not `$this->recycle` directly: it is the documented accessor
     * and the one that returns null rather than raising when nothing was recycled, which is the
     * case this method has to detect and refuse loudly.
     */
    private function requireOrganization(): Organization
    {
        $organization = $this->getRandomRecycledModel(Organization::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'BotFactory requires a recycled organization: Bot::factory()->recycle($org). '
                .'Letting it mint its own would put the bot in a THIRD organization, which is the '
                .'failure that makes an isolation test pass with the tenant filter deleted — the '
                .'row it was meant to prove was hidden belonged to nobody in the test to begin '
                .'with.',
            );
        }

        return $organization;
    }
}
