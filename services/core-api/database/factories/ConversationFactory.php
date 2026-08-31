<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One thread, for a fixture that needs a transcript to already exist.
 *
 * ── IT REQUIRES BOTH PARENTS RECYCLED, AND REFUSES TO RUN OTHERWISE ───────────────────────────
 *
 * `Conversation::factory()->recycle($organization)->recycle($bot)`. Neither is optional and neither
 * is minted here, for the reason `BotDomainFactory` and `ProviderModelEntryFactory` both state: a
 * factory that mints its own parent puts the row in a THIRD organization, and an isolation test
 * built on such a fixture passes with the tenant filter deleted.
 *
 * The two recycled models are checked AGAINST EACH OTHER as well. `conversations_bot_same_org` would
 * refuse a disagreement — so the mistake is a 23503 rather than a silent cross-tenant row — but the
 * failure arrives as a constraint name inside a factory, which reads like a schema bug and sends
 * the reader to the migration.
 *
 * ── THE DEFAULT IS AN ANONYMOUS HOSTED VISITOR, WHICH IS THE COMMON AND WEAKER CASE ───────────
 *
 * A conversation carries EXACTLY ONE of `user_id` and `anonymous_session_id`
 * (`conversations_participant_exclusive`), so the default has to pick one. It picks the anonymous
 * side deliberately: that is the path with no account behind it, the one every public surface
 * produces, and the one where an authorization mistake has no user to fall back on. A fixture that
 * defaulted to an authenticated participant would make every "an anonymous visitor may not reach
 * this" test pass whether or not the check exists. `->by($user)` says the other case out loud.
 *
 * ── THE DEFAULT STATUS IS `Active`, AND THAT IS NOT THE USUAL FAIL-CLOSED CHOICE ──────────────
 *
 * `BotDomainFactory` defaults to `Pending` because a domain row starts unusable and is PROMOTED. A
 * conversation is the opposite: `Active` is its first and only starting state, `ConversationStatus`
 * has no path back into it, and a fixture that started in a terminal state could not be moved into
 * one by any legal transition — so `->ended()` and `->expired()` would have nothing to test.
 *
 * @extends Factory<Conversation>
 */
final class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$organization, $bot] = $this->requireParents();

        $startedAt = CarbonImmutable::instance($this->faker->dateTimeBetween('-30 days', '-1 hour'));

        return [
            // Taken from the RECYCLED organization and from nowhere else. `organization_id` and
            // `bot_id` are both outside the model's $fillable, so nothing but a factory or a
            // repository can set either.
            'organization_id' => $organization->id,
            'bot_id' => $bot->id,

            // The anonymous side. See the class docblock for why this is the default, and note the
            // grammar: `conversations_anonymous_session_shape` pins 16-128 characters of
            // `[A-Za-z0-9_-]`, so a shorter or punctuated token is refused by the database.
            'user_id' => null,
            'anonymous_session_id' => 'sess_'.Str::lower(Str::random(26)),

            'channel' => ConversationChannel::Hosted,
            'status' => ConversationStatus::Active,
            'locale' => 'en',

            // No consent asked for. `conversations_consent_snapshot_present` requires the text
            // whenever this is true, which is why `->collecting()` sets both together.
            'consent_required' => false,
            'consent_granted_at' => null,
            'consent_text_snapshot' => null,

            'started_at' => $startedAt,
            // AFTER the start, always: `conversations_activity_after_start` refuses the other order,
            // and a fixture that produced it would fail as a constraint violation in whichever test
            // happened to use it rather than here.
            'last_activity_at' => $startedAt->addMinutes(5),
            'retention_expires_at' => null,
        ];
    }

    /**
     * An AUTHENTICATED participant, replacing the anonymous session rather than joining it.
     *
     * BOTH COLUMNS ARE SET, one to null, and that is not defensive noise: `->by($user)` applied to a
     * factory whose default filled the session column would otherwise produce a row with two
     * participants, which `conversations_participant_exclusive` refuses — correctly, and with a
     * message about a constraint rather than about the state.
     */
    public function by(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'anonymous_session_id' => null,
        ]);
    }

    /** The surface this thread was held on. */
    public function channel(ConversationChannel $channel): static
    {
        return $this->state(fn (): array => ['channel' => $channel]);
    }

    /**
     * Closed on purpose. Distinct from `expired()`: this one is somebody's decision.
     */
    public function ended(): static
    {
        return $this->state(fn (): array => ['status' => ConversationStatus::Ended]);
    }

    /** Closed by the clock. */
    public function expired(): static
    {
        return $this->state(fn (): array => ['status' => ConversationStatus::Expired]);
    }

    /**
     * A retention deadline. `$in` is relative to the thread's start, because
     * `conversations_retention_after_start` requires it to be later.
     */
    public function retainedFor(int $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'retention_expires_at' => CarbonImmutable::parse($attributes['started_at'])->addDays($days),
        ]);
    }

    /**
     * Consent was required, shown, and granted — all three, together.
     *
     * They move as a unit because two constraints tie them: `consent_required` demands the snapshot,
     * and a granted timestamp demands `consent_required`. A state that set one would be a fixture
     * whose only use is proving the constraint fires, and that assertion belongs in the schema test.
     */
    public function collecting(string $consentText = 'We store this conversation to improve answers.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'consent_required' => true,
            'consent_text_snapshot' => $consentText,
            'consent_granted_at' => CarbonImmutable::parse($attributes['started_at']),
        ]);
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
                'ConversationFactory requires a recycled organization: '
                .'Conversation::factory()->recycle($org)->recycle($bot). Letting it mint its own '
                .'would put the row in a THIRD organization, which is the failure that makes an '
                .'isolation test pass with the tenant filter deleted.',
            );
        }

        if (! $bot instanceof Bot) {
            throw new RuntimeException(
                'ConversationFactory requires a recycled bot: '
                .'Conversation::factory()->recycle($org)->recycle($bot). A conversation with no bot '
                .'is a transcript of nothing, and minting one here would create a second bot nobody '
                .'in the test can name — including the test that asserts a bot with conversations '
                .'cannot be deleted, which would then be asserting it about the wrong bot.',
            );
        }

        if ($bot->organization_id !== $organization->id) {
            throw new RuntimeException(
                'ConversationFactory was given an organization and a bot that belong to DIFFERENT '
                .'organizations. In a two-organization fixture that is a one-letter typo, and the '
                .'row it would produce is a transcript this tenant can read that another tenant\'s '
                .'bot answered — which every downstream layer would AGREE with, because you have '
                .'told it whose bot held the conversation.',
            );
        }

        return [$organization, $bot];
    }
}
