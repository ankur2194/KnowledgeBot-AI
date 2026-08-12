<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\EmbeddingConfigurationController;
use App\Http\Controllers\Api\V1\ProviderConnectionController;
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
|      public chat surface needs the composite sliding window (valkey-keyspaces).
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

Route::middleware(['auth:sanctum', 'surface:admin', 'org.member', 'throttle:admin'])
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
    });
