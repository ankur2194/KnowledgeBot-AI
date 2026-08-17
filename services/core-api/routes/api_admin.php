<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\EmbeddingConfigurationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\ProviderConnectionController;
use App\Http\Controllers\Api\V1\ResendInvitationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API — Surface: admin (403 deny)
|--------------------------------------------------------------------------
|
| Mounted at `api/v1` on the `api` middleware group by bootstrap/app.php.
| Authenticated by the Sanctum SPA COOKIE SESSION (`web` guard) plus CSRF — not a bearer token: a
| bearer token in a browser SPA must live where JavaScript can read it, so one XSS becomes a stolen,
| long-lived, replayable credential (laravel-sanctum-auth).
|
| Every route in this file, without exception:
|   1. sits under `{organization}` and calls ->scopeBindings(), so a foreign id 404s at BINDING
|      time — before any policy runs and before the row is in memory. A bare `Bot $bot` binding is
|      Bot::find($id) with no org predicate, executed in SubstituteBindings, upstream of everything;
|   2. carries `auth:sanctum` and the org-membership middleware, which RE-READS the membership row
|      from PostgreSQL — neither a token row nor a session value is evidence of CURRENT membership;
|   3. calls Gate::authorize() in the controller, because `can` middleware covers checks 2-4 only
|      and never checks 5 (entity status) or 6 (rate limit / quota);
|   4. is throttled. Laravel's fixed-window RateLimiter is acceptable HERE and only here — the
|      public chat surface needs the composite sliding window (valkey-keyspaces);
|   5. carries `verified`, so an unverified address reaches no tenant data. This is where email
|      verification becomes REAL rather than decorative: login deliberately succeeds for an
|      unverified user (otherwise the resend-verification endpoint, which needs auth:sanctum, would
|      be unreachable), so the gate has to sit somewhere, and it sits on every org-scoped route.
|      Note what the caller sees: EnsureEmailIsVerified aborts 403, and bootstrap/app.php rewrites
|      every `authorization` message to one constant — so the envelope CANNOT distinguish
|      "unverified" from "wrong role". `GET /api/v1/me`'s `user.email_verified` is the SPA's only
|      explanation channel for that 403, which is precisely why `verified` is NOT on /me.
|
| THE ROUTES THAT DO NOT FIT THE INVARIANT ABOVE LIVE IN routes/api_auth.php, mounted on the SAME
| `api` group at the SAME `api/v1` prefix with the SAME `surface:admin`. Login has no
| `{organization}` and no session yet; `/me`, logout and the organization switcher are USER-scoped,
| so `org.member` — which throws when the `{organization}` segment is absent — would deny every one
| of them. Two files, one group, one surface: see bootstrap/app.php's "FOUR DISJOINT GROUPS" note.
|
| Areas this file will hold (docs/12 §17.1): authentication, organizations, users and invitations,
| roles, provider connections, provider models, bots, bot appearance and domains, knowledge sources,
| uploads, crawling, processing jobs, conversations, analytics, evaluations, audit logs.
|
| The shape every route follows once the classes exist:
|
|   Route::middleware(['auth:sanctum', 'org.member', 'throttle:admin'])
|       ->prefix('organizations/{organization}')
|       ->scopeBindings()
|       ->group(function (): void {
|           Route::patch('/bots/{bot}', [BotController::class, 'update'])->name('bots.update');
|       });
|
*/

/*
 * `verified` SITS BETWEEN `org.member` AND `throttle:admin`, and the position is not arbitrary. The
 * middleware priority list in bootstrap/app.php sorts the entries it names — TenantContext before
 * SubstituteBindings, AuthenticatesRequests before ThrottleRequests — and EnsureEmailIsVerified is
 * not one of them, so its effective place is "after the listed ones that precede it here". What
 * matters is only that it runs after authentication, which it does.
 *
 * ZERO ARTIFACT CHURN, VERIFIED RATHER THAN ASSUMED. `DumpOpenApiCommand::securityFor()` greps a
 * route's gathered middleware for `auth:sanctum` and for nothing else, so adding `verified` does not
 * change one byte of the generated OpenAPI document; and `UserFactory::definition()` already sets
 * `email_verified_at => now()`, so every existing fixture passes the new gate.
 */
Route::middleware(['auth:sanctum', 'surface:admin', 'org.member', 'verified', 'throttle:admin'])
    ->prefix('organizations/{organization}')
    ->scopeBindings()
    ->group(function (): void {
        /*
         * FINDING C1 — the organization's embedding configuration.
         *
         * `show` is what the ingestion surface reads to decide whether to draw a BLOCKING banner:
         * an organization with no embedding-capable connection cannot ingest a single document,
         * because every chunk is embedded before it is indexed and there is no degraded mode for
         * it the way there is for reranking.
         *
         * `update` sets or clears the designation. It is a PUT rather than a PATCH because the
         * designation is a PAIR and the two halves are meaningless apart — a partial update of
         * one of them is a state the database CHECK rejects anyway.
         *
         * Both sit under {organization} with ->scopeBindings(), so a foreign id 404s at BINDING
         * time; `org.member` re-reads the membership row before that; and Gate::authorize() runs
         * in the controller because `can` middleware covers checks 2-4 only and never reaches
         * check 5 (entity status).
         */
        Route::get('/embedding-configuration', [EmbeddingConfigurationController::class, 'show'])
            ->name('embedding-configuration.show');

        Route::put('/embedding-configuration', [EmbeddingConfigurationController::class, 'update'])
            ->name('embedding-configuration.update');

        /*
         * A connection may be created purely to embed, and that is not a special case: with one
         * sourced embedding vendor it is the only way an organization on any other vendor can
         * ingest at all (finding C1, item 3). The response carries the resulting embedding
         * readiness beside the connection — the save never fails on it.
         */
        Route::post('/provider-connections', [ProviderConnectionController::class, 'store'])
            ->name('provider-connections.store');

        /*
         * MEMBERS AND INVITATIONS — org-scoped tenant data, so they go where all org-scoped data
         * goes.
         *
         * `{invitation}` RESOLVES THROUGH `$organization->invitations()` because the group calls
         * ->scopeBindings(). That is the load-bearing part: a foreign or unknown invitation id 404s
         * at BINDING time, before any policy runs and before the row is in memory. A bare
         * `OrganizationInvitation $invitation` binding would be a global find with no organization
         * predicate, executed inside SubstituteBindings, upstream of every check
         * (laravel-rbac-policies, Gotchas). App\Models\Organization::invitations() exists for exactly
         * this and its docblock says so.
         *
         * THE INVITATION *TOKEN* NEVER APPEARS HERE, in a path or a response. These routes address
         * invitations by ULID, under an organization the caller has proven membership of; the token
         * is a bearer capability and lives only in the mail that carries it and in the guest routes
         * of routes/api_auth.php, which take it in a POST body (laravel-sanctum-auth NN4).
         *
         * `resend` IS A POST AND NOT A PUT/PATCH: it is not idempotent. Each call mints a NEW token
         * and overwrites `token_hash`, killing the previous link — which is the correct semantics
         * (two live links to one invitation are two capabilities with one authority) and is what
         * makes an invitation revocable at all. It is also a SINGLE-ACTION controller rather than a
         * fifth method on InvitationController, because `arch()->preset()->laravel()` limits a
         * controller's public methods to the seven resource verbs plus `__invoke`.
         *
         * WHY THERE IS NO `PATCH /members/{user}` YET. Changing a member's role is a privilege
         * change that needs `members.manage`, plus `members.manage_owner` for anything touching the
         * `owner` role, plus an ON_FAILURE_ABORT audit row inside the same transaction. It is its own
         * unit; a placeholder route for it would publish an endpoint nobody has designed the
         * escalation guard for.
         */
        Route::get('/invitations', [InvitationController::class, 'index'])
            ->name('invitations.index');

        Route::post('/invitations', [InvitationController::class, 'store'])
            ->name('invitations.store');

        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->name('invitations.destroy');

        /*
         * `[Class, '__invoke']` RATHER THAN THE BARE CLASS STRING — no longer load-bearing, and kept.
         * `Route::post($uri, SomeController::class)` stores `controller = SomeController` with no
         * `@method`, and `Route::getActionMethod()` is `Arr::last(explode('@', …))`, so it used to
         * answer with the CLASS NAME; `method_exists()` then failed and the dump aborted with "is not
         * a controller action", taking the whole generated OpenAPI document with it. That gap is
         * closed — `DumpOpenApiCommand::actionMethod()` now reads `uses`, which is what the router
         * dispatches — so the bare class string would work here today. The explicit form stays
         * because it costs eleven characters and says which method runs at the call site.
         */
        /*
         * THE ONE ROUTE IN THIS GROUP THAT NEEDS A SECOND LIMITER, and the group's own
         * `throttle:admin` is why. That limiter keys on (organization, user) at 120/min — the ACTOR —
         * so on its own it permits one administrator to mail one invitee 120 live invitation links a
         * minute. That is a mailbox flood aimed at a third party who never asked to be invited, done
         * with legitimate credentials, and no other control sees it: the policy passes, the org is
         * active, and the invitation is valid on every call. `invitation-resend` keys on the
         * INVITATION, so the budget belongs to the recipient rather than to the sender.
         *
         * Both limiters apply; middleware is additive, so this is the tighter of the two on this route
         * and `throttle:admin` still bounds the actor across the rest of the surface.
         */
        Route::post('/invitations/{invitation}/resend', [ResendInvitationController::class, '__invoke'])
            ->middleware('throttle:invitation-resend')
            ->name('invitations.resend');

        Route::get('/members', [MemberController::class, 'index'])
            ->name('members.index');
    });
