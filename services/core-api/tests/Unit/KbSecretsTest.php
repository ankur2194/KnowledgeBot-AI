<?php

declare(strict_types=1);

use App\Exceptions\SecretUnavailableException;
use App\Support\Kb\KbSecrets;
use Tests\Support\KbSecretFixtures;

/*
|--------------------------------------------------------------------------
| KbSecrets — file-over-value secret resolution
|--------------------------------------------------------------------------
|
| Unit/ boots nothing: no container, no database, no facades. KbSecrets is deliberately a plain
| static class for exactly that reason — it runs while config is being BUILT, before a container
| exists, so it cannot depend on one.
|
| The two assertions that matter most are the two the finding turns on: THE FILE WINS OVER THE
| INLINE VALUE, and AN EMPTY FILE THROWS RATHER THAN DEGRADING TO NULL.
|
| Fixture state lives in Tests\Support\KbSecretFixtures — static rather than on $this, because
| phpunit.xml sets failOnDeprecation="true" and a dynamic property on the test case is a PHP 8.2
| deprecation. It sits in tests/Support/ and not inline here because `Tests\` is mapped PSR-4 to
| ./tests, so a class declared in this file made `composer install` print a "does not comply with
| psr-4 autoloading standard. Skipping." warning on every single run.
*/

/**
 * Set a raw environment variable the way `Illuminate\Support\Env` reads them. Its repository is
 * immutable, so writing through it is refused; the superglobals its adapters read are not, and the
 * reader consults them on every call.
 */
function kbSetEnv(string $name, string $value): void
{
    KbSecretFixtures::$names[] = $name;
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
}

function kbSecretFile(string $basename, string $contents): string
{
    $path = KbSecretFixtures::$dir.'/'.$basename;
    file_put_contents($path, $contents);

    return $path;
}

beforeEach(function (): void {
    KbSecrets::flush();

    KbSecretFixtures::$dir = sys_get_temp_dir().'/kb-secrets-'.bin2hex(random_bytes(8));
    KbSecretFixtures::$names = [];

    mkdir(KbSecretFixtures::$dir, 0o700, true);
});

afterEach(function (): void {
    foreach (KbSecretFixtures::$names as $name) {
        unset($_SERVER[$name], $_ENV[$name]);
    }

    KbSecrets::flush();

    foreach (glob(KbSecretFixtures::$dir.'/*') ?: [] as $leftover) {
        @unlink($leftover);
    }

    @rmdir(KbSecretFixtures::$dir);

    KbSecretFixtures::$dir = '';
    KbSecretFixtures::$names = [];
});

it('falls back to the inline environment value when no pointer is set', function (): void {
    // Local development: no secrets are mounted and the plain env value is the norm. This is the
    // case that must keep working, or nobody can run the application on a laptop.
    kbSetEnv('KB_TEST_SECRET', 'from-env');

    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('from-env');
});

it('returns the default when nothing is set at all', function (): void {
    expect(KbSecrets::get('KB_TEST_ABSENT', 'fallback'))->toBe('fallback');
    expect(KbSecrets::get('KB_TEST_ABSENT_TOO'))->toBeNull();
});

it('reads the file named by <NAME>_FILE', function (): void {
    kbSetEnv('KB_TEST_SECRET_FILE', kbSecretFile('kek', 'material-from-file'));

    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('material-from-file');
});

it('reads the file named by <NAME>_PATH', function (): void {
    // infrastructure/docker/env/core-api.env.example ships KB_KEK_PATH while the Docker
    // official-image convention is POSTGRES_PASSWORD_FILE. Both spellings are supported; dropping
    // either would have meant renaming half the estate.
    kbSetEnv('KB_TEST_SECRET_PATH', kbSecretFile('kek', 'material-from-path'));

    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('material-from-path');
});

it('prefers the file over the inline value when both are set', function (): void {
    // THE PRECEDENCE ASSERTION. A mounted secret is a deliberate act; a leftover line in a `.env`
    // is not. If the value won, a stale `.env` would silently outrank a rotated secret and the
    // symptom would be a 401 on a request that looks correct in every log.
    kbSetEnv('KB_TEST_SECRET_FILE', kbSecretFile('kek', 'from-file'));
    kbSetEnv('KB_TEST_SECRET', 'from-env');

    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('from-file');
});

it('prefers _FILE over _PATH when both point somewhere', function (): void {
    kbSetEnv('KB_TEST_SECRET_FILE', kbSecretFile('a', 'from-file-suffix'));
    kbSetEnv('KB_TEST_SECRET_PATH', kbSecretFile('b', 'from-path-suffix'));

    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('from-file-suffix');
});

it('trims trailing newlines and surrounding whitespace', function (string $written): void {
    // `openssl rand -base64 32 > file` and an editor-saved file both add a trailing newline; a
    // value pasted into an env var does not. One byte of difference is an HMAC mismatch, which
    // surfaces as a 401 with no useful message on either side of the seam.
    kbSetEnv('KB_TEST_SECRET_FILE', kbSecretFile('kek', $written));

    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('abc123');
})->with([
    'trailing LF' => ["abc123\n"],
    'trailing CRLF' => ["abc123\r\n"],
    'trailing blank line' => ["abc123\n\n"],
    'leading and trailing space' => ['  abc123  '],
]);

it('carries a comma-separated list out of a file exactly as the env value would', function (string $written): void {
    // APP_PREVIOUS_KEYS is a LIST, and config/app.php explodes it on commas. A file delivering the
    // same list must produce the same array — above all it must not produce a TRAILING EMPTY KEY,
    // because `openssl`-style redirection and every editor add a newline the env value never has.
    // An empty string reaching Laravel's Encrypter as a previous key is a hard failure at boot
    // ("unsupported cipher or incorrect key length"), so this is the shape of the whole rotation.
    kbSetEnv('KB_TEST_SECRET_FILE', kbSecretFile('previous_keys', $written));

    // The expression from config/app.php, verbatim.
    $keys = array_filter(
        array_map('trim', explode(',', (string) KbSecrets::get('KB_TEST_SECRET', ''))),
    );

    expect(array_values($keys))->toBe(['base64:aaa', 'base64:bbb']);
})->with([
    'one line, trailing LF' => ["base64:aaa,base64:bbb\n"],
    'one line, no newline' => ['base64:aaa,base64:bbb'],
    'trailing comma then LF' => ["base64:aaa,base64:bbb,\n"],
    'one key per line' => ["base64:aaa,\nbase64:bbb\n"],
    'spaces around the comma' => ["base64:aaa , base64:bbb\n"],
]);

it('yields an empty previous-keys list when the variable is absent entirely', function (): void {
    // The default path: no rotation in flight. `(string) null` is '', explode gives [''], and
    // array_filter must leave nothing behind — one empty previous key is enough to break boot.
    $keys = array_filter(
        array_map('trim', explode(',', (string) KbSecrets::get('KB_TEST_ABSENT_LIST', ''))),
    );

    expect($keys)->toBe([]);
});

it('trims the inline value too, so one secret delivered two ways is one string', function (): void {
    kbSetEnv('KB_TEST_SECRET', "abc123\n");

    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('abc123');
});

it('throws on an EMPTY secret file rather than degrading to null', function (): void {
    // The whole reason the resolver fails loudly. An empty signing key is not a broken key:
    // hash_hmac('sha256', $canonical, '') returns a perfectly valid digest, and it matches whatever
    // a peer that also resolved to empty computed. Authentication switches off and nothing raises.
    kbSetEnv('KB_TEST_SECRET_FILE', kbSecretFile('kek', ''));
    kbSetEnv('KB_TEST_SECRET', 'from-env');

    expect(fn () => KbSecrets::get('KB_TEST_SECRET', 'default'))
        ->toThrow(SecretUnavailableException::class);
});

it('throws on a whitespace-only secret file', function (): void {
    kbSetEnv('KB_TEST_SECRET_FILE', kbSecretFile('kek', "\n  \n"));

    expect(fn () => KbSecrets::get('KB_TEST_SECRET'))
        ->toThrow(SecretUnavailableException::class);
});

it('throws when the pointer names a file that does not exist', function (): void {
    kbSetEnv('KB_TEST_SECRET_FILE', KbSecretFixtures::$dir.'/never-written');
    kbSetEnv('KB_TEST_SECRET', 'from-env');

    expect(fn () => KbSecrets::get('KB_TEST_SECRET'))
        ->toThrow(SecretUnavailableException::class);
});

it('throws when the pointer names a directory', function (): void {
    kbSetEnv('KB_TEST_SECRET_PATH', KbSecretFixtures::$dir);

    expect(fn () => KbSecrets::get('KB_TEST_SECRET'))
        ->toThrow(SecretUnavailableException::class);
});

it('treats an empty pointer variable as absent, not as an error', function (): void {
    // `env_file:` entries are routinely declared-but-blank across environments. A blank line must
    // not brick local development, where the inline value is the norm.
    kbSetEnv('KB_TEST_SECRET_FILE', '');
    kbSetEnv('KB_TEST_SECRET', 'from-env');

    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('from-env');
});

it('reads once and memoises', function (): void {
    $path = kbSecretFile('kek', 'first');
    kbSetEnv('KB_TEST_SECRET_FILE', $path);

    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('first');

    file_put_contents($path, 'second');

    // Rotation is a restart, not a race between a signer and its own memo.
    expect(KbSecrets::get('KB_TEST_SECRET'))->toBe('first');
});

it('memoises absence too, and still honours a per-call default', function (): void {
    expect(KbSecrets::get('KB_TEST_MISSING'))->toBeNull();
    expect(KbSecrets::get('KB_TEST_MISSING', 'later-default'))->toBe('later-default');
});

it('names the secret and the path in the exception, and never the material', function (): void {
    // A fixture secret that DOES resolve, so its value is genuinely live in this process and in
    // the resolver's memo at the moment the next failure is raised.
    $live = 'S3CRET-'.bin2hex(random_bytes(16));
    kbSetEnv('KB_TEST_LIVE_FILE', kbSecretFile('live', $live."\n"));

    expect(KbSecrets::get('KB_TEST_LIVE'))->toBe($live);

    // A second secret whose file is removed after it was written — the material existed on disk,
    // and must still not turn up anywhere in the thrown exception.
    $doomed = 'S3CRET-'.bin2hex(random_bytes(16));
    $doomedPath = kbSecretFile('doomed', $doomed);
    kbSetEnv('KB_TEST_DOOMED_FILE', $doomedPath);
    unlink($doomedPath);

    $caught = null;

    try {
        KbSecrets::get('KB_TEST_DOOMED');
    } catch (SecretUnavailableException $e) {
        $caught = $e;
    }

    if ($caught === null) {
        throw new \RuntimeException('KbSecrets::get() must throw when the secret file is missing.');
    }

    // getTraceAsString() renders call ARGUMENTS, so this greps the whole rendered exception and
    // not just the message: a secret passed as an argument anywhere on the throwing path shows up
    // here. The message itself must still name WHICH secret and WHICH path, or an operator cannot
    // act on it.
    $rendered = (string) $caught;

    expect($caught->getMessage())->toContain('KB_TEST_DOOMED');
    expect($caught->getMessage())->toContain($doomedPath);

    expect($rendered)->not->toContain($live);
    expect($rendered)->not->toContain($doomed);
    expect($rendered)->not->toContain(substr($live, 0, 16));
});
