<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\BotController;
use App\Http\Controllers\Api\V1\BotDomainController;
use App\Http\Controllers\Api\V1\BotSourceAssignmentController;
use App\Http\Controllers\Api\V1\BotStarterQuestionController;
use App\Http\Controllers\Api\V1\BotStatusController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\EmbeddingConfigurationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\PlaygroundSessionController;
use App\Http\Controllers\Api\V1\ProviderConnectionController;
use App\Http\Controllers\Api\V1\ProviderModelController;
use App\Http\Controllers\Api\V1\QuotaController;
use App\Http\Controllers\Api\V1\ReprocessSourceController;
use App\Http\Controllers\Api\V1\RerankConfigurationController;
use App\Http\Controllers\Api\V1\ResendInvitationController;
use App\Http\Controllers\Api\V1\RotateProviderCredentialController;
use App\Http\Controllers\Api\V1\SourceController;
use App\Http\Controllers\Api\V1\SourceStatusController;
use App\Http\Controllers\Api\V1\UploadLimitsController;
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
         * THE RERANK DESIGNATION — the third per-surface provider choice.
         *
         * `bots.provider_connection_id` decides who ANSWERS, `/embedding-configuration` above
         * decides who EMBEDS, and this pair decides who RERANKS. Until it existed, "use NVIDIA NIM
         * for reranking and OpenAI for chat" was not expressible anywhere in the platform:
         * `app/rag/rerank.py::rerank_gate` takes a `model` argument and nothing upstream of it
         * decided what that model was.
         *
         * IT MIRRORS THE EMBEDDING ROUTES' SHAPE AND NOT THEIR BEHAVIOUR, and the one difference is
         * worth reading before either action is changed. A missing embedding designation blocks
         * INGESTION — `show` there is a blocking banner and its verdict comes from the data plane on
         * every read, uncached. A missing rerank designation blocks NOTHING: stage 11 is skipped
         * before any call goes out and candidates are served in fused order, which is a cheaper,
         * measured, supported mode reported on the retrieval trace as `rerank_skip_reason`. So
         * `show` here is a pure read of two columns on the bound `organizations` row — no service,
         * no query, no cross-seam request — and there is no readiness endpoint to mirror.
         *
         * WHICH VENDORS CAN RERANK IS NOT ANSWERED ON THIS SIDE OF THE SEAM AND MUST NOT BECOME SO.
         * It is three questions — does the vendor publish a ranking route, does the model row claim
         * the capability, can this platform threshold the scale it returns — and
         * `capabilities.can_rerank` is the AND of all three and their only home. What Laravel
         * enforces here is TENANCY AND EXISTENCE: the designated connection must be one of this
         * organization's, and the (connection, model) pair must be a row in its catalogue.
         *
         * `update` is a PUT rather than a PATCH because the designation is a PAIR whose halves are
         * meaningless apart, and clearing it is `{"connection_id": null, "model": null}` rather than
         * a DELETE: turning reranking off is an operating mode, not the removal of a resource.
         *
         * Both sit under {organization} with ->scopeBindings(), so a foreign id 404s at BINDING
         * time; `org.member` re-reads the membership row before that; and Gate::authorize() runs in
         * the controller because `can` middleware covers checks 2-4 only and never reaches check 5
         * (entity status).
         */
        Route::get('/rerank-configuration', [RerankConfigurationController::class, 'show'])
            ->name('rerank-configuration.show');

        Route::put('/rerank-configuration', [RerankConfigurationController::class, 'update'])
            ->name('rerank-configuration.update');

        /*
         * PROVIDER CONNECTIONS — the organization's stored credentials.
         *
         * `{providerConnection}` RESOLVES THROUGH `$organization->providerConnections()` because
         * the group calls ->scopeBindings(). That is the load-bearing part: a foreign or unknown
         * id 404s at BINDING time, before any policy runs and before the row is in memory. A bare
         * `ProviderConnection $providerConnection` binding would be a global find with no
         * organization predicate, executed inside SubstituteBindings, upstream of every check
         * (laravel-rbac-policies, Gotchas). The parameter is spelled `providerConnection` and not
         * `connection` on purpose: Laravel derives the child relation as
         * Str::plural(Str::camel($parameter)), so the name IS the wiring — `{connection}` would
         * look for a `connections()` relation that does not exist and 404 everything.
         *
         * A connection may be created purely to embed, and that is not a special case: with one
         * sourced embedding vendor it is the only way an organization on any other vendor can
         * ingest at all (finding C1, item 3). The response carries the resulting embedding
         * readiness beside the connection — the save never fails on it.
         *
         * `index` and `show` demand `providers.view`, which a KNOWLEDGE MANAGER holds (§6.4: an
         * ingestion operator has to know whether the organization can embed at all); the three
         * write verbs demand `providers.manage`, which that role does not hold. The split is per
         * action rather than per controller, so it is asserted per action too.
         */
        Route::get('/provider-connections', [ProviderConnectionController::class, 'index'])
            ->name('provider-connections.index');

        Route::post('/provider-connections', [ProviderConnectionController::class, 'store'])
            ->name('provider-connections.store');

        Route::get('/provider-connections/{providerConnection}', [ProviderConnectionController::class, 'show'])
            ->name('provider-connections.show');

        /*
         * A PATCH, and it accepts `label` and `status` and NOTHING ELSE. There is no `credential`
         * field on this route: UpdateProviderConnectionRequest declares no such rule, its DTO has
         * no member to hold one, and the service it feeds never reaches the vault. Replacing a key
         * is the route below.
         */
        Route::patch('/provider-connections/{providerConnection}', [ProviderConnectionController::class, 'update'])
            ->name('provider-connections.update');

        /*
         * A GUARDED HARD DELETE. Refused with 409 while the connection is the organization's
         * designated embedding credential OR its designated rerank credential — which is exactly
         * what the two composite ON DELETE RESTRICT constraints on
         * `organizations.embedding_connection_id` and `organizations.rerank_connection_id` intend,
         * and the controller does not work around either: the constraints stay the authority and
         * the pre-flight checks are only the actionable sentences. The two refusals carry DIFFERENT
         * sentences because they name different consequences — see ProviderConnectionService.
         */
        Route::delete('/provider-connections/{providerConnection}', [ProviderConnectionController::class, 'destroy'])
            ->name('provider-connections.destroy');

        /*
         * ROTATION IS ITS OWN ROUTE, ITS OWN CONTROLLER, AND ITS OWN LIMITER.
         *
         * A PUT on a sub-resource rather than a field on the PATCH above, because the separation is
         * the security control: rotation re-authenticates the actor (§18.3 — it breaks every live
         * bot on that provider the instant it commits) and a relabel does not, and one route
         * covering both would apply the weaker policy to both.
         *
         * A SINGLE-ACTION CONTROLLER because `arch()->preset()->laravel()` limits a controller's
         * public methods to the seven resource verbs plus `__construct`, `__invoke` and
         * `middleware` — the same reason ResendInvitationController exists. `[Class, '__invoke']`
         * rather than the bare class string is no longer load-bearing (DumpOpenApiCommand reads
         * `uses`) and is kept because it says which method runs at the call site.
         *
         * THE SECOND LIMITER IS NOT DECORATION, and it is the same argument as
         * `invitation-resend`. `throttle:admin` keys on (organization, user) at 120/min — the
         * ACTOR — so on an endpoint that verifies a password it permits 120 guesses a minute from
         * a legitimately signed-in session, which makes the §18.3 re-authentication a formality.
         * `credential-rotation` keys on the actor at 5 per 15 minutes and on the IP, which is the
         * per-account-AND-per-IP shape §18.3 requires of every password-verifying endpoint. Both
         * apply; middleware is additive.
         */
        Route::put(
            '/provider-connections/{providerConnection}/credential',
            [RotateProviderCredentialController::class, '__invoke'],
        )
            ->middleware('throttle:credential-rotation')
            ->name('provider-connections.credential.update');

        /*
         * PROVIDER MODELS — the catalog under one connection (docs/11 §16.2).
         *
         * THE CHILD SEGMENT IS `{model}`, AND THE NAME IS THE WIRING. `->scopeBindings()` on this
         * group makes Laravel resolve a child through its PARENT's relation, and the relation name
         * is DERIVED rather than declared: `Model::childRouteBindingRelationshipName()` is
         * `Str::plural(Str::camel($childType))`, so `{model}` becomes `models()` — which is
         * App\Models\ProviderConnection::models(), and it already existed. Verified against
         * vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php:2534, not assumed.
         * `{providerModel}` would derive `providerModels()`, which does not exist, and every
         * request here would 404.
         *
         * THE PARENT IS THE PRECEDING BOUND PARAMETER, NOT THE FIRST ONE:
         * `Route::parentOfParameter()` returns `array_values($this->parameters)[$key - 1]`. So
         * `{model}` scopes to `{providerConnection}` and `{providerConnection}` scopes to
         * `{organization}` — two hops, both scoped. That is what makes a model row belonging to
         * ANOTHER connection of the SAME organization a 404 at binding time, which is the case the
         * composite foreign key `(organization_id, provider_connection_id)` and the scoped binding
         * exist for together.
         *
         * THE FAILURE THIS PREVENTS IS NOT LOUD, AND IT IS WORTH STATING PRECISELY. Losing the
         * scoping on `{model}` — by renaming the segment, by dropping ->scopeBindings(), or by
         * omitting `ProviderConnection $providerConnection` from the action signature — does NOT
         * expose another tenant's row: `#[ScopedBy(OrganizationScope::class)]` on
         * ProviderModelEntry still appends the organization predicate, which is the backstop
         * layer doing its job. What is lost is the CONNECTION predicate, so any catalog row of any
         * of this organization's connections resolves under any other connection's URL. No
         * cross-tenant test can see that, which is why
         * tests/Security/ProviderModelAccessTest.php asserts it on its own.
         *
         * `index` and `show` demand `providers.view`, which a KNOWLEDGE MANAGER holds (§6.4: an
         * ingestion operator has to know whether the organization can embed at all, and these
         * rows' capability flags are half of that answer); the three write verbs demand
         * `providers.manage`, which that role does not hold.
         *
         * WHY `update` IS A PUT AND NOT A PATCH: the row has seven mutable attributes, and "a body
         * that changes nothing is refused" is expressible in `rules()` only as
         * `required_without_all` naming six siblings on each of seven fields. The readable
         * alternative — an `after()` closure — is invisible to `kb:dump-form-rules`, so the
         * generated client would never be told the constraint exists (docs/22 finding 19). A PUT
         * states the complete desired state and every rule stays one line the manifest can see.
         *
         * NO SECOND LIMITER, unlike the credential rotation below it. Nothing on these five routes
         * touches a credential or verifies a password, so `throttle:admin`'s (organization, user)
         * budget is the whole of check 6 here.
         *
         * A GUARDED HARD DELETE. Refused with 409 while the row is the (connection, model) PAIR the
         * organization's embedding designation names. Unlike the connection delete, NO DATABASE
         * CONSTRAINT backs that refusal — `organizations.embedding_model` is a bare `text` column
         * with no foreign key to this table — so the check is the authority and is performed twice:
         * once in the controller for the readable error, and once inside the repository's
         * transaction under the same organization row lock the designation write takes.
         *
         * THAT LOCK CLOSES THE RACE IN BOTH DIRECTIONS ONLY BECAUSE THE DESIGNATION WRITE ALSO
         * RE-VERIFIES, and this sentence used to claim more than the code did. The delete's
         * re-read catches designate-then-delete; delete-then-designate was open, because
         * EloquentOrganizationRepository::designateEmbeddingConnection() took the same lock and
         * then wrote without checking the pair still existed. Both requests returned 200 and the
         * organization was left naming a `provider_models` row that had been removed. It now
         * re-verifies under that lock; the two serialise, and whichever loses is refused.
         */
        Route::get('/provider-connections/{providerConnection}/models', [ProviderModelController::class, 'index'])
            ->name('provider-connections.models.index');

        Route::post('/provider-connections/{providerConnection}/models', [ProviderModelController::class, 'store'])
            ->name('provider-connections.models.store');

        Route::get('/provider-connections/{providerConnection}/models/{model}', [ProviderModelController::class, 'show'])
            ->name('provider-connections.models.show');

        Route::put('/provider-connections/{providerConnection}/models/{model}', [ProviderModelController::class, 'update'])
            ->name('provider-connections.models.update');

        Route::delete('/provider-connections/{providerConnection}/models/{model}', [ProviderModelController::class, 'destroy'])
            ->name('provider-connections.models.destroy');

        /*
         * BOTS — the retrieval scope (docs/02 §8.3, docs/11 §16.3).
         *
         * `{bot}` RESOLVES THROUGH `$organization->bots()` because the group calls
         * ->scopeBindings(). That is the load-bearing part and it matters more here than anywhere
         * else on this surface: `bot_ids` is one of the four mandatory Qdrant filter terms, so a
         * bot resolved out of the wrong organization does not produce a wrong answer — it produces
         * a correct-looking answer, at normal latency, with a 200, citing a document the
         * organization never uploaded. A foreign or unknown id 404s at BINDING time, before any
         * policy is constructed and before the row is in memory.
         *
         * THE SEGMENT NAME IS THE WIRING. `Model::childRouteBindingRelationshipName()` is
         * `Str::plural(Str::camel($childType))`, so `{bot}` resolves through `bots()` —
         * App\Models\Organization::bots(), which exists for exactly this and whose docblock says
         * so. `{knowledgeBot}` would derive `knowledgeBots()`, which does not exist, and every
         * request here would 404.
         *
         * `public_bot_id` IS NOT THE ROUTE KEY AND `getRouteKeyName()` IS NOT OVERRIDDEN. The
         * admin surface addresses a bot by its ULID, under an organization the caller has proven
         * membership of; the opaque public token addresses it on the UNAUTHENTICATED surfaces —
         * hosted chat, the widget bootstrap, the theme stylesheet — and those resolve their own
         * binding, organization-agnostically, because there is no organization in hand at that
         * point. Merging the two keys would make one leaked widget snippet address the
         * configuration endpoint too. App\Models\Bot records this at length.
         *
         * `index` and `show` demand `bots.view`, which ALL FOUR roles hold — Phase C6 has a
         * Knowledge Manager assign sources TO bots and Phase E has an Analyst review conversations
         * PER bot, and §6.4/§6.5 mention bots in neither direction, so both grants are an
         * extension of the specification recorded in App\Enums\Permission. The three write verbs
         * demand `bots.manage`, which neither of those roles holds. The split is per action rather
         * than per controller, so tests/Security/BotEndpointAccessTest.php asserts it per action —
         * a uniform dataset goes green against a `view` silently mapped to `bots.manage`, and
         * every role that holds `bots.manage` also holds `bots.view`, so the mis-mapping would
         * show up only for the two roles a hand-written dataset under-covers.
         *
         * `update` IS A PATCH AND NOT A PUT, which is the OPPOSITE of the call the provider-model
         * routes above make, and the difference is the field count. There, seven mutable
         * attributes made "a body that changes nothing is refused" expressible as
         * `required_without_all` naming six siblings on each of seven fields, and a PUT was the
         * readable alternative. Here there are twenty-five, so that spelling is unreadable — and a
         * PUT is not available either, because a bot's complete state includes `public_bot_id`,
         * `organization_id` and `retrieval_configuration_version`, none of which this endpoint may
         * ever accept. So it is a PATCH, and the empty-body refusal moves into the controller as a
         * 422 that carries a real per-field map. BotController::update() states the whole
         * argument.
         *
         * NO SECOND LIMITER, unlike the credential rotation above. Nothing on these five routes
         * touches a credential or verifies a password, so `throttle:admin`'s (organization, user)
         * budget is the whole of check 6 here. The one destructive action removes a row this
         * organization owns outright rather than a key that authenticates it, so §18.3
         * re-authentication does not apply — a confirmation belongs in the console.
         *
         * A GUARDED HARD DELETE, and the guard is not a refusal: the three child tables reference
         * `bots (organization_id, id)` with ON DELETE RESTRICT, so the repository removes them in
         * dependency order inside the same transaction. That is a deliberate cascade written at
         * one call site rather than a property of the schema, and EloquentBotRepository::delete()
         * records why the keys are not CASCADE. What IS refused, with a 409, is any edit to an
         * archived bot and any write that would leave the bot `published` with no model — both in
         * BotService, both decided against the state the write leaves behind rather than against
         * the request body.
         */
        Route::get('/bots', [BotController::class, 'index'])
            ->name('bots.index');

        Route::post('/bots', [BotController::class, 'store'])
            ->name('bots.store');

        Route::get('/bots/{bot}', [BotController::class, 'show'])
            ->name('bots.show');

        Route::patch('/bots/{bot}', [BotController::class, 'update'])
            ->name('bots.update');

        Route::delete('/bots/{bot}', [BotController::class, 'destroy'])
            ->name('bots.destroy');

        /*
         * THE LIFECYCLE TRANSITION, ON ITS OWN ROUTE.
         *
         * `status` used to be one of twenty-five optional fields on the PATCH above, and it was
         * the only one of them that decides whether an END USER can reach the bot at all.
         * `UpdateBotRequest` now declares it `prohibited` — a 422 naming this route rather than a
         * silent drop, because an absent rule means `validated()` discards the field and a client
         * that had not been updated would publish a bot, get a 200, and find it still in `draft`.
         *
         * A PUT AND NOT A PATCH: the body is the complete desired state of the one thing this
         * route addresses. It is NOT idempotent in the strict sense — re-sending the status a bot
         * already holds is a 422 — and that is a deliberate trade stated in
         * `BotService::transition()`: a no-op would write a `bot.updated` audit row describing a
         * change that did not happen, which makes the trail wrong in the one direction nobody
         * checks it in. A transition endpoint is not a state assertion.
         *
         * THE GUARD IS NOT DUPLICATED HERE. `BotService::transition()` builds a one-column
         * `BotEdit` and hands it to `BotService::update()`, so the archived read-only rule, the
         * publish guard, the row lock and the audit row are the same code on both paths. The guard
         * evaluates the RESULTING state rather than the transition, which is what makes "clear the
         * model on an already-published bot" refuse by the same check that refuses "publish a
         * model-less draft" — and all THREE of its refusals are live. The third, "no assigned
         * source", was missing while `bot_source_assignments` did not exist; Phase C landed that
         * table, the marker came out with it, and the refusal is
         * `BotService::PUBLISH_NEEDS_ASSIGNED_SOURCE`. It counts ENABLED assignments rather than
         * assignments, because a grant switched off grants nothing.
         *
         * A SINGLE-ACTION CONTROLLER because `arch()->preset()->laravel()` limits a controller's
         * public methods to the seven resource verbs plus `__construct`, `__invoke` and
         * `middleware` — the same reason RotateProviderCredentialController exists. `[Class,
         * '__invoke']` rather than the bare class string is no longer load-bearing
         * (DumpOpenApiCommand reads `uses`) and is kept because it says which method runs at the
         * call site.
         *
         * NO SECOND LIMITER. Publishing exposes a bot this organization owns; it touches no
         * credential and verifies no password, so `throttle:admin`'s (organization, user) budget is
         * the whole of check 6.
         */
        Route::put('/bots/{bot}/status', [BotStatusController::class, '__invoke'])
            ->name('bots.status.update');

        /*
         * THE D5 PLAYGROUND'S CREDENTIAL — an ADMIN route that issues a PUBLIC RUNTIME bearer.
         *
         * ── IT IS HERE PRECISELY SO `rt/v1` DOES NOT HAVE TO CHANGE ─────────────────────────
         *
         * routes/api_public.php's header states the rule as a decision: *"`ResolveChatSession`
         * resolves ONE mechanism, the `kbw_` chat session, and adding a second is a deliberate
         * change to the four-mechanisms rule, not an omission to patch in a later route."* Teaching
         * that middleware to also accept an admin session cookie is exactly the change it refuses —
         * two auth paths on one endpoint means two authorization paths and one of them will drift.
         *
         * So the console mints instead. It calls THIS route with its ordinary cookie session, gets
         * a `kbw_` bearer, and talks to the completely unmodified streaming path. `rt/v1` still
         * resolves exactly one credential TYPE; what this route changes is who may be issued one
         * and what its server-side record says about them (`kind: playground` -> `actor_type: user`
         * + diagnostics, derived from that ONE stored field so no two fields can disagree).
         *
         * ── A POST, BECAUSE IT IS NOT IDEMPOTENT ────────────────────────────────────────────
         *
         * Each call mints a NEW bearer under a NEW Valkey key; the previous one keeps working until
         * its own TTL lapses. That is the same semantics `POST /sdk/v1/session` has and the same
         * reason `invitations/{invitation}/resend` is a POST: a call that issues a capability is
         * not a state assertion.
         *
         * ── `bots.manage` AND NOT `bots.view` ───────────────────────────────────────────────
         *
         * All four roles hold `bots.view`. A playground turn spends the organization's provider
         * quota and writes a conversation row, so it is a WRITE wearing a chat control — an analyst
         * who may read a bot's settings must not be able to spend tokens from that screen. The
         * shipped panel already hides its composer on `canManage === false`, and UI hiding is not
         * one of the six checks. There is no `bots.playground` permission for the reason there is no
         * `bots.publish` one: it would be granted to exactly the same two roles.
         *
         * ── CHECK 5 IS THE BOT'S STATUS, AND IT IS NOT `isRetrievable()` ────────────────────
         *
         * `testing` and `published` only. `BotStatus::isPlaygroundReachable()` is a SECOND narrow
         * method rather than a widening of the public one, and its docblock records that the enum
         * contradicted itself about this: `isRetrievable()`'s paragraph says the playground
         * authorizes by policy alone, while `Draft`'s own case comment says *"Never reachable from
         * any channel, including the admin playground."* The specific statement wins and it is also
         * the fail-closed reading.
         *
         * ── AUDITED as `bot.playground_session.minted`, ON_FAILURE_LOG ──────────────────────
         *
         * The credential is already in Valkey when the row is written, so there is nothing to roll
         * back — `auth.login.succeeded`'s shape, not `provider.connection.*`'s. The row carries the
         * bot, the DERIVED session id, the diagnostics flag and the lifetime, and no token in any
         * form. It exists for the one fact the resulting conversation, provider-call and usage rows
         * do not record: that a diagnostics-capable bearer was issued, to whom, and for how long.
         *
         * NO SECOND LIMITER. `throttle:admin`'s (organization, user) budget is the whole of check 6:
         * this mint touches no credential and verifies no password, and the money is spent by the
         * chat turn, which has its own four-scope sliding window inside ChatGate.
         */
        Route::post('/bots/{bot}/playground-session', [PlaygroundSessionController::class, '__invoke'])
            ->name('bots.playground-session.store');

        /*
         * THE WIDGET ORIGIN ALLOW-LIST — a SECURITY CONTROL, not a preference (docs/02 §8.3,
         * docs/11 §16.3).
         *
         * A row here is what lets a page on the public internet boot a chat widget that speaks with
         * this organization's credential, on its corpus, against its quota — and every downstream
         * check AGREES with it, because it has been told that this origin belongs to that bot.
         * There is no later layer that catches a bad row.
         *
         * `{domain}` RESOLVES THROUGH `$bot->domains()` because the group calls ->scopeBindings(),
         * and `{bot}` resolves through `$organization->bots()`. THE PARENT IS THE PRECEDING BOUND
         * PARAMETER, NOT THE FIRST ONE: `Route::parentOfParameter()` returns
         * `array_values($this->parameters)[$key - 1]`, so this is two scoped hops. That is what
         * makes an allow-list entry belonging to ANOTHER BOT OF THE SAME ORGANIZATION a 404 at
         * binding time — the case the composite foreign key `(organization_id, bot_id)` and the
         * scoped binding exist for together, and the case no cross-tenant test can see. Losing it
         * does not expose another tenant's row (`#[ScopedBy(OrganizationScope::class)]` still
         * appends the organization predicate) — what is lost is the BOT predicate, which
         * tests/Security/BotChildEndpointAccessTest.php asserts on its own.
         *
         * THE SEGMENT NAME IS THE WIRING. `Model::childRouteBindingRelationshipName()` is
         * `Str::plural(Str::camel($childType))`, so `{domain}` derives `domains()` —
         * App\Models\Bot::domains(), which exists for exactly this. `{botDomain}` would derive
         * `botDomains()`, which does not, and every request here would 404.
         *
         * THERE IS NO WILDCARD GRAMMAR, in the request, in the schema, or in the eventual matcher.
         * `App\Support\Web\ExactOrigin` refuses `*` outright and normalises what it accepts into
         * the RFC 6454 serialisation a browser actually sends — lower-cased, one trailing slash
         * dropped, a default port dropped — while REFUSING a non-empty path rather than trimming
         * it, because trimming would widen the grant from one page to a whole host.
         *
         * `index` demands `bots.view`, which ALL FOUR roles hold: `App\Enums\Permission::BotsView`
         * names "a bot's configuration, its origin allow-list, and its starter questions" in as
         * many words. The three write verbs demand `bots.manage` through
         * `BotPolicy::manageChildren()` — an ability that existed with NO CALL SITE until this
         * route file, and one that authorizes against the PARENT BOT rather than the child row.
         * That is its design: the three child models have no policies of their own, so
         * `Gate::authorize('update', $domain)` would silently DENY (no policy means deny), and
         * authorizing against the ORGANIZATION instead would pass for a caller addressing a bot
         * they were never shown. The split is per action, so it is asserted per action.
         *
         * `update` CARRIES ONLY `status` AND `origin` IS IMMUTABLE. Editing an origin in place
         * would carry an existing promotion across to a different origin — a grant moved silently.
         * Remove and re-add; the trail then says both things happened.
         *
         * EVERY WRITE HERE IS AUDITED PER ROW, and that is finding L2 (`docs/22` § *The security
         * read of the bots surface*) being closed rather than a general principle: a bot delete
         * destroyed its allow-list with no record of what it permitted, contradicting the reason
         * `bot_domains` gives for its own ON DELETE RESTRICT. `bot.domain.created`,
         * `bot.domain.status_changed` and `bot.domain.deleted` carry one origin each, verbatim,
         * with the actor and the time, and they outlive the bot; the `bot.*` rows now carry scalar
         * summaries so a reader landing on `bot.deleted` knows to go looking for them.
         */
        Route::get('/bots/{bot}/domains', [BotDomainController::class, 'index'])
            ->name('bots.domains.index');

        Route::post('/bots/{bot}/domains', [BotDomainController::class, 'store'])
            ->name('bots.domains.store');

        Route::patch('/bots/{bot}/domains/{domain}', [BotDomainController::class, 'update'])
            ->name('bots.domains.update');

        Route::delete('/bots/{bot}/domains/{domain}', [BotDomainController::class, 'destroy'])
            ->name('bots.domains.destroy');

        /*
         * THE STARTER QUESTIONS — the first-run suggestion chips (docs/02 §8.3, docs/11 §16.3).
         *
         * The LOWER-STAKES of the two child surfaces: a question authorizes nobody and bills
         * nothing. It is still bot CONFIGURATION under §18.11, so the writes are audited — without
         * the question TEXT, which is unbounded tenant prose of exactly the kind `AuditLogger`
         * refuses from the `bot.*` rows. The asymmetry with the allow-list above is the point
         * rather than an inconsistency: an origin string IS the security fact; a chip label is text
         * on a button.
         *
         * `{starterQuestion}` DERIVES `starterQuestions()` through
         * `Str::plural(Str::camel($childType))` — App\Models\Bot::starterQuestions(), which
         * already orders by `sort_order`. Two scoped hops, exactly as for `{domain}` above.
         *
         * `sort_order` IS NOT A CREATE FIELD AND IS A "MOVE TO" INTENT ON THE PATCH.
         * `bot_starter_questions_org_bot_position` is UNIQUE per bot and DELIBERATELY NOT
         * DEFERRABLE — the migration records the trade — so a direct write collides with whichever
         * row holds the target position, as SQLSTATE 23505 rendered as a 500 for a request the
         * operator has every right to make. Every mutation re-sequences the whole list to 0..n-1
         * inside one transaction under the bot's row lock, so a reorder or a delete moves the
         * OTHER questions too and the console must re-read the collection rather than patch one
         * row into a cached list.
         *
         * `index` demands `bots.view` and the three writes demand `bots.manage` through
         * `manageChildren`, exactly as above.
         */
        Route::get('/bots/{bot}/starter-questions', [BotStarterQuestionController::class, 'index'])
            ->name('bots.starter-questions.index');

        Route::post('/bots/{bot}/starter-questions', [BotStarterQuestionController::class, 'store'])
            ->name('bots.starter-questions.store');

        Route::patch(
            '/bots/{bot}/starter-questions/{starterQuestion}',
            [BotStarterQuestionController::class, 'update'],
        )
            ->name('bots.starter-questions.update');

        Route::delete(
            '/bots/{bot}/starter-questions/{starterQuestion}',
            [BotStarterQuestionController::class, 'destroy'],
        )
            ->name('bots.starter-questions.destroy');

        /*
         * THE RETRIEVAL SCOPE — which knowledge sources this bot may answer from (docs/02 §8.3,
         * docs/11 §16.3).
         *
         * THE HIGHEST-STAKES CHILD SURFACE ON A BOT, and higher than the origin allow-list above.
         * `bot_ids` is one of the four mandatory Qdrant filter terms and it is resolved from
         * `bot_source_assignments`, so a row here does not merely permit something — it is what a
         * correctly-filtered vector query MATCHES ON. A wrong row is not caught by the tenant
         * filter; it is ENFORCED by it, and the answer comes back at normal latency with a
         * well-formed citation and an HTTP 200.
         *
         * IT IS ALSO THE ONE ROW IN THE SCHEMA THAT CAN SPAN TWO ORGANIZATIONS
         * (kb-tenancy-isolation NN2). `bot_id` and `source_id` each inherit their own organization
         * and nothing in the foreign-key graph forces them to agree; what forces them is the
         * denormalized `organization_id` plus `bot_source_assignments_bot_same_org` and
         * `bot_source_assignments_source_same_org`. The endpoints below do not replace that guard
         * and could not: a caller really can be a legitimate admin of the organization whose bot is
         * named. tests/Security/BotSourceAssignmentAccessTest.php asserts BOTH halves — the service
         * refusal and the constraint by name — because either alone leaves the other untested.
         *
         * `{sourceAssignment}` RESOLVES THROUGH `$bot->sourceAssignments()` because the group calls
         * ->scopeBindings(), and `{bot}` resolves through `$organization->bots()`. THE PARENT IS THE
         * PRECEDING BOUND PARAMETER, NOT THE FIRST ONE: `Route::parentOfParameter()` returns
         * `array_values($this->parameters)[$key - 1]`, so this is two scoped hops, exactly as for
         * `{domain}` and `{starterQuestion}` above. Losing the second hop does not expose another
         * tenant's row — `#[ScopedBy(OrganizationScope::class)]` still appends the organization
         * predicate — what is lost is the BOT predicate, so any grant of any of this organization's
         * bots would resolve under any other bot's URL.
         *
         * THE SEGMENT NAME IS THE WIRING. `Model::childRouteBindingRelationshipName()` is
         * `Str::plural(Str::camel($childType))`, so `{sourceAssignment}` derives
         * `sourceAssignments()` — App\Models\Bot::sourceAssignments(), which exists for exactly
         * this. `{assignment}` would derive `assignments()`, which the bot does not have, and every
         * request here would 404.
         *
         * DELETE ADDRESSES THE GRANT AND NOT THE SOURCE. `…/source-assignments/{sourceAssignment}`
         * rather than `…/sources/{source}`, because the resource being withdrawn is the assignment:
         * a path naming the source would read as "delete this document from this bot", and the one
         * thing this endpoint must never be mistaken for is a source delete.
         *
         * TWO GATES ON TWO RECORDS, AND NEITHER IS THE ORGANIZATION. `Permission::SourcesAssign`
         * states the split: the SOURCE carries `sources.assign` through
         * `KnowledgeSourcePolicy::assign()`, and the BOT is additionally authorized with
         * `bots.view` — which is why `Permission::BotsView` is granted to all four roles, and the
         * reason `BotPolicy` gives for granting it to knowledge_manager is this surface by name.
         * `BotPolicy::manageChildren()` is deliberately NOT used: it carries `bots.manage`, which
         * knowledge_manager does not hold, so it would deny the one role the permission catalog
         * names as this action's performer. What is copied from that ability is the IDIOM — the
         * record authorized is the PARENT BOT and never the organization, because authorizing
         * against the organization would pass for a caller addressing a bot they were never shown.
         *
         * THERE IS NO PATCH, AND THE ABSENCE IS A DECISION. `AuditLogger` defines
         * `bot.source_assignment.created` and `bot.source_assignment.deleted` and no updated
         * operation, both ON_FAILURE_ABORT. A PATCH on `priority` or `enabled` would either change
         * the retrieval scope with no audit row — the finding those two operations exist to close —
         * or invent an operation. Changing either is a delete and a re-create, and the trail then
         * says both things happened. Same call as `origin` being immutable on the allow-list above.
         *
         * NO SECOND LIMITER. Granting a bot access to a document this organization already owns
         * touches no credential, verifies no password and consumes no storage, so `throttle:admin`'s
         * (organization, user) budget is the whole of check 6.
         */
        Route::get('/bots/{bot}/source-assignments', [BotSourceAssignmentController::class, 'index'])
            ->name('bots.source-assignments.index');

        Route::post('/bots/{bot}/source-assignments', [BotSourceAssignmentController::class, 'store'])
            ->name('bots.source-assignments.store');

        Route::delete(
            '/bots/{bot}/source-assignments/{sourceAssignment}',
            [BotSourceAssignmentController::class, 'destroy'],
        )
            ->name('bots.source-assignments.destroy');

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

        /*
         * KNOWLEDGE SOURCES — the corpus a bot may answer from.
         *
         * `{source}` RESOLVES THROUGH `$organization->sources()` because the group calls
         * ->scopeBindings(). That is the load-bearing part: a foreign or unknown id 404s at BINDING
         * time, before any policy runs and before the row is in memory. A bare
         * `KnowledgeSource $source` binding would be a global find with no organization predicate,
         * executed inside SubstituteBindings, upstream of every check (laravel-rbac-policies,
         * Gotchas). THE SEGMENT NAME IS THE WIRING: `Model::childRouteBindingRelationshipName()` is
         * `Str::plural(Str::camel($childType))`, so `{source}` derives `sources()` —
         * App\Models\Organization::sources(), which exists for exactly this. `{knowledgeSource}`
         * would derive `knowledgeSources()`, which does not exist, and 404 everything.
         *
         * WHY THIS SURFACE IS DIFFERENT FROM THE BOT ONE. `source_status` and `source_version_id`
         * are two of the four mandatory Qdrant filter terms (kb-tenancy-isolation NN3) and both are
         * resolved from these tables, so a mistake here does not produce an error — it produces a
         * correct-looking answer, at normal latency, citing a document the organization never
         * uploaded.
         *
         * `index` and `show` demand `sources.view`; `update` and `destroy` demand `sources.manage`;
         * `store` demands `sources.manage` on the ORGANIZATION and additionally `sources.upload`
         * when the body carries files, because uploading is the one action that consumes a storage
         * quota and hands bytes to an untrusted parser (Permission::SourcesUpload). The Analyst
         * role holds NONE of the four `sources.*` permissions — Permission::SourcesView's docblock
         * refuses the tempting grant, because a conversation transcript renders its citations off
         * the denormalized citation row and reads nothing from `knowledge_sources`.
         *
         * `POST /sources` IS MULTIPART-SHAPED AND THE PART NAME IS `files[0]`, INDEXED EVEN FOR ONE
         * FILE. StoreSourceRequest declares `files` and `files.*` so the 422 keys read `files.0`,
         * which is what lets the console render a per-file error against the row an operator can
         * see. The upload INTAKE runs behind it: kb-security-baseline's six-step gate — size,
         * extension allow-list on the NFKC-normalized name, MIME sniffed from content by libmagic,
         * the extension/MIME cross-check, the OPC macro and embedded-object refusal, the SHA-256 —
         * in UploadIntake, in one method, in one order, because THE ORDER IS THE SECURITY PROPERTY.
         * A batch with any refused part creates nothing at all, and every refusal writes a
         * source.upload.rejected audit row carrying a closed reason token.
         *
         * GET /sources/upload-limits IS DECLARED BEFORE GET /sources/{source}, AND THE ORDER IS
         * NOT COSMETIC. RouteCollection matches in registration order, so a literal segment sharing
         * a prefix with a parameterised one has to come first — declared after it, `upload-limits`
         * binds as `{source}`, misses the scoped `$organization->sources()` lookup and 404s with a
         * body byte-identical to "no such route". It carries `sources.upload` rather than
         * `sources.view`: it describes the act of uploading, and the permission that governs the
         * POST should govern its precondition, so a role change cannot leave a console able to read
         * the ceilings and unable to act on them.
         *
         * DELETE IS PHASE 1 OF A TWO-PHASE REMOVAL and returns the source rather than an
         * acknowledgement: `status` becomes `deleting`, `deleted_at` is stamped, `purged_at` stays
         * null, and the row is already out of retrieval. Phase 2 — the verified purge of vectors,
         * objects and the four Valkey families — is deletion-engineer's on both sides of the seam,
         * and this route dispatches nothing toward it deliberately.
         */
        Route::get('/sources', [SourceController::class, 'index'])
            ->name('sources.index');

        Route::post('/sources', [SourceController::class, 'store'])
            ->name('sources.store');

        // BEFORE `/sources/{source}`. See the block above — after it, this is a 404 that reads as
        // "the endpoint does not exist".
        Route::get('/sources/upload-limits', UploadLimitsController::class)
            ->name('sources.upload-limits');

        Route::get('/sources/{source}', [SourceController::class, 'show'])
            ->name('sources.show');

        Route::patch('/sources/{source}', [SourceController::class, 'update'])
            ->name('sources.update');

        Route::delete('/sources/{source}', [SourceController::class, 'destroy'])
            ->name('sources.destroy');

        /*
         * DISABLE AND ENABLE, ON THEIR OWN ROUTE.
         *
         * `status` is the column that decides whether a source is in the corpus at all, so it does
         * not sit among the PATCH's optional fields — two doors to it are two places a check has
         * to be, which is the same argument that put the bot lifecycle on its own route.
         * `UpdateSourceRequest` declares `status` as `missing` rather than dropping the rule,
         * because an absent rule means `validated()` silently discards the field and a client that
         * had not been updated would disable a source, get a 200, and find it still answering.
         *
         * A PUT AND NOT A PATCH: the body is the complete desired state of the one thing this route
         * addresses. It is NOT idempotent in the strict sense — re-sending the state a source
         * already holds is a 422 — and that is deliberate: a no-op would write a `source.disabled`
         * or `source.enabled` audit row describing a change that did not happen.
         *
         * THE ACCEPTED SET IS `disabled` AND `ready`, not the fifteen states. Thirteen of the
         * transitions are the pipeline walking and arrive on the ingestion callback; `queued` is
         * POST /reprocess, which mints the force nonce without which a resubmission silently
         * dedupes; and `deleting` is DELETE, which stamps `deleted_at` with it. Sending `ready`
         * re-enables whatever the source published as — WHICH of the two ready states it lands in
         * is read from its live versions, because "did this document parse cleanly" is a fact about
         * the content rather than a choice a client may overwrite.
         *
         * THE GUARD IS NOT DUPLICATED HERE. `SourceState::transitionTable()` is the only statement
         * of the machine and `SourceService` asks it under the row lock that also reads
         * `previous_status` for the audit row.
         *
         * NO SECOND LIMITER. Disabling withdraws a source this organization owns, touches no
         * credential and verifies no password, so `throttle:admin`'s (organization, user) budget is
         * the whole of check 6.
         */
        Route::put('/sources/{source}/status', [SourceStatusController::class, '__invoke'])
            ->name('sources.status.update');

        /*
         * REPROCESS — run this source through the pipeline again.
         *
         * A POST AND NOT A PUT BECAUSE IT IS NOT IDEMPOTENT, AND MUST NOT BE. Every call mints a
         * new `force_nonce`, which is the one component of the ingest key that changes when nothing
         * else did — without it a resubmission of unchanged content produces an identical key,
         * `UNIQUE (source_item_id, ingest_key)` resolves it to the existing version, and the admin
         * sees "already processed" for a button labelled Reprocess.
         *
         * IT THEREFORE DOES NOT HONOUR AN `Idempotency-Key` REQUEST HEADER, and neither does POST
         * /sources. A client-supplied key would have to mean "if you have seen this, return the
         * earlier response", which is exactly the dedupe the force nonce exists to defeat. The
         * INTERNAL seam still carries X-KB-Idempotency-Key, derived server-side from the source,
         * every item and the nonce — so a RETRY of one submission is a replay while a SECOND PRESS
         * is a second run. The two ideas look alike and are opposites.
         *
         * 202 AND NOT 200: nothing has been reprocessed when this returns. The submission is a
         * queued job, progress arrives on `status`, and the PREVIOUS VERSION KEEPS SERVING every
         * query until the new one is indexed AND verified.
         *
         * `sources.manage` and not `sources.upload` — no new content is admitted and no storage
         * quota is consumed. What it does spend is provider embedding tokens on every item, which
         * the audit row records as `item_count` because it is a billing fact rather than an
         * authorization one.
         */
        Route::post('/sources/{source}/reprocess', [ReprocessSourceController::class, '__invoke'])
            ->name('sources.reprocess');

        Route::get('/members', [MemberController::class, 'index'])
            ->name('members.index');

        /*
         * ═══ CONVERSATION REVIEW (docs/04 §8.22, §6.2/§6.3/§6.5) ══════════════════════════════
         *
         * TWO ENDPOINTS AND NO WRITE. A thread is opened by the public runtime, written by the
         * relay's finalizer, and removed by the retention sweeper or by an erasure workflow that is
         * `deletion-engineer`'s — §18.11 treats a transcript as EVIDENCE, so a surface that could
         * both read and destroy it would be able to rewrite the record it exists to preserve.
         *
         * `{conversation}` RESOLVES THROUGH `$organization->conversations()` because the group calls
         * ->scopeBindings(). `Model::childRouteBindingRelationshipName()` is
         * `Str::plural(Str::camel($childType))`, so the segment name and the relation name are one
         * fact in two places: `{conversation}` derives `conversations()`, which
         * App\Models\Organization declares for exactly this. Rename either and the binding falls to
         * the UNSCOPED `else` branch — `Conversation::resolveRouteBinding($id)`, executed inside
         * SubstituteBindings, upstream of the Gate call — and every foreign thread is loaded into
         * memory before authorization runs.
         *
         * NEITHER ROUTE HAS AN ENTITY-STATUS CHECK, deliberately: reading what was said, and why an
         * answer was refused, is exactly what a suspended organization's operator needs to do, and
         * neither action writes. The one status check this surface SHOULD have is §18.10's
         * per-organization "may administrators review conversations" switch, WHICH HAS NO COLUMN —
         * `ConversationController`'s docblock carries the measurement and the TODO rather than
         * inventing one here.
         *
         * THE TRANSCRIPT IS NOT THE RUNTIME'S. `rt/v1`'s transcript is scoped to the SESSION that
         * owns the thread and is settled-only; this one is scoped to the ORGANIZATION and carries
         * unsettled turns, citations, retrieval traces, feedback and provider attempts. Both go
         * through one repository so the join up to `conversations` — the only tenancy the four
         * child tables have — is expressed once.
         */
        Route::get('/conversations', [ConversationController::class, 'index'])
            ->name('conversations.index');

        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
            ->name('conversations.show');

        /*
         * ═══ THE AUDIT TRAIL (§18.11) ═════════════════════════════════════════════════════════
         *
         * ONE ENDPOINT, READ-ONLY, AND NO ROW ROUTE. A `/audit-logs/{auditLog}` would need a
         * binding over a table whose primary key is the composite `(id, created_at)` a partitioned
         * table requires and whose model deliberately carries NO `#[ScopedBy]` — so the binding
         * would resolve a row with no tenant predicate and hand it to a policy that THROWS on a
         * platform-scope row. Filtering the list by actor or by subject answers the same questions
         * through the one query shape that carries the organization predicate.
         *
         * IT IS BEHIND `audit.view`, WHICH THE OWNER AND THE ADMINISTRATOR HOLD AND THE KNOWLEDGE
         * MANAGER AND THE ANALYST DO NOT — a narrower set than `conversations.view` two blocks up,
         * and the difference is deliberate: an audit row names a COLLEAGUE, carries their IP
         * address and their user agent, and twelve of the operations being `source.*` is not a
         * reason to hand an ingestion operator the credential-rotation rows beside them.
         * `Permission::AuditView` also records that the grant is an EXTENSION of the spec rather
         * than a reading of it: §6.1 gives "Access platform-level audit logs" to the PLATFORM owner
         * and §6.2-§6.5 name no organization role at all.
         *
         * PLATFORM-SCOPE ROWS (organization_id IS NULL) ARE NEVER RETURNED HERE. That is a decision
         * and not an accident of `organization_id = ?` being false for a NULL; the repository, the
         * interface and a Security test all state it, because the change that would undo it is a
         * one-line `orWhereNull()` that reads like a feature request.
         *
         * NO ENTITY-STATUS CHECK: a suspended organization needs its audit trail MORE than an
         * active one does, because that is what an incident looks like.
         */
        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->name('audit-logs.index');

        /*
         * ═══ THE USAGE AND QUALITY DASHBOARD (§8.23) ══════════════════════════════════════════
         *
         * ONE ENDPOINT AND NOT ELEVEN. The tiles are read together — they are one screen — so a
         * tile per endpoint would be eleven round trips, eleven authorization checks and eleven
         * chances for one of them to be scoped differently from the other ten.
         * `kb-tenancy-isolation` lists analytics as its own isolation layer precisely because
         * "group by bot_id and drop the redundant organization_id" is such an easy thing to talk
         * yourself into, and the queries stay separate inside the repository so each keeps its own
         * explicit organization predicate.
         *
         * A GET WITH A QUERY STRING, not a POST with a body. Every parameter is a filter over a
         * read; a POST would make the dashboard uncacheable by every layer that caches on the URL
         * and would put a body on a request that changes nothing.
         *
         * NO ENTITY-STATUS CHECK. A suspended organization's operator still needs to read their own
         * numbers, and this endpoint writes nothing — see the controller.
         */
        Route::get('/analytics', AnalyticsController::class)
            ->name('analytics.show');

        /*
         * ═══ THE QUOTA CEILINGS ═══════════════════════════════════════════════════════════════
         *
         * `show` and `update` sit behind DIFFERENT permissions, which is unusual on this surface and
         * is the point: reading how much of an allowance is used is a report (`analytics.view`),
         * and changing the ceiling is a plan change (`quotas.manage`, held by the Organization Owner
         * alone among the four org roles). Collapsing them would hand the ceiling to every role that
         * may read the dashboard, including the Analyst.
         *
         * `update` IS A PUT AND NOT A PATCH, for the reason the two designation endpoints above are
         * PUTs and then some: the four ceilings are a COMPLETE SET, and on this body an omitted key
         * and a null key would otherwise mean the same thing while meaning opposite things — "leave
         * it alone" versus "remove this ceiling entirely". A client that dropped a key from its
         * payload would silently remove a limit.
         *
         * THERE IS A CHECK ON `update` THAT IS NOT VISIBLE ANYWHERE IN THIS FILE:
         * `QuotaLimitService` refuses a RAISE from a caller who is not `users.is_platform_owner`.
         * It cannot be middleware or a policy — it is a comparison between the submitted numbers and
         * the persisted ones — and it exists because §6.1 assigns limit control to the platform
         * owner while §6.2 gives the org owner "manage organization settings". That contradiction is
         * recorded in Permission::QuotasManage and in the migration, not resolved.
         *
         * Both sit under {organization} with ->scopeBindings(), so a foreign id 404s at BINDING
         * time; `org.member` re-reads the membership row before that; and Gate::authorize() runs in
         * the controller because `can` middleware covers checks 2-4 only and never reaches check 5
         * (entity status).
         */
        Route::get('/quotas', [QuotaController::class, 'show'])
            ->name('quotas.show');

        Route::put('/quotas', [QuotaController::class, 'update'])
            ->name('quotas.update');
    });
