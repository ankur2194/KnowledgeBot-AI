<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who produced one message (docs/11 §16.6).
 *
 * ── THREE ROLES, AND `System` IS NOT THE BOT'S SYSTEM INSTRUCTION ───────────────────────────
 *
 * THE BOT'S INSTRUCTIONS ARE NEVER A ROW IN THIS TABLE. `bots.system_instruction` and
 * `bots.answer_style_instruction` are configuration; they are assembled into the prompt by the
 * control plane and shipped in the configuration snapshot. Storing them as a `system` message would
 * put a prompt an operator edits into a transcript that must stay a record of what happened, and it
 * would make the retrieved-content boundary (non-negotiable 7) one row-order mistake wide: a
 * `system` row the transcript can carry is a `system` row a replay can be talked into carrying.
 *
 * `System` here is the PLATFORM speaking to the participants in the thread — "this conversation was
 * ended by an administrator", "the model could not be reached". It has no provider call behind it
 * and it is never sent to a provider. It exists because the alternative is rendering those notices
 * as `assistant` messages, which makes the bot appear to have said something it did not.
 *
 * ── NO `tool` ROLE, AND ITS ABSENCE IS DELIBERATE ───────────────────────────────────────────
 *
 * This platform has no tool-calling surface. Adding the value "so it is there" would make the
 * CHECK constraint accept rows nothing writes and nothing renders, and the first thing to write one
 * would be an adapter mapping a provider's own vocabulary straight through — which is exactly the
 * kind of untrusted passthrough the retrieved-content rule exists to stop.
 */
enum MessageRole: string
{
    /** A question or instruction from the human. UNTRUSTED INPUT, always. */
    case User = 'user';

    /** The bot's answer. Produced by a provider call, cited from retrieved evidence. */
    case Assistant = 'assistant';

    /** A platform notice. No provider call, never sent to a provider. See the class docblock. */
    case System = 'system';

    /**
     * Whether a message in this role may name a `provider_call_id`.
     *
     * A user turn costs nothing and a platform notice is generated here, so a provider call on
     * either is a mis-attributed cost. `messages_provider_call_only_on_assistant` is generated
     * from this and refuses the row.
     */
    public function mayHaveProviderCall(): bool
    {
        return $this === self::Assistant;
    }

    /**
     * The roles that may NOT name a provider call, as wire values, for the CHECK constraint.
     *
     * @return list<string>
     */
    public static function withoutProviderCalls(): array
    {
        return array_values(array_map(
            static fn (self $c): string => $c->value,
            array_filter(self::cases(), static fn (self $c): bool => ! $c->mayHaveProviderCall()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
