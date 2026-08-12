<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Dump every FormRequest's rule set to packages/contracts/rules/ as JSON.
 *
 * WHY THIS EXISTS. The OpenAPI document in packages/contracts/ is generated, never hand-edited, so
 * the FormRequest is the single place a request rule can change. This command is what makes that
 * enforceable: CI runs it and fails on a diff, so changing a validation rule without regenerating
 * the contract is a red build rather than a client that 422s in production on a field it was never
 * told about.
 *
 * DETERMINISM IS THE WHOLE POINT. Two things guarantee it and both are load-bearing:
 *   - keys are sorted (ksort), because reflection order follows source order and a reordered method
 *     would otherwise produce a diff on an unrelated PR;
 *   - JSON flags are pinned to JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES, because the default
 *     escapes every `/` and a single-line blob makes every diff whole-file.
 * Rule ORDER INSIDE a field is NOT sorted — `bail|required|string` is semantic, and sorting it
 * would silently change what the rule means.
 *
 * With zero FormRequests this writes zero files, creates no directory, and exits 0. That is the
 * correct behaviour on an empty tree — and it is also the reason a real defect lived here
 * unnoticed: passing on an empty directory is not evidence of anything. See the comment on
 * `build()` in documentFor(), and tests/Feature/DumpFormRulesCommandTest.php, which asserts the
 * tree is NOT empty before asserting the exit code.
 *
 * The class carries the `Command` suffix because arch()->preset()->laravel() asserts it for
 * everything in App\Console\Commands. The artisan signature — the part that is actually a
 * contract — is unchanged: `kb:dump-form-rules`.
 */
final class DumpFormRulesCommand extends Command
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    protected $signature = 'kb:dump-form-rules
                            {--path= : Output directory (defaults to <repo>/packages/contracts/rules)}
                            {--check : Do not write; exit non-zero if any file would change}';

    protected $description = 'Dump every FormRequest rule set to packages/contracts/rules as JSON.';

    public function handle(): int
    {
        $sourceDir = app_path('Http/Requests');

        // The output directory belongs to packages/contracts/, which this service does not own and
        // must not create speculatively.
        $outputDir = rtrim(
            (string) ($this->option('path') ?? '') ?: dirname(base_path(), 2).'/packages/contracts/rules',
            '/',
        );

        if (! is_dir($sourceDir)) {
            $this->components->info('No app/Http/Requests directory; nothing to dump.');

            return self::SUCCESS;
        }

        /** @var array<string, string> $documents relative file path => JSON body */
        $documents = [];
        $failures = 0;

        foreach ($this->formRequestClasses($sourceDir) as $class) {
            try {
                $documents[$this->relativePathFor($class)] = $this->documentFor($class);
            } catch (Throwable $e) {
                // A FormRequest whose rules() needs a resolved route or an authenticated user
                // cannot be dumped, and that is a design smell worth surfacing rather than
                // skipping: the contract is then unknowable without a live request.
                $this->components->error("{$class}: {$e->getMessage()}");
                $failures++;
            }
        }

        if ($failures > 0) {
            return self::FAILURE;
        }

        ksort($documents, SORT_STRING);

        if ($documents === []) {
            $this->components->info('No FormRequests found; wrote 0 files.');

            return self::SUCCESS;
        }

        return $this->option('check') === true
            ? $this->verify($outputDir, $documents)
            : $this->write($outputDir, $documents);
    }

    /**
     * @return list<class-string<FormRequest>>
     */
    private function formRequestClasses(string $sourceDir): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in($sourceDir)->name('*.php')->sortByName() as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'App\\Http\\Requests\\'.$relative;

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(FormRequest::class)) {
                continue;
            }

            /** @var class-string<FormRequest> $class */
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * @param  class-string<FormRequest>  $class
     */
    private function documentFor(string $class): string
    {
        // BUILD, NEVER MAKE. `make()` runs the FormRequest's VALIDATION, and there is nothing to
        // validate against out here.
        //
        // FormRequestServiceProvider::boot() attaches two container hooks:
        //     afterResolving(ValidatesWhenResolved::class, fn ($r) => $r->validateResolved())
        //     resolving(FormRequest::class,  ... ->setRedirector($app->make(Redirector::class)))
        // Container::resolve() fires both (Container.php:955) AFTER delegating construction to
        // build() (Container.php:937); build() fires neither — only attribute callbacks. So the
        // single word here is what separates "instantiate a FormRequest" from "handle a request",
        // and both hooks broke this command the moment the first real FormRequest landed:
        //
        //   validateResolved()  validated the EMPTY CLI request, so every FormRequest carrying
        //                       `required` or `present` threw ValidationException, counted as a
        //                       failure, and exited 1 before anything was written or compared.
        //   setRedirector()     resolves the redirector, which attaches session.store, which with
        //                       `session.encrypt => true` builds an EncryptedStore and therefore
        //                       the encrypter. That is an incidental APP_KEY requirement on a
        //                       command that reads nothing but reflection — and a fresh checkout
        //                       ships APP_KEY empty, so it died with "No application encryption key
        //                       has been specified" before it even reached the validation error.
        //
        // The cost is exactly one thing: a container BINDING for a FormRequest class would be
        // ignored here. Nothing binds one, and a dump that honoured a test double would be
        // describing a contract no request ever meets.
        //
        // getInstance() rather than $this->laravel because build() lives on the concrete
        // Illuminate\Container\Container and not on the Contracts\Foundation\Application interface
        // the Command property is typed as. Same object either way — Application::__construct
        // registers itself as the container instance.
        /** @var FormRequest $request */
        $request = Container::getInstance()->build($class);

        // rules() itself still goes through the container, not `$request->rules()`, so a rules()
        // method may type-hint its own dependencies — the document is dumped from EXECUTING
        // rules(), which is the whole point of this command, and build() does not take that away.
        //
        // PHPStan cannot prove the callable: `rules()` is a Laravel CONVENTION, not a method any
        // interface on FormRequest declares, so `array{FormRequest, 'rules'}` is not provably a
        // `callable`. Its existence is guaranteed one layer up instead — Pest's `laravel` arch
        // preset asserts `expect('App\Http\Requests')->toHaveMethod('rules')`, which fails the
        // suite before this line could ever be reached with a FormRequest that lacks it.
        // @phpstan-ignore argument.type
        $rules = $this->laravel->call([$request, 'rules']);

        if (! is_array($rules)) {
            // Not defensive noise: this is what makes the ignore above honest. The container
            // returns mixed, so without this the `foreach` below would be iterating an unchecked
            // value and every downstream type would be a guess.
            throw new RuntimeException("{$class}::rules() did not return an array.");
        }

        $normalized = [];

        foreach ($rules as $field => $rule) {
            $normalized[(string) $field] = $this->normalize($rule);
        }

        ksort($normalized, SORT_STRING);

        return json_encode([
            'class' => $class,
            'rules' => $normalized,
        ], self::JSON_FLAGS)."\n";
    }

    /**
     * Rule objects and closures have no stable string form, so they are recorded by class name.
     * A rule that renders as `Closure` is a rule no client can be generated from — treat it as a
     * finding, not as noise.
     */
    private function normalize(mixed $rule): mixed
    {
        if (is_array($rule)) {
            // Order preserved deliberately: `bail|required|string` is semantic.
            return array_values(array_map(fn (mixed $r): mixed => $this->normalize($r), $rule));
        }

        if (is_string($rule) || is_int($rule) || is_bool($rule) || $rule === null) {
            return $rule;
        }

        if (is_object($rule)) {
            return method_exists($rule, '__toString') ? (string) $rule : $rule::class;
        }

        return get_debug_type($rule);
    }

    /**
     * @param  class-string<FormRequest>  $class
     */
    private function relativePathFor(string $class): string
    {
        return str_replace('\\', '/', substr($class, strlen('App\\Http\\Requests\\'))).'.json';
    }

    /**
     * @param  array<string, string>  $documents
     */
    private function write(string $outputDir, array $documents): int
    {
        foreach ($documents as $relative => $json) {
            $target = $outputDir.'/'.$relative;

            if (! is_dir($dir = dirname($target)) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                $this->components->error("Could not create {$dir}");

                return self::FAILURE;
            }

            file_put_contents($target, $json);
        }

        // Prune orphans, or a deleted FormRequest leaves a contract file describing an endpoint
        // that no longer exists and the CI diff never notices.
        foreach ($this->existingDocuments($outputDir) as $relative) {
            if (! array_key_exists($relative, $documents)) {
                unlink($outputDir.'/'.$relative);
                $this->components->warn("pruned {$relative}");
            }
        }

        $this->components->info(sprintf('Wrote %d rule document(s) to %s', count($documents), $outputDir));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $documents
     */
    private function verify(string $outputDir, array $documents): int
    {
        $drift = [];

        foreach ($documents as $relative => $json) {
            $target = $outputDir.'/'.$relative;

            if (! is_file($target) || file_get_contents($target) !== $json) {
                $drift[] = $relative;
            }
        }

        foreach ($this->existingDocuments($outputDir) as $relative) {
            if (! array_key_exists($relative, $documents)) {
                $drift[] = $relative.' (orphan)';
            }
        }

        if ($drift !== []) {
            $this->components->error('Form rules are out of date. Run: php artisan kb:dump-form-rules');

            foreach ($drift as $relative) {
                $this->line('  '.$relative);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function existingDocuments(string $outputDir): array
    {
        if (! is_dir($outputDir)) {
            return [];
        }

        $found = [];

        foreach (Finder::create()->files()->in($outputDir)->name('*.json')->sortByName() as $file) {
            $found[] = $file->getRelativePathname();
        }

        return $found;
    }
}
