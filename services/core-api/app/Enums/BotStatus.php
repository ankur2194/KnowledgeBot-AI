<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The bot lifecycle of docs/02 §8.3: draft, testing, published, paused, archived.
 *
 * TEXT + CHECK IN THE MIGRATION, NEVER A NATIVE PG ENUM, for the reason organizations' own status
 * column records: `ALTER TYPE ... ADD VALUE` cannot be rolled back, so adding a state would be an
 * irreversible migration. The five values live here and in one CHECK constraint, and
 * `bots_status_check` is generated from `values()` so the two cannot drift.
 *
 * ── `isRetrievable()` IS NOT A UI CONCERN AND IS NOT A SYNONYM FOR `Published` ─────────────────
 *
 * It answers "may an end user on a channel reach this bot at all", and it is deliberately narrower
 * than "is this bot editable". A bot in `Testing` is reachable from the admin playground by a
 * member of its organization and is NOT reachable from hosted chat, the widget, or mobile; that
 * distinction is a property of the SURFACE, not of this enum, so this method answers only the
 * public half and the playground authorizes through `BotPolicy::view()` instead. Writing it the
 * other way — a single `isUsable()` consulted by both — is how a draft bot becomes answerable on a
 * customer's website.
 *
 * `Paused` and `Archived` differ in intent and not in reachability: a paused bot is expected back,
 * an archived one is not. Neither answers. They are separate cases because an operator looking at a
 * list has to be able to tell "we turned this off for the weekend" from "this is over", and a
 * single `disabled` value makes that question unanswerable from the console.
 */
enum BotStatus: string
{
    /** Being configured. Never reachable from any channel, including the admin playground. */
    case Draft = 'draft';
    /** Reachable from the admin playground only, by a member of the owning organization. */
    case Testing = 'testing';
    /** Live on every channel the bot's access mode and origin allow-list permit. */
    case Published = 'published';
    /** Temporarily withdrawn. The configuration and the knowledge assignments survive untouched. */
    case Paused = 'paused';
    /** Permanently withdrawn. The row survives so conversation history and audit entries resolve. */
    case Archived = 'archived';

    /**
     * Whether an END USER on a public channel may reach this bot.
     *
     * One value, and that is the point: every widening of this set is a bot answering somebody it
     * was not published for.
     */
    public function isRetrievable(): bool
    {
        return $this === self::Published;
    }

    /**
     * Whether the bot's configuration may still be edited.
     *
     * This is CHECK 5 of the six (kb-security-baseline §18.4) for every write on a bot, and it is
     * the check `Gate::authorize()` cannot make: a policy authorizes a caller, not a state. An
     * archived bot is read-only — its configuration is the record of what answered, and editing it
     * rewrites the explanation of past conversations without changing the conversations.
     */
    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
