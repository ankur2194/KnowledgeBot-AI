<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture tests
|--------------------------------------------------------------------------
|
| ARCH TESTS ARE SUITE MEMBERS, NOT A NICETY. They encode the doctrine no runtime test can
| observe — layering, env() reads, debug helpers, and who may open a connection to the AI service —
| and they run UNCONDITIONALLY on every CI run, never accelerated by test-impact analysis (which
| follows PHP call graphs and cannot see across a process boundary into FastAPI or Qdrant).
|
| These rules are live from day one, on an empty tree, ON PURPOSE: a rule added after the first
| violation exists is a rule that ships with an exception list.
|
| One doctrine that is string-level but too important to leave to a grep lives beside this file:
| tests/Arch/SecretsResolverTest.php token-scans config/, app/, bootstrap/, routes/ and database/
| and fails on any secret-shaped env() read that does not go through App\Support\Kb\KbSecrets
| (security finding B2 — the infrastructure delivers secrets as FILES and `docker compose config`
| renders every interpolated VALUE in full).
|
| FIVE RULES RESIST arch() BECAUSE THEY ARE STRING-LEVEL, AND THIS BLOCK USED TO SAY THEY "STAY CI
| GREPS RATHER THAN TESTS". There is no CI — `.github/` was deleted on 2026-08-17 and nothing
| replaced it — so that sentence described an invariant with no enforcement at all, which is worse
| than an untested one because a reader concludes it is mechanically guarded. All five are now in
| tests/Arch/StringLevelDoctrineTest.php, which tokenizes the tree instead of grepping it:
|
|   rg 'withoutGlobalScopes\(' services/core-api/app
|   rg 'ai-api|services\.ai\.url' services/core-api/app | grep -v Services/Internal
|   rg 'toEmbeddings\(|whereVectorSimilarTo\(' services/core-api/
|   rg '\$this->authorize\(|authorizeResource\(' services/core-api/app/Http/Controllers
|   rg 'forceFill\(|forceCreate\(' services/core-api/app
|
| The greps are kept verbatim because they are still how a human checks by hand, and because they
| document what each rule means in one line. They are NOT how the rule is enforced, and they are not
| equivalent: three of the five match their own documentation (this comment block is a hit for four
| of them), which is the false positive that gets a grep ignored and then deleted. A token scan sees
| no comments and no string literals, so the test says nothing about this block.
*/

arch()->preset()->php();        // die, var_dump, debug helpers, deprecated functions
arch()->preset()->security();   // eval, md5/sha1, uniqid, mt_rand, extract — kb-security-baseline
arch()->preset()->laravel();

/*
|--------------------------------------------------------------------------
| `preset → laravel` CRASHES, AND THEREFORE ENFORCES NOTHING
|--------------------------------------------------------------------------
|
| The line above fails on every run with
|
|   Typed property PHPUnit\Architecture\Elements\ObjectDescriptionBase::$path
|   must not be accessed before initialization
|
| and the failure is NOT ours. Measured 2026-08-21, by moving `vendor/laravel/pint/app` aside and
| re-running: with it gone the preset PASSES; restored, it crashes again. The chain:
|
|   1. `laravel/pint` is a dev dependency that ships a whole Laravel application, and its own
|      composer.json maps `"App\\": "app/"`. Composer merges that into the ROOT autoloader:
|      `vendor/composer/autoload_psr4.php` reads
|        'App\\' => array($baseDir.'/app', $vendorDir.'/laravel/pint/app')
|      so the `App` arch layer contains Pint's classes as well as ours.
|   2. Pest tags anything under `vendor/` with `Pest\Arch\Objects\VendorObjectDescription`, whose
|      `make()` sets only `name` and `uses` — never `$path`, never `$reflectionClass`.
|   3. Every POSITIVE arch callback is guarded by `isset($object->reflectionClass)`
|      (`vendor/pestphp/pest/src/Expectation.php` — `toBeEnum`, `toImplement`, `toExtend`, …), so on
|      a vendor object the guard is false and the object is reported as a VIOLATION.
|   4. `Blueprint::targeted()` then renders that violation by reading `$object->path`, which was
|      never initialized. Fatal Error, no assertion, no file.
|
| Three Pint classes trip it: App\Enums\NodePackageManager, App\Exceptions\PrettierException and
| App\Providers\AppServiceProvider.
|
| THE CONSEQUENCE IS THE PART TO CARRY, AND IT IS WORSE THAN "THE RULE IS OFF". The reported
| MESSAGE is always the crash, so no rule in the preset can ever state its own verdict. But the
| reported CODE FRAME is not the crash's — when some other rule in the same preset is genuinely
| violated, the frame points at the real violator's file and line while the message above it is the
| uninitialized-property Error. Measured 2026-08-21, both ways: with a `RuntimeException` subclass
| placed under App\Services\Sources, `preset → laravel` failed at that file's `class` line; with the
| tree clean, the same failure had no frame at all.
|
| That combination is how the two Phase C classes stayed broken for a phase. IllegalSourceTransition
| and UploadRejected DID violate the preset's "no Throwable outside App\Exceptions" rule, the frame
| moved between them as each was edited, and the message said "library bug" the whole time — so the
| frame read as an artifact of the crash rather than as a finding. Both have been moved into
| App\Exceptions, and the rule they broke is restated below in a form that reports itself.
|
| Restating a preset rule here is only possible for NEGATIVE ones: `not->toImplement()` passes
| `! isset($object->reflectionClass) || …`, so a vendor object satisfies it instead of crashing.
| The positive rules (`App\Models` extends Model, `App\Http\Requests` has `rules()`, …) cannot be
| restated this way and stay unenforced until the upstream bug is fixed or Pint stops being
| autoloaded into `App\\`. Do not delete the preset line: when either of those happens it starts
| working again, and its failure is the only signal that it currently does not.
*/
arch('an exception lives in App\Exceptions, where the handler and the reader both look')
    // The Laravel preset's own rule, restated because the preset cannot report it (see above).
    // Verified to FAIL rather than pass vacuously: adding a `RuntimeException` subclass under
    // App\Services\Sources makes this test report that file and line (measured 2026-08-21).
    ->expect('App')
    ->not->toImplement(\Throwable::class)
    ->ignoring('App\Exceptions');

arch('clients never reach FastAPI, so only one class may open a connection to it')
    ->expect('Illuminate\Support\Facades\Http')
    ->toOnlyBeUsedIn('App\Services\Internal');

arch('raw query builders stay where the organization scope is applied')
    ->expect('Illuminate\Support\Facades\DB')
    ->toOnlyBeUsedIn('App\Repositories\Eloquent');

arch('env() is read once, in config')
    // config/ holds no classes, so it is not scanned by arch() — which is exactly what makes this
    // rule expressible as "nowhere". An env() read outside config is a value that is correct until
    // the first `php artisan config:cache`, after which it silently becomes null.
    ->expect('env')
    ->not->toBeUsed();

arch('no test-only bypass exists to be called')
    // kb-tenancy-isolation NN4. Requires Tests\ to be in composer autoload-dev psr-4 — arch() only
    // scans autoloaded namespaces, so without that mapping this rule passes vacuously forever.
    ->expect('Tests')
    ->not->toBeUsedIn('App');

// Every policy is org-scoped BY CONSTRUCTION: OrgScopedPolicy::permit() takes the RECORD, so
// "admin of some organization" is unrepresentable (laravel-rbac-policies NN1).
//
// THE ->ignoring() IS NOT A WEAKENING AND MUST NOT BE REMOVED. `toHaveSuffix('Policy')` matches
// OrgScopedPolicy itself — the abstract base's own name ends in `Policy` — and no class extends
// itself, so without this the rule fails on the very class it exists to enforce. Ignoring the base
// excludes exactly one class, by name, and every concrete policy is still checked.
arch('every policy is org-scoped by construction')
    ->expect('App\Policies')
    ->toHaveSuffix('Policy')
    // Leading backslashes are not decoration: this file is in the GLOBAL namespace, and pint's
    // fully_qualified_strict_types rule is configured with leading_backslash_in_global_namespace,
    // so `App\Policies\OrgScopedPolicy::class` (which resolves identically) fails `composer lint`.
    ->toExtend(\App\Policies\OrgScopedPolicy::class)
    ->ignoring(\App\Policies\OrgScopedPolicy::class);

/*
|--------------------------------------------------------------------------
| Pending rules — uncomment with the code they describe
|--------------------------------------------------------------------------
|
| These iterate a set that is currently EMPTY, and an empty dataset is an error in Pest 5 rather
| than a vacuous pass. They are written out in full so that enabling each one is a one-line
| uncomment on the PR that creates the first case — which is the PR where the rule earns its keep.
|
| // A controller that cannot touch a model cannot skip a policy or an organization scope.
| // arch('a controller cannot touch a model')
| //     ->expect('App\Models')
| //     ->toOnlyBeUsedIn(['App\Repositories\Eloquent', 'App\Policies', 'Database']);
|
| // No credential type can reach a response body.
| // arch('no credential type can reach a response body')
| //     ->expect('App\Http\Resources')
| //     ->not->toUse(['App\Support\Crypto\KeyVault', 'decrypt']);
|
| // The #[ScopedBy] attribute resists arch() because it is an attribute, so it is a reflection
| // test — and it is the one that catches a NEW model, the case a reviewer is least likely to
| // notice. orgOwnedModels() reflects over App\Models for an organization_id column.
| // test('every org-owned model carries the organization scope', function (string $class) {
| //     expect((new ReflectionClass($class))->getAttributes(ScopedBy::class))->not->toBeEmpty();
| // })->with(fn () => orgOwnedModels());
|
| DO NOT "FIX" THE RULE ABOVE BY ENABLING IT UNQUALIFIED. It is not waiting on a first org-owned
| model any more — several exist — it is waiting on an ANNOTATED EXCEPTION LIST, because two models
| hold `organization_id` and deliberately carry no #[ScopedBy]:
|
|   OrganizationUser         read BEFORE a tenant context exists, by the middleware whose job is to
|                            establish one. Scoping it by the context it produces is circular.
|   OrganizationInvitation   read on the GUEST paths — invitation preview, register, accept — before
|                            any organization is known, because the token is what identifies it.
|
| OrganizationScope FAILS CLOSED (`whereRaw('1 = 0')` with no bound context), so "fixing" the rule
| by adding the attribute to those two does not merely over-scope them: it makes every invitation
| lookup return nothing, always, and REGISTRATION BREAKS SILENTLY while the endpoint renders a
| plausible "this invitation is no longer valid". Each model's docblock states this at length.
| Enabling this rule therefore requires the exception list to carry each model's reason inline, in
| the `// tenancy-exempt:` spirit kb-tenancy-isolation already establishes — a bare
| `->ignoring(...)` of two model names would strip exactly the reasoning that keeps them correct.
*/
