<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The fifteen lifecycle states of docs/03 §8.9, in CONTRACT ORDER.
 *
 * ── THIS ENUM IS A SECOND STATEMENT OF `services/ai-service/app/ingestion/states.py` ──────────
 *
 * The two files must hold the same fifteen strings in the same order, and neither generates the
 * other. That is deliberate rather than lazy: the value crosses the process boundary as a bare
 * string on the ingestion status callback, so a divergence is not a type error anywhere — it is a
 * status Laravel writes and the data plane's `SourceState(value)` refuses, or worse, one the data
 * plane reports and this enum's `from()` throws on inside a callback handler that has already
 * committed the row it was describing.
 *
 * DO NOT RE-SORT ALPHABETICALLY. The order is §8.9's, which is also the order a healthy run walks,
 * and it is what makes the transition table below reviewable line by line against the
 * specification. `states.py` says the same thing in the same words for the same reason.
 *
 * FIFTEEN, NOT FOURTEEN. Early summaries of the spec collapsed `Ready` and `ReadyWithWarnings`
 * into one because they behave identically for retrieval. They are separate rows in the contract,
 * and collapsing them loses the only signal that says "this document parsed badly and published
 * anyway" (§8.11: parser and OCR warnings are advisory, never a retrieval predicate).
 *
 * ── TEXT + CHECK, NEVER A NATIVE PG ENUM ─────────────────────────────────────────────────────
 *
 * `ALTER TYPE ... ADD VALUE` cannot be rolled back, so a sixteenth state would be an irreversible
 * migration. `knowledge_sources_status_check` and `source_versions_status_check` are both
 * generated from `values()`, so the enum and the two constraints cannot drift — and R3 of the
 * Phase C1 rulings is that those two columns share ONE vocabulary rather than each getting a
 * narrower subset. A subset per table would be defensible on paper (`Draft` and `Archived` are
 * source-level, the processing states are version-level) and is refused because the rollup is a
 * real operation: `kb-source-lifecycle` has the version's processing state displayed ON the source,
 * so a source-level CHECK that excluded `Parsing` would refuse the row the rollup writes.
 *
 * ── WHAT "RETRIEVABLE" MEANS, AND WHY IT IS NOT A PROPERTY OF THIS VALUE ALONE ────────────────
 *
 * `isRetrievable()` answers only the STATUS term of the four mandatory Qdrant filter terms
 * (kb-tenancy-isolation NN3). Reachability is the AND of the status, the ACTIVE-VERSION POINTER
 * (`source_items.current_version_id`), the bot assignment and the organization — and three of those
 * four live somewhere other than this value. Points can exist in the collection for a version in
 * any state and stay unreachable; that is precisely the mechanism that makes publication atomic.
 * Never write a query that treats this predicate as the whole filter.
 */
enum SourceState: string
{
    /** Created, never submitted. No version exists yet. Source-level only. */
    case Draft = 'draft';

    /** Accepted, idempotency key resolved, awaiting a worker. The prior version keeps serving. */
    case Queued = 'queued';

    /** Acquiring bytes or crawling the URL. */
    case Fetching = 'fetching';

    /** Docling / OCR extraction. */
    case Parsing = 'parsing';

    /** Structural normalization and cleanup; `document_elements` are written here. */
    case Normalizing = 'normalizing';

    /** `chunks` rows are written here. */
    case Chunking = 'chunking';

    /** Dense and sparse representations are built here. */
    case Embedding = 'embedding';

    /**
     * Upsert plus verification.
     *
     * NOT RETRIEVABLE, and this is the state whose reading matters most: the points are already in
     * the collection and the active-version pointer still names the PREVIOUS version. Anything that
     * treats "points are present" as "the version is live" has re-invented the half-a-document bug.
     */
    case Indexing = 'indexing';

    /** Verified and activated. */
    case Ready = 'ready';

    /** Activated with advisory parser/OCR warnings. Identical to Ready for retrieval (§8.11). */
    case ReadyWithWarnings = 'ready_with_warnings';

    /** Terminal FOR THIS RUN, not for the item. The prior version keeps serving (§13.7). */
    case Failed = 'failed';

    /** Excluded by the status filter immediately; every vector is retained (§8.17). */
    case Disabled = 'disabled';

    /** Physical removal in flight. Hands off to kb-deletion-and-verification. */
    case Deleting = 'deleting';

    /** Removal verified. Terminal, with no legal edge out. */
    case Deleted = 'deleted';

    /** Metadata and objects retained, vectors dropped; restore is a rebuild (§15.5). */
    case Archived = 'archived';

    /**
     * THE LEGAL-TRANSITION TABLE, TRANSCRIBED FROM `kb-source-lifecycle`'s STATE TABLE.
     *
     * As data and never as scattered `if`s, for one reason: a state machine spread over the call
     * sites that use it cannot be READ. Nobody can answer "may a Failed source be archived" without
     * grepping, and the answer they get is whichever call site they found first. This is the only
     * statement of the machine, and every caller asks it through `canTransitionTo()`.
     *
     * TRANSITIONS NOT IN THIS TABLE ARE BUGS, and the four the skill calls out by name are each
     * refused by construction rather than by a special case:
     *
     *   Indexing -> Ready without verification   the edge EXISTS and carries the one extra
     *                                            condition in this file — see `$verified`.
     *   Ready -> Deleted skipping Deleting       not an edge. Physical removal is two-phase and
     *                                            `Deleting` is the phase the purge worker owns.
     *   any edge out of Deleted                  the row is empty. Deleted is terminal.
     *   Failed -> Ready without a new run        not an edge. A failed run re-enters at `Queued`,
     *                                            which mints a NEW version row; nothing promotes a
     *                                            failed version in place.
     *
     * @return array<string, list<self>>
     */
    public static function transitionTable(): array
    {
        return [
            self::Draft->value => [self::Queued, self::Deleted, self::Archived],
            self::Queued->value => [self::Fetching, self::Failed, self::Deleting],
            self::Fetching->value => [self::Parsing, self::Failed, self::Deleting],
            self::Parsing->value => [self::Normalizing, self::Failed],
            self::Normalizing->value => [self::Chunking, self::Failed],
            self::Chunking->value => [self::Embedding, self::Failed],
            self::Embedding->value => [self::Indexing, self::Failed],
            // The two Ready edges below are the ONLY ones in this table that carry a second
            // condition. See `canTransitionTo()`.
            self::Indexing->value => [self::Ready, self::ReadyWithWarnings, self::Failed],
            self::Ready->value => [self::Queued, self::Disabled, self::Deleting, self::Archived],
            self::ReadyWithWarnings->value => [self::Queued, self::Disabled, self::Deleting, self::Archived],
            self::Failed->value => [self::Queued, self::Disabled, self::Deleting, self::Archived],
            self::Disabled->value => [self::Ready, self::ReadyWithWarnings, self::Deleting, self::Archived],
            self::Deleting->value => [self::Deleted],
            // TERMINAL. An empty row rather than an absent key, so `canTransitionTo()` has nothing
            // to fall back to and a future reader cannot mistake the absence for an oversight.
            self::Deleted->value => [],
            self::Archived->value => [self::Ready, self::Deleting],
        ];
    }

    /**
     * The edges that additionally require a PASSING VERIFICATION, not merely a legal predecessor.
     *
     * `kb-source-lifecycle` NN1: "a version becomes searchable only after it is fully indexed AND
     * verified", and the failure it names is the one users describe as "the bot only knows half the
     * document". The verification itself is a data-plane fact — only the worker that wrote the
     * points can count them with `exact=True` — so it arrives on the ingestion status callback as
     * `indexed_verified` and is passed in here. This enum cannot perform it and does not pretend to;
     * what it does is make the caller state that it happened.
     *
     * @return list<self>
     */
    public static function verificationGatedTargets(): array
    {
        return [self::Ready, self::ReadyWithWarnings];
    }

    /**
     * May this state move to `$next`?
     *
     * `$verified` DEFAULTS TO FALSE AND THAT DIRECTION IS THE WHOLE POINT. The caller who forgot to
     * thread the verification result through is refused, loudly, at the transition — rather than
     * publishing an unverified version, which fails nowhere and shows up months later as a bot that
     * knows the first eleven pages of a contract. A default of `true` would make the argument
     * decorative and would read as harmless in review.
     */
    public function canTransitionTo(self $next, bool $verified = false): bool
    {
        $legal = self::transitionTable()[$this->value];

        if (! in_array($next, $legal, true)) {
            return false;
        }

        if ($this === self::Indexing && in_array($next, self::verificationGatedTargets(), true)) {
            return $verified;
        }

        return true;
    }

    /**
     * Reachable by a query — and only ever THROUGH THE ACTIVE-VERSION POINTER. See the class
     * docblock: this is one term of four, never the filter.
     */
    public function isRetrievable(): bool
    {
        return $this === self::Ready || $this === self::ReadyWithWarnings;
    }

    /**
     * The version states an ingestion run walks. While any of these is current, the PRIOR version
     * keeps serving every query.
     *
     * `Queued` is deliberately NOT here: it is acceptance, not work in flight, and the distinction
     * is what a "stuck run" sweep keys off — a version queued for an hour is a scheduling problem,
     * a version parsing for an hour is a document problem.
     */
    public function isProcessing(): bool
    {
        return match ($this) {
            self::Fetching, self::Parsing, self::Normalizing,
            self::Chunking, self::Embedding, self::Indexing => true,
            default => false,
        };
    }

    /**
     * Whether NO legal edge leaves this state.
     *
     * Exactly one value, and it is not `Failed`. `Failed` is terminal for the RUN and not for the
     * item — a fresh run re-enters at `Queued` with a new version row — which is the distinction
     * `states.py` states in the same words. Deriving this from the table rather than restating it
     * means the two can never disagree.
     */
    public function isTerminal(): bool
    {
        return self::transitionTable()[$this->value] === [];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
