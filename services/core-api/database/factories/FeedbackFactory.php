<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeedbackRating;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One thumb.
 *
 * ── IT REQUIRES A RECYCLED MESSAGE AND REFUSES TO RUN OTHERWISE ───────────────────────────────
 *
 * `Feedback::factory()->recycle($message)`. The message is the tenant fact — this table has no
 * `organization_id`, and `submitted_by_user_id` is NOT one either: an administrator may rate an
 * answer a customer received, so that column names who rated and never whose row it is.
 *
 * ── THE DEFAULT SUBMITTER IS A SESSION, FOR THE REASON `ConversationFactory`'s IS ─────────────
 *
 * A row carries EXACTLY ONE of the two submitter columns (`feedback_submitter_exclusive`), so the
 * default picks one, and it picks the anonymous side: that is the path with no account behind it,
 * the one the widget produces, and the one where an authorization mistake has no user to fall back
 * on. `->by($user)` says the other case out loud and clears the session column, because setting one
 * without clearing the other is a row the database refuses.
 *
 * ── THE DEFAULT IS `Positive`, AND THE ASYMMETRY IS WORTH KNOWING ─────────────────────────────
 *
 * Neither value is safer than the other, so the choice is arbitrary — except that a `Negative`
 * default would make every "the console surfaces unhappy answers" fixture pass by accident.
 * `->negative()` states it.
 *
 * @extends Factory<Feedback>
 */
final class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $message = $this->requireMessage();

        return [
            'message_id' => $message->id,
            'rating' => FeedbackRating::Positive,
            'comment' => null,

            // The anonymous side. The grammar is the one `feedback_session_shape` pins, and it is
            // the SAME grammar `conversations_anonymous_session_shape` pins, on purpose: they hold
            // tokens minted by the same code.
            'submitted_by_user_id' => null,
            'submitted_by_session' => 'sess_'.Str::lower(Str::random(26)),
        ];
    }

    /**
     * An authenticated rater, replacing the session rather than joining it.
     *
     * See the class docblock: `feedback_submitter_exclusive` refuses a row with both.
     */
    public function by(User $user): static
    {
        return $this->state(fn (): array => [
            'submitted_by_user_id' => $user->id,
            'submitted_by_session' => null,
        ]);
    }

    /** A specific session token, so a test can assert the one-verdict-per-person index. */
    public function bySession(string $session): static
    {
        return $this->state(fn (): array => [
            'submitted_by_session' => $session,
            'submitted_by_user_id' => null,
        ]);
    }

    public function negative(): static
    {
        return $this->state(fn (): array => ['rating' => FeedbackRating::Negative]);
    }

    /**
     * Free text from a stranger. Bounded at 4,000 characters by `feedback_comment_bounded`; this
     * factory does not enforce that, so a test can write the row the database must refuse.
     */
    public function commenting(string $comment): static
    {
        return $this->state(fn (): array => ['comment' => $comment]);
    }

    private function requireMessage(): Message
    {
        $message = $this->getRandomRecycledModel(Message::class);

        if (! $message instanceof Message) {
            throw new RuntimeException(
                'FeedbackFactory requires a recycled message: '
                .'Feedback::factory()->recycle($message). Feedback has NO organization_id — it '
                .'reaches one through `feedback -> messages -> conversations` — so minting a parent '
                .'here would put this verdict in a third organization nothing in the test can name. '
                .'`submitted_by_user_id` is not a substitute: it names WHO RATED, not whose row it '
                .'is, because an administrator may rate an answer a customer received.',
            );
        }

        return $message;
    }
}
