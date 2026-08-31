<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Enums\ProviderModelDeletion;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Repositories\Contracts\ProviderModelRepositoryInterface;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The catalog of models under one provider connection: list, register, edit, delete.
 *
 * ── NOTHING HERE TOUCHES A CREDENTIAL, AND NOTHING HERE CAN ───────────────────────────────────
 *
 * This class does not import App\Support\Crypto\CredentialVault and no method it calls reaches
 * one. A catalog row is metadata ABOUT a model — its identifier, its declared capabilities, its
 * limits, its list price — and the key that authenticates against the vendor lives on the parent
 * connection, written only by ProviderConnectionService. That separation is why the endpoints
 * below need no §18.3 re-authentication: nothing they change can break a live bot's credentials.
 *
 * ── WHAT A CATALOG ROW DECIDES, AND THEREFORE WHY IT IS BEHIND `providers.manage` ─────────────
 *
 * It is not decoration. `capability_flags` is the ROW axis of the capability question, and
 * services/ai-service/app/providers/embedding_selection.py reads it to decide WHICH of an
 * organization's connections embeds its corpus. So adding an `embedding` flag can change the
 * vector space every future upload is indexed under, and disabling a row can take an
 * organization's only embedder out of the candidate set. Reads are `providers.view` — which §6.4
 * grants a Knowledge Manager, because an ingestion operator has to be able to see whether the
 * organization can embed at all — and every write is `providers.manage`.
 *
 * ── THIS CLASS DRAWS NO CONCLUSION FROM A CAPABILITY FLAG ─────────────────────────────────────
 *
 * The flag list is stored as the operator declared it and is never validated against a local
 * capability matrix. The matrix that matters is
 * services/ai-service/app/providers/capabilities.py, which carries a source per cell and refuses
 * an incoherent row by name with the cell quoted — a vendor that publishes no such endpoint, a
 * vendor whose score scale this platform cannot threshold, or a row claiming two task-exclusive
 * capabilities at once. Duplicating any part of that here would be a second copy that drifts, and
 * the drifting copy is always the one that ships. Inferring capability from the model IDENTIFIER
 * — the "it has text-embedding in the name" shortcut — is the one thing this class must never
 * grow: vendors have already retired ids that code was parsing.
 *
 * ── EVERY AUDIT ROW IS WRITTEN INSIDE THE REPOSITORY'S TRANSACTION ────────────────────────────
 *
 * All three `provider.model.*` operations are ON_FAILURE_ABORT, so a failed audit write must roll
 * the change back. AuditLogger opens no transaction of its own and `DB` is arch-pinned to
 * App\Repositories\Eloquent, so each mutating repository method takes the audit call as a REQUIRED
 * closure and invokes it inside its own transaction. The closures below are what land there.
 */
final readonly class ProviderModelService
{
    /**
     * The one sentence an operator gets when a delete is refused because the row is the
     * organization's designated embedding model.
     *
     * IT NAMES THE REMEDY AND THE CONSEQUENCE, for the same reason
     * ProviderConnectionService::DESIGNATED_FOR_EMBEDDING does: clearing the designation returns
     * the organization to resolve-by-rule, which may select a DIFFERENT (provider, model) than the
     * existing corpus was indexed under — and that is a re-index, not a setting.
     *
     * A CONSTANT BECAUSE IT IS RAISED FROM TWO PLACES that must not drift: the controller's
     * pre-flight 409, which is the fast readable error, and the repository's in-transaction
     * verdict, which is what happens when a designation lands between that check and the DELETE.
     * A caller who loses that race deserves the same sentence.
     */
    public const DESIGNATED_FOR_EMBEDDING = 'This model is the organization\'s designated embedding '
        .'model, so deleting it would leave the designation naming a catalog row that no longer '
        .'exists and the next upload would fail with a resolution error instead of anyone seeing '
        .'why now. Clear the embedding designation first (PUT /embedding-configuration with '
        .'connection_id and model both null), read the readiness verdict it returns, and delete '
        .'the row after that.';

    /**
     * The same sentence for the rerank designation, and it is a SEPARATE constant rather than a
     * parameterised one (`docs/22` § T36).
     *
     * TWO CONSTANTS BECAUSE THE CONSEQUENCES ARE NOT THE SAME SENTENCE WITH A NOUN SWAPPED.
     * Clearing the embedding designation returns the organization to resolve-by-rule and may
     * select a different `(provider, model)` than the existing corpus was indexed under — a
     * re-index. Clearing the rerank designation turns reranking off until another is chosen, which
     * changes ranking quality and costs nothing to undo. An operator acting on the wrong one of
     * those two has been actively misled.
     *
     * IT ALSO NAMES THE SYMPTOM, WHICH THE EMBEDDING ONE DOES NOT NEED TO. An embedding
     * designation naming a missing row fails the next upload loudly. This one fails silently, so
     * the sentence has to say what "silently" means or the operator has no reason to believe the
     * refusal is worth respecting.
     */
    public const DESIGNATED_FOR_RERANK = 'This model is the organization\'s designated rerank '
        .'model, so deleting it would leave the designation naming a catalog row that no longer '
        .'exists — and unlike the embedding designation, nothing would report it: reranking would '
        .'simply stop, with no error and no metric movement, and the only symptom would be answers '
        .'getting worse. Clear the rerank designation first (PUT /rerank-configuration with '
        .'connection_id and model both null) and delete the row after that.';

    /** SQLSTATE 23505 — unique_violation. */
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(
        private ProviderModelRepositoryInterface $models,
        private AuditLogger $audit,
    ) {}

    /**
     * Every catalog row under one connection, deterministically ordered.
     *
     * NO AUDIT ROW. §18.11 audits credential changes and destructive operations; reading a catalog
     * is neither, and auditing it would bury the rows that matter under one per page load.
     *
     * @return list<ProviderModelEntry>
     */
    public function list(Organization $organization, ProviderConnection $connection): array
    {
        return $this->models->forConnection($organization->organizationId(), $connection->id);
    }

    /**
     * Register one model under a connection.
     *
     * ── THE DUPLICATE IS A 422 AND NOT A 500, IN TWO LAYERS ───────────────────────────────────
     *
     * `provider_models_org_connection_model` is UNIQUE on (organization, connection, model), and a
     * second row for the same identifier would otherwise surface as SQLSTATE 23505 rendered by the
     * error envelope as `internal_dependency` / 500 — a bug report about the server for what is
     * plainly a bad request.
     *
     * The PRE-FLIGHT check is an org-scoped repository query and NOT a `unique:` validation rule.
     * That is the house rule DesignateEmbeddingConnectionRequest writes out at length: `unique:`
     * queries the table with NO organization predicate unless somebody remembers to add one, which
     * is the exact shape of Filament CVE-2026-48067 — the select query was tenant-scoped and the
     * validation rule for the same field was not. Adding `,organization_id,{id}` by hand would work
     * and would also be a tenant predicate assembled from route input inside a rule string, which
     * is the thing that goes wrong. A repository method takes the organization as a typed argument
     * and cannot forget it.
     *
     * The CATCH is the race the pre-flight cannot win: two administrators registering the same
     * model identifier in the same instant. The index is the authority, the check is the good
     * message, and the loser gets the same 422 rather than a 500 — the same construction
     * ProviderConnectionService uses for the designated-connection 23503.
     *
     * @throws ValidationException 422, keyed on `model`, for a duplicate identifier
     */
    public function create(
        Organization $organization,
        ProviderConnection $connection,
        NewProviderModelEntry $input,
        ?string $actorId = null,
        ?Request $request = null,
    ): ProviderModelEntry {
        $organizationId = $organization->organizationId();

        if ($this->models->modelExists($organizationId, $connection->id, $input->model)) {
            throw $this->duplicate($input->model);
        }

        try {
            return $this->models->create(
                $organizationId,
                $connection->id,
                $input,
                // A FULL CLOSURE AND NOT AN ARROW FUNCTION: `fn () => $this->record(...)`
                // implicitly RETURNS the call's value, `record()` is `void`, and the interface
                // types the callback as `Closure(ProviderModelEntry): void`.
                function (ProviderModelEntry $row) use ($organizationId, $actorId, $request): void {
                    $this->record(
                        AuditLogger::PROVIDER_MODEL_CREATED,
                        $organizationId,
                        $actorId,
                        $row,
                        $request,
                    );
                },
            );
        } catch (QueryException $conflict) {
            if ($this->violates($conflict, 'provider_models_org_connection_model')) {
                throw $this->duplicate($input->model);
            }

            throw $conflict;
        }
    }

    /**
     * Replace a row's mutable attributes.
     *
     * NO DUPLICATE PATH EXISTS HERE, because the model identifier is not editable and
     * `ProviderModelEdit` has no member that could carry one. See that class for why a rename is a
     * re-index rather than an edit.
     */
    public function update(
        Organization $organization,
        ProviderConnection $connection,
        ProviderModelEntry $model,
        ProviderModelEdit $edit,
        ?string $actorId = null,
        ?Request $request = null,
    ): ProviderModelEntry {
        $organizationId = $organization->organizationId();

        $updated = $this->models->update(
            $organizationId,
            $connection->id,
            $model->id,
            $edit,
            function (ProviderModelEntry $row) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::PROVIDER_MODEL_UPDATED,
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
     * Hard-delete one catalog row.
     *
     * @throws ConflictHttpException when the row is the organization's designated embedding model
     *                               — which the controller checks first, so reaching it here means
     *                               the designation landed in between
     */
    public function delete(
        Organization $organization,
        ProviderConnection $connection,
        ProviderModelEntry $model,
        ?string $actorId = null,
        ?Request $request = null,
    ): void {
        $organizationId = $organization->organizationId();

        $outcome = $this->models->delete(
            $organizationId,
            $connection->id,
            $model->id,
            function (ProviderModelEntry $row) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::PROVIDER_MODEL_DELETED,
                    $organizationId,
                    $actorId,
                    $row,
                    $request,
                );
            },
        );

        match ($outcome) {
            ProviderModelDeletion::Deleted => null,

            // THE RACE THE CONTROLLER'S CHECK CANNOT WIN. Two administrators, one designating this
            // pair for embedding and one deleting it, in the same instant: the pre-flight 409 read
            // "not designated", and the repository's in-transaction re-read under the same row
            // lock the designation write takes found otherwise. The loser deserves exactly what
            // the pre-flight check would have said a millisecond earlier.
            ProviderModelDeletion::DesignatedForEmbedding => throw new ConflictHttpException(
                self::DESIGNATED_FOR_EMBEDDING,
            ),

            // The same race on the other designation, and the reason it is a separate arm is the
            // reason the enum has a fourth case: the operator has to be told which configuration
            // to clear. See `docs/22` § T36.
            ProviderModelDeletion::DesignatedForRerank => throw new ConflictHttpException(
                self::DESIGNATED_FOR_RERANK,
            ),

            // Deleted between the binding and the transaction. DELETE is not idempotent here on
            // purpose: an audit row exists for the first delete, and a 200 for the second would
            // claim this actor performed a deletion the trail does not record.
            ProviderModelDeletion::Missing => throw new NotFoundHttpException,
        };
    }

    /**
     * The 422 a duplicate model identifier produces, keyed on the field the form renders.
     *
     * KEYED ON `model` AND NOT RAISED AS A BARE 409, because there IS a field to key it on — which
     * is the distinction ProviderConnectionController::destroy's docblock draws for the case where
     * there is not. The SPA's `applyServerErrors` puts this under the input the operator typed
     * into, which is where it belongs; a 409 would surface as a banner about a request that is
     * plainly about one field.
     */
    private function duplicate(string $model): ValidationException
    {
        return ValidationException::withMessages([
            'model' => 'This connection already carries a row for "'.$model.'". One connection '
                .'holds one row per model identifier — two rows for the same id would make "which '
                .'capabilities does this model claim" ambiguous, and the embedding designation '
                .'names a (connection, model) PAIR, so it could no longer name one row. Edit the '
                .'existing row instead.',
        ]);
    }

    /**
     * One audit row describing a catalog entry.
     *
     * THE DETAILS ARE BUILT FROM THE PERSISTED ROW, never from request input — which is what makes
     * "nothing credential-shaped can appear here" a property of the code rather than of the
     * caller's discipline. `ProviderModelEntry` has no credential to offer: the key lives on the
     * parent connection and is not reachable from this object.
     *
     * `capabilities` is JOINED INTO A STRING because `AuditLogger::sanitize()` drops a non-scalar
     * outright — an array in `details` is how `$request->all()` gets in one nesting level down, and
     * it is also what would stop `details` json-encoding as an OBJECT, which the table CHECKs. The
     * flag list is the security-relevant half of this row (an `embedding` flag decides which
     * credential embeds the corpus), so losing it silently would be the worst of both.
     *
     * A ROW THAT CLAIMS NOTHING WRITES NO `capabilities` KEY AT ALL, and that is the sanitizer's
     * rule rather than a decision here: an empty string is skipped exactly as a null is, without
     * being reported, because reporting an optional field's absence would produce a WARNING on
     * every request it is absent from. So `capabilities` present means "these flags", and
     * `capabilities` absent means "none" — the same reading the three pricing keys have.
     */
    private function record(
        string $operation,
        string $organizationId,
        ?string $actorId,
        ProviderModelEntry $row,
        ?Request $request,
    ): void {
        $this->audit->record(
            $operation,
            organizationId: $organizationId,
            actorId: $actorId,
            details: [
                // The PARENT, echoed on every row. `subject_id` is this catalog row's ULID, and
                // after a hard delete it resolves to nothing — so without the connection id the
                // trail cannot say which credential's catalog was changed.
                'connection_id' => $row->provider_connection_id,
                'model' => $row->model,
                'display_name' => $row->display_name,
                'capabilities' => implode(',', $row->supportedCapabilities()),
                'enabled' => $row->enabled,
                // THE TWO LIMITS, WHICH THIS METHOD DID NOT USED TO PASS. They were out of the
                // allow-list because a standing guard refused any ECHOED field with `token` in its
                // NAME and `max_output_tokens` is a false positive under a substring rule. The
                // guard was narrowed to read the name segment by segment — singular `token` is a
                // bearer capability, a plural `tokens` beside a magnitude word is a count — so
                // both fields are allow-listed now and the trail can finally say who changed a
                // model's limits. See AuditLogger's PROVIDER_MODEL_CREATED docblock.
                //
                // INTEGERS, so sanitize() keeps them by the `is_int()` path: a limit of 0 records
                // as 0 rather than being skipped the way an empty string is.
                'context_window' => $row->context_window,
                'max_output_tokens' => $row->max_output_tokens,
                //
                // Nulls are skipped by the sanitizer without being reported, so an unpriced row
                // simply omits these three rather than writing three nulls or a warning.
                'input_price_per_million' => $row->input_price_per_million,
                'output_price_per_million' => $row->output_price_per_million,
                'price_currency' => $row->price_currency,
            ],
            subjectType: ProviderModelEntry::class,
            subjectId: $row->id,
            request: $request,
        );
    }

    private function violates(QueryException $exception, string $constraint): bool
    {
        return $exception->getCode() === self::UNIQUE_VIOLATION
            && str_contains($exception->getMessage(), $constraint);
    }
}
