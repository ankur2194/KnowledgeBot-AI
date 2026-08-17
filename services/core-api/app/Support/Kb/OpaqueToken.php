<?php

declare(strict_types=1);

namespace App\Support\Kb;

use SensitiveParameter;

/**
 * THE ONE IMPLEMENTATION of the opaque capability token — minted, digested, and compared here and
 * nowhere else. Organization invitations and email verification both use it.
 *
 * WHY OPAQUE DATABASE TOKENS AND NOT SIGNED URLS. `URL::hasValidSignature($request)` validates the
 * signature against `$request->url()`, which is THIS API's URL — while the URL the recipient
 * clicked was the SPA's. A `temporarySignedRoute` therefore cannot be verified after the SPA echoes
 * its parameters back, and any difference in query-parameter order or percent-encoding between what
 * Laravel signed and what the SPA re-sends fails `hash_equals` silently, on some clients and not
 * others. Three further reasons, each sufficient on its own: a row is single-use where a signed URL
 * is replayable until it expires; a row is revocable, so re-sending an invitation kills the old
 * link by overwriting the digest; and password reset is already an opaque hashed database token
 * (Illuminate\Auth\Passwords\DatabaseTokenRepository), so matching it is the SMALLER design.
 *
 * ENTROPY AND LENGTH. 32 bytes from the CSPRNG, hex-encoded to 64 characters — which is why every
 * FormRequest that accepts one of these validates `size:64` rather than a range. Hex, not base64url:
 * it survives a mail client's URL rewriting, a copy-paste out of a plain-text part, and a
 * case-preserving proxy without a single ambiguous character, and it costs 22 characters nobody
 * reads. `random_bytes()` throws on an entropy failure; it never degrades to a weak source, and the
 * banned alternatives (`rand`, `mt_rand`, `uniqid`) are what the Pest security arch preset exists to
 * catch.
 *
 * THE DIGEST IS RAW sha256 — 32 BYTES, FOR A `bytea` COLUMN. Not hex in a `text` column (twice the
 * index, and two spellings of one value), and deliberately not bcrypt: bcrypt exists to make a
 * LOW-entropy secret expensive to guess, and there is nothing to stretch in a 256-bit random. More
 * importantly, bcrypt embeds a per-row salt, so the digest could not be the lookup key — every
 * guest read would degrade from one unique-index probe to a scan plus a verify per row.
 *
 * THE PLAINTEXT IS NEVER STORED. It exists in the emailed URL and in the queued job body for the
 * life of that job, and nowhere else: no column, no log line, no audit `details` payload, no
 * response body. A stolen database backup therefore yields no usable invitation link.
 */
final class OpaqueToken
{
    /**
     * Bytes drawn from the CSPRNG.
     */
    public const BYTES = 32;

    /**
     * Characters in the hex-encoded plaintext — the `size:64` every FormRequest validates.
     */
    public const LENGTH = 64;

    /**
     * Bytes in the stored digest. The `bytea` column's width, stated once.
     */
    public const DIGEST_BYTES = 32;

    /**
     * A fresh capability token. Hand it to the recipient; store only digest() of it.
     */
    public static function mint(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    /**
     * The value that goes in the `token_hash` column and that the unique index is built on.
     *
     * Raw binary, so the caller writes it straight through App\Support\Crypto\BinaryCast. Unsalted
     * ON PURPOSE, which is what makes the digest a usable lookup key — see the class docblock.
     */
    public static function digest(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token, binary: true);
    }

    /**
     * Compare a presented token against a stored digest.
     *
     * `hash_equals` rather than `===`, and the argument order matters: the KNOWN value first, the
     * user-supplied second, which is the signature's contract. In practice a lookup by digest
     * already decides these paths, so this is the belt for the cases that compare a row already in
     * hand — and it is here so no call site reaches for `===` on a secret.
     */
    public static function matches(#[SensitiveParameter] string $token, string $digest): bool
    {
        return hash_equals($digest, self::digest($token));
    }
}
