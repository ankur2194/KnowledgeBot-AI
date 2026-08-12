<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use App\Models\Organization;
use App\Repositories\Contracts\EmbeddingCandidateRepositoryInterface;
use App\Services\Internal\InternalAiClient;

/**
 * "Can this organization ingest anything, and if not, why not?" — asked in exactly one way.
 *
 * FINDING C1, THE PART A HUMAN SEES. Before ADR-030 an organization could save a chat connection,
 * upload a document, pay for the parse and the OCR, and discover only then that no connection of
 * theirs can embed — and there was no name for the failure, no rule that produced it, and nothing
 * on any screen that could have said so. This service is what puts the answer in front of them at
 * the moment they configure, and it deliberately gets that answer from the same function the
 * upload path calls.
 */
final readonly class EmbeddingReadinessService
{
    public function __construct(
        private EmbeddingCandidateRepositoryInterface $candidates,
        private InternalAiClient $ai,
    ) {}

    /**
     * The verdict for $organization.
     *
     * TOTAL AND NON-RAISING FOR CONFIGURATION REASONS — it returns an unselected readiness with an
     * explanation rather than throwing, because the caller is a save handler and a connection
     * screen. It still raises for TRANSPORT reasons (KbException), and that distinction is the
     * point: "your configuration cannot embed" is a state to render, "the AI service is down" is
     * an error to report.
     *
     * The upload path calls `assert_org_can_embed` on the far side instead, which is the same
     * computation plus a raise. One rule, two shapes, never two implementations.
     */
    public function for(Organization $organization, ?string $actorId = null): EmbeddingReadiness
    {
        // The organization comes from the RECORD, not from a session's "current org" and not from
        // request input. Everything downstream — the candidate query, the signed X-KB-Org-Id — is
        // derived from this one value, so there is no second place a tenant could be substituted.
        $organizationId = $organization->organizationId();

        return $this->ai->embeddingReadiness(
            organizationId: $organizationId,
            candidates: $this->candidates->forOrg($organizationId),
            designation: EmbeddingDesignation::fromOrganization($organization),
            actorId: $actorId,
        );
    }
}
