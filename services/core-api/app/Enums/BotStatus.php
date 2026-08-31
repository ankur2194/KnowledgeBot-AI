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
     * Whether an ADMINISTRATOR of this bot's organization may run it from the D5 playground.
     *
     * ── IT IS A SECOND, NARROWER METHOD AND NEVER A WIDENING OF `isRetrievable()` ──────────────
     *
     * The warning above — "a single `isUsable()` consulted by both is how a draft bot becomes
     * answerable on a customer's website" — is about ONE method serving TWO surfaces. This is the
     * opposite shape: two methods, each naming its own surface, each written as a POSITIVE list, so
     * widening one cannot widen the other and neither has a `!==` that admits whatever is added
     * next.
     *
     * ── THE ENUM CONTRADICTS ITSELF ABOUT THIS AND THE CONTRADICTION IS RESOLVED FAIL-CLOSED ──
     *
     * `isRetrievable()`'s paragraph says the playground/public split "is a property of the SURFACE,
     * not of this enum, so … the playground authorizes through `BotPolicy::view()` instead" — i.e.
     * a permission check and no status check at all. `Draft`'s own case comment says the opposite
     * and says it more specifically: *"Never reachable from any channel, including the admin
     * playground."* Both cannot hold: authorization by policy alone admits a `draft` bot.
     *
     * The specific statement wins, and it is also the fail-closed one, so this method refuses
     * `Draft`. It refuses `Paused` and `Archived` for the reason those two cases give directly —
     * *"Neither answers."* `Testing` is admitted because its case comment exists for exactly this
     * surface: *"Reachable from the admin playground only, by a member of the owning organization."*
     *
     * ── AND THE PERMISSION IS `bots.manage`, NOT `bots.view` ───────────────────────────────────
     *
     * A second divergence from `isRetrievable()`'s paragraph, decided the same way and recorded
     * here rather than left to be discovered. `Testing`'s case comment says "by a member of the
     * owning organization", which is `bots.view` — held by all four roles. A playground turn spends
     * the organization's provider quota and writes a `conversations` row, so it is a WRITE wearing
     * a chat control, and an analyst who may only READ a bot's settings must not be able to spend
     * tokens from that screen. The mint therefore authorizes `BotPolicy::update()`. This method
     * decides the STATUS half only; the permission half is the policy's.
     */
    public function isPlaygroundReachable(): bool
    {
        return $this === self::Testing || $this === self::Published;
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
