<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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

        // THE SOURCE DECIDES THE ENCODING, AND THAT IS THE WHOLE STRUCTURE OF THIS METHOD.
        //
        // This used to be one flat test on the `\x` prefix, which BOTH branches can carry: a
        // resource holds DECODED bytes, and a sealed credential begins with a random 12-byte IV, so
        // about one credential in 65,536 starts with the two ASCII bytes `\` and `x` purely by
        // chance. The prefix therefore never distinguished the two cases; it only usually did. The
        // guard that made it safe — "and the remainder must also LOOK like hex" — narrowed the
        // window to (16/256)^n and left it open, which is a probability argument standing in for a
        // structural one.
        //
        // Branching on the SOURCE removes the question instead of making it unlikely: a resource is
        // raw bytes because PDO decoded them, and a string is `\x` + hex because
        // :self::set() is the only thing that can have produced it. Neither branch has to guess,
        // and no ciphertext can fall into the other one whatever its first two bytes are.
        if (is_resource($value)) {
            // FROM OFFSET 0, EXPLICITLY, AND THAT ARGUMENT IS THE WHOLE POINT. A bare
            // stream_get_contents() reads from the CURRENT position and leaves the stream drained,
            // and Eloquent does not cache a class cast whose get() returns a non-object
            // (getClassCastableAttributeValue() unsets the cache unless is_object($value)) — so
            // every access re-runs this method against the same handle. The first read of a bytea
            // attribute returned the bytes and the second returned '', which CredentialVault::open()
            // reports as 'ciphertext is truncated': a message that reads like key corruption and
            // sends the reader to the KEK. Reading from 0 makes repeated access idempotent.
            $bytes = stream_get_contents($value, -1, 0);

            // RETURNED HERE, NOT FALLEN THROUGH. This branch used to assign back into `$value` and
            // drop into the string branch below — which is precisely how DECODED bytes beginning
            // `\x` reached hex2bin() and came back as different, shorter, silently wrong bytes.
            // A resource is bytes; there is nothing left to decide about it.
            return $bytes === false ? null : $bytes;
        }

        if (! is_string($value)) {
            return null;
        }

        // ── THE STRING BRANCH ──────────────────────────────────────────────────────────────────
        //
        // A string reaching here came from :self::set() — either straight out of `$attributes`
        // after an assignment, or from a PDO build that hands `bytea` back as PostgreSQL's own
        // text representation. Both are `\x` + an even number of hex digits by construction, so
        // the prefix is a reliable discriminator HERE in a way it never was against a resource.
        //
        // THE WELL-FORMEDNESS CHECK BELOW IS NOT THAT DISCRIMINATOR AND MUST NOT BE READ AS ONE.
        // It is there because `hex2bin()` raises E_WARNING on an odd-length or non-hex input, and
        // an application that promotes warnings would turn a malformed stored value into a 500 on
        // a path whose job is to read a credential. A value that fails it is a row nothing in this
        // application could have written; returning it unchanged is the same answer this method
        // gave before and keeps the failure where it can be diagnosed — CredentialVault::open(),
        // which reports a decryption failure — rather than as a warning from a cast.
        if (! str_starts_with($value, '\x')) {
            return $value;
        }

        $body = substr($value, 2);

        if ($body === '' || strlen($body) % 2 !== 0 || ! ctype_xdigit($body)) {
            return $value;
        }

        $decoded = hex2bin($body);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        // NULL IS A REAL STATE and travels through unchanged: a nullable `bytea` column means
        // "absent", which must not become an empty string that later decrypts as a truncation.
        if ($value === null) {
            return [$key => null];
        }

        // A NON-STRING THROWS. It used to return `[$key => null]`, which is SILENT DATA LOSS the
        // first time this general-purpose cast meets a nullable `bytea` column: assigning an
        // integer, an object with a __toString, or a stream would NULL the column, `save()` would
        // succeed, and the row would read back as "no credential stored" with nothing anywhere
        // saying a value had been discarded. On a non-nullable column it is a NOT NULL violation
        // at the driver and looks like a schema bug; on a nullable one it is quietly wrong forever.
        //
        // There is no correct silent handling: this cast's contract is raw BYTES, and PHP has one
        // type for those. A caller passing something else has a bug, and a bug in a credential
        // write is exactly the kind that must be loud at the assignment rather than discovered at
        // the next decryption.
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'BinaryCast writes raw bytes and received %s for [%s] on [%s]. Returning null here '
                .'would NULL a bytea column and lose the value silently. Pass a string, or a null '
                .'if the column is genuinely being cleared.',
                get_debug_type($value),
                $key,
                $model::class,
            ));
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
