<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Surface;
use App\Models\User;
use App\Repositories\Contracts\BotDomainRepositoryInterface;
use App\Repositories\Contracts\BotRepositoryInterface;
use App\Repositories\Contracts\BotSourceAssignmentRepositoryInterface;
use App\Repositories\Contracts\BotStarterQuestionRepositoryInterface;
use App\Repositories\Contracts\EmbeddingCandidateRepositoryInterface;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Repositories\Contracts\MembershipRepositoryInterface;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Repositories\Contracts\PendingSourceObjectRepositoryInterface;
use App\Repositories\Contracts\ProviderConnectionRepositoryInterface;
use App\Repositories\Contracts\ProviderModelRepositoryInterface;
use App\Repositories\Contracts\SparseCorpusStatisticsRepositoryInterface;
use App\Repositories\Eloquent\EloquentBotDomainRepository;
use App\Repositories\Eloquent\EloquentBotRepository;
use App\Repositories\Eloquent\EloquentBotSourceAssignmentRepository;
use App\Repositories\Eloquent\EloquentBotStarterQuestionRepository;
use App\Repositories\Eloquent\EloquentEmbeddingCandidateRepository;
use App\Repositories\Eloquent\EloquentKnowledgeSourceRepository;
use App\Repositories\Eloquent\EloquentMembershipRepository;
use App\Repositories\Eloquent\EloquentOrganizationRepository;
use App\Repositories\Eloquent\EloquentPendingSourceObjectRepository;
use App\Repositories\Eloquent\EloquentProviderConnectionRepository;
use App\Repositories\Eloquent\EloquentProviderModelRepository;
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
use Illuminate\Support\Str;

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
        // The organization's bots. A SEPARATE interface from the provider ones rather than more
        // methods on either: a bot is the retrieval scope and its repository is the only one that
        // paginates, while the connection repository owns the credential columns and is the only
        // thing that touches the vault's output. A write on one has no business sharing a seam with
        // the other.
        $this->app->bind(
            BotRepositoryInterface::class,
            EloquentBotRepository::class,
        );

        // THE TWO CHILD COLLECTIONS GET THEIR OWN INTERFACES rather than more methods on the bot
        // repository, and the reason is not symmetry. `bot_domains` is a SECURITY CONTROL — every
        // row is a standing grant to a page on the public internet — and its repository is the one
        // seam where a write has to be inseparable from the audit row that records it (finding L2).
        // `bot_starter_questions` is a rendering list whose whole difficulty is a non-deferrable
        // unique index on `sort_order`. Neither has anything to say to the other, and folding them
        // into `BotRepositoryInterface` would put the allow-list's write path behind the same seam
        // as a bot rename.
        $this->app->bind(
            BotDomainRepositoryInterface::class,
            EloquentBotDomainRepository::class,
        );

        $this->app->bind(
            BotStarterQuestionRepositoryInterface::class,
            EloquentBotStarterQuestionRepository::class,
        );

        // THE THIRD CHILD COLLECTION, AND THE ONLY ONE WHOSE ROWS CAN NAME TWO ORGANIZATIONS.
        // `bot_source_assignments` is kb-tenancy-isolation NN2: its own interface rather than more
        // methods on the bot repository, for the reason the two above give and one more of its own
        // — every write here has to state the organization explicitly rather than infer it from
        // either parent, and a seam whose every method takes it as a required positional argument
        // is what makes inferring it unexpressible.
        $this->app->bind(
            BotSourceAssignmentRepositoryInterface::class,
            EloquentBotSourceAssignmentRepository::class,
        );

        $this->app->bind(
            EmbeddingCandidateRepositoryInterface::class,
            EloquentEmbeddingCandidateRepository::class,
        );

        // The one query in the application keyed on a USER rather than an organization — the org
        // switcher's list and the login-time default. See the interface for why that is not a scope
        // violation: `organization_users` is the table the tenant scope is derived FROM.
        // The organization's knowledge sources, and the item and version rows underneath them.
        // ONE interface for all three tables rather than three, because they are not three
        // entities a caller composes — they are one cascade with one lifecycle, and every write
        // that touches a version also touches the item's active-version pointer under the same
        // lock. Splitting them would put the pointer flip on one seam and the row it points at on
        // another, which is exactly the split ADR-012 exists to forbid across the Laravel/FastAPI
        // boundary and would be no better inside one process.
        $this->app->bind(
            KnowledgeSourceRepositoryInterface::class,
            EloquentKnowledgeSourceRepository::class,
        );

        $this->app->bind(
            MembershipRepositoryInterface::class,
            EloquentMembershipRepository::class,
        );

        $this->app->bind(
            OrganizationRepositoryInterface::class,
            EloquentOrganizationRepository::class,
        );

        // The write-ahead ledger of object-storage keys (security finding S3). It is bound like
        // every other repository, but its MODEL is the one exception to the tenant-scope rule —
        // `PendingSourceObject` carries no `#[ScopedBy]`, because its only significant reader is a
        // cross-tenant console sweep and `OrganizationScope` fails closed. The model's docblock
        // carries the full reasoning; it is noted here because this list is where a reader counts
        // the repositories and would otherwise have no reason to look.
        $this->app->bind(
            PendingSourceObjectRepositoryInterface::class,
            EloquentPendingSourceObjectRepository::class,
        );

        $this->app->bind(
            ProviderConnectionRepositoryInterface::class,
            EloquentProviderConnectionRepository::class,
        );

        // The model catalog under a connection. A SEPARATE interface from the one above rather
        // than five more methods on it: the connection repository owns the credential columns and
        // is the only thing that touches the vault's output, and a catalog write has no business
        // sharing a seam with that.
        $this->app->bind(
            ProviderModelRepositoryInterface::class,
            EloquentProviderModelRepository::class,
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
     * ALL SIX LIMITERS LAND IN ONE METHOD, INCLUDING ONES WHOSE BODIES ARE NOT WRITTEN YET. A named
     * limiter with no route is inert, and four change sets editing one method is four conflicts. The
     * `throttle:` name on a route is resolved at REQUEST time, so a route may reference a limiter
     * defined here before its controller does anything — but the reverse fails loudly:
     * `ThrottleRequests` throws "Rate limiter [x] is not defined" on the first request.
     *
     * PREFIXES ARE NEEDED *WITHIN* ONE ARRAY, AND ONLY THERE — measured, not assumed.
     * `ThrottleRequests::handleRequestUsingNamedLimiter()` computes the cache key as
     * `md5($limiterName.$limit->key)`, so the limiter NAME already namespaces every bucket and
     * `login`'s `ip:` can never collide with `invitation`'s. What DOES collide is two Limits in the
     * SAME array whose `by()` values are equal — an unprefixed IP beside an unprefixed email, in a
     * deployment where somebody submits their own IP address as their email. Hence the prefixes.
     */
    private function defineRateLimiters(): void
    {
        /*
         * KEYED ON (organization, user) AND NOT ON IP. Two admins of the same organization behind one
         * office NAT must not throttle each other, and one admin who belongs to two organizations
         * must not have work in one count against the other. Falling back to the IP covers only the
         * unauthenticated case, which on this surface is already a 401.
         *
         * ON THE USER-SCOPED ROUTES IN routes/api_auth.php THERE IS NO `{organization}` SEGMENT, so
         * the key is literally `org:none|user:<id>` and the budget is 120/min per user across /me,
         * logout and the organization switcher together. That is correct — those routes are not
         * tenant work — but it is worth stating, because the name of this limiter suggests otherwise.
         */
        RateLimiter::for('admin', static function (Request $request): Limit {
            $user = $request->user();
            $organization = $request->route('organization');

            $key = $user === null
                ? 'ip:'.((string) $request->ip())
                : 'org:'.(is_string($organization) ? $organization : 'none').'|user:'.$user->getAuthIdentifier();

            return Limit::perMinute(120)->by($key);
        });

        /*
         * PER ACCOUNT *AND* PER IP (docs/13 §18.3, laravel-sanctum-auth). Neither axis works alone:
         * per-IP only lets a botnet spray one account from ten thousand hosts, and per-account only
         * lets one host walk the entire user table five addresses per minute at a time.
         *
         * The account axis is keyed on the SUBMITTED email, lower-cased, so `Bob@x.com` and
         * `bob@x.com` share one bucket — the same normalisation LoginRequest applies, for the same
         * reason: without it the budget is reset by changing the case of one letter.
         */
        RateLimiter::for('login', static fn (Request $request): array => [
            Limit::perMinute(5)->by('acct:'.Str::lower(self::inputString($request, 'email'))),
            Limit::perMinute(20)->by('ip:'.((string) $request->ip())),
        ]);

        /*
         * A RESET LINK IS EMAILED, WHICH MAKES THE ACCOUNT AXIS TIGHTER THAN LOGIN'S. Unthrottled,
         * this endpoint is a mailbox flood aimed at somebody else's inbox — the account being
         * attacked is not the attacker's, and the cost lands on the victim.
         *
         * Laravel's OWN broker throttle (`config/auth.php`'s `throttle`, 60 s) is not a substitute:
         * it only covers the branch where a token was actually created, so an unknown address creates
         * nothing and is entirely unthrottled by it.
         */
        RateLimiter::for('password-request', static fn (Request $request): array => [
            Limit::perMinutes(15, 3)->by('acct:'.Str::lower(self::inputString($request, 'email'))),
            Limit::perMinute(10)->by('ip:'.((string) $request->ip())),
        ]);

        /*
         * SUBMISSION IS KEYED ON THE TOKEN, NOT ON THE EMAIL, because the token is the thing being
         * guessed. An email axis here would be keyed on a value the guesser supplies and can vary at
         * will, which resets the budget for free.
         *
         * Hashed before it becomes a key even though `md5($limiterName.$key)` already hides it:
         * `ThrottleRequests::withoutHashedKeys()` exists and someone may call it, and
         * laravel-sanctum-auth's Definition of done requires a test asserting no plaintext capability
         * token appears in Valkey.
         */
        RateLimiter::for('password-reset', static fn (Request $request): array => [
            Limit::perMinute(5)->by('tok:'.hash('sha256', self::inputString($request, 'token'))),
            Limit::perMinute(20)->by('ip:'.((string) $request->ip())),
        ]);

        /*
         * VERIFICATION MAIL. 6 per hour per user is the anti-flood axis, and it is aimed at the
         * account's OWN mailbox: the resend endpoint takes no address, so the only inbox it can fill
         * is the authenticated user's.
         *
         * ── KNOWN HAZARD, REPORTED AND NOT SILENTLY REDESIGNED ─────────────────────────────────
         * This limiter is applied to TWO routes and only one of them is authenticated.
         * `POST /auth/email/verify` is a GUEST route (a verification link is opened from a mail
         * client, often in another browser), so `$request->user()` is null there and EVERY anonymous
         * caller shares the single `user:anon` bucket — six verification attempts per hour for the
         * whole world. That is a global choke, not a per-caller limit.
         *
         * REPAIRED, as that note prescribed: the per-account axis falls back to the TOKEN DIGEST
         * rather than to a literal 'anon'. The authenticated route (`verification-notification`)
         * carries no `token` field, so it still keys purely on the user id and its per-user budget is
         * unchanged; the guest route keys on the capability being presented, which is the only
         * per-caller identity a mail link has. Six attempts per hour PER TOKEN is a correct budget —
         * a verification token is single-use, so a legitimate holder needs one.
         *
         * A caller who omits the token shares the digest-of-'' bucket, exactly as the `invitation`
         * limiter's docblock argues: those requests are 422 regardless, and grouping them throttles
         * the malformed-body prober harder rather than more loosely. The token is hashed before it
         * becomes a key even though ThrottleRequests already md5s it, because `withoutHashedKeys()`
         * can turn that off and the Security suite asserts no plaintext token reaches Valkey.
         */
        RateLimiter::for('verification', static fn (Request $request): array => [
            Limit::perMinutes(60, 6)->by(
                $request->user() !== null
                    ? 'user:'.((string) $request->user()->getAuthIdentifier())
                    : 'tok:'.hash('sha256', self::inputString($request, 'token')),
            ),
            Limit::perMinute(20)->by('ip:'.((string) $request->ip())),
        ]);

        /*
         * PREVIEW, REGISTER AND ACCEPT SHARE ONE LIMITER, keyed on the token digest and the IP and
         * NEVER on the email. On `/register` the email is attacker-supplied, so an email axis would
         * let a prober reset the budget by changing one character of an address they invented.
         *
         * The empty-token bucket (the digest of '') is shared by every request that omits the field.
         * That is a FEATURE — it throttles the no-token probe hard — and those requests are 422
         * regardless of whether they get through.
         */
        RateLimiter::for('invitation', static fn (Request $request): array => [
            Limit::perMinute(10)->by('inv:'.hash('sha256', self::inputString($request, 'token'))),
            Limit::perMinute(20)->by('ip:'.((string) $request->ip())),
        ]);

        /*
         * ROTATING A PROVIDER CREDENTIAL VERIFIES THE ACTOR'S PASSWORD, so it is a
         * password-verifying endpoint and gets the shape §18.3 requires of every one of them: per
         * ACCOUNT and per IP together. Neither axis works alone — per-IP only lets a botnet spray
         * one account, per-account only lets one host walk the table.
         *
         * WHAT `throttle:admin` CANNOT DO HERE. It keys on (organization, user) at 120/min, which
         * is a fine budget for reading and relabelling and is a terrible one for an endpoint that
         * answers "is this the right password": 120 guesses a minute from a legitimately signed-in
         * session makes the re-authentication a formality rather than a control. The account axis
         * below is what gives the §18.3 check its meaning.
         *
         * 5 per 15 MINUTES is deliberately tight and is not a usability problem: rotating a
         * provider key follows a human going to the vendor's console and copying a new one, which
         * does not happen five times a quarter-hour. A stolen SPA session that survives XSS still
         * cannot brute-force the password behind it at this rate.
         *
         * KEYED ON THE ACTOR AND NOT ON THE CONNECTION. The thing being guessed is the actor's
         * password, and the password is the same whichever connection the request names — a
         * per-connection key would let an organization with twenty connections buy twenty budgets
         * for one password. The IP axis is what bounds a distributed attempt against many
         * accounts.
         */
        RateLimiter::for('credential-rotation', static fn (Request $request): array => [
            Limit::perMinutes(15, 5)->by(
                'actor:'.((string) ($request->user()?->getAuthIdentifier() ?? 'anon')),
            ),
            Limit::perMinute(10)->by('ip:'.((string) $request->ip())),
        ]);

        /*
         * RESEND IS RATE-LIMITED ON THE RECIPIENT, WHICH `throttle:admin` CANNOT DO.
         *
         * `admin` keys on (organization, user) — the ACTOR — at 120/min, so one administrator could mail
         * one invitee 120 live invitation links a minute. That is a mailbox flood aimed at a third party
         * who never asked to be invited, and it is performed with legitimate credentials, so no other
         * control sees it: the policy passes, the org is active, and the invitation is valid every time.
         *
         * Keyed on the INVITATION, not the address: the address is not in the request (the route carries
         * `{invitation}` and the row owns the email), and the invitation id is the narrowest identifier
         * that maps one-to-one onto a recipient. Also keyed per actor+org so one administrator cannot
         * exhaust another's budget for a shared org, and so the limit is not a denial-of-service surface
         * against a colleague.
         *
         * 3/hour is deliberately tight. A legitimate resend follows a human saying "I never got it",
         * which does not happen three times an hour; the mail itself is queued on `notify` and a
         * recipient's provider will greylist long before a person asks a fourth time.
         */
        RateLimiter::for('invitation-resend', static fn (Request $request): array => [
            Limit::perMinutes(60, 3)->by('inv:'.((string) $request->route('invitation'))),
            Limit::perMinute(10)->by(
                'actor:'.((string) ($request->user()?->getAuthIdentifier() ?? 'anon'))
                .'|org:'.((string) $request->route('organization')),
            ),
        ]);
    }

    /**
     * One request field as a string, for a rate-limiter key.
     *
     * `(string) $request->input($key)` — which is how the design snippet reads — is wrong twice over
     * on hostile input: a client may post `email` as an ARRAY, and casting an array to string emits an
     * "Array to string conversion" warning and yields the literal `Array`, so every such request
     * would share one bucket AND write a warning per attempt. PHPStan cannot type the cast either,
     * because `input()` returns mixed.
     *
     * A non-string therefore keys as the empty string, which shares a bucket with the field being
     * absent. That is the correct direction: those requests are all 422s, and grouping them throttles
     * the malformed-body prober harder rather than more loosely.
     */
    private static function inputString(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
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
     * forceFill() and forceCreate() bypass all of it.
     *
     * THIS LINE USED TO SAY "CI GREPS FOR BOTH", AND THERE IS NO CI: `.github/` was deleted on
     * 2026-08-17 and nothing replaced it. The check is a TEST now rather than a review obligation —
     * tests/Arch/StringLevelDoctrineTest.php tokenizes app/ and holds the call sites against an
     * annotated allow-list, so the ONE legitimate use (PasswordResetService, where the write is not
     * a user-supplied payload) is pinned by file and by count and a second one fails the suite. The
     * grep a human runs is `rg 'forceFill\(|forceCreate\(' services/core-api/app`.
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
         * THE PARAMETER STAYS `Authenticatable` AND THE READ STAYS DEFENSIVE, WHICH IS WHY THIS IS
         * NOT `$user->isPlatformOwner()`. `App\Models\User` exists now and carries that method
         * (User.php), so the old `TODO(rbac)` deferring to its existence is discharged — but Gate
         * callbacks are invoked with whatever the resolved guard returns, and typing this against
         * the concrete model would turn a guard misconfiguration into a TypeError on the Horizon
         * route instead of a denial. `data_get()` on an absent attribute yields the default, so an
         * authenticatable that is not our `User` FAILS CLOSED, which is the only direction this
         * gate may fail in.
         */
        Gate::define('viewHorizon', static function (Authenticatable $user): bool {
            return $user instanceof User
                ? $user->isPlatformOwner()
                : (bool) data_get($user, 'is_platform_owner', false);
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
