<?php

declare(strict_types=1);

namespace App\Services\Rerank;

use App\Exceptions\KbException;
use App\Models\Organization;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Repositories\Contracts\ProviderConnectionRepositoryInterface;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * Set or clear which connection supplies an organization's rerank credential.
 *
 * ── WHAT THIS CLASS CHECKS, AND WHAT IT REFUSES TO CHECK ───────────────────────────────────────
 *
 * IT CHECKS TENANCY AND EXISTENCE. The named connection must belong to THIS organization and must
 * be there; the named (connection, model) pair must be a row in this organization's catalog, which
 * the repository re-verifies under the `organizations` row lock. Three layers back that up: the
 * org-scoped read below, the in-transaction re-verification, and the composite foreign key
 * (id, rerank_connection_id) -> provider_connections (organization_id, id) in the database.
 *
 * IT DOES NOT CHECK WHETHER THAT VENDOR CAN RERANK, and that is a boundary rather than an omission.
 * The question is three, not one — does the vendor publish a ranking route
 * (`capabilities.PROVIDER_TASKS`), does this model row claim the capability
 * (`provider_models.capability_flags`), and can this platform threshold the scale that comes back
 * (`capabilities.RERANK_SCALE`, finding #47) — and
 * `services/ai-service/app/providers/capabilities.py::can_rerank` is the AND of all three and the
 * one place they are answered. A PHP copy would be a second decision procedure that can disagree
 * with the first, and it would disagree the day a vendor ships an endpoint, in the direction of
 * refusing a configuration that works. The refusal, where there is one, is decided at QUERY time by
 * `rerank_gate` before any call goes out and reported on the retrieval trace as
 * `rerank_skip_reason`.
 *
 * ── THIS IS WHY THERE IS NO READINESS CALL, AND WHY THAT IS NOT AN EMBEDDING REGRESSION ────────
 *
 * `EmbeddingDesignationService` resolves against the data plane before persisting and HARD-FAILS a
 * designation that cannot resolve, because a bad embedding designation means the organization
 * cannot ingest a single document and the only symptom is a failed upload later.
 *
 * A rerank designation cannot fail that way. Every outcome is servable: the reranker runs, or
 * `rerank_gate` skips it and `evidence.select_unranked` serves fused order — a cheaper, measured,
 * deliberately supported mode. So there is nothing to hard-fail ON, and a readiness call here would
 * be a synchronous cross-seam round trip on an admin write to decide a question whose answer never
 * blocks the write. If an admin SCREEN later wants to warn "this vendor will not rerank", that is a
 * separate decision with the embedding readiness endpoint as its precedent, and it belongs on a
 * read endpoint rather than in front of this write.
 *
 * ── A DESIGNATION IS NEVER SUBSTITUTED ─────────────────────────────────────────────────────────
 *
 * If the pair named turns out not to rerank, nothing else is selected in its place. There is no
 * resolve-by-rule for reranking on either side of the seam, and inventing one here would mean
 * choosing a threshold on a score scale nobody has measured — which is exactly what
 * `RerankCalibration.__post_init__` exists to make unconstructible.
 */
final readonly class RerankDesignationService
{
    /**
     * The 422 an operator gets when the connection they named is not one of theirs.
     *
     * IT SAYS "NOT AMONG THIS ORGANIZATION'S CONNECTIONS" AND NEVER "DOES NOT EXIST", because those
     * are different sentences and only one of them is ours to say. A foreign connection is refused
     * by ABSENCE from an org-scoped read — no foreign row is ever loaded to be compared against —
     * so this message is what that absence means, and it is identical whether the id belongs to
     * another tenant or to nobody. Distinguishing the two would turn this endpoint into an
     * existence oracle over every organization's credentials.
     */
    public const NOT_THIS_ORGANIZATIONS_CONNECTION = 'That connection is not among this '
        .'organization\'s provider connections, so it cannot be designated as the rerank '
        .'credential. Choose one of this organization\'s connections, or add the connection first.';

    public function __construct(
        private ProviderConnectionRepositoryInterface $connections,
        private OrganizationRepositoryInterface $organizations,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws KbException `validation` (422) when the connection is not this organization's, or the
     *                     (connection, model) pair is not in its catalog
     */
    public function designate(
        Organization $organization,
        ?RerankDesignation $proposed,
        ?string $actorId = null,
        ?Request $request = null,
    ): Organization {
        $organizationId = $organization->organizationId();

        if ($proposed !== null && ! $this->connections->existsForOrg($organizationId, $proposed->connectionId)) {
            // ORG-SCOPED, AND THE ORGANIZATION IS A REQUIRED POSITIONAL ARGUMENT. Without it this
            // is `ProviderConnection::find($id)`, which answers "does this exist anywhere" — an
            // existence oracle over every tenant's credentials, reachable by anyone with a valid
            // session in any organization. The composite foreign key on the write is the backstop
            // under this check; it is not a substitute for it, because a 23503 arrives as a
            // constraint name rather than as a sentence an operator can act on.
            throw KbException::validation(self::NOT_THIS_ORGANIZATIONS_CONNECTION);
        }

        // CLEARING IS NEVER REFUSED. An organization turning reranking off is choosing a supported
        // mode, and refusing to let it undo a designation would leave a dangling pointer at a
        // connection it is trying to remove — which the ON DELETE RESTRICT would then also block.
        // Undesignate, then delete.
        return $this->organizations->designateRerankConnection(
            $organizationId,
            $proposed,
            // A FULL CLOSURE AND NOT AN ARROW FUNCTION: `fn () => $this->record(...)` implicitly
            // RETURNS the call's value, and `record()` is void — both a PHPStan finding and a quiet
            // lie about the contract, which types the callback as returning void.
            function (Organization $row, ?RerankDesignation $previous) use ($actorId, $request): void {
                $this->record($row, $previous, $actorId, $request);
            },
        );
    }

    /**
     * One audit row describing the change, written inside the repository's transaction.
     *
     * BOTH PAIRS COME OFF THE PERSISTED ROW, never from request input — which is what makes
     * "nothing credential-shaped can appear here" a property of the code rather than of the
     * caller's discipline. `Organization` has no credential to offer: the key lives on
     * `provider_connections`, envelope-encrypted, and this path never reads that table.
     */
    private function record(
        Organization $row,
        ?RerankDesignation $previous,
        ?string $actorId,
        ?Request $request,
    ): void {
        $current = RerankDesignation::fromOrganization($row);

        // WHICH OPERATION IS DERIVED FROM THE PERSISTED STATE, not from the argument the caller
        // passed. The two cannot disagree today, and deriving it from the row is what keeps that
        // true if a future path ever writes this column another way.
        $operation = $current === null
            ? AuditLogger::RERANK_DESIGNATION_CLEARED
            : AuditLogger::RERANK_DESIGNATION_SET;

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
