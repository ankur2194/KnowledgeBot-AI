<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw bytes in and out of a PostgreSQL `bytea` column.
 *
 * WHY THIS EXISTS AT ALL. `bytea` is mandatory for ciphertext (postgresql-patterns,
 * kb-security-baseline §18.2) and PDO has no way to bind a parameter as one: `prepareBindings()`
 * sends every string as text, so raw AES-GCM output — which is not valid UTF-8 and never will be —
 * is rejected by the server with `22021 invalid byte sequence for encoding "UTF8"`. That error is
 * the GOOD outcome. The bad one is the "fix" a hurried reader reaches for: change the column to
 * `text`. It appears to work, and then a `pg_dump`/restore across a different `client_encoding`
 * silently mangles bytes that were never valid UTF-8 to begin with, and a tenant's provider
 * credential reads back as mojibake months later with no error at the moment of damage.
 *
 * So the bytes travel as PostgreSQL's own hex input format — `\x` followed by hex digits, pure
 * ASCII, losslessly cast to `bytea` by the server on the way in.
 *
 * READ SIDE IS DEFENSIVE ON PURPOSE. PDO_PGSQL has returned `bytea` as a stream resource in some
 * builds and as the `\x…` text representation in others, depending on how the column was fetched.
 * Handling both is three lines; guessing wrong is a decryption failure that looks like key
 * corruption.
 *
 * @implements CastsAttributes<string, string>
 */
final class BinaryCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (! is_string($value)) {
            return null;
        }

        if (str_starts_with($value, '\x')) {
            $decoded = hex2bin(substr($value, 2));

            return $decoded === false ? null : $decoded;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! is_string($value)) {
            return [$key => null];
        }

        // ALWAYS ENCODE. The obvious optimisation — "skip it if the value already starts with
        // \x, so a re-save does not double-encode" — is a silent corruption waiting for one
        // credential in 65,536: AES-GCM output is uniform bytes, so `0x5C 0x78` is a perfectly
        // ordinary two-byte prefix and that branch would store it half-encoded, undetectably.
        // Round-tripping through get() yields raw bytes again, so encoding unconditionally is
        // already idempotent for every path that reads before it writes.
        return [$key => '\x'.bin2hex($value)];
    }
}
