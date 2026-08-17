<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailVerificationToken;
use App\Models\OrganizationInvitation;
use App\Repositories\Eloquent\EloquentEmailVerificationTokenRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Delete the auth capabilities that have expired without ever being used.
 *
 * ── WHY THIS EXISTS, GIVEN THAT AN EXPIRED TOKEN ALREADY CONFERS NOTHING ────────────────────────
 *
 * Not for correctness: `expires_at` is checked on every consume, so a row left lying about is inert.
 * Three other reasons, in descending order of how much they matter:
 *
 *   1. AN EXPIRED INVITATION HOLDS THE `organization_invitations_one_pending_per_email` SLOT. That
 *      partial unique index covers `(organization_id, email) WHERE accepted_at IS NULL AND revoked_at
 *      IS NULL` — and expiry is NOT one of those predicates. So an invitation nobody accepted keeps
 *      its address occupied forever, and re-inviting the same person fails with a 23505 rather than
 *      creating a fresh invitation. That is a user-visible bug this command is the fix for, which is
 *      also why the invitation half of the sweep is not optional.
 *   2. A hashed bearer capability that no longer serves any purpose is a row that should not be in a
 *      backup. The digest is one-way and the plaintext was never stored, so this is hygiene rather
 *      than a breach containment — stated that way so nobody reads it as more than it is.
 *   3. Both partial indexes stay small, which is the only reason the two sweeps are index scans.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DELETE ────────────────────────────────────────────────────────
 *
 *   * CONSUMED verification tokens and ACCEPTED or REVOKED invitations. Those rows are the record that
 *     a particular link was used, or refused, and their cutoff is a RETENTION decision rather than the
 *     token's own TTL. See EmailVerificationTokenRepositoryInterface::deleteExpired() — the
 *     consequence (unbounded growth of terminal rows) is recorded there rather than resolved by
 *     whoever wrote the sweep.
 *   * `password_reset_tokens`. That table is the framework's, and the framework's own
 *     `auth:clear-resets` prunes it using the broker's configured expiry
 *     (`config/auth.php` -> `passwords.users.expire`). Duplicating it here would put two different
 *     definitions of "expired" on one table, and the scheduler runs both.
 *
 * ── IDEMPOTENT AND SAFE TO RUN TWICE ────────────────────────────────────────────────────────────
 *
 * Both statements are DELETEs bounded by a timestamp; a second run deletes nothing and exits 0. It is
 * scheduled, so it will run twice on a bad day, and a command that failed on re-run would be a command
 * whose failure alert everyone learns to ignore.
 *
 * ── FAILURE IS REPORTED THROUGH THE EXIT CODE, NOT SWALLOWED ────────────────────────────────────
 *
 * `->onFailure()` and the global ScheduledTaskFailed listener key on a NON-ZERO EXIT CODE, so a
 * command that catches its own exception and returns SUCCESS is indistinguishable from one that
 * worked. Each half runs in its own try/catch and a failure in either returns FAILURE while the other
 * half still runs: the two tables are independent, and letting one exception skip the invitation sweep
 * would resurrect defect 1 above with nothing but a stack trace to say why.
 *
 * The class carries the `Command` suffix because arch()->preset()->laravel() asserts it for everything
 * in App\Console\Commands. The artisan signature — the part that is a contract, pinned by
 * tests/Feature/ScheduleTest.php — is `kb:prune-auth-tokens`.
 */
final class PruneAuthTokensCommand extends Command
{
    protected $signature = 'kb:prune-auth-tokens
                            {--dry-run : Count what would be deleted and change nothing}';

    protected $description = 'Delete expired, never-used invitation and email-verification tokens.';

    public function handle(EloquentEmailVerificationTokenRepository $verificationTokens): int
    {
        // ONE CLOCK READING for both sweeps. Two calls to now() cannot disagree by much, but "much" is
        // not the standard: a row on the boundary being counted by the dry run and skipped by the
        // delete is a discrepancy somebody would have to explain.
        //
        // UTC explicitly, matching CreateAuditPartitionsCommand: `app.timezone` is UTC today, and
        // reading the clock in UTC here means a future timezone change cannot silently shift the
        // boundary of a destructive sweep.
        $now = CarbonImmutable::now('UTC');
        $dryRun = (bool) $this->option('dry-run');

        $rows = [];
        $failed = false;

        try {
            $invitations = $this->pruneInvitations($now, $dryRun);
            $rows[] = ['organization_invitations', $invitations, $dryRun ? 'would delete' : 'deleted'];
        } catch (Throwable $failure) {
            $this->error('organization_invitations sweep failed: '.$failure->getMessage());
            $rows[] = ['organization_invitations', 0, 'failed'];
            $failed = true;
        }

        try {
            $verification = $dryRun
                ? $this->countExpiredVerificationTokens($now)
                : $verificationTokens->deleteExpired($now);
            $rows[] = ['email_verification_tokens', $verification, $dryRun ? 'would delete' : 'deleted'];
        } catch (Throwable $failure) {
            $this->error('email_verification_tokens sweep failed: '.$failure->getMessage());
            $rows[] = ['email_verification_tokens', 0, 'failed'];
            $failed = true;
        }

        $this->table(['table', 'rows', 'status'], $rows);

        if ($dryRun) {
            $this->comment('--dry-run: nothing was deleted.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * ── THIS QUERY BELONGS BEHIND A REPOSITORY AND IS NOT YET ───────────────────────────────────
     *
     * `App\Repositories\Contracts\OrganizationInvitationRepositoryInterface` exists — it landed
     * alongside this command, from the agent that owns the invitation surface — and it has NO prune
     * method: findByTokenDigest, accountExistsFor, forOrganization, liveFor, createPending, revoke,
     * rotateToken and the two accept methods, none of which is a sweep. Adding one would mean editing
     * that agent's two files concurrently, and a second competing interface over one table is exactly
     * the drift a repository layer exists to prevent. So the sweep is written against the model here,
     * with this note. MOVE IT once a `deleteExpired(CarbonImmutable $now): int` exists on that
     * contract; the method body transplants unchanged.
     *
     * tenancy-exempt: a maintenance sweep across every organization, deliberately. The rows it deletes
     * are dead capabilities and the predicate names no tenant, so narrowing it by `organization_id`
     * would mean either iterating every organization for no benefit or leaving most of the table
     * unswept. `OrganizationInvitation` carries no OrganizationScope for its own documented reasons
     * (the guest paths read it before any organization is known), so nothing is being bypassed here —
     * there is no scope to bypass.
     */
    private function pruneInvitations(CarbonImmutable $now, bool $dryRun): int
    {
        $query = $this->expiredInvitations($now);

        // Cast, because Illuminate\Database\Eloquent\Builder::delete() and ::count() are both
        // untyped passthroughs and read as `mixed` at PHPStan level 8.
        return $dryRun ? (int) $query->count() : (int) $query->delete();
    }

    private function countExpiredVerificationTokens(CarbonImmutable $now): int
    {
        // The dry-run counterpart of the repository's DELETE. It is spelled out here rather than added
        // to the repository interface because a `countExpired()` on that contract would exist for one
        // console flag and would be a second place the predicate has to stay in step; here it sits four
        // lines from the sweep it is meant to preview.
        //
        // tenancy-exempt: `email_verification_tokens` has no organization_id at all. See
        // App\Repositories\Contracts\EmailVerificationTokenRepositoryInterface.
        return (int) EmailVerificationToken::query()
            ->where('expires_at', '<', $now)
            ->whereNull('consumed_at')
            ->count();
    }

    /**
     * `expires_at < now AND accepted_at IS NULL AND revoked_at IS NULL` — exactly the predicate of the
     * `organization_invitations_pending_expires` partial index, so this is an index scan over only the
     * rows that can possibly match.
     *
     * The two IS NULL clauses are not redundant with the expiry check: an invitation can legitimately
     * be accepted AFTER its `expires_at` has passed (OrganizationInvitation::status() documents that
     * ordering), and deleting an ACCEPTED row would destroy the record of how a member joined.
     *
     * @return Builder<OrganizationInvitation>
     */
    private function expiredInvitations(CarbonImmutable $now): Builder
    {
        return OrganizationInvitation::query()
            ->where('expires_at', '<', $now)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at');
    }
}
