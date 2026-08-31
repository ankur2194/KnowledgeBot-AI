<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * One turn.
 *
 * ── IT REQUIRES A RECYCLED CONVERSATION AND REFUSES TO RUN OTHERWISE ──────────────────────────
 *
 * `Message::factory()->recycle($conversation)`. `messages` has no `organization_id`; the
 * conversation IS the tenant fact, so a minted parent is not merely a third row — it is a third
 * ORGANIZATION, and an isolation test built on one passes with the tenant filter deleted. There is
 * no organization to check the parent against here, which is precisely why the requirement is
 * absolute: this factory cannot detect the mistake any other way.
 *
 * ── THE DEFAULT IS A COMPLETE USER TURN ───────────────────────────────────────────────────────
 *
 * A user message is inserted directly as `Complete` — it has no provider call, nothing streams, and
 * its content exists at insert time. That makes it the only role whose default needs no second
 * decision, and it is the row every other fixture in a transcript hangs off.
 *
 * `->assistant()` is the interesting one and it deliberately does NOT default to `Complete`: an
 * assistant row starts `Pending` in production, before any token exists, and a fixture that skipped
 * that state would make every "the reaper settles an abandoned stream" test pass whether or not the
 * pre-terminal states are ever written. `->assistant()->settled()` says the finished case out loud.
 *
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $conversation = $this->requireConversation();

        return [
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            // DISTINGUISHABLE BETWEEN TWO FIXTURES BY DEFAULT. A shared literal makes a cross-tenant
            // leak compare equal to itself, which is the failure mode of every isolation assertion
            // written against a constant.
            'content' => $this->faker->unique()->sentence().' ?',
            'status' => MessageStatus::Complete,
            'parent_message_id' => null,
            'provider_call_id' => null,
        ];
    }

    /**
     * An assistant answer that has not started yet: `Pending`, no content.
     *
     * `messages_content_present_when_complete` permits a null only outside `complete`, which is why
     * this state moves both columns together.
     */
    public function assistant(): static
    {
        return $this->state(fn (): array => [
            'role' => MessageRole::Assistant,
            'content' => null,
            'status' => MessageStatus::Pending,
        ]);
    }

    /** A platform notice. No provider call, ever — `messages_provider_call_only_on_assistant`. */
    public function system(string $notice = 'This conversation was ended by an administrator.'): static
    {
        return $this->state(fn (): array => [
            'role' => MessageRole::System,
            'content' => $notice,
            'status' => MessageStatus::Complete,
        ]);
    }

    /** Mid-stream. The one state whose content is deliberately partial. */
    public function streaming(string $partial = 'The refund window is'): static
    {
        return $this->state(fn (): array => [
            'content' => $partial,
            'status' => MessageStatus::Streaming,
        ]);
    }

    /** Finished, with text. Use after `->assistant()`. */
    public function settled(?string $content = null): static
    {
        return $this->state(fn (): array => [
            'content' => $content ?? $this->faker->unique()->paragraph(),
            'status' => MessageStatus::Complete,
        ]);
    }

    /** Ended in an error. `provider_calls.error_class` is where the class lives, not here. */
    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => MessageStatus::Failed]);
    }

    /** The client went away. NOT an error — see MessageStatus. */
    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => MessageStatus::Cancelled]);
    }

    /**
     * A RETRY of an earlier turn.
     *
     * IT COPIES THE PARENT'S `conversation_id` RATHER THAN TRUSTING THE RECYCLED ONE, because
     * `messages_parent_same_conversation` is a COMPOSITE key: a retry whose conversation disagrees
     * with its parent's is refused by the database, and the refusal would arrive as a constraint
     * name rather than as the fixture mistake it is. Copying makes the pair correct by construction.
     */
    public function retryOf(Message $parent): static
    {
        return $this->state(fn (): array => [
            'conversation_id' => $parent->conversation_id,
            'parent_message_id' => $parent->id,
            'role' => $parent->role,
        ]);
    }

    private function requireConversation(): Conversation
    {
        $conversation = $this->getRandomRecycledModel(Conversation::class);

        if (! $conversation instanceof Conversation) {
            throw new RuntimeException(
                'MessageFactory requires a recycled conversation: '
                .'Message::factory()->recycle($conversation). A message has NO organization_id — '
                .'the conversation is the tenant fact — so minting one here would put this turn in '
                .'a third organization that nothing in the test can name, and this factory has no '
                .'organization to check it against. That is the fixture shape under which an '
                .'isolation test passes with the tenant filter deleted.',
            );
        }

        return $conversation;
    }
}
