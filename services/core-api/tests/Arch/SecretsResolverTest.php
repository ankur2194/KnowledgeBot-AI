<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Secret-shaped configuration reads must go through KbSecrets
|--------------------------------------------------------------------------
|
| Security finding B2: the infrastructure supplies secrets as FILES at /run/secrets/<name> and
| Laravel read them as environment VALUES, with nothing bridging the two. The bridge is
| App\Support\Kb\KbSecrets. THIS TEST is what stops the bridge being routed around — it matters
| more than the resolver, because the resolver is a one-time fix and the regression is forever.
|
| The failure it prevents is not subtle once you see it: an implementer meets a missing value and
| satisfies it the obvious way, by putting the material in `env/core-api.env`. `make prod-config`
| is a DOCUMENTED pre-deploy step that runs `docker compose config`, which renders every
| interpolated value IN FULL — so the KEK and both signing keys land in a terminal, a CI log, and
| whatever ticket that output was pasted into. Secrets are files precisely so they appear in no
| config dump and no `docker inspect`.
|
| Two rules, both string-level, which is why they are a token scan rather than an arch() rule:
| arch() reflects over autoloaded CLASSES and config/ holds none.
|
| Adding a name to ALLOWED_SECRET_SHAPED_NAMES is a deliberate, reviewable act and every entry
| carries the reason it is not key material. Adding one without a reason is the thing this test
| exists to make conspicuous.
*/

/**
 * Names that MATCH the secret-shaped pattern but are not secrets. Each entry states why.
 *
 * @var array<string, string>
 */
const ALLOWED_SECRET_SHAPED_NAMES = [
    // APP_KEY and APP_PREVIOUS_KEYS were once allowed here on the grounds that their custody is
    // the framework's. That reasoning did not survive: APP_KEY encrypts sessions and cookies, it
    // is the key behind every `encrypted:` cast — where provider credentials live — and it is the
    // HMAC key behind the audit `key_fingerprint`. Whose variable it is does not change what a
    // `docker compose config` dump prints. Both now resolve through KbSecrets in config/app.php,
    // and this test is what keeps them there.

    // Key IDENTIFIERS. The id is the public selector that rides on the wire in
    // X-KB-Signature = key_id + ":" + hex(...) — it names which key, it is not the key.
    'AI_HMAC_ACTIVE_KEY_ID' => 'the key id this service signs with, e.g. "k1"',
    'AWS_ACCESS_KEY_ID' => 'the S3 access key ID; the paired AWS_SECRET_ACCESS_KEY is resolved',

    // Broker names, table names and durations from Laravel's auth scaffolding.
    'AUTH_PASSWORD_BROKER' => 'the name of a broker defined in config/auth.php',
    'AUTH_PASSWORD_RESET_TOKEN_TABLE' => 'a database table name',
    'AUTH_PASSWORD_TIMEOUT' => 'seconds before a password confirmation expires',

    // A literal, published prefix. Sanctum prepends it to issued tokens so secret scanners can
    // recognise a leaked one — publishing it is the entire point.
    'SANCTUM_TOKEN_PREFIX' => 'the literal prefix stamped on issued Sanctum tokens',

    // An integer label naming which KEK wrapped a stored DEK. The KEK itself is KB_KEK and is
    // resolved through KbSecrets.
    'KB_KEK_VERSION' => 'an integer version tag, not key material',
];

/**
 * A name is secret-shaped if it contains any of these. Substring matching on purpose: a NEW
 * secret nobody has thought of yet is caught by the shape of its name, not by an inventory.
 */
const SECRET_NAME_FRAGMENTS = ['KEY', 'SECRET', 'PASSWORD', 'KEK', 'TOKEN'];

/**
 * Directories scanned. `config/` is where the reads live; the rest are scanned so a read cannot
 * simply move somewhere arch() does not reflect over either.
 *
 * @return list<string>
 */
function kbScannedPhpFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['config', 'app', 'bootstrap', 'routes', 'database'] as $dir) {
        $path = $root.'/'.$dir;

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
 * Every call to the global `env()` helper in a file, as `name => line`.
 *
 * Token-based rather than a regex, because a regex over source also matches the word `env(`
 * inside a comment — and these files are heavily commented, so a comment-driven false positive
 * would get the test disabled within a week. A dynamic first argument is reported as `<dynamic>`
 * and fails the assertion: a name that cannot be read statically cannot be classified.
 *
 * @return list<array{name: string, line: int}>
 */
function kbEnvReads(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));

    /** @var list<array{0: int, 1: string, 2: int}|string> $significant */
    $significant = [];

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $significant[] = $token;
    }

    $reads = [];
    $count = count($significant);

    for ($i = 0; $i < $count; $i++) {
        $token = $significant[$i];

        if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'env') {
            continue;
        }

        // Not a method call, not a static call, not a function declaration named env().
        $previous = $significant[$i - 1] ?? null;

        if (is_array($previous) && in_array($previous[0], [
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW,
        ], true)) {
            continue;
        }

        if (($significant[$i + 1] ?? null) !== '(') {
            continue;
        }

        $argument = $significant[$i + 2] ?? null;

        $reads[] = [
            'name' => is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING
                ? trim($argument[1], "'\"")
                : '<dynamic>',
            'line' => $token[2],
        ];
    }

    return $reads;
}

function kbIsSecretShaped(string $name): bool
{
    foreach (SECRET_NAME_FRAGMENTS as $fragment) {
        if (str_contains($name, $fragment)) {
            return true;
        }
    }

    return false;
}

test('no secret-shaped env() read survives outside the resolver', function (): void {
    $violations = [];

    foreach (kbScannedPhpFiles() as $file) {
        foreach (kbEnvReads($file) as $read) {
            $name = $read['name'];

            if ($name === '<dynamic>') {
                $violations[] = sprintf(
                    '%s:%d — env() called with a non-literal name, which cannot be classified as '
                    .'a secret or a non-secret. Use a literal, or KbSecrets::get().',
                    $file,
                    $read['line'],
                );

                continue;
            }

            if (! kbIsSecretShaped($name)) {
                continue;
            }

            if (array_key_exists($name, ALLOWED_SECRET_SHAPED_NAMES)) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d — env(\'%s\') is secret-shaped and must be read through '
                .'App\Support\Kb\KbSecrets::get(\'%s\') so a mounted %s_FILE / %s_PATH wins over '
                .'an inline value. If it is genuinely not a secret, add it to '
                .'ALLOWED_SECRET_SHAPED_NAMES with the reason.',
                $file,
                $read['line'],
                $name,
                $name,
                $name,
                $name,
            );
        }
    }

    expect($violations)->toBe([]);
});

test('the environment repository is reachable from exactly one class', function (): void {
    // KbSecrets reads Illuminate\Support\Env rather than env() — it is the thing the "no env()
    // outside config" rule points at, not an exception to it. That is only safe while it is the
    // ONLY class doing so: a second one is a second, unaudited way to read raw environment.
    $resolver = dirname(__DIR__, 2).'/app/Support/Kb/KbSecrets.php';
    $offenders = [];

    foreach (kbScannedPhpFiles() as $file) {
        if ($file === $resolver) {
            continue;
        }

        $source = (string) file_get_contents($file);

        if (str_contains($source, 'Illuminate\Support\Env') || str_contains($source, 'Env::get(')) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
});

test('every allow-list entry states why it is not a secret', function (): void {
    foreach (ALLOWED_SECRET_SHAPED_NAMES as $name => $reason) {
        expect(kbIsSecretShaped($name))
            ->toBeTrue("{$name} does not match the secret-name pattern and does not need allowing");
        expect(trim($reason))->not->toBe('');
    }
});
