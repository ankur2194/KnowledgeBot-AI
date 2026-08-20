<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The general-knowledge behaviour of docs/07 §12.19. Two modes, and the default is `Strict`.
 *
 * THE DEFAULT IS IN THE SPECIFICATION AND IS NOT A PREFERENCE: *"The default should be strict RAG
 * mode because the project is intended to demonstrate grounded answers."* It is written into the
 * column default as well as into this file, because a row inserted by a repair script or a seeder
 * that never touched a FormRequest still has to land on the grounded side.
 *
 * WHAT THE TWO MODES ACTUALLY CHANGE is not this enum's business — stage 12's refusal and stage 14's
 * prompt are `kb-rag-query-contract`'s, and the disclosure obligation on `RagFirst` is
 * `kb-ai-chat-ux`'s. This enum carries the bot's choice across the seam in the configuration
 * snapshot and nothing else. In particular, NOTHING HERE DECIDES WHETHER A BOT MAY ANSWER FROM
 * MODEL KNOWLEDGE: that is `bots.allow_general_answers`, a separate column, and the two are
 * deliberately not one field — see the migration, which records why a mode and an escape hatch that
 * always move together would make the publish guard unexpressible.
 */
enum BotAnswerMode: string
{
    /** Answer only from active knowledge sources; refuse when nothing clears the evidence gate. */
    case Strict = 'strict';
    /** Use sources first, and allow general model knowledge only when clearly disclosed. */
    case RagFirst = 'rag_first';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
