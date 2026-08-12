<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Surface;
use App\Repositories\Contracts\EmbeddingCandidateRepositoryInterface;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Repositories\Contracts\ProviderConnectionRepositoryInterface;
use App\Repositories\Contracts\SparseCorpusStatisticsRepositoryInterface;
use App\Repositories\Eloquent\EloquentEmbeddingCandidateRepository;
use App\Repositories\Eloquent\EloquentOrganizationRepository;
use App\Repositories\Eloquent\EloquentProviderConnectionRepository;
use App\Repositories\Eloquent\EloquentSparseCorpusStatisticsRepository;
use App\Services\Internal\InternalRequestSigner;
use App\Support\Crypto\CredentialVault;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Sanctum registers `sanctum/csrf-cookie` itself. We register it in routes/web.php instead,
        // so the route sits in exactly one group with our own throttle and `route:list` shows one
        // entry for it.
        //
        // VERIFIED on the first `composer install`: Sanctum 4.3 has NO `Sanctum::ignoreRoutes()`.
        // The call that used to stand here fataled with "Call to undefined method" on every artisan
        // command and every test boot. The opt-out in this version is `'routes' => false` in
        // config/sanctum.php, which SanctumServiceProvider::defineRoutes() checks with a strict
        // comparison — see the comment there. Nothing is needed for it in this method.

        $this->bindTenancy();
        $this->bindRepositories();
        $this->bindInternalClient();
    }

    /**
     * The tenant context is a SINGLETON so that middleware, the global scope and every repository
     * in one request see the same object. It is also the reason TenantContext::runFor() clears in
     * a `finally` rather than exposing a bare setter: a singleton in a pooled worker outlives the
     * request that set it.
     *
     * `Surface` defaults to Admin and is overridden per route group by BindSurface. The default is
     * the SAFE one in exactly one direction — an admin 403 rendered on a public route would be an
     * enumeration oracle — so BindSurface is applied explicitly on every group, including admin,
     * and this default exists only so a policy resolved outside an HTTP request (an artisan
     * command, a queued job) has something rather than a container error.
     */
    private function bindTenancy(): void
    {
        $this->app->singleton(TenantContext::class);

        $this->app->bind(Surface::class, static fn (): Surface => Surface::Admin);
    }

    private function bindRepositories(): void
    {
        $this->app->bind(
            EmbeddingCandidateRepositoryInterface::class,
            EloquentEmbeddingCandidateRepository::class,
        );

        $this->app->bind(
            OrganizationRepositoryInterface::class,
            EloquentOrganizationRepository::class,
        );

        $this->app->bind(
            ProviderConnectionRepositoryInterface::class,
            EloquentProviderConnectionRepository::class,
        );

        $this->app->bind(
            SparseCorpusStatisticsRepositoryInterface::class,
            EloquentSparseCorpusStatisticsRepository::class,
        );
    }

    /**
     * The signer and the vault both read secret material out of config, and both are constructed
     * HERE rather than reading config themselves — so there is exactly one place in the
     * application where a KEK or an HMAC secret is fetched, and an arch test can assert that no
     * secret-shaped env() read survives anywhere else.
     *
     * `services.ai.hmac.keys` is filtered of empty entries by config/services.php, so a missing
     * secret arrives as `null` here and InternalRequestSigner refuses to sign with it. An empty
     * signing key produces a valid-looking signature that verifies against any peer which also
     * resolved to empty.
     */
    private function bindInternalClient(): void
    {
        $this->app->singleton(InternalRequestSigner::class, static function (): InternalRequestSigner {
            $keys = config('services.ai.hmac.keys');
            $activeKeyId = (string) config('services.ai.hmac.active');

            return new InternalRequestSigner(
                prefix: (string) config('kb.signing_prefix'),
                keyId: $activeKeyId,
                secret: is_array($keys) && is_string($keys[$activeKeyId] ?? null)
                    ? $keys[$activeKeyId]
                    : null,
            );
        });

        $this->app->singleton(CredentialVault::class, static function (): CredentialVault {
            $kek = config('kb.credentials.kek');

            return new CredentialVault(
                kek: is_string($kek) ? $kek : null,
                kekVersion: (int) config('kb.credentials.kek_version'),
            );
        });
    }

    public function boot(): void
    {
        $this->enforceModelStrictness();
        $this->isolateParallelTestWorkers();
        $this->defineGates();
        $this->defineRateLimiters();
        $this->watchTheScheduler();
    }

    /**
     * CHECK 6 of the six, for the admin surface.
     *
     * Laravel's fixed-window RateLimiter is acceptable HERE and only here. The public chat surface
     * needs the composite sliding window over bot + origin + session + IP evaluated in one EVALSHA
     * (valkey-keyspaces), because a fixed window lets 2x the limit through across a boundary — on
     * an admin console that is a non-event, on a metered chat surface it is the bill.
     *
     * KEYED ON (organization, user) AND NOT ON IP. Two admins of the same organization behind one
     * office NAT must not throttle each other, and one admin who belongs to two organizations must
     * not have work in one count against the other. Falling back to the IP covers only the
     * unauthenticated case, which on this surface is already a 401.
     */
    private function defineRateLimiters(): void
    {
        RateLimiter::for('admin', static function (Request $request): Limit {
            $user = $request->user();
            $organization = $request->route('organization');

            $key = $user === null
                ? 'ip:'.((string) $request->ip())
                : 'org:'.(is_string($organization) ? $organization : 'none').'|user:'.$user->getAuthIdentifier();

            return Limit::perMinute(120)->by($key);
        });
    }

    /**
     * Strict Eloquent. The half that is security-relevant is preventSilentlyDiscardingAttributes():
     * `organization_id` is guarded on every model, so a PATCH that over-posts it is SILENTLY
     * DROPPED by default — a 200 response, an unmoved row, and no signal that someone tried to move
     * a bot into another organization. Strict mode turns that into a MassAssignmentException.
     *
     * Called unconditionally on purpose. The one half worth relaxing later is preventLazyLoading:
     * in production a lazy-load violation is a performance defect, not a security one, and throwing
     * 500 at a tenant's dashboard is the wrong trade. Relax THAT one specifically
     * (Model::preventLazyLoading(false) under isProduction, with a
     * handleLazyLoadingViolationUsing() logger) once there is real traffic — never by weakening
     * shouldBeStrict() as a whole.
     *
     * forceFill() and forceCreate() bypass all of it; CI greps for both.
     */
    private function enforceModelStrictness(): void
    {
        Model::shouldBeStrict();
    }

    /**
     * `php artisan test --parallel` creates kb_test_1 … kb_test_N and appends the token — FOR THE
     * DATABASE ONLY. The Qdrant collection, the Valkey logical databases, the cache prefix and the
     * rate limiter are all shared across workers, and that is the parallel hazard that actually
     * bites: a canary planted by worker 2 turns up in worker 1's isolation assertion, and the
     * rate-limit test fails only in CI (pest-testing).
     */
    private function isolateParallelTestWorkers(): void
    {
        ParallelTesting::setUpProcess(static function (int $token): void {
            config([
                'services.qdrant.collection' => "kb_test_{$token}",
                'database.redis.default.database' => 8 + $token,
                'database.redis.coordination.database' => 8 + $token,
                'database.redis.ephemeral.database' => 8 + $token,
                'cache.prefix' => "kb_test_{$token}_",
            ]);
        });
    }

    private function defineGates(): void
    {
        /*
         * /horizon renders EVERY organization's job payloads, tags and exception traces side by
         * side. It is a cross-tenant surface, so this gate must resolve a PLATFORM OPERATOR
         * (users.is_platform_owner, §6.1) and never a tenant admin — a tenant admin who can open
         * the dashboard is a tenancy layer-1 breach, not a UX bug.
         *
         * The gate Horizon publishes defaults to a local-only email allow-list. Keeping that
         * default in production is a lockout; replacing it with `true` is the breach. This is the
         * third option, and it is the only correct one.
         *
         * TODO(rbac): replace data_get() with $user->isPlatformOwner() once App\Models\User exists.
         */
        Gate::define('viewHorizon', static function (Authenticatable $user): bool {
            return (bool) data_get($user, 'is_platform_owner', false);
        });
    }

    /**
     * ONE global listener per event, never a per-task ->onFailure() — a per-task hook is a hook
     * someone forgets on the next task.
     *
     * The two events fail differently and both are silent by default:
     *   - ScheduledTaskFailed: ScheduleRunCommand catches every Throwable, dispatches this, and
     *     hands it to the exception handler, which writes one stdout line nobody reads. Note that
     *     ->onFailure() keys on a NON-ZERO EXIT CODE, so a command that catches its own exception
     *     and returns SUCCESS is indistinguishable from success — commands must return FAILURE on
     *     partial failure.
     *   - ScheduledTaskSkipped: withoutOverlapping() registers a SKIP FILTER, so a lock stranded by
     *     a SIGKILL makes the event fail filtersPass() and dispatch this instead. Nothing throws,
     *     nothing logs, and the dashboard shows the sweep as "running" while it has done nothing
     *     for a week. A skip counter climbing at exactly one per tick is the fingerprint, which is
     *     why `outcome="skipped"` sits OUTSIDE the error rate but carries its own sustained-skip
     *     alert (kb-observability-conventions).
     */
    private function watchTheScheduler(): void
    {
        // A scheduled task that threw is the SELF-origin reading of `internal_dependency`
        // (ADR-029, finding O1): nothing downstream declared itself unavailable, our own command
        // failed. Consistency with bootstrap/app.php's render closure is therefore about what is
        // ABSENT here — this line carries no `retryable` and no status, because a log line is not
        // an envelope and never becomes a response, and a `retryable: true` copied in from the
        // downstream row would be read by a runbook as "just re-run it". `origin` is deliberately
        // not a field: it is a rendering input, and the log field set is closed
        // (kb-observability-conventions references/logs-health-audit.md).
        Event::listen(static function (ScheduledTaskFailed $event): void {
            Log::error('scheduled task failed', [
                'error_class' => 'internal_dependency',
                'operation' => $event->task->description ?? $event->task->getSummaryForDisplay(),
                'exception' => $event->exception::class,
            ]);
        });

        Event::listen(static function (ScheduledTaskSkipped $event): void {
            Log::warning('scheduled task skipped', [
                'outcome' => 'skipped',
                'operation' => $event->task->description ?? $event->task->getSummaryForDisplay(),
            ]);
        });
    }
}
