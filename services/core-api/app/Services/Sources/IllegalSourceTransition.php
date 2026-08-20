<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Enums\SourceState;
use RuntimeException;

/**
 * A move that is not an edge of `SourceState::transitionTable()` — or one of the two edges that is,
 * asked for without the verification it carries.
 *
 * ── RAISED, NEVER LOGGED, AND NEVER A SILENT NO-OP ────────────────────────────────────────────
 *
 * `kb-source-lifecycle`: "transitions not in that table are bugs" and "illegal transitions raise
 * rather than log". The four the skill names — `Indexing -> Ready` with no passing verification,
 * `Ready -> Deleted` skipping `Deleting`, any edge out of `Deleted`, and `Failed -> Ready` without
 * a new run — are each refused by construction rather than by a special case, and this is what the
 * refusal looks like from the outside.
 *
 * A no-op would be worse than a wrong answer on exactly one of those: `Indexing -> Ready` without
 * verification silently publishes a version that indexed half a document, and nothing downstream
 * can tell that from a complete one.
 *
 * ── IT CARRIES BOTH ENDS BECAUSE THE CALLER RENDERS THE MESSAGE ──────────────────────────────
 *
 * `SourceService` converts it into a `validation` refusal (422) keyed on `status`, which is the
 * field an admin console has an input for. The internal callback converts it into the same class,
 * because the data plane sending a status this row cannot reach is a contract violation and not a
 * dependency being unwell — `internal_dependency` would tell the caller to retry a frame that will
 * be refused identically forever.
 */
final class IllegalSourceTransition extends RuntimeException
{
    public function __construct(
        public readonly SourceState $from,
        public readonly SourceState $to,
        public readonly bool $verified,
    ) {
        // "A row in", NOT "A source in". This exception covers `knowledge_sources.status` AND
        // `source_versions.status` — R3 gives both columns the same fifteen-value vocabulary — so a
        // message naming one of them sends a reader to the wrong table half the time.
        // THE VERIFICATION CLAUSE IS ADDED ONLY WHERE IT IS TRUE. `canTransitionTo()` applies the
        // gate to exactly two edges — `Indexing -> Ready` and `Indexing -> ReadyWithWarnings` — so
        // appending it whenever `$verified` is false would tell an operator that `fetching ->
        // normalizing` was refused for want of a verification, sending them looking for a flag that
        // has nothing to do with it. Every other refusal is simply an edge the table does not have.
        $gated = $from === SourceState::Indexing
            && in_array($to, SourceState::verificationGatedTargets(), true);

        parent::__construct(
            "A row in `{$from->value}` cannot move to `{$to->value}`"
            .($gated && ! $verified ? ' without a passing verification' : '')
            .'. The legal moves are in App\Enums\SourceState::transitionTable(), which is the only '
            .'statement of the machine.',
        );
    }
}
