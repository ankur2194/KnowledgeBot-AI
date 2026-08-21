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
 *
 * ── TWO MESSAGES, AND WHICH ONE IS PUBLISHED IS THE WHOLE POINT ──────────────────────────────
 *
 * `getMessage()` is written for an OPERATOR and names `App\Enums\SourceState::transitionTable()`,
 * because the person it is addressed to is the one who has to read that table. It reaches a log
 * line and an exception trace and nothing else.
 *
 * `clientMessage()` is written for the TENANT ADMINISTRATOR and is the only one that becomes a
 * per-field `errors.status` entry. The two were one string until now, and the cost was concrete:
 * `apps/web` renders a `validation` envelope's per-field messages VERBATIM — on the correct general
 * premise that Laravel validation messages are end-user copy — so a PHP class name reached the DOM
 * of a customer's console, and `apps/web/src/features/sources/source-row-actions.tsx` grew a
 * special case that discards the message and substitutes its own sentence, plus a test asserting
 * `transitionTable` never renders. That workaround is a client compensating for server copy; the
 * copy is the thing worth fixing, and this is that fix. The client's substitution can be removed
 * once `apps/web`'s owner is ready, and until then it is harmless — it renders a true sentence in
 * place of a true sentence.
 *
 * NEITHER MESSAGE NAMES A COLUMN OR A TABLE, and that is `kb-ui-patterns` -> `references/states.md`
 * applied rather than quoted: "never surface internal vocabulary". A status VALUE is not internal
 * vocabulary — it is the published `status` field of `SourceResource`, the same string the console
 * renders in its pill — so naming both ends is the specific fact the reader needs, not jargon.
 */
final class IllegalSourceTransition extends RuntimeException
{
    /**
     * Whether this refusal is the VERIFICATION GATE rather than a missing edge.
     *
     * Computed once, in the constructor, and read by both messages. Recomputing it in
     * `clientMessage()` would be the same two-line condition in two places, and the failure mode of
     * a drift between them is a user told to wait for an indexing run that is not happening.
     */
    public readonly bool $gatedOnVerification;

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
        $this->gatedOnVerification = $from === SourceState::Indexing
            && in_array($to, SourceState::verificationGatedTargets(), true);

        parent::__construct(
            "A row in `{$from->value}` cannot move to `{$to->value}`"
            .($this->gatedOnVerification && ! $verified ? ' without a passing verification' : '')
            .'. The legal moves are in App\Enums\SourceState::transitionTable(), which is the only '
            .'statement of the machine.',
        );
    }

    /**
     * The same refusal, in the words a tenant administrator reads.
     *
     * ── THE TWO SENTENCES ARE DIFFERENT REFUSALS AND SAY SO ──────────────────────────────────
     *
     * The verification gate is a WAIT: the run is under way, it will reach the state on its own,
     * and there is nothing for the reader to do. Every other refusal is a MISREAD ROW: the source
     * is somewhere the reader did not think it was, most often because it moved after the page
     * rendered. Collapsing them into one sentence would tell somebody watching an indexing run to
     * go and change something, which is the one action that cannot help.
     *
     * `$verified` is not consulted here. `clientMessage()` is only ever called on an exception that
     * has already been raised, and `canTransitionTo()` raises on a gated edge exactly when the
     * verification is absent — so on a gated edge, "the verification did not pass" is the reason by
     * construction and repeating the flag would be a second spelling of the same test.
     */
    public function clientMessage(): string
    {
        if ($this->gatedOnVerification) {
            return 'This source is still being indexed, so it cannot be marked "'.$this->to->value
                .'" yet. It moves there on its own once indexing finishes and its content has been '
                .'checked.';
        }

        return 'This source is "'.$this->from->value.'" right now, so it cannot move to "'
            .$this->to->value.'". Its status may have changed since it was last loaded — reload the '
            .'source to see where it is now.';
    }
}
