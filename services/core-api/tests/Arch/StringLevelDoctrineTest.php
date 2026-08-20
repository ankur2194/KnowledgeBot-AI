<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The five string-level doctrines, as tests rather than as greps
|--------------------------------------------------------------------------
|
| Each rule below was a CI grep, listed at the head of tests/Arch/DoctrineTest.php and named in the
| docblock of the class it guards. `.github/` was deleted on 2026-08-17 and nothing replaced it, so
| between that day and this file each of them named a REAL INVARIANT WITH NO ENFORCEMENT — which is
| worse than an untested one, because the comment tells a reader it is mechanically guarded.
|
| They are string-level, which is why arch() cannot express them: arch() reflects over autoloaded
| classes and reasons about TYPES. "This method is never called" and "this literal appears nowhere"
| are properties of the source text.
|
| ── WHY A TOKEN SCAN AND NOT `grep` IN A SHELL ────────────────────────────────────────────────────
|
| The five published greps are honest one-liners for a human and are unusable as a gate, because
| every one of them matches its own documentation. `rg 'withoutGlobalScopes\(' app` returns exactly
| one hit today and it is the sentence in OrganizationScope's docblock explaining why the grep
| exists. `rg 'ai-api'` matches InternalAiClient's opening line. A gate whose first run is four
| false positives is a gate that gets `--exclude`d until it means nothing, and then deleted.
|
| `token_get_all()` removes the whole problem rather than working around it. Comments are dropped,
| so prose about a banned call is not a banned call — which is what lets those docblocks go on
| naming the thing they forbid. And a rule's own needle is written here as a STRING LITERAL, while
| what it looks for is a T_STRING followed by `(`, so this file cannot match itself and needs no
| self-exclusion. That self-tripping shape is what ADR-036 is about.
|
| ── WHAT THIS FILE DOES NOT DO ────────────────────────────────────────────────────────────────────
|
| It does not resolve types. `->forceFill(` on something that is not an Eloquent model is reported
| as a violation, and a `Model::query()->withoutGlobalScopes()` reached through a variable named
| anything at all is reported too. That direction is correct for a ban: the cost of a false positive
| is one annotated allow-list entry, and the cost of a false negative is the invariant.
|
| WHERE tests/ IS SCANNED AND WHERE IT IS NOT, because the two rules differ and the difference is
| deliberate. `withoutGlobalScopes()` is BANNED IN app/ AND EXPECTED IN tests/ — the Security suite
| uses it dozens of times to read the raw row a tenant filter is hiding, which is how it proves the
| filter hid it. The vector-helper rule scans everything, because its published grep does.
*/

/**
 * Directories scanned for the four `app/`-scoped rules.
 *
 * `app/` alone, exactly as each published grep says. A controller, a job or a model that moved to
 * `routes/` or `database/` would not be a controller any more; the one rule whose reach is wider
 * says so at its own call site.
 *
 * @return list<string>
 */
function kbAppPhpFiles(): array
{
    return kbPhpFilesUnder(['app']);
}

/**
 * @param  list<string>  $directories  relative to services/core-api
 * @return list<string>
 */
function kbPhpFilesUnder(array $directories): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach ($directories as $directory) {
        $path = $root.'/'.$directory;

        if (! is_dir($path)) {
            continue;
        }

        /** @var iterable<string, \SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * The significant tokens of a file: whitespace, comments and docblocks removed.
 *
 * THIS IS THE WHOLE MECHANISM. Everything below asks questions of this list, and every false
 * positive the published greps produce is a comment that is not in it.
 *
 * @return list<array{0: int, 1: string, 2: int}|string>
 */
function kbSignificantTokens(string $source): array
{
    $significant = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $significant[] = $token;
    }

    return $significant;
}

/**
 * Every CALL of one of `$names` in `$source`, as `name => line`.
 *
 * A call is a `T_STRING` whose lower-cased text is in the set, followed by `(`. That covers
 * `foo(`, `$x->foo(`, `X::foo(` and `$x?->foo(` alike, and excludes a declaration (`function foo(`)
 * and an instantiation (`new foo(`), neither of which is the thing any of these rules bans.
 *
 * The comparison is case-insensitive because PHP method names are, so `ForceFill(` is the same
 * call and would otherwise be the bypass.
 *
 * @param  list<string>  $names  lower-cased
 * @return list<array{name: string, line: int}>
 */
function kbCallsTo(string $source, array $names): array
{
    $tokens = kbSignificantTokens($source);
    $count = count($tokens);
    $calls = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        if (! in_array(strtolower($token[1]), $names, true)) {
            continue;
        }

        if (($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }

        $previous = $tokens[$i - 1] ?? null;

        if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_NEW], true)) {
            continue;
        }

        $calls[] = ['name' => $token[1], 'line' => $token[2]];
    }

    return $calls;
}

/**
 * Every `$this->method(` call in `$source` whose method is in `$names`.
 *
 * Separate from kbCallsTo() because `authorize` is a name the codebase legitimately uses — the
 * FormRequest hook is called `authorize()` and every one of them declares it. What is banned is
 * calling `$this->authorize(...)` ON A CONTROLLER, which fatals with "Call to undefined method"
 * because Laravel's base controller has not used AuthorizesRequests since 11. Matching the receiver
 * is what separates the two, and matching only `$this` is deliberate: the trait can only be
 * inherited, so no other receiver expresses the mistake.
 *
 * @param  list<string>  $names  lower-cased
 * @return list<array{name: string, line: int}>
 */
function kbThisCallsTo(string $source, array $names): array
{
    $tokens = kbSignificantTokens($source);
    $count = count($tokens);
    $calls = [];

    for ($i = 2; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        if (! in_array(strtolower($token[1]), $names, true)) {
            continue;
        }

        if (($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }

        $arrow = $tokens[$i - 1] ?? null;
        $receiver = $tokens[$i - 2] ?? null;

        $isArrow = is_array($arrow)
            && in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

        if (! $isArrow) {
            continue;
        }

        if (! is_array($receiver) || $receiver[0] !== T_VARIABLE || $receiver[1] !== '$this') {
            continue;
        }

        $calls[] = ['name' => $token[1], 'line' => $token[2]];
    }

    return $calls;
}

/**
 * Every string literal in `$source` containing any of `$needles`, as `value => line`.
 *
 * Both encapsed forms, because `"…{$x}…"` splits into T_ENCAPSED_AND_WHITESPACE pieces and a
 * hostname interpolated into a URL string is exactly the shape this rule is looking for.
 *
 * @param  list<string>  $needles
 * @return list<array{value: string, line: int}>
 */
function kbStringLiteralsContaining(string $source, array $needles): array
{
    $hits = [];

    foreach (kbSignificantTokens($source) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if (! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        foreach ($needles as $needle) {
            if (str_contains($token[1], $needle)) {
                $hits[] = ['value' => $token[1], 'line' => $token[2]];

                break;
            }
        }
    }

    return $hits;
}

/**
 * A file path as `app/Foo/Bar.php`, so a failure message is readable and stable across checkouts.
 */
function kbRelativePath(string $absolute): string
{
    $root = dirname(__DIR__, 2).'/';

    return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
}

/*
|--------------------------------------------------------------------------
| The scanner's own positive control
|--------------------------------------------------------------------------
|
| EVERY RULE BELOW IS AN ABSENCE ASSERTION, so all five pass against a scanner that finds nothing —
| a tokenizer fed the wrong path, a needle list that went empty, a receiver test that matches no
| receiver. That is the same failure shape pest-testing NN2 names for the isolation suite, and it
| needs the same defence: prove the matcher FIRES before trusting it not to.
|
| The fixture carries each banned construct twice — once as code and once inside a comment — so this
| test also pins the property the whole file rests on: prose about a banned call is not a call.
*/
test('the token scanner finds each banned construct in code and ignores it in a comment', function (): void {
    $fixture = <<<'PHP'
    <?php
    // withoutGlobalScopes() in a line comment, and $this->authorize() and forceFill() too.
    /** A docblock naming forceCreate() and 'services.ai.url' and toEmbeddings(). */
    final class Probe extends Controller
    {
        public function handle(): void
        {
            Bot::query()->withoutGlobalScopes()->get();
            $model->forceFill(['organization_id' => 'x']);
            Bot::forceCreate([]);
            $this->authorize('update', $model);
            $this->authorizeResource(Bot::class);
            Http::get('http://ai-api:8000/internal/v1/probe');
            $q->toEmbeddings();
        }
    }
    PHP;

    expect(kbCallsTo($fixture, ['withoutglobalscopes']))->toHaveCount(1)
        ->and(kbCallsTo($fixture, ['forcefill', 'forcecreate']))->toHaveCount(2)
        ->and(kbThisCallsTo($fixture, ['authorize', 'authorizeresource']))->toHaveCount(2)
        ->and(kbCallsTo($fixture, ['toembeddings', 'wherevectorsimilarto']))->toHaveCount(1)
        ->and(kbStringLiteralsContaining($fixture, ['ai-api']))->toHaveCount(1);

    // AND THE OTHER DIRECTION, which is the property that makes the docblocks legal. The fixture's
    // first three lines name every banned construct; none of them is a hit.
    $commentsOnly = "<?php\n".implode("\n", array_slice(explode("\n", $fixture), 1, 2));

    expect(kbCallsTo($commentsOnly, ['withoutglobalscopes', 'forcefill', 'forcecreate', 'toembeddings']))->toBe([])
        ->and(kbThisCallsTo($commentsOnly, ['authorize', 'authorizeresource']))->toBe([])
        ->and(kbStringLiteralsContaining($commentsOnly, ['services.ai.url']))->toBe([]);

    // And the scanner is actually being pointed at a tree. A rule that scanned zero files would
    // pass every assertion below.
    expect(kbAppPhpFiles())->not->toBeEmpty('no PHP file was found under app/, so nothing was scanned');
});

/*
|--------------------------------------------------------------------------
| 1. withoutGlobalScopes() — kb-tenancy-isolation
|--------------------------------------------------------------------------
*/
test('no application code removes the global scopes', function (): void {
    // `withoutGlobalScope` (singular) is banned alongside the plural, which is one name MORE than
    // the published grep carried. It takes a scope name, and the name it would be given is
    // OrganizationScope — so the narrower spelling is the one that removes the tenant filter
    // deliberately rather than by accident, and leaving it out would have been an odd place to draw
    // the line.
    $violations = [];

    foreach (kbAppPhpFiles() as $file) {
        foreach (kbCallsTo((string) file_get_contents($file), ['withoutglobalscopes', 'withoutglobalscope']) as $call) {
            $violations[] = sprintf('%s:%d — %s()', kbRelativePath($file), $call['line'], $call['name']);
        }
    }

    expect($violations)->toBe([], implode("\n", array_merge(
        ['a query in app/ removes its global scopes, which removes the tenant filter with them:'],
        $violations,
        [
            'OrganizationScope is the BACKSTOP behind the explicit forOrg($orgId) argument. A query '
            .'that drops it is scoped by nothing at all, and the failure is a successful query that '
            .'returns every tenant\'s rows. tests/Security/ uses this method deliberately and is not '
            .'scanned — reading the raw row is how it proves the filter hid it.',
        ],
    )));
});

/*
|--------------------------------------------------------------------------
| 2. forceFill() / forceCreate() — the strict-model bypass
|--------------------------------------------------------------------------
*/
test('the two writes that bypass strict Eloquent stay where they are annotated', function (): void {
    /**
     * The complete allow-list, as `relative path => number of calls`.
     *
     * ONE ENTRY, AND IT IS COUNTED RATHER THAN NAMED, so a second forceFill() added to the SAME
     * file is a failure too — a per-file exemption that admits any number of calls is how an
     * allow-list stops being one. Each entry states why the bypass is correct there.
     *
     * PasswordResetService  the broker's reset closure. `password` IS fillable; forceFill() says
     *                       the write is not a user-supplied payload, and the same call rotates
     *                       `remember_token` because setRememberToken() returns void and cannot be
     *                       chained into ->save(). Read the call site: it argues both halves.
     */
    $allowed = ['app/Services/Auth/PasswordResetService.php' => 1];

    $found = [];

    foreach (kbAppPhpFiles() as $file) {
        $calls = kbCallsTo((string) file_get_contents($file), ['forcefill', 'forcecreate']);

        if ($calls !== []) {
            $found[kbRelativePath($file)] = count($calls);
        }
    }

    ksort($found);

    // EXACT EQUALITY IN BOTH DIRECTIONS. A new call site fails, and so does the DISAPPEARANCE of
    // the pinned one — because an allow-list entry for a call that no longer exists is an
    // exemption nobody is watching, and the next forceFill() added to that file inherits it.
    expect($found)->toBe($allowed, implode("\n", [
        'the forceFill()/forceCreate() call sites in app/ are not the annotated set.',
        'found:   '.json_encode($found),
        'allowed: '.json_encode($allowed),
        'Both bypass Model::shouldBeStrict(), and the half that is security-relevant is '
        .'preventSilentlyDiscardingAttributes(): organization_id is guarded on every model, so a '
        .'PATCH that over-posts it is silently dropped without strict mode. A new call site needs '
        .'an entry here with the reason it is not a mass-assignment hole.',
    ]));
});

/*
|--------------------------------------------------------------------------
| 3. $this->authorize() / authorizeResource() — laravel-rbac-policies
|--------------------------------------------------------------------------
*/
test('no controller authorizes through a trait the base controller does not use', function (): void {
    // SCANNED OVER ALL OF app/ AND NOT ONLY app/Http/Controllers, which is one directory wider than
    // the published grep. The fatal is a property of the CLASS not using the trait, not of where the
    // file lives, and a controller-shaped action class outside that directory fails identically.
    $violations = [];

    foreach (kbAppPhpFiles() as $file) {
        foreach (kbThisCallsTo((string) file_get_contents($file), ['authorize', 'authorizeresource']) as $call) {
            $violations[] = sprintf('%s:%d — $this->%s()', kbRelativePath($file), $call['line'], $call['name']);
        }
    }

    expect($violations)->toBe([], implode("\n", array_merge(
        ['a class calls $this->authorize() or $this->authorizeResource():'],
        $violations,
        [
            'Since Laravel 11 the base controller does not use AuthorizesRequests, so both are '
            .'"Call to undefined method" AT RUNTIME — not at analysis time and not in review. Use '
            .'Gate::authorize(). Re-adding the trait is worse than the fatal: half the controllers '
            .'would authorize through an inherited trait and half through the facade, and the two '
            .'drift.',
        ],
    )));
});

/*
|--------------------------------------------------------------------------
| 4. the AI service's address — kb-architecture-map NN3
|--------------------------------------------------------------------------
*/
test('only App\\Services\\Internal knows where the AI service lives', function (): void {
    // The arch rule beside this one pins the Http FACADE to that namespace. This pins the ADDRESS,
    // which is the half a raw stream, a Guzzle client constructed by hand, or a queued job building
    // a URL would get past.
    $violations = [];

    foreach (kbAppPhpFiles() as $file) {
        $relative = kbRelativePath($file);

        if (str_starts_with($relative, 'app/Services/Internal/')) {
            continue;
        }

        foreach (kbStringLiteralsContaining((string) file_get_contents($file), ['ai-api', 'services.ai.url']) as $hit) {
            $violations[] = sprintf('%s:%d — %s', $relative, $hit['line'], $hit['value']);
        }
    }

    expect($violations)->toBe([], implode("\n", array_merge(
        ['a literal naming the AI service escaped App\\Services\\Internal:'],
        $violations,
        [
            'Everything that makes an internal call correct — HMAC signing, deadline propagation, '
            .'the org metadata, the retry ban — lives in InternalAiClient. A second caller that '
            .'knows the address has none of it, and the request will be REFUSED at the FastAPI end '
            .'for a missing signature, which reads as an outage rather than as a layering bug.',
        ],
    )));
});

/*
|--------------------------------------------------------------------------
| 5. no vector search in the control plane — ADR-030 / kb-architecture-map
|--------------------------------------------------------------------------
*/
test('the control plane runs no vector search of its own', function (): void {
    // THE ONE RULE WHOSE REACH IS THE WHOLE SERVICE, because its published grep is
    // `rg … services/core-api/` — a helper of this shape appearing in a test, a seeder or a route
    // file is the same architectural mistake as one in app/. Qdrant is the data plane's, and the
    // four mandatory payload filters are expressible only there.
    //
    // This file names both methods and is scanned like any other: they are string literals here and
    // the scanner looks for calls, so there is no self-exclusion to get wrong.
    $violations = [];

    foreach (kbPhpFilesUnder(['app', 'bootstrap', 'config', 'database', 'routes', 'tests']) as $file) {
        foreach (kbCallsTo((string) file_get_contents($file), ['toembeddings', 'wherevectorsimilarto']) as $call) {
            $violations[] = sprintf('%s:%d — %s()', kbRelativePath($file), $call['line'], $call['name']);
        }
    }

    expect($violations)->toBe([], implode("\n", array_merge(
        ['a vector-search helper appeared in the control plane:'],
        $violations,
        [
            'Laravel owns identity, tenancy and configuration; retrieval is FastAPI\'s and every '
            .'Qdrant query must carry all four payload filters (kb-tenancy-isolation NN3). A '
            .'convenience helper here is a query built where those terms are not available.',
        ],
    )));
});
