<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One audited event. APPEND-ONLY, AND THE DATABASE ENFORCES IT.
 *
 * THIS MODEL MUST NEVER BE USED TO UPDATE OR DELETE A ROW. The migration revokes UPDATE and DELETE
 * on `audit_logs` (and on every partition) from the application role, so `$log->save()` on a loaded
 * row and `$log->delete()` both come back as SQLSTATE 42501 — a 500 in production, from a statement
 * a reviewer waved through. :self::performUpdate() and :self::delete() therefore refuse in PHP
 * first, so the failure is a LogicException with a sentence in it at the call site, in a unit test,
 * before it is ever a driver error in a request. Neither guard is the mechanism; the grant is.
 *
 * $fillable IS EMPTY AND STAYS EMPTY. Rows are written by exactly one class,
 * App\Services\Audit\AuditLogger, through App\Repositories\Eloquent\EloquentAuditLogRepository,
 * which assigns every attribute explicitly. Mass assignment is what turns
 * `'details' => $request->all()` (kb-security-baseline:147, the failure mode this whole unit is
 * designed against) from a thing somebody has to write into a thing that happens by default. There
 * is no factory for this model either, for the same reason: the production writer is the only way in
 * and a test that bypasses it is a test of nothing.
 *
 * organization_id IS NULLABLE, and that is a property of the events rather than a looseness.
 * A failed login for an address that belongs to no user has no organization; neither does a
 * platform-scope action. Refusing to record those, or inventing a sentinel organization for them,
 * would lose the rows an intrusion investigation opens with.
 *
 * WHICH IS WHY THERE IS NO #[ScopedBy(OrganizationScope::class)], and the reason is the nullability
 * rather than convenience. OrganizationScope::apply() appends `where organization_id = ?`, and that
 * predicate is FALSE for a NULL — so with the scope attached, every org-less row becomes
 * unreachable through Eloquent by construction, from every surface, forever, with no error. On top
 * of that the scope fails closed with no bound TenantContext (OrganizationScope:53-58) and these
 * rows are written on the guest path where no context exists — inserts are unaffected, but a
 * read-back would return nothing and render as "no audit history", which is the most
 * plausible-looking wrong answer this table could give. Safety comes from the other direction, the
 * same one OrganizationUser relies on: every org-scoped read of this table goes through a repository
 * method that takes `organization_id` as a required positional argument.
 *
 * THAT PARAGRAPH USED TO SAY "any FUTURE read-back", AND THE FUTURE ARRIVED IN PHASE 6a.
 * AuditLogRepositoryInterface::paginate() is the read side, App\Services\Audit\AuditTrailReader is
 * its use case, and GET /api/v1/organizations/{organization}/audit-logs is the surface. Three
 * consequences follow and each is enforced somewhere rather than promised here:
 *
 *   * THE EXEMPTION IS NOW A LIVE ASSERTION. This docblock used to end "the reflection test sketched
 *     in tests/Arch/DoctrineTest.php will fail on this class the day it is enabled; it needs an
 *     explicit exemption there naming this docblock". That general rule is still commented out —
 *     it still cannot be enabled unqualified — so the exemption was written as its own file instead:
 *     tests/Arch/AuditTrailDoctrineTest.php, in the construction ConversationDoctrineTest and
 *     SourceCascadeDoctrineTest use, naming this class explicitly so no `->ignoring(...)` is needed
 *     and none can be acquired by accident.
 *   * WHAT AN ORG-SCOPED READER SEES FOR A PLATFORM ROW IS NOW A DECISION AND NOT A LATENT
 *     QUESTION: nothing. `organization_id = ?` already excludes a NULL, so the answer happens
 *     whether or not anybody chose it — which is precisely why it is stated at the interface, at the
 *     predicate, and in tests/Security/AuditTrailAccessTest.php, where a platform row is planted on
 *     purpose. §6.1 assigns platform-level audit logs to the platform owner, on a surface that does
 *     not exist yet; audit_logs_platform_created is the partial index waiting for it.
 *   * `performUpdate()` AND `delete()` BELOW ARE NOW REACHABLE. Until there was a reader, nothing in
 *     this application loaded an AuditLog through Eloquent, so a `save()` on a LOADED row was
 *     unreachable by construction. A reader is one `->update()` away from making it reachable, which
 *     is why both refusals are asserted rather than merely written.
 *
 * @property string $id
 * @property \Carbon\CarbonImmutable $created_at
 * @property string $operation
 * @property string $outcome
 * @property string|null $organization_id
 * @property string|null $actor_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property array<string, scalar> $details
 */
final class AuditLog extends Model implements OrgOwned
{
    use HasUlids;

    /**
     * The table has `created_at` and NO `updated_at` — a row that could be updated would not be an
     * audit row. Nulling UPDATED_AT keeps Eloquent's timestamp handling for created_at (so
     * Carbon::setTestNow() moves it, which is how the partition tests reach another month) while
     * never referencing a column that does not exist.
     */
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    /**
     * The database key is composite — (id, created_at) — because a partitioned table's primary key
     * must contain the partition key. Eloquent has no composite-key support and does not need any
     * here: `id` is a ULID, unique by construction, and nothing looks a row up by key. Declared as
     * the non-incrementing string key so HasUlids fills it on insert.
     */
    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    /**
     * EMPTY BY DESIGN — see the class docblock. Do not add a field here "for the factory".
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The organization this row belongs to.
     *
     * THROWS RATHER THAN RETURNING A SENTINEL when the row has none. App\Support\Tenancy\OrgOwned
     * declares `organizationId(): string`, non-nullable, because OrgScopedPolicy::permit() resolves
     * membership of THE RECORD'S organization and there is no membership of no organization. An
     * empty string here would be a lie the policy layer would then compare against a real
     * organization id and deny — which reads as a permission bug rather than as what it is.
     *
     * The contradiction is real and is reported rather than papered over: this model satisfies
     * OrgOwned only for its org-scoped rows. A platform-scope row (a failed login for an unknown
     * address) is not authorizable through any org policy and must be read by the platform-owner
     * surface, which does not exist yet.
     */
    public function organizationId(): string
    {
        $organizationId = $this->organization_id;

        if ($organizationId === null) {
            throw new LogicException(
                'This audit row has no organization: it records a platform-scope event (for example '
                .'a failed login for an address that is not a user). It cannot be authorized through '
                .'an org-scoped policy, and asking it for an organization id is the bug.',
            );
        }

        return $organizationId;
    }

    /**
     * @return never
     */
    public function delete()
    {
        throw new LogicException(
            'audit_logs is append-only: the application role has no DELETE on it (SQLSTATE 42501), '
            .'and retention is DETACH PARTITION CONCURRENTLY + DROP TABLE via '
            .'kb:prune-audit-partitions — never a row delete.',
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'details' => 'array',
        ];
    }

    /**
     * `Builder<static>` verbatim from Model::performUpdate()'s own annotation. Narrowing it to
     * `Builder<$this>` is a contravariance error, and dropping the generic entirely trips level 6's
     * missing-type-parameter check — the same pair of constraints OrganizationScope::apply() documents.
     *
     * @param  Builder<static>  $query
     * @return never
     */
    protected function performUpdate(Builder $query)
    {
        throw new LogicException(
            'audit_logs is append-only: the application role has no UPDATE on it (SQLSTATE 42501). '
            .'A row that needed correcting is a second row, not an edit.',
        );
    }
}
