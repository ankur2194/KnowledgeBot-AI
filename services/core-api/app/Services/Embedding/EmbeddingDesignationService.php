<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use App\Exceptions\KbException;
use App\Models\Organization;
use App\Repositories\Contracts\EmbeddingCandidateRepositoryInterface;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Services\Audit\AuditLogger;
use App\Services\Internal\InternalAiClient;
use Illuminate\Http\Request;

/**
 * Set or clear which connection supplies an organization's embedding credential.
 *
 * THE TWO SAVE PATHS FAIL DIFFERENTLY, ON PURPOSE.
 *
 *   Saving a CONNECTION must not hard-fail on readiness. Refusing to store an organization's only
 *   chat connection because it cannot also embed is worse than the disease — the organization then
 *   has no working configuration at all, and the thing it was told to fix is unrelated to the
 *   thing it was doing. That path calls EmbeddingReadinessService and renders `explanation` as a
 *   blocking banner on the ingestion surface instead.
 *
 *   Setting a DESIGNATION does hard-fail, and must. Here the operator is explicitly choosing which
 *   connection embeds; accepting a choice that cannot resolve would store a configuration whose
 *   only symptom is a failed upload later, which is precisely the late discovery C1 exists to
 *   remove. The 422 carries the data plane's own explanation verbatim, so the banner and the error
 *   say the same words.
 *
 * A DESIGNATION IS NEVER SUBSTITUTED. If the pair named cannot embed, nothing else is selected in
 * its place — silently embedding through a different connection would change the vector space
 * under a corpus nobody reindexed, and cosine distance is defined between any two vectors of equal
 * width, so nothing would raise and only ranking would change.
 */
final readonly class EmbeddingDesignationService
{
    public function __construct(
        private EmbeddingCandidateRepositoryInterface $candidates,
        private OrganizationRepositoryInterface $organizations,
        private InternalAiClient $ai,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{organization: Organization, readiness: EmbeddingReadiness}
     *
     * @throws KbException `validation` (422) when the proposed designation cannot resolve
     */
    public function designate(
        Organization $organization,
        ?EmbeddingDesignation $proposed,
        ?string $actorId = null,
        ?Request $request = null,
    ): array {
        $organizationId = $organization->organizationId();

        // Org-scoped, and it is the ONLY set of connections that reaches the wire. A designation
        // naming a connection in another organization is therefore not "rejected" by a comparison
        // we wrote — it is simply absent from the candidate list, and the resolution rule answers
        // "not among this organization's connections" without anyone having read a foreign row.
        // The composite foreign key on the write is the backstop under that.
        $candidates = $this->candidates->forOrg($organizationId);

        // Resolve BEFORE persisting, and outside any transaction. Both halves matter: an HTTP call
        // between BEGIN and COMMIT pins xmin for its whole duration and stops autovacuum
        // reclaiming dead tuples database-wide (postgresql-patterns), and validating after the
        // write would mean rolling back a decision the operator has already been told succeeded.
        $readiness = $this->ai->embeddingReadiness(
            organizationId: $organizationId,
            candidates: $candidates,
            designation: $proposed,
            actorId: $actorId,
        );

        // Only an explicit designation is held to this bar. Clearing it ($proposed === null) is
        // always allowed even when the organization then has no embedder at all: an organization
        // that has torn down its provider configuration is in a state it chose, and refusing to
        // let it undo a designation would leave a dangling pointer to a connection it is trying to
        // remove — which the ON DELETE RESTRICT would then also block. Undesignate, then delete.
        if ($proposed !== null && ! $readiness->isReady()) {
            throw KbException::validation($readiness->explanation);
        }

        $organization = $this->organizations->designateEmbeddingConnection(
            $organizationId,
            $proposed,
            // A FULL CLOSURE AND NOT AN ARROW FUNCTION: `fn () => $this->record(...)` implicitly
            // RETURNS the call's value, and `record()` is void — both a PHPStan finding and a quiet
            // lie about the contract, which types the callback as returning void.
            function (Organization $row, ?EmbeddingDesignation $previous) use ($actorId, $request): void {
                $this->record($row, $previous, $actorId, $request);
            },
        );

        return ['organization' => $organization, 'readiness' => $readiness];
    }

    /**
     * One audit row describing the change, written inside the repository's transaction.
     *
     * ── THIS CLOSES THE GAP THE RERANK PAIR MADE VISIBLE, AND IT IS THE BIGGER OF THE TWO ────
     *
     * Until it existed, `designateEmbeddingConnection()` wrote with no audit call and no closure to
     * pass one through, so "who moved the vector space, and from what" had no answer in
     * `audit_logs`. `(provider, model)` IS the vector space (ADR-031) — it is part of the Qdrant
     * collection name — so re-designating it strands every chunk already indexed until somebody
     * re-embeds the whole corpus at a provider's per-token price, and clearing it hands the choice
     * to the resolution rule in `embedding_selection.py`, which may pick a different connection or
     * refuse outright and block ingestion for the organization.
     *
     * BOTH PAIRS COME OFF THE PERSISTED ROW, never from request input — which is what makes
     * "nothing credential-shaped can appear here" a property of the code rather than of the caller's
     * discipline. `Organization` has no credential to offer: the key lives on
     * `provider_connections`, envelope-encrypted, and this path never reads that table.
     */
    private function record(
        Organization $row,
        ?EmbeddingDesignation $previous,
        ?string $actorId,
        ?Request $request,
    ): void {
        $current = EmbeddingDesignation::fromOrganization($row);

        // WHICH OPERATION IS DERIVED FROM THE PERSISTED STATE, not from the argument the caller
        // passed. The two cannot disagree today, and deriving it from the row is what keeps that
        // true if a future path ever writes these columns another way.
        $operation = $current === null
            ? AuditLogger::EMBEDDING_DESIGNATION_CLEARED
            : AuditLogger::EMBEDDING_DESIGNATION_SET;

        $details = $current === null ? [] : $current->toArray();

        // Nulls are skipped by the sanitizer without being reported, so an organization that had no
        // previous designation simply omits both keys rather than writing a pair of nulls.
        $details['previous_connection_id'] = $previous?->connectionId;
        $details['previous_model'] = $previous?->model;

        $this->audit->record(
            $operation,
            organizationId: $row->organizationId(),
            actorId: $actorId,
            details: $details,
            subjectType: Organization::class,
            subjectId: $row->id,
            request: $request,
        );
    }
}
