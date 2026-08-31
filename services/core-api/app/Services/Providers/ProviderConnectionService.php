<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Repositories\Contracts\ProviderConnectionRepositoryInterface;
use App\Services\Audit\AuditLogger;
use App\Services\Embedding\EmbeddingReadiness;
use App\Services\Embedding\EmbeddingReadinessService;
use App\Support\Crypto\CredentialVault;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Storing, listing, editing, deleting and re-keying a provider credential.
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
 *
 * ── THE PLAINTEXT KEY EXISTS ON EXACTLY TWO CODE PATHS AND DIES ON BOTH ────────────────────────
 *
 * `create()` and `rotate()` take a DTO whose credential is `#[SensitiveParameter]`, hand it
 * straight to `CredentialVault::seal()`, and hold nothing afterwards but the sealed array. No
 * method here decrypts: `CredentialVault::open()` is called only by the internal client, inside
 * the request that makes the provider call (laravel-control-plane). `list()`, `update()` and
 * `delete()` cannot reach a key at all — `ProviderConnectionEdit` has no member for one, and the
 * repository methods they call write no ciphertext column.
 *
 * ── SEAL BEFORE THE TRANSACTION, ALWAYS ────────────────────────────────────────────────────────
 *
 * Both write paths seal FIRST. A vault that cannot reach its KEK throws before any row is touched,
 * which is the only correct response: a missing key-encrypting key is never a reason to store a
 * credential unwrapped, and on the rotation path it is never a reason to leave a connection with
 * half-replaced state either.
 *
 * ── EVERY AUDIT ROW IS WRITTEN INSIDE THE REPOSITORY'S TRANSACTION ─────────────────────────────
 *
 * All four `provider.connection.*` operations are ON_FAILURE_ABORT, so a failed audit write must
 * roll the change back. AuditLogger opens no transaction of its own and `DB` is arch-pinned to
 * App\Repositories\Eloquent, so each mutating repository method takes the audit call as a REQUIRED
 * closure and invokes it inside its own transaction. The closures below are what land there.
 */
final readonly class ProviderConnectionService
{
    /**
     * The one sentence an operator gets when a delete is refused because the connection is the
     * organization's designated embedding credential.
     *
     * A CONSTANT BECAUSE IT IS RAISED FROM TWO PLACES that must not drift: the controller's
     * pre-flight 409, which is the readable error, and the 23503 mapping below, which is what
     * happens when a designation lands between that check and the DELETE. A caller who loses that
     * race deserves the same sentence, not a constraint name in a 500.
     *
     * IT NAMES THE REMEDY AND THE CONSEQUENCE, because the migration that created the constraint
     * says the operator "undesignates first, and sees what that means": clearing the designation
     * returns the organization to resolve-by-rule, which may select a DIFFERENT (provider, model)
     * than the existing corpus was indexed under — and that is a re-index, not a setting.
     */
    public const DESIGNATED_FOR_EMBEDDING = 'This connection supplies the organization\'s embedding '
        .'credential, so deleting it would leave every already-indexed chunk in a vector space '
        .'nothing can reproduce. Clear the embedding designation first (PUT '
        .'/embedding-configuration with connection_id and model both null), read the readiness '
        .'verdict it returns, and delete the connection after that.';

    /**
     * The same refusal for the RERANK designation, and it sits here beside its twin rather than in a
     * second place for the reason the constant above records: it is raised from the controller's
     * pre-flight 409 AND from the 23503 mapping below, and a caller who loses the race between them
     * deserves the same sentence rather than a constraint name in a 500.
     *
     * ── IT NAMES A DIFFERENT CONSEQUENCE, BECAUSE THE CONSEQUENCE IS DIFFERENT ────────────────
     *
     * Deleting the embedding connection strands an indexed corpus in a vector space nothing can
     * reproduce. Deleting the rerank connection does not break anything: `ON DELETE RESTRICT` is
     * still right, but the reason is the opposite one. If the designation were simply cleared,
     * stage 11 would stop running with NO error, no metric jump on any single request, and nothing
     * on any response to say so — answers would just get worse. That is the failure this platform is
     * least able to notice, so the operator turns reranking off explicitly and sees what that means.
     */
    public const DESIGNATED_FOR_RERANK = 'This connection supplies the organization\'s rerank '
        .'credential, so deleting it would silently stop reranking for every bot — answers would '
        .'be served in fused order with no error anywhere. Turn reranking off first (PUT '
        .'/rerank-configuration with connection_id and model both null), and delete the connection '
        .'after that.';

    /** SQLSTATE 23503 — foreign_key_violation. */
    private const FOREIGN_KEY_VIOLATION = '23503';

    public function __construct(
        private ProviderConnectionRepositoryInterface $connections,
        private CredentialVault $vault,
        private EmbeddingReadinessService $readiness,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{connection: ProviderConnection, readiness: EmbeddingReadiness}
     */
    public function create(
        Organization $organization,
        NewProviderConnection $input,
        ?string $actorId = null,
        ?Request $request = null,
    ): array {
        $organizationId = $organization->organizationId();

        // Sealed before anything is written. A vault that cannot reach its KEK throws here, and
        // the connection is not created — a missing key-encrypting key is never a reason to store
        // a credential unwrapped.
        $sealed = $this->vault->seal($input->credential);

        $connection = $this->connections->create(
            $organizationId,
            $input,
            $sealed,
            // A FULL CLOSURE AND NOT AN ARROW FUNCTION. `fn () => $this->record(...)` implicitly
            // RETURNS the call's value, and `record()` here is `void` — which is both a PHPStan
            // finding ("result of a void method is used") and a quiet lie about the contract, since
            // the interface types the callback as `Closure(ProviderConnection): void`.
            function (ProviderConnection $row) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::PROVIDER_CONNECTION_CREATED,
                    $organizationId,
                    $actorId,
                    $row,
                    $request,
                );
            },
        );

        // Recomputed from the organization's connections as they now stand. Not derived from
        // $input: an operator who has just added their second embedding-capable connection needs
        // to be told their configuration became AMBIGUOUS, and that is a fact about the set, not
        // about the row they submitted.
        return [
            'connection' => $connection,
            'readiness' => $this->readiness->for($organization->refresh(), $actorId),
        ];
    }

    /**
     * Every connection this organization owns, oldest first.
     *
     * NO AUDIT ROW. §18.11 audits credential CHANGES and destructive operations; a read of the
     * masked list is neither, and auditing it would bury the four rows that matter under one per
     * page load.
     *
     * @return list<ProviderConnection>
     */
    public function list(Organization $organization): array
    {
        return $this->connections->forOrg($organization->organizationId());
    }

    /**
     * Apply a label and/or status edit.
     *
     * There is no vault call on this path and no argument that could carry a key: see
     * ProviderConnectionEdit.
     */
    public function update(
        Organization $organization,
        ProviderConnection $connection,
        ProviderConnectionEdit $edit,
        ?string $actorId = null,
        ?Request $request = null,
    ): ProviderConnection {
        $organizationId = $organization->organizationId();

        $updated = $this->connections->update(
            $organizationId,
            $connection->id,
            $edit,
            function (ProviderConnection $row) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::PROVIDER_CONNECTION_UPDATED,
                    $organizationId,
                    $actorId,
                    $row,
                    $request,
                );
            },
        );

        if ($updated === null) {
            // The row was deleted between the route binding and the transaction. The same 404 the
            // binding would have produced, not a 500 describing a race the caller cannot act on.
            throw new NotFoundHttpException;
        }

        return $updated;
    }

    /**
     * Hard-delete the connection and its model rows.
     *
     * @throws ConflictHttpException when the connection is still the organization's designated
     *                               embedding OR rerank credential — which the controller checks
     *                               first, so reaching it here means a designation landed in between
     */
    public function delete(
        Organization $organization,
        ProviderConnection $connection,
        ?string $actorId = null,
        ?Request $request = null,
    ): void {
        $organizationId = $organization->organizationId();

        try {
            $deleted = $this->connections->delete(
                $organizationId,
                $connection->id,
                function (ProviderConnection $row) use ($organizationId, $actorId, $request): void {
                    $this->record(
                        AuditLogger::PROVIDER_CONNECTION_DELETED,
                        $organizationId,
                        $actorId,
                        $row,
                        $request,
                    );
                },
            );
        } catch (QueryException $conflict) {
            // THE RACE THE CONTROLLER'S CHECK CANNOT WIN. Two administrators, one designating this
            // connection for embedding and one deleting it, in the same instant: the pre-flight
            // 409 read "not designated", the DELETE then hit the composite ON DELETE RESTRICT. The
            // loser deserves exactly what the pre-flight check would have said a millisecond
            // earlier — the constraint is the authority and the check is only the good message.
            if ($this->violates($conflict, 'organizations_embedding_connection_same_org')) {
                throw new ConflictHttpException(self::DESIGNATED_FOR_EMBEDDING, $conflict);
            }

            // THE SECOND ARM, AND IT IS TESTED SEPARATELY RATHER THAN ASSUMED TO FOLLOW. Two
            // composite ON DELETE RESTRICT constraints now reference this row and they are
            // distinguished only by constraint NAME inside the driver's message, so an arm added
            // without its own test is an arm that can match the wrong one — or neither, and fall
            // through to a 500 describing a race the caller cannot act on.
            if ($this->violates($conflict, 'organizations_rerank_connection_same_org')) {
                throw new ConflictHttpException(self::DESIGNATED_FOR_RERANK, $conflict);
            }

            throw $conflict;
        }

        if (! $deleted) {
            // Deleted between the binding and the transaction. DELETE is not idempotent here on
            // purpose: an audit row exists for the first delete, and a 200 for the second would
            // claim this actor performed a deletion the trail does not record.
            throw new NotFoundHttpException;
        }
    }

    /**
     * Replace the stored secret.
     *
     * The §18.3 re-authentication has already happened — it is a validation rule on
     * RotateProviderCredentialRequest, so it runs before this method is reachable and before any
     * row is read. Nothing about the password reaches this layer.
     */
    public function rotate(
        Organization $organization,
        ProviderConnection $connection,
        ProviderCredentialRotation $input,
        ?string $actorId = null,
        ?Request $request = null,
    ): ProviderConnection {
        $organizationId = $organization->organizationId();

        // Sealed before the transaction, for the same reason as create(): a vault that cannot
        // reach its KEK must fail before the row is touched, not halfway through replacing it.
        $sealed = $this->vault->seal($input->credential);

        $rotated = $this->connections->rotateCredential(
            $organizationId,
            $connection->id,
            $sealed,
            function (ProviderConnection $row) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::PROVIDER_CREDENTIAL_ROTATED,
                    $organizationId,
                    $actorId,
                    $row,
                    $request,
                    [
                        // The two version numbers, and NOTHING derived from the key — not a
                        // prefix, not the last four, not a fingerprint. See AuditLogger's
                        // four-operation docblock for why a provider credential is not
                        // fingerprinted the way an invitation token is.
                        'key_version' => $row->key_version,
                        'credential_version' => $row->credential_version,
                    ],
                );
            },
        );

        if ($rotated === null) {
            throw new NotFoundHttpException;
        }

        return $rotated;
    }

    /**
     * One audit row describing a connection, with the three fields every provider operation
     * echoes plus whatever the operation adds.
     *
     * THE DETAILS ARE BUILT FROM THE PERSISTED ROW, never from request input. That is what makes
     * "no credential-shaped value can appear here" a property of the code rather than of the
     * caller's discipline: `$row` has no plaintext to offer — `ProviderConnection` carries no
     * accessor for one — and `$extra` is only ever reached from this file.
     *
     * @param  array<string, scalar>  $extra
     */
    private function record(
        string $operation,
        string $organizationId,
        ?string $actorId,
        ProviderConnection $row,
        ?Request $request,
        array $extra = [],
    ): void {
        $this->audit->record(
            $operation,
            organizationId: $organizationId,
            actorId: $actorId,
            details: [
                'provider' => $row->provider->value,
                'label' => $row->label,
                'status' => $row->status->value,
            ] + $extra,
            subjectType: ProviderConnection::class,
            subjectId: $row->id,
            request: $request,
        );
    }

    private function violates(QueryException $exception, string $constraint): bool
    {
        return $exception->getCode() === self::FOREIGN_KEY_VIOLATION
            && str_contains($exception->getMessage(), $constraint);
    }
}
