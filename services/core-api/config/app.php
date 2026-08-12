<?php

declare(strict_types=1);

use App\Support\Kb\KbSecrets;

return [

    'name' => env('APP_NAME', 'KnowledgeBot'),

    'env' => env('APP_ENV', 'production'),

    // Debug leaks stack traces, config values and query bindings into the error envelope. The render
    // closure in bootstrap/app.php never renders an exception message on a 5xx regardless, but debug
    // also enables the framework's own HTML handler — default to off and fail safe.
    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    // HARD REQUIREMENT, not a default: the scheduler runs interval frequencies with no wall clock to
    // skip or repeat, and every tenant-local time is stored as a UTC timestamptz. A non-UTC value
    // here makes a task scheduled at 02:30 run twice in October and not at all in March
    // (laravel-scheduler). Deliberately NOT read from env, and there is deliberately NO
    // `schedule_timezone` key — setting one re-introduces the wall clock the schedule avoids.
    'timezone' => 'UTC',

    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',

    /*
     * A SECRET, resolved file-first like every other one (App\Support\Kb\KbSecrets):
     * APP_KEY_FILE=/run/secrets/app_key, or APP_KEY_PATH.
     *
     * Being Laravel's own variable does not shrink the blast radius, which is the whole of it:
     * APP_KEY encrypts sessions and cookies, it is the key behind every `encrypted:` cast — where
     * provider credentials live — and it is the HMAC key behind the audit `key_fingerprint`
     * (kb-security-baseline §18.4). Left as an environment VALUE it renders in full in a
     * `docker compose config` dump, and `make prod-config` runs exactly that as a documented
     * pre-deploy step. That is the finding, not a variant of it.
     *
     * NULL-ABLE ON PURPOSE, and KbSecrets keeps it that way: an ABSENT variable resolves soft to
     * null, because `php artisan key:generate` has to run before a key exists and the framework
     * tolerates the gap. Only a POINTER that names an unreadable, missing or empty file throws.
     *
     * `key:generate` writes APP_KEY into `.env`, never into a secret file — so in a deployed
     * environment, where APP_KEY_FILE is set, the mounted file still wins and the command's write
     * is inert. That is the intended precedence: rotate the secret, restart, rebuild the config
     * cache. Locally, with no pointer set, `key:generate` works exactly as it always has.
     */
    'key' => KbSecrets::get('APP_KEY'),

    /*
     * Retired APP_KEY values — a COMMA-SEPARATED LIST, and the same key material, kept readable for
     * precisely the rotation window in which the current key is readable beside it. It is therefore
     * a secret on the same terms: APP_PREVIOUS_KEYS_FILE=/run/secrets/app_previous_keys, holding
     * the same comma-separated text the env value would have held.
     *
     * Envelope-encrypted provider credentials are decrypted with their own data keys, not with
     * APP_KEY (kb-security-baseline). `previous_keys` covers session and cookie payloads only, so an
     * APP_KEY rotation does not log every admin out mid-deploy.
     *
     * The per-element `trim()` is what makes the file and the env value the SAME list. KbSecrets
     * trims the resolved string, so a file written as `k1,k2\n` loses its trailing newline and
     * produces no trailing empty key — but a file written one key per line, `k1,\nk2\n`, still
     * carries an interior newline that only a per-element trim removes. `array_filter` then drops
     * the empties, so a trailing comma costs nothing either way.
     */
    'previous_keys' => array_filter(
        array_map('trim', explode(',', (string) KbSecrets::get('APP_PREVIOUS_KEYS', ''))),
    ),

    'maintenance' => [
        // `cache` (not `file`), so every container in the stack agrees on maintenance state. The
        // store must be the shared, non-evicting one — an evicted maintenance flag reads as "up".
        'driver' => env('APP_MAINTENANCE_DRIVER', 'cache'),
        'store' => env('APP_MAINTENANCE_STORE', 'valkey'),
    ],

];
