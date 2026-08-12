<?php

declare(strict_types=1);

namespace App\Support\Kb;

use App\Exceptions\SecretUnavailableException;
use Illuminate\Support\Env;

/**
 * FILE-OVER-VALUE SECRET RESOLUTION — the one bridge between how the infrastructure DELIVERS
 * secrets and how Laravel READS configuration.
 *
 * The platform mounts secrets as files at /run/secrets/<name> (Compose `secrets:`) and exports a
 * pointer variable — `DB_PASSWORD_FILE=/run/secrets/postgres_password`. Laravel's `env()` reads
 * values. Nothing bridged the two, so the obvious way to satisfy a missing value was to put the
 * material in `env/core-api.env` — and `make prod-config` runs `docker compose config`, which
 * renders every interpolated value IN FULL into the terminal, the CI log, and whatever ticket that
 * output was pasted into. Secrets are files precisely so they appear in no config dump, no
 * `docker inspect`, and no /proc/<pid>/environ.
 *
 * Every secret-shaped config read goes through here. `tests/Arch/SecretsResolverTest.php` fails
 * the build if a new one does not.
 *
 * RESOLUTION ORDER, fixed:
 *
 *   1. `<NAME>_FILE`  — if set, read that file. Wins over everything, including an inline value.
 *   2. `<NAME>_PATH`  — same, second. Both spellings exist in the wild: `_FILE` is the Docker
 *                       official-image convention (`POSTGRES_PASSWORD_FILE`) and `_PATH` is what
 *                       `infrastructure/docker/env/core-api.env.example` already ships for the KEK.
 *                       Supporting one would have meant renaming the other's half of the estate.
 *   3. `<NAME>`       — the inline environment value. The norm in local development, where no
 *                       secrets are mounted.
 *   4. `$default`.
 *
 * THE FILE WINS OVER THE VALUE, never the other way round. If both are present the file is the
 * deliberate act (someone mounted a secret) and the value is the leftover (someone forgot to clear
 * a line in a `.env`). Preferring the value would make a stale `.env` silently outrank a rotated
 * secret, and the symptom would be a 401 on a request that looks correct in every log.
 *
 * FAILURE IS LOUD, AND THE EMPTY FILE IS THE REASON. A pointer that names an unreadable, missing
 * or empty file throws — it never degrades to the inline value and never degrades to null. An
 * empty signing key is not a broken key: `hash_hmac('sha256', $canonical, '')` returns a perfectly
 * valid digest, and it matches whatever a peer that also resolved to empty computed. The system
 * keeps working, with authentication switched off, and nothing anywhere raises.
 *
 * TRIMMED, ALWAYS. `openssl rand -base64 32 > /run/secrets/kek` writes a trailing newline; an
 * editor-saved file writes one too; a value pasted into an env var does not. Those differ by
 * exactly one byte, and the resulting HMAC mismatch is a 401 with no useful message anywhere. We
 * trim the file AND the inline value so the same secret delivered two ways is the same string.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * CONFIG CACHING — the deliberate decision.
 *
 * These calls sit inside `config/*.php`, so they run at CONFIG-BUILD TIME and the resolved
 * plaintext lands in the array `php artisan config:cache` writes to `bootstrap/cache/config.php`.
 * That is chosen, not overlooked.
 *
 * The alternative — a lazy value in config — is not representable. `config:cache` serialises with
 * `var_export()`, which cannot express a closure; Laravel rejects the whole cache with "Your
 * configuration files are not serializable" the moment one appears. Storing the *path* in config
 * and resolving at each use site would work, but it moves the read into every consumer, which is
 * exactly the sprawl this class exists to prevent — and one consumer that forgets is a plaintext
 * path read on a hot path or, worse, a bare `env()` at runtime returning null under a cached
 * config.
 *
 * So the config cache is secret material at rest, and three things follow, all already true:
 *   - `bootstrap/cache/*` is in `.gitignore` and `.dockerignore`;
 *   - the Dockerfile deliberately does NOT run `config:cache` at build time, so no image layer
 *     can contain it;
 *   - `config:cache` runs at container start, after the secrets are mounted, on a writable layer
 *     that dies with the container.
 *
 * The second consequence is operational: a secret rotation requires a config-cache rebuild, i.e.
 * a container restart. That is already true of every other configuration change here.
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 */
final class KbSecrets
{
    /**
     * Pointer suffixes, in precedence order. `_FILE` first — see the class docblock.
     */
    private const POINTER_SUFFIXES = ['_FILE', '_PATH'];

    /**
     * Resolved secrets, memoised for the life of the process. A `null` entry means "resolved, and
     * genuinely absent" — it is not a cache miss, so an absent secret is not re-read on every call.
     *
     * The array is private and never passed as an argument to anything, which is what keeps its
     * contents out of a PHP stack trace: `getTraceAsString()` renders call arguments.
     *
     * @var array<string, string|null>
     */
    private static array $resolved = [];

    /**
     * Resolve a secret. Reads once per process; never logs; never appears in an exception message.
     *
     * @throws SecretUnavailableException when a `_FILE`/`_PATH` pointer is set and the file is
     *                                    missing, unreadable, or empty.
     */
    public static function get(string $name, ?string $default = null): ?string
    {
        if (! array_key_exists($name, self::$resolved)) {
            self::$resolved[$name] = self::resolve($name);
        }

        return self::$resolved[$name] ?? $default;
    }

    /**
     * Drop the memo. FOR TESTS ONLY — there is no rotation path that calls this. A worker that has
     * already resolved a key keeps it until the process ends, which is what makes rotation a
     * restart rather than a race between a signer and its own memo.
     */
    public static function flush(): void
    {
        self::$resolved = [];
    }

    private static function resolve(string $name): ?string
    {
        foreach (self::POINTER_SUFFIXES as $suffix) {
            $pointer = $name.$suffix;
            $path = self::envString($pointer);

            // An EMPTY pointer is treated as absent, not as an error. `env_file:` entries are
            // frequently declared-but-blank across environments, and a blank line there must not
            // brick local development where the inline value is the norm. A pointer that names a
            // path, on the other hand, is a promise — and a broken promise throws below.
            if ($path === null) {
                continue;
            }

            return self::readSecretFile($name, $pointer, $path);
        }

        return self::envString($name);
    }

    /**
     * @throws SecretUnavailableException
     */
    private static function readSecretFile(string $name, string $pointer, string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw SecretUnavailableException::unreadable($name, $pointer, $path);
        }

        // Silenced: a failed read emits a warning containing the path, and the warning handler is
        // not ours to trust with it. The `false` check below is the real error path.
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw SecretUnavailableException::unreadable($name, $pointer, $path);
        }

        $secret = trim($contents);

        if ($secret === '') {
            throw SecretUnavailableException::blank($name, $pointer, $path);
        }

        return $secret;
    }

    /**
     * Read an environment variable as a trimmed, non-empty string, or null.
     *
     * `Illuminate\Support\Env::get()` rather than the `env()` helper on purpose: `env()` is banned
     * outside `config/` by an arch test, and this class is neither config nor an exception to that
     * rule — it is the thing the rule points at. `Env` is the same implementation `env()` calls,
     * so dotenv's literal coercion ("true", "null", "(empty)") behaves identically. A non-string
     * result means the value was one of those literals, which is never valid key material.
     */
    private static function envString(string $key): ?string
    {
        $value = Env::get($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
