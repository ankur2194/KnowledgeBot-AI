<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Repositories\Contracts\ProviderConnectionRepositoryInterface;
use App\Services\Embedding\EmbeddingReadiness;
use App\Services\Embedding\EmbeddingReadinessService;
use App\Support\Crypto\CredentialVault;

/**
 * Storing a provider credential, and telling the organization what it can now do.
 *
 * ── ITEM 3 OF FINDING C1: AN EMBEDDING-ONLY CONNECTION IS PERMITTED ────────────────────────────
 *
 * Nothing here couples the embedding connection to the chat connection, and that decoupling is not
 * a nicety — with exactly one sourced embedding vendor
 * (`capabilities.providers_offering(EMBEDDING)` is `{"openai"}`), adding a second connection purely
 * to embed is the ONLY way an Anthropic-only organization can ingest a single document. So a
 * connection whose model rows carry `embedding` and nothing else is a first-class, valid,
 * expected configuration. Concretely that means:
 *
 *   - `$models` may contain only embedding rows. There is no "a connection must serve chat" check,
 *     and adding one would silently make that organization's configuration unreachable.
 *   - A row that claims embedding must NOT also claim chat flags. That is not our rule and is not
 *     enforced here: `capabilities.assert_row_coherent` refuses it on the data-plane side, because
 *     rows are task-exclusive and a row claiming both describes a model that does not exist. Our
 *     job is to send the row as the operator declared it and surface the refusal.
 *
 * ── THE SAVE DOES NOT HARD-FAIL ON READINESS ───────────────────────────────────────────────────
 *
 * The readiness verdict is computed AFTER the connection is stored and returned alongside it. It
 * never blocks the write. Refusing to store an organization's only chat connection because it
 * cannot also embed would leave them with no working configuration at all and an error about
 * something they were not doing.
 */
final readonly class ProviderConnectionService
{
    public function __construct(
        private ProviderConnectionRepositoryInterface $connections,
        private CredentialVault $vault,
        private EmbeddingReadinessService $readiness,
    ) {}

    /**
     * @return array{connection: ProviderConnection, readiness: EmbeddingReadiness}
     */
    public function create(
        Organization $organization,
        NewProviderConnection $input,
        ?string $actorId = null,
    ): array {
        $organizationId = $organization->organizationId();

        // Sealed before anything is written. A vault that cannot reach its KEK throws here, and
        // the connection is not created — a missing key-encrypting key is never a reason to store
        // a credential unwrapped.
        $sealed = $this->vault->seal($input->credential);

        $connection = $this->connections->create($organizationId, $input, $sealed);

        // Recomputed from the organization's connections as they now stand. Not derived from
        // $input: an operator who has just added their second embedding-capable connection needs
        // to be told their configuration became AMBIGUOUS, and that is a fact about the set, not
        // about the row they submitted.
        return [
            'connection' => $connection,
            'readiness' => $this->readiness->for($organization->refresh(), $actorId),
        ];
    }
}
