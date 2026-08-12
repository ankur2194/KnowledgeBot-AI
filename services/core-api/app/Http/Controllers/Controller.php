<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Base controller.
 *
 * NOTE THE TRAIT THAT IS NOT HERE. Since Laravel 11 the skeleton's base controller no longer uses
 * Illuminate\Foundation\Auth\Access\AuthorizesRequests, so `$this->authorize(...)` and
 * `$this->authorizeResource(...)` DO NOT EXIST and fatal with "Call to undefined method" at
 * runtime — not at analysis time, and not in a code review. Re-adding the trait here is worse than
 * the fatal: it makes half the controllers authorize through an inherited trait and half through
 * the facade, and the two drift.
 *
 * Use Gate::authorize() everywhere. CI greps for `$this->authorize(` and `authorizeResource(` under
 * app/Http/Controllers and fails on a hit (laravel-rbac-policies).
 *
 * Controllers stay thin by construction: FormRequest in, Policy decides, Service decides behaviour,
 * Repository owns the query, API Resource shapes the output.
 *
 * The rule that would make "thin" mechanical — App\Models used only from App\Repositories\Eloquent,
 * App\Policies and Database, so a controller cannot touch a model and therefore cannot skip a policy
 * or an organization scope — is written out in full but COMMENTED OUT, under "Pending rules" in
 * tests/Arch/DoctrineTest.php. It iterates App\Models, which is empty, and an empty dataset is an
 * error in Pest 5 rather than a vacuous pass. Enabling it is a one-line uncomment on the PR that
 * adds the first model. Until that PR, nothing enforces the layering here and review is the only
 * check.
 *
 * Gate::authorize() covers checks 2, 3 and 4 of the six. Checks 5 (entity status) and 6 (rate limit
 * / quota) are separate call sites in the controller or the service, and they are the two reviewers
 * forget (kb-security-baseline §18.4).
 */
abstract class Controller
{
    //
}
