<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

/*
|--------------------------------------------------------------------------
| The `App\` namespace has exactly one directory in it
|--------------------------------------------------------------------------
|
| THIS FILE GUARDS THE ARCH TESTS THEMSELVES, which is why it is here rather than in Feature.
| `docs/22` § Q15 is the episode: `laravel/pint` is a `require-dev` package that ships a whole
| Laravel application, and its own composer.json maps `"App\\": "app/"`. Composer merges that into
| the ROOT autoloader, so `vendor/composer/autoload_psr4.php` reads
|
|     'App\\' => array($baseDir . '/app', $vendorDir . '/laravel/pint/app')
|
| and the `App` arch layer contains Pint's classes as well as ours. Pest tags anything under
| `vendor/` with `VendorObjectDescription`, whose `make()` sets neither `$path` nor
| `$reflectionClass`; every POSITIVE arch callback is guarded by `isset($object->reflectionClass)`,
| so a vendor object is reported as a VIOLATION; and `Blueprint::targeted()` then renders that
| violation by reading `$object->path`, which was never initialized. Fatal Error, no assertion.
|
| THE FAILURE MODE IS WHAT MAKES THIS WORTH A TEST RATHER THAN A COMMENT, and it is not "the rule
| is off". The reported MESSAGE is always the crash, so no rule in the preset can state its own
| verdict — but the reported CODE FRAME is the real violator's. A genuine violation therefore shows
| up as a library-bug message pointing at a real file, and the frame reads as an artifact of the
| crash. Two Phase C exception classes stayed broken for a whole phase that way, with the finding
| on screen the entire time.
|
| ═══ MEASURED 2026-08-25: THE CRASH IS NOT PRESENT IN THIS TREE, AND THE CAUSE STILL IS ═════════
|
| `arch()->preset()->laravel()` passes, and it is NOT passing vacuously — verified with a positive
| control: a class under `App\Jobs` that does not implement `ShouldQueue` fails it with the right
| message at the right line, and the failure disappears when the class does. The positive rules
| Q15 called permanently inert are live.
|
| What resolved it is not a fix anyone made. `laravel/pint` v1.30.4 is installed from **dist**, and
| its dist archive export-ignores `app/`, so the directory the autoloader points at DOES NOT EXIST
| and Pest finds nothing to walk. The `App\` prefix still maps to two entries and always did.
|
| So the hazard is latent, not gone, and it comes back through routes nobody would connect to an
| arch test: `composer install --prefer-source`, a `composer.json` losing `preferred-install: dist`,
| a Pint release that stops export-ignoring `app/`, or a vendor tree restored from a source install.
| `config.preferred-install: dist` in `services/core-api/composer.json` is load-bearing for the
| arch suite and reads like a download-speed preference. This test is what says otherwise.
|
| ═══ WHY THIS SHAPE AND NOT THE OTHER TWO ═══════════════════════════════════════════════════════
|
| Moving Pint to its own tool project (a second composer.json + lock under `tools/`) is the real
| fix and is a build-and-image change, not a test change. `->ignoring('App\…')` is NOT available as
| a workaround and the reason is worth recording: Pint's own tree contains `App\Enums`,
| `App\Exceptions` and `App\Providers`, which are OUR namespaces too, so every `ignoring()` broad
| enough to exclude Pint's classes also blinds the rule to ours. A guard that reports the condition
| is strictly better than an exclusion that hides it.
*/

/** The package root. `base_path()` is unavailable here: arch tests do not boot the application. */
function kbCoreApiPath(string $path = ''): string
{
    return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
}

/**
 * Collapse `.` and `..` lexically. NOT `realpath()`: half the paths under test do not exist, and
 * realpath() returns `false` for those — which would silently merge every absent entry into one.
 */
function kbCanonical(string $path): string
{
    $out = [];

    foreach (explode('/', $path) as $segment) {
        if ($segment === '..') {
            array_pop($out);
        } elseif ($segment !== '.') {
            $out[] = $segment;
        }
    }

    return implode('/', $out);
}

/** @return array<string, list<string>> */
function kbPsr4Map(): array
{
    // The LIVE loader, not the generated file: the file is what Composer wrote, the loader is what
    // Pest actually walks, and an `autoload_psr4.php` edited by hand would satisfy the wrong one.
    $loaders = ClassLoader::getRegisteredLoaders();
    $loader = reset($loaders);

    // `reset()` on an empty array returns false, and an empty loader registry would make every
    // assertion below vacuously true — the exact shape this file exists to refuse.
    expect($loader)->toBeInstanceOf(ClassLoader::class, 'no Composer ClassLoader is registered');
    assert($loader instanceof ClassLoader);

    return $loader->getPrefixesPsr4();
}

it('gives the App\\ arch layer exactly one directory on disk, and it is ours', function (): void {
    // THE ASSERTION IS ABOUT WHAT EXISTS, NOT WHAT IS REGISTERED, and the difference is the whole
    // finding. `App\` is registered against two directories in this tree and always has been; only
    // one of them is on disk, and a directory Pest cannot open contributes no objects to walk. So
    // the registration is the hazard and the materialization is the failure, and this asserts the
    // failure — it goes red the moment pint's app/ appears, which is the moment the preset breaks.
    $present = array_values(array_filter(
        array_map(kbCanonical(...), (array) (kbPsr4Map()['App\\'] ?? [])),
        is_dir(...),
    ));

    expect($present)->toBe(
        [kbCanonical(kbCoreApiPath('app'))],
        "The App\\ arch layer is no longer just our app/ directory:\n  - ".implode("\n  - ", $present)."\n\n".
        "A dev dependency has merged its own application into our namespace. Every POSITIVE rule in\n".
        "arch()->preset()->laravel() will now crash with an uninitialized-property Error — and the\n".
        "crash prints ITS message over the REAL violator's code frame, so the next genuine finding\n".
        "will read as a library bug. See this file's header.\n".
        'The usual cause is an install that is not --prefer-dist.'
    );
});

it('accounts for every registered PSR-4 directory that is absent from disk', function (): void {
    // The silent half of the same hazard, and the reason § Q15's measurement no longer reproduces.
    // A prefix pointing at an absent directory enforces nothing and reports as a clean pass, which
    // is indistinguishable from enforcing everything and finding nothing. So the absent set is
    // pinned rather than merely allowed: it may not grow, and it may not shrink either.
    $missing = [];

    foreach (kbPsr4Map() as $prefix => $dirs) {
        foreach ((array) $dirs as $dir) {
            if (! is_dir((string) $dir)) {
                $missing[] = $prefix.' => '.kbCanonical((string) $dir);
            }
        }
    }

    sort($missing);

    $pint = kbCanonical(kbCoreApiPath('vendor/laravel/pint'));

    expect($missing)->toBe(
        [
            // All three come from laravel/pint's own composer.json autoload block. Its dist archive
            // export-ignores the whole application, so Composer registers three prefixes against
            // directories the package does not ship. Only the App\ one is dangerous.
            'App\\ => '.$pint.'/app',
            'Database\\Factories\\ => '.$pint.'/database/factories',
            'Database\\Seeders\\ => '.$pint.'/database/seeders',
        ],
        "The set of registered-but-absent PSR-4 directories changed:\n  - ".implode("\n  - ", $missing)."\n\n".
        "GAINED an entry: some package now autoloads a directory it does not ship. Harmless unless\n".
        "the prefix collides with one of ours — check that first.\n".
        "LOST the pint App\\ entry: pint's app/ is on disk now. Go read the previous test's message,\n".
        'because the arch preset is about to start crashing.'
    );
});

it('pins preferred-install to dist, which the arch suite depends on', function (): void {
    // Not a download-speed setting. --prefer-source materializes vendor/laravel/pint/app and
    // takes the whole Laravel preset down with it.
    $composer = json_decode(
        (string) file_get_contents(kbCoreApiPath('composer.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($composer['config']['preferred-install'] ?? null)->toBe('dist');
});
