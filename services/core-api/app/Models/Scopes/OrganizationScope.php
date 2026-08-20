<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The BACKSTOP layer of the two (laravel-control-plane, "Where the tenant scope is enforced").
 *
 * The mechanism is the explicit `forOrg($orgId)` argument on every repository method. This scope
 * catches the query somebody forgot to route through one. It fails silently for `DB::table()`,
 * `DB::select()`, raw SQL and `withoutGlobalScopes()` — none of which touch the Eloquent builder —
 * which is why all three are checked outside it.
 *
 * THIS SENTENCE USED TO SAY "CI GREPS FOR ALL THREE AS WELL", AND THERE IS NO CI: `.github/` was
 * deleted on 2026-08-17 and nothing replaced it. Two of the three are now held by a TEST instead,
 * which is stronger than the grep was — `tests/Arch/DoctrineTest.php` pins the `DB` facade to
 * `App\Repositories\Eloquent`, and `tests/Arch/StringLevelDoctrineTest.php` tokenizes `app/` and
 * fails on a `withoutGlobalScopes(` or `withoutGlobalScope(` CALL (the published grep matched this
 * very docblock, because a grep cannot tell code from a comment). RAW SQL INSIDE
 * `App\Repositories\Eloquent` REMAINS A REVIEW OBLIGATION and nothing mechanical will object to it:
 * `rg 'DB::(raw|select|statement|unprepared)\(' services/core-api/app` is how a human checks it.
 *
 * THE CONTEXT IS RESOLVED INSIDE apply(), NOT INJECTED. `HasGlobalScopes::addGlobalScope()`
 * instantiates a `#[ScopedBy]` class with a bare `new $scope` and caches that instance in a STATIC
 * array keyed by model class — so a constructor-injected context would be resolved once, at first
 * boot, and then survive every subsequent request and every subsequent job in the same worker.
 * That is precisely the pooled-context failure this scope exists to guard against, installed
 * inside the guard itself.
 *
 * WHEN NO CONTEXT IS BOUND THIS SCOPE FILTERS EVERYTHING OUT, and that direction is deliberate.
 * The alternative — apply nothing when the context is empty — makes the backstop vanish exactly
 * when it is most needed: a pooled queue worker whose context was never set would then run every
 * query unscoped, returning every tenant's rows, with no exception anywhere. A query that returns
 * nothing is a loud, immediate, debuggable failure; a query that returns everyone is a breach.
 *
 * Inserts are unaffected — scopes apply to SELECT/UPDATE/DELETE builders — so factories and
 * seeders still work without a bound context.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class OrganizationScope implements Scope
{
    /**
     * `covariant` mirrors Scope::apply()'s own declaration in the framework. Narrowing it to
     * `Builder<TModel>` is a contravariance error, and the tempting "fix" — dropping the generic
     * annotation entirely — would silently take `$builder` back to `mixed` at level 8.
     *
     * @param  Builder<covariant TModel>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->isBound()) {
            // Fail closed. See the class docblock: the empty-context branch is the one that
            // decides whether a stale worker leaks or merely breaks.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), '=', $context->orgId());
    }
}
