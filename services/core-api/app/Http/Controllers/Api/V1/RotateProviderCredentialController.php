<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RotateProviderCredentialRequest;
use App\Http\Resources\ProviderConnectionResource;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\Providers\ProviderConnectionService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Support\Facades\Gate;

/**
 * Replace the secret stored on one provider connection.
 *
 * A SINGLE-ACTION CONTROLLER AND NOT A SIXTH METHOD ON ProviderConnectionController:
 * `arch()->preset()->laravel()` restricts a controller's public methods to the seven resource
 * verbs plus `__construct`, `__invoke` and `middleware`, so `rotateCredential()` would fail the
 * Arch suite. That is the preset working — a verb outside the seven is a single-action controller,
 * which is also how it reads in the route file. Same reason ResendInvitationController exists.
 *
 * A PUT ON A SUB-RESOURCE (`…/provider-connections/{id}/credential`) rather than a field on the
 * PATCH, and the separation is the security control rather than REST taste. Folding it into
 * `PATCH …/{id}` would mean one route, one permission and one re-authentication policy covering
 * both a relabel and a credential replacement — and the weaker of each pair would win. It also
 * makes the rule "the edit endpoint may never accept a credential field" checkable by reading one
 * FormRequest instead of by reasoning about a branch.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4) ────────────────────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group.
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, re-reading `organization_users` from PostgreSQL.
 * 3. ROLE / PERMISSION — `Gate::authorize('rotateCredential', $providerConnection)` ->
 *    `providers.manage`. A knowledge_manager holds `providers.view` only and is refused here;
 *    §6.4 excludes provider credentials from that role wholesale.
 * 4. ENTITY OWNERSHIP — `->scopeBindings()` resolves `{providerConnection}` through
 *    `$organization->providerConnections()`, so a foreign or unknown id 404s at BINDING time,
 *    before the policy and before the row is in memory; the policy then resolves membership of THE
 *    RECORD's organization; and the repository takes `organization_id` as a required argument.
 * 5. ENTITY STATUS — 409 when the organization is not Active, carrying
 *    OrganizationStatus::SUSPENDED_REFUSAL as its message; a 409 with no message renders as
 *    `internal_dependency`'s class-mapped copy, which is false twice for a suspension.
 *    There is deliberately NO check on
 *    the CONNECTION's own status: §18.4's worked example refuses to rotate anything but an
 *    `active` credential, and that is wrong for this product. `invalid` is the state a failed
 *    connection check leaves behind, and `revoked` is the state an operator sets when a key leaks
 *    — replacing the key is the remedy for both, so refusing to rotate them would mean the only
 *    way out of a bad credential is to delete the connection and re-create it, losing its id, its
 *    model rows and its designation. The rotation returns the row to `active` instead.
 * 6. RATE LIMIT — `throttle:admin` AND `throttle:credential-rotation`. The group limiter alone
 *    keys on (organization, user) at 120/min, which on an endpoint that verifies a password is
 *    120 password guesses a minute; the second limiter is what makes the re-authentication mean
 *    something. See AppServiceProvider.
 *
 * AND CHECK 6 IN THE §18.3 SENSE — a destructive action re-authenticates rather than trusting the
 * age of a session. `current_password:web` is a rule on RotateProviderCredentialRequest, so it
 * runs BEFORE this method and before any row is read: a wrong password is a 422 keyed on
 * `current_password` and the connection is untouched, by construction rather than by ordering
 * discipline. The full argument, including why the rule lives in the FormRequest, is on that
 * class.
 */
final class RotateProviderCredentialController extends Controller
{
    /**
     * ── WHAT THE RESPONSE DOES AND DOES NOT CARRY ──────────────────────────────────────────────
     *
     * The connection, with a `masked_key` that has changed because `last_four` has. Nothing else:
     * not the new key, not the old one, not a prefix of either, not a fingerprint, not
     * `key_version`, not `credential_version`. The two version numbers go into the audit row,
     * where an investigation can reach them; a client has no decision to make with either, and the
     * published component is closed so it could not carry them without an OpenAPI change anyway.
     *
     * NO EMBEDDING READINESS beside it, unlike `store`. Rotation changes the key and changes
     * nothing about which (provider, model) pairs this organization can embed with — the candidate
     * set, the designation and the vector space are all identical afterwards. Returning a
     * readiness verdict here would invite a client to re-render a banner that cannot have moved,
     * and would put a synchronous internal call on the path of an operation that has no need of
     * one.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => ProviderConnectionResource::class],
        description: 'The connection after its credential was replaced, wrapped in `data`. '
            .'`masked_key` reflects the NEW key\'s last four; no part of either key — old or new — '
            .'appears in this body, in a log line, or in the audit row. 422 when the actor\'s '
            .'password is wrong (keyed on `current_password`, and the stored credential is '
            .'untouched) or when the submitted value is the masked display string rather than a '
            .'key. 409 when the organization is not active.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function __invoke(
        RotateProviderCredentialRequest $request,
        Organization $organization,
        ProviderConnection $providerConnection,
        ProviderConnectionService $connections,
    ): ProviderConnectionResource {
        // CHECKS 3 AND 4. The §18.3 re-authentication has already passed — it is a validation rule
        // and therefore ran before this line, which is what makes "a failed password must not
        // touch the row" true by construction.
        Gate::authorize('rotateCredential', $providerConnection);

        // CHECK 5. The organization only; the CONNECTION's status is deliberately not checked —
        // see the class docblock.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        return new ProviderConnectionResource($connections->rotate(
            $organization,
            $providerConnection,
            $request->toData(),
            $this->actorId(),
            $request,
        ));
    }

    /**
     * The admin user id, for the audit row's `actor_id`. Never used as a SCOPE — the organization
     * is.
     */
    private function actorId(): ?string
    {
        $user = request()->user();

        return $user instanceof User ? $user->id : null;
    }
}
