---
name: laravel-rbac-policies
description: Authorization for the Laravel control plane — the org-scoped policy base class, the fixed role catalog, and the 403-admin/404-public deny split. Use whenever writing a Policy, a Gate, `can` middleware, an authorize() call site, a route-model binding on a tenant-owned model, or a FormRequest touching an ownership column. Every policy resolves membership of the record's organization first; admin-of-some-org is the cross-tenant bug. Pairs with laravel-sanctum-auth (who you are) and kb-tenancy-isolation (the scoping contract).
---

# Laravel RBAC and Policies

Laravel **13.24** (released 2026-08-04; 13.x active support to 2027-09-30, PHP 8.3–8.5). Roles are **hand-rolled**, not `spatie/laravel-permission` — see "Package or hand-rolled" below.
**Authoritative spec:** docs/01-product-scope.md §6.1–6.6, docs/02-functional-auth-tenancy-bots.md §8.1 §8.2, docs/11-data-model.md §16.1, docs/13-security.md §18.4

## Non-negotiables

1. **Every tenant policy is org-scoped before it is role-scoped.** A policy that asks "is this user an admin?" without "of *this record's* organization?" grants cross-tenant write access to anyone who is an admin anywhere. Membership is resolved from `$record->organizationId()`, never from `$user->role`, never from a session "current org" that the record was not checked against.
2. **Policies are layer 3 of seven, never the only one** (`kb-tenancy-isolation` §8.2). A passing policy does not license an unscoped query. Repository reads still carry `organization_id`; the policy authorizes a row you *already hold*, and cannot authorize a set you are about to fetch.
3. **`authorization` renders 403 on authenticated admin surfaces and 404 on public runtime/SDK surfaces** (`kb-error-taxonomy`) — a 403 on a foreign identifier confirms the row exists and turns the endpoint into an enumeration oracle. The `error_class` is `authorization` in **both** cases; never branch retry, fallback, or breaker logic on the rendered status.
4. **No global `Gate::before` returns `true`.** It short-circuits every policy including org scoping, and fires for abilities that have no policy method at all. Platform Owner (§6.1) holds platform gates only; reaching tenant data is an impersonation flow that *sets* an organization context and writes an audit row (`kb-tenancy-isolation` NN 4).
5. **Ownership columns are never mass-assignable and never validated.** `organization_id` absent from `$fillable`, from every FormRequest rule set, and from every DTO. Over-posting a tenant key is an authorization bug with a 200 response.
6. **UI hiding is not authorization** (§18.4). Every protected action runs all six server-side checks; this skill implements checks 2, 3, and 4 — `kb-security-baseline` owns the list and checks 5 and 6 are the ones reviewers forget.

## How we use it

### The roles the spec defines

§6.1–6.6 names six actors. Five are ours to authorize; `organization_users.role` (§16.1) is a **single scalar** — one role per user per organization.

| Actor | Value | Scope | May not |
|---|---|---|---|
| Platform Owner §6.1 | `users.is_platform_owner` | **platform**, not an org role | reach tenant data without an audited impersonation |
| Organization Owner §6.2 | `owner` | org | — |
| Organization Administrator §6.3 | `admin` | org | billing; destructive org-level actions |
| Knowledge Manager §6.4 | `knowledge_manager` | org | provider credentials, members, bot publish |
| Analyst / Reviewer §6.5 | `analyst` | org | any write outside feedback and eval datasets |
| End User §6.6 | — | none | *not a member*; authorized by bot access mode on public surfaces |

### Package or hand-rolled

**Hand-rolled.** `spatie/laravel-permission` 8.3.0 supports Laravel 13, and its teams feature does scope roles by a tenant key — but it scopes them through **ambient static state**: `PermissionRegistrar::setPermissionsTeamId()`. That is the pooled-context failure mode `kb-tenancy-isolation` already names — a queue worker, an Octane request, or a mid-request org switch inherits the previous occupant's team, and the package's own docs require you to `unset()` the model's `roles`/`permissions` relations by hand after every switch. It also needs its middleware prioritized *before* `SubstituteBindings` or bindings resolve with no team and 404 unpredictably. We would be paying that risk for a feature we do not have: our roles are fixed in the spec, so there is no per-tenant role CRUD to store. Permissions live in code (`Permission` enum), the role→permission map lives in code (`OrgRole::grants()`), and the only database fact is the membership row — one place to get org scoping wrong instead of two. Revisit only if customers require custom roles; that is an ADR, not a refactor.

### The pattern that makes it structurally hard to get wrong

One abstract base owns the *only* authorization primitive. Every ability is a one-line delegation to it, so there is no syntactic room to write a role check that forgot its organization. Policies resolve through the container, so the surface context is constructor-injected.

```php
// services/core-api/app/Policies/OrgScopedPolicy.php
abstract class OrgScopedPolicy
{
    // Bound per route group: admin API vs public runtime/SDK (laravel-sanctum-auth).
    public function __construct(protected readonly Surface $surface) {}

    /**
     * The only place an authorization decision is made. Note the argument order:
     * the organization comes from the RECORD, so "admin of some org" is unrepresentable.
     */
    protected function permit(?User $user, OrgOwned $record, Permission $permission): Response
    {
        if ($user === null) {
            return $this->refuse();                       // guests reach here only on public surfaces
        }
        // Resolved per check and keyed by org id — never memoized across organizations,
        // because workers and Octane reuse the User instance (kb-tenancy-isolation).
        $membership = $user->membershipFor($record->organizationId());

        return $membership?->isActive() && $membership->role->grants($permission)
            ? Response::allow()
            : $this->refuse();
    }

    /** error_class is `authorization` either way; only the rendered status differs (kb-error-taxonomy). */
    private function refuse(): Response
    {
        return $this->surface->isPublic()
            ? Response::denyAsNotFound()                  // widget, hosted chat, mobile, SDK
            : Response::deny('This action is unauthorized.');   // authenticated admin API → 403
    }
}

// services/core-api/app/Policies/BotPolicy.php — auto-discovered: App\Models\Bot → App\Policies\BotPolicy.
final class BotPolicy extends OrgScopedPolicy
{
    public function view(?User $u, Bot $bot): Response    { return $this->permit($u, $bot, Permission::BotsView); }
    public function update(?User $u, Bot $bot): Response  { return $this->permit($u, $bot, Permission::BotsManage); }
    public function publish(?User $u, Bot $bot): Response { return $this->permit($u, $bot, Permission::BotsPublish); }
    public function delete(?User $u, Bot $bot): Response  { return $this->permit($u, $bot, Permission::BotsDelete); }

    /**
     * `create` has no model, so it has no organization on its arguments. The org is passed
     * explicitly from the authenticated context — an org_id a caller can set is a parameter,
     * not a scope (kb-tenancy-isolation NN 6). OrgContext implements OrgOwned.
     */
    public function create(?User $u, OrgContext $ctx): Response { return $this->permit($u, $ctx, Permission::BotsManage); }
}
```

```php
// services/core-api/routes/api_admin.php — the organization is a URL segment so bindings can be scoped.
Route::prefix('v1/organizations/{organization}')
    ->middleware(['auth:sanctum', 'org.member'])
    ->scopeBindings()   // {bot} resolves via $organization->bots(), so a foreign id 404s at
    ->group(function () {   // binding time — before any policy, and before the row is in memory.
        Route::patch('/bots/{bot}', [BotController::class, 'update'])->name('bots.update');
    });

// services/core-api/app/Http/Controllers/Api/BotController.php
public function update(
    UpdateBotRequest $request,     // rules contain no organization_id — over-posting it is a 422
    Organization $organization,
    Bot $bot,
    BotService $bots,
): BotResource {
    Gate::authorize('update', $bot);                      // checks 2, 3, 4 — org, role, ownership
    abort_unless($bot->status->isEditable(), 409);        // check 5 — entity status (§18.4)

    return new BotResource($bots->update($bot, $request->toData()));
}
```

Route-level `can` middleware (`->can('update', 'bot')`, or the Laravel 13 `#[Authorize('update', 'bot')]` controller attribute) is equivalent and fine for single-model routes; it does **not** cover checks 5 and 6, so a destructive or state-dependent action still needs the controller call.

**Boundaries.** Tokens, sessions, CSRF, guards, and how `Surface` is bound → `laravel-sanctum-auth`. The tenant-filter contract and the other six layers → `kb-tenancy-isolation`. Rendered statuses and retry semantics → `kb-error-taxonomy`. Schema, indexes, and FK chains → `postgresql-patterns`.

## Gotchas

- **A member of Org A edits Org B's bot; the audit row says the check passed.** The policy read `$user->role === 'admin'` — true, of *some* organization. Every role read must start from `$record->organizationId()`; that is why `permit()` takes the record, not a role name.
- **`$this->authorize(...)` fatals with "Call to undefined method".** Since Laravel 11 the skeleton's base `Controller` no longer uses `AuthorizesRequests`, so `authorize()` and `authorizeResource()` do not exist. Use `Gate::authorize()` everywhere and grep for `$this->authorize(` in CI — mixing the two means half your controllers silently have no trait to inherit from.
- **An enumeration script maps every tenant's bot ids in an hour, and every response was a correct 403.** The widget and hosted-chat routes used the admin deny. Public runtime and SDK surfaces return `Response::denyAsNotFound()`; a foreign id and a nonexistent id must be indistinguishable.
- **A suspended user keeps working on exactly the endpoints that used inline authorization.** `Gate::allowIf()` and `Gate::denyIf()` **do not execute `before` or `after` hooks** — documented, and easy to miss. Any rule you expect to apply globally must live in the policy body, not in a hook.
- **A `PATCH` moves a bot to another organization and returns 200.** `organization_id` was fillable and the FormRequest passed it through. Guard the column, keep it out of every rule set and DTO, and call `Model::shouldBeStrict()` in `AppServiceProvider` — `preventSilentlyDiscardingAttributes()` turns the silent drop into a `MassAssignmentException` in dev and CI. `forceFill()`/`forceCreate()` bypass all of it; grep for them.
- **A bare `Bot $bot` binding loads another org's row into memory before the policy runs.** Implicit binding is `Bot::find($id)` with no org predicate, and it happens in `SubstituteBindings` — upstream of `can` middleware and of the controller. Anything built from that instance (a relation query, a log line, a cache key, a response-time difference) leaks before authorization ever fires. Nest the route under `{organization}` with `->scopeBindings()`.
- **The isolation test passes on `/organizations/{organization}/bots/{bot}` and the same code leaks on `/bots/{bot}`.** `scopeBindings()` scopes a *child* to a *parent route parameter*. With no parent segment it is a no-op — silently. If a route cannot carry the org in its path, override `resolveRouteBinding()` on the model to apply the authenticated org, and assert it in a test.
- **An export, a report, or a dashboard returns another org's rows and no policy ever ran.** Policies are invoked per model instance. `Model::query()`, `DB::table()`, `whereIn` aggregates, and Scout searches invoke nothing — `viewAny` gates the *endpoint*, not the data. Every collection read goes through a repository method that takes the org as a required argument; the CI greps in `kb-tenancy-isolation`'s Definition of done are what actually cover this gap.
- **A route 404s that worked yesterday, immediately after adding tenant-context middleware.** Middleware that establishes org context must be prioritized *before* `SubstituteBindings`; registered after it, bindings resolve with no context and the scoped lookup finds nothing. The symptom is a 404, not an error, so it reads as a routing bug.
- **`can:update,post` on a route whose parameter is `{bot}` returns 403 for everyone, forever, and the policy never runs.** The middleware's second argument is a *route parameter name*, not a variable. When no such parameter exists, `Authorize::getModel()` returns `null` — and `Gate::resolveAuthCallback()` guards the policy branch with `isset($arguments[0])`, which is false for `null`, so no policy is resolved, no ability is defined, and the fallback empty closure denies. A typo in the parameter name is therefore indistinguishable from a correctly-denying policy. It fails closed, which is why it survives review and gets debugged as a broken permission matrix. Keep `->can('update', 'bot')` next to the binding so the mismatch is visible in one line, and use the fully-qualified class form (`can:create,App\Models\Bot`) for model-less abilities — `getModel()` returns a *class name* argument unchanged, and a bare unquoted word is not one.
- **A user removed from an organization keeps full access until the process recycles.** `membershipFor()` was memoized on the `User` instance, and queue workers plus Octane keep that instance alive across requests and across tenants. Cache per `(user_id, org_id)` with a request-lifetime store, and invalidate on membership change.
- **A queued job writes correct-looking rows into the wrong organization and no policy denied it.** Jobs have no authenticated user, so `Gate` denies by default — which pushes people to call the service directly and skip authorization entirely. Authorize at enqueue time, carry `organization_id` in the job payload (§16.8), and have the service assert it on entry.

## Official docs

- [Laravel 13.x — Authorization](https://laravel.com/docs/13.x/authorization) — gates, policy discovery, `Response::deny`/`denyWithStatus`/`denyAsNotFound`, `before`/`after`, `can` middleware, `#[Authorize]`.
- [Laravel 13.x — Routing: route model binding](https://laravel.com/docs/13.x/routing#route-model-binding) — implicit binding, `scopeBindings()`, `withoutScopedBindings()`, `missing()`, custom keys.
- [Laravel 13.x — Eloquent: mass assignment](https://laravel.com/docs/13.x/eloquent#mass-assignment) — `$fillable`/`$guarded`, `forceFill`, and the strict-mode helpers.
- [Laravel 13.x — Middleware: priority](https://laravel.com/docs/13.x/middleware#sorting-middleware) — ordering relative to `SubstituteBindings`.
- [spatie/laravel-permission — Teams permissions](https://spatie.be/docs/laravel-permission/v8/basic-usage/teams-permissions) — read before reopening the package decision.
- [OWASP — Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html) — deny by default, and enforcing at a single trusted layer.

## Definition of done

- [ ] Every tenant policy extends `OrgScopedPolicy`; `rg -n 'function (view|update|delete|create|publish)' app/Policies` shows only one-line `permit(...)` delegations — no policy body reads a role directly.
- [ ] `rg -n 'Gate::before|->before\(' app/` returns nothing that can return `true` for a tenant ability.
- [ ] Public runtime/SDK surfaces return **404** for a foreign id and **404** for a nonexistent id, asserted by a test that compares both bodies; admin surfaces return 403. Both log `error_class=authorization`.
- [ ] Every tenant-owned route either nests under `{organization}` with `->scopeBindings()` or overrides `resolveRouteBinding()`; a test hits each changed route with a valid id from *another* org and asserts 404 at binding time.
- [ ] `organization_id` is guarded on every model, absent from every FormRequest rule set and DTO; `Model::shouldBeStrict()` is on; a test posts `organization_id` to an update endpoint and asserts the row is unmoved.
- [ ] `rg -n '\$this->authorize\(|authorizeResource\(' app/Http/Controllers` returns nothing (base `Controller` has no `AuthorizesRequests` since Laravel 11).
- [ ] Every new `Permission` case is granted in `OrgRole::grants()` for each role that should hold it, and a table-driven test asserts the full role × permission matrix — a permission nobody was granted fails silently otherwise.
- [ ] Two-organization feature tests per changed endpoint: member of A vs record of B, suspended membership, correct role/wrong org, wrong role/correct org, and guest.
- [ ] Every dispatched job carries `organization_id`; the receiving service asserts it against the records it touches.
