<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened when a `bots` row was asked to go away.
 *
 * ── WHY A THREE-STATE RESULT AND NOT A BOOLEAN ─────────────────────────────────────────────────
 *
 * This is `ProviderModelDeletion`'s shape and its argument applies verbatim, with one difference in
 * where the authority sits. There, the refusal had no constraint behind it. HERE THERE IS ONE —
 * `conversations_bot_same_org` is `ON DELETE RESTRICT`, so a bot with a transcript is undeletable
 * whatever the application does — and the enum exists anyway, because a constraint produces SQLSTATE
 * 23503 rendered by the error envelope as a 500, for a request that is entirely legitimate and whose
 * correct answer is a 409 with a sentence explaining archiving.
 *
 * SO THE DATABASE IS THE GUARANTEE AND THIS IS THE GOOD ERROR MESSAGE — the same division of labour
 * `BotRepositoryInterface::slugExists()` records for the duplicate-slug case. And the check that
 * produces this value runs INSIDE the delete's transaction, under the same `lockForUpdate()` on the
 * bot row, which is what closes the race a pre-flight check in the controller cannot: a conversation
 * starting between the read and the DELETE would otherwise win, and the operator would get a 500
 * describing a constraint instead of the 409 they would have got a millisecond earlier.
 *
 * The lock genuinely covers it. A `conversations` insert naming this bot takes a `FOR KEY SHARE`
 * lock on the referenced `bots` row to enforce its own foreign key, and `FOR UPDATE` conflicts with
 * `FOR KEY SHARE` — so while the delete holds the row, no new conversation for that bot can commit.
 *
 * ── WHY REFUSING IS THE RIGHT ANSWER AT ALL ────────────────────────────────────────────────────
 *
 * `BotService::delete()` carried `TODO(phase-e)` naming three candidates, and the migration
 * 2026_08_26_002800 carries the full argument. The short form: CASCADE destroys every transcript,
 * every provider call that billed for them and every piece of feedback, from a button labelled
 * "delete bot" — the TODO's own words are that it "makes the data loss invisible". SET NULL keeps
 * the transcript and loses what it was a transcript OF, so every §8.23 aggregate gains a bucket that
 * appears in the totals and in no breakdown. RESTRICT costs the operator a delete they cannot
 * perform, and `BotStatus::Archived` — whose docblock already reads "the row survives so
 * conversation history and audit entries resolve" — is the route that exists for exactly this.
 *
 * `enum` rather than three bools or a nullable string, because the three outcomes are exhaustive and
 * a `match` over them is checked. It lives in App\Enums because `arch()->preset()->laravel()` asserts
 * `expect('App')->not->toBeEnums()->ignoring('App\Enums')`.
 */
enum BotDeletion
{
    /** The row and its four child collections are gone, and the audit rows committed with them. */
    case Deleted;

    /**
     * No such bot in THIS organization. In practice: deleted between the route binding and the
     * transaction, because a foreign or unknown id 404s at binding time long before this.
     */
    case Missing;

    /**
     * The bot has held at least one conversation. NOTHING WAS CHANGED AND NO AUDIT ROW WAS WRITTEN
     * — the check runs before the audit closure, so a refused delete leaves no `bot.deleted` row
     * claiming a deletion that did not happen. `bot.delete.refused` is what records the attempt.
     */
    case HasConversations;
}
