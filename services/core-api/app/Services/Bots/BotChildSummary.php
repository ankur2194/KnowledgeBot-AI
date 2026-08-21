<?php

declare(strict_types=1);

namespace App\Services\Bots;

/**
 * What a bot's four child collections held at the moment a `bot.*` audit row was written.
 *
 * ── THIS TYPE EXISTS TO CLOSE FINDING L2, AND THE FINDING IS WORTH RESTATING ──────────────────
 *
 * `docs/22` § *The security read of the bots surface*: deleting a bot destroys its widget origin
 * allow-list with NO RECORD OF WHAT IT PERMITTED — which contradicts the reason `bot_domains` gives
 * for its own `ON DELETE RESTRICT`, namely that a security review may later need to reconstruct it.
 * It was latent only because nothing created a domain; the endpoints in this batch make it live.
 *
 * The closure has two halves and this type is the smaller one:
 *
 *   the per-row trail  `bot.domain.created`, `bot.domain.status_changed` and `bot.domain.deleted`
 *                      carry ONE ORIGIN EACH, verbatim, with the actor and the timestamp. Those
 *                      rows are append-only and outlive the bot, so they — not this summary — are
 *                      what actually reconstructs an allow-list after the fact.
 *   this summary       Every `bot.created`, `bot.updated` and `bot.deleted` row carries the state
 *                      of the three collections. It is the TRIPWIRE: a reader who lands on
 *                      `bot.deleted` and sees `domain_count: 4` knows to go and look for the four
 *                      `bot.domain.*` rows, where a reader who saw nothing would conclude the bot
 *                      had no allow-list at all.
 *
 * ── EVERY FIELD IS A SCALAR OR BECOMES ONE, BECAUSE `AuditLogger::sanitize()` DROPS ARRAYS ─────
 *
 * A structure in `details` is how `$request->all()` gets in one nesting level down, and it is also
 * what would stop `details` json-encoding as an OBJECT, which the table CHECKs. So the two list
 * fields below are rendered with `implode(',', …)` at the call site — the same shape
 * `capabilities` uses on the three `provider.model.*` operations, and for the same reason.
 *
 * ── THE COUNTS SIT BESIDE THE JOINED LISTS DELIBERATELY, AND THAT IS NOT REDUNDANCY ───────────
 *
 * `sanitize()` truncates an echoed string at 512 characters SILENTLY. Fifty origins do not fit, so
 * `active_origins` can be cut with nothing saying so. `active_domain_count` is what makes the cut
 * DETECTABLE: a reader comparing the count against the number of entries in the string can see
 * that the string is partial, and knows to read the per-origin rows instead. A count is an integer
 * and takes `sanitize()`'s `is_int()` path, so it is never truncated and never redacted.
 *
 * ── AND THE OTHER DEGRADATION, NAMED RATHER THAN FIXED ────────────────────────────────────────
 *
 * `active_origins` is tenant-controlled text passing the shape backstop like any other echoed
 * string, so a legal origin whose host matches a vendor-key pattern — `https://sk-abcdefghijkl.example`
 * is a legal host and twelve characters after `sk-` — fingerprints the WHOLE joined field and takes
 * every other origin in it with it. That is the same designed degradation finding L4 records for a
 * bot's slug, bounded here by the per-origin rows carrying each value on its own.
 */
final readonly class BotChildSummary
{
    /**
     * @param  list<string>  $activeOrigins  the origins that actually permit an embed, ordered
     * @param  list<string>  $fallbackModelIds  the chain, in position order
     */
    public function __construct(
        public int $domainCount,
        public int $activeDomainCount,
        public array $activeOrigins,
        public int $starterQuestionCount,
        public int $fallbackModelCount,
        public array $fallbackModelIds,
        public int $sourceAssignmentCount,
        public int $enabledSourceAssignmentCount,
    ) {}

    /**
     * The audit `details` fragment, ready to merge.
     *
     * ── ONLY THE ACTIVE ORIGINS ARE JOINED, AND ONLY THE ORIGINS ARE JOINED AT ALL ────────────
     *
     * `active_origins` carries the rows that GRANT something, because that is the question a
     * security review asks of a deleted bot: what could embed this. A pending or disabled row
     * granted nothing, its existence is recorded in `domain_count`, and its value is in its own
     * `bot.domain.created` row.
     *
     * THE STARTER QUESTIONS ARE COUNTED AND NEVER ECHOED, and the asymmetry with the origins is the
     * decision this method encodes. An origin is a GRANT — the string IS the security fact, and
     * losing it loses the ability to answer "what could reach this". A starter question is PROSE
     * that decides nothing: it is a chip label, it authorizes nobody, and `AuditLogger` already
     * refuses `description`, `welcome_message`, `placeholder_text` and `consent_text` from the same
     * rows on exactly that ground — an append-only table an investigator has to be able to read is
     * the wrong place for unbounded tenant copy. The count is what records that N of them went with
     * the bot.
     *
     * THE FALLBACK CHAIN IS ECHOED AS ULIDs even though nothing in this batch writes it, because
     * `BotController::destroy()` already destroys it and the chain names `provider_models` rows —
     * i.e. WHICH CREDENTIALS CAN BE BILLED for that bot's answers. That is the same argument the
     * allow-list makes, so it gets the same treatment; whoever lands the chain's write endpoints
     * owes it the per-row operations the origins now have.
     *
     * @return array<string, int|string>
     */
    public function toAuditDetails(): array
    {
        return [
            'domain_count' => $this->domainCount,
            'active_domain_count' => $this->activeDomainCount,
            // A SCALAR, because sanitize() drops arrays outright — the `capabilities` precedent.
            // An empty string is skipped SILENTLY by the sanitizer (the documented `''` case), so
            // a bot with no active origins simply omits the key while `active_domain_count: 0`
            // still records the fact.
            'active_origins' => implode(',', $this->activeOrigins),
            'starter_question_count' => $this->starterQuestionCount,
            'fallback_model_count' => $this->fallbackModelCount,
            'fallback_model_ids' => implode(',', $this->fallbackModelIds),

            // ── THE RETRIEVAL SCOPE, AS TWO INTEGERS AND NO LIST ─────────────────────────────
            //
            // COUNTED AND NEVER ECHOED, and the asymmetry with `active_origins` is deliberate. An
            // origin string IS the security fact and losing it loses the ability to answer "what
            // could reach this bot"; a source id is a pointer whose meaning lives in another table
            // and whose NAME is unbounded tenant prose. What actually reconstructs the retrieval
            // scope is the per-grant `bot.source_assignment.created` and `.deleted` rows, which
            // carry `source_id`, `source_name`, `priority` and `enabled` one grant at a time and
            // outlive the bot — including the ones a bot delete writes for the grants it destroys.
            // These two numbers are the TRIPWIRE that sends a reader of `bot.deleted` looking for
            // them, exactly as `domain_count` does for the allow-list.
            //
            // BOTH, because they answer different questions. `source_assignment_count` says how
            // many rows went; `enabled_source_assignment_count` says how many of them GRANTED
            // anything, and a bot whose every grant was switched off had exactly as much corpus as
            // one with none. The same pairing, for the same reason, as `domain_count` beside
            // `active_domain_count`.
            'source_assignment_count' => $this->sourceAssignmentCount,
            'enabled_source_assignment_count' => $this->enabledSourceAssignmentCount,
        ];
    }
}
