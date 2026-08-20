<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\BotController;
use App\Http\Controllers\Api\V1\BotDomainController;
use App\Http\Controllers\Api\V1\BotStarterQuestionController;
use App\Http\Controllers\Api\V1\BotStatusController;
use App\Http\Controllers\Api\V1\EmbeddingConfigurationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\ProviderConnectionController;
use App\Http\Controllers\Api\V1\ProviderModelController;
use App\Http\Controllers\Api\V1\ReprocessSourceController;
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
         * designated embedding credential — which is exactly what the composite ON DELETE RESTRICT
         * on `organizations.embedding_connection_id` intends, and the controller does not work
         * around it: the constraint stays the authority and the pre-flight check is only the
         * actionable sentence.
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
         * model-less draft" — and its third refusal, "no assigned source", is still missing because
         * `bot_source_assignments` is Phase C's table. The `TODO(phase-c)` in
         * `BotService::assertPublishable()` stays.
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
    });
