<?php

declare(strict_types=1);

namespace App\Support\Kb;

/**
 * The opaque token that addresses a bot on the UNAUTHENTICATED surfaces — a hosted-chat URL, a
 * widget snippet, a theme stylesheet request.
 *
 * ── IT IS NOT AN `OpaqueToken`, AND THE DIFFERENCE IS THE WHOLE REASON THIS CLASS EXISTS ──────
 *
 * `App\Support\Kb\OpaqueToken` mints a CAPABILITY: the plaintext is never stored, only its digest,
 * because possession of the string is the authorization. This is the opposite kind of value. It is
 * an IDENTIFIER — it is printed into a customer's own page source, bookmarked, cached in a
 * stylesheet URL, and it authorizes nothing on its own: whether the bot answers is the AND of
 * `BotStatus::isRetrievable()`, `BotAccessMode::allowsAnonymous()` and the origin allow-list, none
 * of which this string carries. So it is stored in plaintext, resolved by exact equality against a
 * global unique index, and reusing `OpaqueToken` for it would make the column unlookupable.
 *
 * ── IT IS NOT THE ULID EITHER, AND THAT IS A SECURITY DECISION ────────────────────────────────
 *
 * The create migration records it: a ULID carries a 48-bit millisecond timestamp in its leading
 * characters, so publishing one publishes the creation time of every bot and makes neighbouring
 * bots guessable by anyone who has seen two of them. It is also the identifier the ADMIN surface
 * authorizes against, so reusing it would mean one leaked widget snippet addresses the
 * configuration endpoint too.
 *
 * ── THE SHAPE IS PINNED IN THREE PLACES AND IS NOT OURS TO WIDEN ──────────────────────────────
 *
 * `bots_public_bot_id_shape` CHECKs `^[A-Za-z0-9_-]{1,64}$`, and
 * `apps/web/src/app/(chat)/c/[publicBotId]/theme.css/route.ts:66` refuses to forward anything else
 * as a path segment. What is minted here is a strict subset of that grammar — a fixed prefix and
 * lower-case hex — so a value from this class can never be the one that discovers a disagreement
 * between the other two.
 *
 * ── ENTROPY, AND WHAT HAPPENS IF TWO COLLIDE ──────────────────────────────────────────────────
 *
 * 16 bytes from the CSPRNG, hex-encoded: 128 bits, in a globally unique index. A collision is a
 * `bots_public_bot_id_unique` violation rendered as a 500, and that is DELIBERATELY not caught and
 * retried. At this width a collision does not happen by chance; it happens when the CSPRNG is
 * broken, and a silent retry loop would turn "this deployment is minting predictable public bot
 * identifiers" into a slightly slower create. A duplicate SLUG is a 422 because a human chose it;
 * a duplicate token is not a user error and must not be rendered as one.
 */
final class PublicBotIdentifier
{
    /**
     * Visible, and not decoration. A bare random string sitting next to a ULID in a failing
     * assertion is not distinguishable by eye, and the entire point of the column is that the two
     * are different keys. `BotFactory` prefixes its fixtures identically.
     */
    public const PREFIX = 'pub_';

    /** Bytes drawn from the CSPRNG before hex encoding. */
    public const BYTES = 16;

    public static function mint(): string
    {
        return self::PREFIX.bin2hex(random_bytes(self::BYTES));
    }
}
