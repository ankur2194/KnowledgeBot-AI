<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| kb:dump-form-rules — the gate that makes packages/contracts/rules/ trustworthy
|--------------------------------------------------------------------------
|
| WHY THIS FILE EXISTS. The command was written against an EMPTY app/Http/Requests and was correct
| for exactly as long as that directory stayed empty. It resolved each FormRequest through the
| container, and the container is precisely where Laravel attaches the two hooks that make a
| FormRequest behave like a REQUEST rather than like a rules() container:
|
|   FormRequestServiceProvider::boot()
|     afterResolving(ValidatesWhenResolved::class, fn ($r) => $r->validateResolved())
|     resolving(FormRequest::class, fn ($r, $app) => $r->setRedirector($app->make(Redirector::class)))
|
| So the first FormRequest carrying `required` or `present` broke the command in two INDEPENDENT
| ways, and CI (`php artisan kb:dump-form-rules --check`) went red on both:
|
|   1. validateResolved() validated the empty CLI request and threw ValidationException. Every
|      FormRequest counted as a failure and the command exited 1 before writing or comparing
|      anything.
|   2. setRedirector(Redirector::class) resolves the redirector, which attaches session.store, which
|      with `session.encrypt => true` builds an EncryptedStore and therefore the ENCRYPTER — an
|      incidental APP_KEY requirement on a command that reads nothing but reflection. On a fresh
|      checkout, where .env ships APP_KEY empty, the command died with "No application encryption
|      key has been specified" before it ever reached the validation error above.
|
| Failure mode 2 is the one a test can miss by accident: phpunit.xml pins an APP_KEY for the suite,
| so a test that only asserts an exit code proves nothing about it. Both are asserted separately
| below, because they are two bugs that happened to share a line.
|
| The fix is `$this->laravel->build($class)` — Container::build() fires no resolving callbacks, so
| neither hook runs, while `$this->laravel->call([$request, 'rules'])` on the next line still
| injects rules()'s own dependencies. The document is still dumped from EXECUTING rules(), which is
| the entire reason this command exists rather than a parser over the source.
*/

/**
 * A scratch output directory, unique per test AND per parallel worker.
 *
 * Never the real packages/contracts/rules/: that directory belongs to the publication target CI
 * diffs, and a suite that rewrote it would make `--check` assert that the tests and the tests
 * agree.
 */
function scratchRulesDir(): string
{
    $dir = sys_get_temp_dir().'/kb-form-rules-'.getmypid().'-'.Str::random(8);

    File::ensureDirectoryExists($dir);

    return $dir;
}

/**
 * @return list<class-string<FormRequest>>
 */
function formRequestClassesUnderTest(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path('Http/Requests'))->name('*.php')->sortByName() as $file) {
        $class = 'App\\Http\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (is_subclass_of($class, FormRequest::class)) {
            /** @var class-string<FormRequest> $class */
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * @return array<string, mixed>
 */
function readManifest(string $path): array
{
    $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

    assert(is_array($decoded), "manifest {$path} is not a JSON object");

    return $decoded;
}

afterEach(function (): void {
    foreach (File::directories(sys_get_temp_dir()) as $dir) {
        if (str_starts_with(basename($dir), 'kb-form-rules-'.getmypid().'-')) {
            File::deleteDirectory($dir);
        }
    }
});

it('dumps every FormRequest in the tree, including the ones that require a field', function (): void {
    $classes = formRequestClassesUnderTest();

    // The guard that keeps this test honest. On an empty app/Http/Requests the command exits 0
    // having asserted nothing, which is exactly the state in which the bug was invisible.
    expect($classes)->not->toBeEmpty();

    $dir = scratchRulesDir();

    // FAILURE MODE 1. Before the fix this is exit 1 with one ERROR line per FormRequest:
    //   "App\Http\Requests\StoreProviderConnectionRequest: The provider field is required."
    expect(Artisan::call('kb:dump-form-rules', ['--path' => $dir]))->toBe(0, Artisan::output());

    $required = 0;

    foreach ($classes as $class) {
        $relative = str_replace('\\', '/', substr($class, strlen('App\\Http\\Requests\\'))).'.json';

        expect(File::exists($dir.'/'.$relative))->toBeTrue();

        $manifest = readManifest($dir.'/'.$relative);

        expect($manifest['class'] ?? null)->toBe($class)
            ->and($manifest['rules'] ?? null)->toBeArray();

        $rules = $manifest['rules'];
        assert(is_array($rules));

        foreach ($rules as $list) {
            if (is_array($list) && (in_array('required', $list, true) || in_array('present', $list, true))) {
                $required++;
            }
        }
    }

    // The property that makes the exit code above mean something: the tree really does contain the
    // shape — a mandatory field — that an empty CLI request cannot satisfy. If this ever drops to
    // zero the test above has silently stopped covering the regression, and it should fail loudly
    // rather than keep passing.
    expect($required)->toBeGreaterThan(0);
});

it('validates nothing, so a mandatory field is dumped rather than raised', function (): void {
    // The narrow statement of failure mode 1, independent of how many FormRequests exist. A rule
    // set the command could only obtain by NOT validating is proof the afterResolving hook did not
    // fire — validateResolved() against the empty CLI request would have thrown on this very rule.
    $dir = scratchRulesDir();

    expect(Artisan::call('kb:dump-form-rules', ['--path' => $dir]))->toBe(0, Artisan::output());

    $manifest = readManifest($dir.'/StoreProviderConnectionRequest.json');
    $rules = $manifest['rules'] ?? null;

    assert(is_array($rules));

    expect($rules['provider'] ?? null)->toContain('required');
});

it('resolves neither the redirector nor the encrypter, so it needs no APP_KEY', function (): void {
    // FAILURE MODE 2, asserted by mechanism rather than by symptom. phpunit.xml pins an APP_KEY for
    // the whole suite, so under test the redirector resolves happily and the exit code says
    // nothing — the assertion has to be that the command never asked for it at all.
    //
    // `resolved()` is the honest probe: the container marks an abstract resolved only after the
    // instance is successfully built, so these four names being false AFTER the run means no code
    // path touched session encryption. That is equivalent to "runs with APP_KEY empty", which is
    // the property a fresh checkout actually needs.
    $app = app();

    expect($app->resolved('redirect'))->toBeFalse()
        ->and($app->resolved('encrypter'))->toBeFalse();

    $dir = scratchRulesDir();

    expect(Artisan::call('kb:dump-form-rules', ['--path' => $dir]))->toBe(0, Artisan::output());

    expect($app->resolved('redirect'))->toBeFalse()
        ->and($app->resolved('encrypter'))->toBeFalse()
        ->and($app->resolved('session'))->toBeFalse()
        ->and($app->resolved('session.store'))->toBeFalse();
});

it('still runs when the application key is empty', function (): void {
    // The same property as the test above, asserted as the symptom a fresh checkout sees. Kept
    // alongside it rather than instead of it: this one proves the outcome, the other proves the
    // reason, and a future refactor could break either without breaking the other.
    config(['app.key' => '', 'app.previous_keys' => []]);

    $app = app();
    $app->forgetInstance('encrypter');
    $app->forgetInstance('redirect');
    $app->forgetInstance('session');
    $app->forgetInstance('session.store');

    $dir = scratchRulesDir();

    expect(Artisan::call('kb:dump-form-rules', ['--path' => $dir]))->toBe(0, Artisan::output());

    // Positive control, run AFTER the command so it cannot warm the very instance under test. If
    // this did not throw, the assertion above would be passing against a perfectly usable
    // encrypter and would prove nothing.
    expect(fn (): mixed => app('redirect'))->toThrow(MissingAppKeyException::class);
});

it('is byte-identical across two runs, which is what makes --check a gate', function (): void {
    // A --check that flapped would be worse than no check: the first red build nobody can explain
    // is the one that teaches a team to re-run CI instead of reading it. Key order is ksort'ed and
    // the JSON flags are pinned precisely so this holds.
    $first = scratchRulesDir();
    $second = scratchRulesDir();

    expect(Artisan::call('kb:dump-form-rules', ['--path' => $first]))->toBe(0, Artisan::output())
        ->and(Artisan::call('kb:dump-form-rules', ['--path' => $second]))->toBe(0, Artisan::output());

    $names = array_map(
        static fn (\SplFileInfo $file): string => $file->getFilename(),
        File::allFiles($first),
    );

    sort($names);

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect(File::get($second.'/'.$name))->toBe(File::get($first.'/'.$name));
    }

    expect(array_map(
        static fn (\SplFileInfo $file): string => $file->getFilename(),
        File::allFiles($second),
    ))->toEqualCanonicalizing($names);
});

it('passes --check against its own output and fails on a single changed byte', function (): void {
    $dir = scratchRulesDir();

    expect(Artisan::call('kb:dump-form-rules', ['--path' => $dir]))->toBe(0, Artisan::output())
        ->and(Artisan::call('kb:dump-form-rules', ['--path' => $dir, '--check' => true]))->toBe(0, Artisan::output());

    // Tamper with one rule and the gate must notice. Without this the --check assertion above is
    // satisfied by a command that compares nothing.
    $target = $dir.'/StoreProviderConnectionRequest.json';
    File::put($target, str_replace('"max:120"', '"max:119"', File::get($target)));

    expect(Artisan::call('kb:dump-form-rules', ['--path' => $dir, '--check' => true]))->toBe(1);

    // An orphan — a rule document for a FormRequest that no longer exists — is drift too, and it is
    // the half of the check `git diff --exit-code` cannot see on an untracked tree.
    File::put($target, File::get($dir.'/DesignateEmbeddingConnectionRequest.json'));
    File::put($dir.'/DeletedRequest.json', '{}');

    expect(Artisan::call('kb:dump-form-rules', ['--path' => $dir, '--check' => true]))->toBe(1);
});

it('is registered under the name CI invokes', function (): void {
    // .github/workflows/ci.yml runs `php artisan kb:dump-form-rules --check` by literal name. A
    // rename that compiles is a red build nobody edited.
    expect(array_keys(app(Kernel::class)->all()))->toContain('kb:dump-form-rules');
});
