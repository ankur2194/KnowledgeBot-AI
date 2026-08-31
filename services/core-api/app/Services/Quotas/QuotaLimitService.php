<?php

declare(strict_types=1);

namespace App\Services\Quotas;

use App\Enums\QuotaMetric;
use App\Exceptions\KbException;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * Read and write an organization's four quota ceilings.
 *
 * ═══ THE SPEC DOES NOT AGREE WITH ITSELF ABOUT WHO OWNS THESE FOUR NUMBERS ══════════════════
 *
 * §6.1 gives "Control limits for storage, bots, users, and ingestion" to the PLATFORM OWNER —
 * `users.is_platform_owner`, which is not an organization role at all. §6.2 gives the Organization
 * Owner "Manage organization settings". Those two sentences overlap on exactly these four columns
 * and the specification never says which wins. THIS IS REPORTED AS A CONTRADICTION RATHER THAN
 * SILENTLY RESOLVED; `Permission::QuotasManage` and the migration carry the same note.
 *
 * ═══ WHAT SHIPS: THE NARROW READING, PLUS A DIRECTIONAL GUARD ═══════════════════════════════
 *
 *   * `Permission::QuotasManage` is held by the ORGANIZATION OWNER alone among the four org roles,
 *     so an Administrator cannot touch a quota at all. That is the policy layer's half.
 *   * THIS CLASS ADDS THE DIRECTION. An organization owner may LOWER a ceiling, or set one where
 *     there was none. RAISING a ceiling — or removing it entirely by setting it to null — requires
 *     `users.is_platform_owner`.
 *
 * THE ARGUMENT IS ONE SENTENCE: a quota an organization can raise is not a quota. Self-service
 * TIGHTENING is safe and is a thing operators genuinely want (capping their own spend, or stopping
 * a runaway integration); self-service LOOSENING is a plan change, and §6.1 says who makes those.
 *
 * ── WHY THE GUARD IS HERE AND NOT IN THE POLICY ─────────────────────────────────────────────
 *
 * `OrgScopedPolicy::permit()` takes `(user, record, permission)` and has NO ARGUMENT POSITION for
 * "the direction of the change" — the same structural reason `Permission::MembersManageOwner` is its
 * own case rather than logic inside a policy body. Here the missing argument is not a role, it is a
 * comparison between the submitted numbers and the persisted ones, which only the service has. The
 * house rule applies exactly: the FormRequest validates shape, the Policy decides permission, the
 * SERVICE decides behaviour.
 *
 * ── THE GAP A REVIEWER SHOULD LOOK AT FIRST ─────────────────────────────────────────────────
 *
 * THE PLATFORM OWNER'S HALF HAS NO SCREEN. There is no platform-admin surface anywhere in this
 * application — `users.is_platform_owner` is set by `kb:bootstrap-organization --platform-owner` and
 * read by exactly one Gate and one Resource field. So raising a limit today means an owner-flagged
 * user calling the same admin endpoint, which works and is not what §6.1 describes. That is a real
 * gap and it is named rather than papered over: closing it is a platform surface, not a change here.
 */
final readonly class QuotaLimitService
{
    /**
     * The 403 an organization owner gets when they try to raise their own ceiling.
     *
     * IT NAMES THE DIRECTION AND THE REMEDY, and it deliberately does NOT say "you are not a
     * platform owner" — that would be an oracle over a flag the caller cannot see and cannot act on.
     * It says what is permitted from here, which is the actionable half.
     */
    public const RAISING_NEEDS_PLATFORM_OWNER = 'A quota may be lowered from here, and raising or '
        .'removing one is a plan change: it needs a platform operator. Lower the limit, or contact '
        .'support to change the plan.';

    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private AuditLogger $audit,
    ) {}

    /**
     * Apply a complete set of ceilings.
     *
     * @param  User|null  $actor  the authenticated user, whose PLATFORM flag decides whether a raise
     *                            is permitted. Null on a console path, which is treated as NOT a
     *                            platform owner — the conservative direction, and one an operator
     *                            can escape by passing the flagged user explicitly.
     *
     * @throws KbException `authorization` (403) when a non-platform-owner raises or removes a limit
     */
    public function apply(
        Organization $organization,
        QuotaLimits $proposed,
        ?User $actor = null,
        ?Request $request = null,
    ): Organization {
        $current = QuotaLimits::fromOrganization($organization);

        // THE DIRECTION CHECK RUNS AGAINST THE BOUND ROW, WHICH IS A READ OUTSIDE THE LOCK — so it
        // can race a concurrent write and admit a raise that the repository then applies on top of a
        // different baseline. That race is accepted and named rather than closed with a second lock,
        // for two reasons: both racing writers hold `quotas.manage`, which is the Owner alone, so the
        // population is one or two people; and the AUDIT ROW is computed under the lock from the
        // values the repository actually read, so `raised` on the row is always true of the write
        // that happened, whatever this pre-check concluded. The audit is the authority for review;
        // this is the gate for the common case.
        if ($this->raisesAny($current, $proposed) && ! $this->isPlatformOwner($actor)) {
            // `authorization`, NOT `tenant_quota`. The caller is not over a quota — they are not
            // permitted to perform this act — and rendering it as `tenant_quota` would put a
            // permission refusal into the bucket a dashboard counts plan breaches in.
            throw new KbException(
                'authorization',
                self::RAISING_NEEDS_PLATFORM_OWNER,
                403,
                \App\Support\Kb\ErrorTaxonomy::ORIGIN_SELF,
            );
        }

        return $this->organizations->setQuotaLimits(
            $organization->organizationId(),
            $proposed,
            // A FULL CLOSURE AND NOT AN ARROW FUNCTION: `fn () => $this->record(...)` implicitly
            // RETURNS the call's value, and `record()` is void — both a PHPStan finding and a quiet
            // lie about the contract, which types the callback as returning void.
            function (Organization $row, QuotaLimits $previous) use ($actor, $request): void {
                $this->record($row, $previous, $actor?->id, $request);
            },
        );
    }

    /**
     * Does the proposal move ANY ceiling upwards, or remove one?
     *
     * ── `null` IS THE HIGHEST VALUE THERE IS, AND THAT IS THE COMPARISON THAT IS EASY TO GET
     *    BACKWARDS ────────────────────────────────────────────────────────────────────────
     *
     * `null` means UNLIMITED, so `current = 100` → `proposed = null` is the biggest raise available
     * and a naive `$proposed > $current` reads it as `null > 100`, which PHP evaluates as FALSE.
     * That single comparison would let any organization owner remove every ceiling on their own
     * account, and it would review as correct.
     *
     * The four cases, written out because three of them are not the obvious one:
     *
     *   current null, proposed null      unchanged — not a raise
     *   current null, proposed number    a ceiling appears where there was none — a LOWERING
     *   current number, proposed null    the ceiling is removed — the LARGEST possible raise
     *   current number, proposed number  ordinary numeric comparison
     */
    private function raisesAny(QuotaLimits $current, QuotaLimits $proposed): bool
    {
        foreach (QuotaMetric::cases() as $metric) {
            $before = $current->limitFor($metric);
            $after = $proposed->limitFor($metric);

            if ($before === null) {
                // Already unlimited. Nothing is higher, so no proposal can raise it.
                continue;
            }

            if ($after === null || $after > $before) {
                return true;
            }
        }

        return false;
    }

    /**
     * `users.is_platform_owner` (§6.1), read off the model rather than from a Gate.
     *
     * A Gate would be the idiomatic spelling and is the wrong shape here: `AppServiceProvider`
     * defines one for a platform-scope ability, and reaching for it would make this decision
     * resolvable from a container in a service whose other decisions are all pure. The flag is a
     * column; this reads the column.
     */
    private function isPlatformOwner(?User $actor): bool
    {
        return $actor?->isPlatformOwner() === true;
    }

    /**
     * One audit row, written inside the repository's transaction.
     *
     * EVERY VALUE COMES OFF THE PERSISTED ROW AND OFF THE PREVIOUS SET READ UNDER THE SAME LOCK,
     * never from request input — so `raised` is true of the write that actually happened rather than
     * of the proposal the caller submitted, which is what makes the row usable as the authority when
     * the pre-check above loses its race.
     *
     * A null ceiling is skipped by the sanitizer without being reported, so "this metric is
     * unmetered" is the ABSENCE of the key rather than a stored null — the same rule the two
     * designation pairs' `previous_*` keys rely on.
     */
    private function record(
        Organization $row,
        QuotaLimits $previous,
        ?string $actorId,
        ?Request $request,
    ): void {
        $current = QuotaLimits::fromOrganization($row);

        $this->audit->record(
            AuditLogger::QUOTA_LIMITS_UPDATED,
            organizationId: $row->organizationId(),
            actorId: $actorId,
            details: [
                'storage_bytes_quota' => $current->storageBytes,
                'bots_quota' => $current->bots,
                'users_quota' => $current->users,
                'monthly_tokens_quota' => $current->monthlyTokens,
                'previous_storage_bytes_quota' => $previous->storageBytes,
                'previous_bots_quota' => $previous->bots,
                'previous_users_quota' => $previous->users,
                'previous_monthly_tokens_quota' => $previous->monthlyTokens,
                // DERIVED HERE, FROM THE TWO SETS, and recorded as a boolean because it is the
                // question an audit of this surface asks first: a `raised: true` row whose actor is
                // not a platform owner is the finding.
                'raised' => $this->raisesAny($previous, $current),
            ],
            subjectType: Organization::class,
            subjectId: $row->id,
            request: $request,
        );
    }
}
