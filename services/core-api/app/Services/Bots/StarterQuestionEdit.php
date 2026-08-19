<?php

declare(strict_types=1);

namespace App\Services\Bots;

/**
 * The validated input to a starter-question PATCH.
 *
 * ── NULL MEANS "NOT SUPPLIED" HERE, AND THAT IS SAFE FOR EXACTLY THE REASON IT IS NOT ON `BotEdit` ─
 *
 * `BotEdit` is a column MAP rather than a set of nullable members because a bot has twelve nullable
 * columns, so "the field was absent" and "the field was sent as null" are two different
 * instructions and one nullable member cannot carry both. Neither of the two columns here is
 * nullable — `question` is NOT NULL with a non-blank CHECK, `sort_order` is NOT NULL with a
 * non-negative CHECK — so there is no "clear it" instruction to express and the ambiguity cannot
 * arise. The same call `ProviderConnectionEdit` makes, and it says so for the same reason.
 */
final readonly class StarterQuestionEdit
{
    public function __construct(
        public ?string $question = null,
        /**
         * The position the caller wants this question MOVED TO — not a value to write into the
         * column. `BotStarterQuestionService` re-sequences the whole list; `UpdateBotStarterQuestionRequest`
         * records why a direct write would collide with the unique index.
         */
        public ?int $position = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->question === null && $this->position === null;
    }
}
