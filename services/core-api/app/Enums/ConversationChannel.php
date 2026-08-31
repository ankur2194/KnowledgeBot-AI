<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a conversation is being held: hosted web, embedded web, mobile, admin playground, or API
 * (docs/04 §8.22, docs/11 §16.6).
 *
 * ── THIS IS NOT `App\Enums\Surface`, AND THE TWO MUST NEVER BE MERGED ────────────────────────
 *
 * `Surface` has four values — admin, public_runtime, sdk, internal — and answers ONE question:
 * does a denial on this route render as a 403 that admits the record exists, or as a 404 that does
 * not. It is bound per ROUTE GROUP and it is an authorization concern.
 *
 * This enum has five values and answers a different question entirely: which CLIENT the human on
 * the other end is using, recorded on the transcript so analytics (§8.23) can say "the widget
 * produces twice the refusal rate of hosted chat". Three of these five values — hosted, embedded
 * and mobile — all arrive on the SAME `Surface::PublicRuntime`, so a merged enum would lose the
 * distinction the analytics exists to draw. And `playground` is `Surface::Admin`, which is why
 * folding them would put an admin-only value into a vocabulary the public runtime writes.
 *
 * ── THE AUTHENTICATION SPLIT IS A REAL CONSTRAINT AND IT IS IN THE DATABASE ─────────────────
 *
 * `permitsAnonymousSession()` below is not a UI hint. `conversations_authenticated_channel` is
 * generated from it, so a `playground` or `api` conversation with no `user_id` is refused by
 * PostgreSQL. Both of those channels prove an identity before a turn is possible — the playground
 * through the admin SPA session, the API through a Sanctum token that belongs to a user — so a row
 * claiming otherwise is not a state this table will hold. The other three may be either: a hosted
 * chat visitor may be signed in or may be a cookie.
 *
 * TEXT + CHECK IN THE MIGRATION, NEVER A NATIVE PG ENUM, for the reason every status column in this
 * schema records: `ALTER TYPE ... ADD VALUE` cannot be rolled back, so a sixth channel would be an
 * irreversible migration.
 */
enum ConversationChannel: string
{
    /** Our own chat page at `/c/{public_bot_id}`, served from this platform's origin. */
    case Hosted = 'hosted';

    /** The embedded widget, in an iframe on a customer's own page. */
    case Embedded = 'embedded';

    /** The React Native app. */
    case Mobile = 'mobile';

    /** The admin playground (§8.24). Admin-authenticated, never reachable by an end user. */
    case Playground = 'playground';

    /** A programmatic caller holding a Sanctum token. */
    case Api = 'api';

    /**
     * Whether a conversation on this channel may be held by somebody who has not authenticated.
     *
     * WRITTEN POSITIVELY, listing the channels that MAY be anonymous rather than the two that may
     * not. A negative test (`$this !== self::Playground`) admits whatever is added tomorrow, which
     * on this enum means a new channel silently gaining the right to hold a transcript with no
     * identity attached to it.
     */
    public function permitsAnonymousSession(): bool
    {
        return $this === self::Hosted || $this === self::Embedded || $this === self::Mobile;
    }

    /**
     * The channels that require an authenticated user, as wire values, for the CHECK constraint.
     *
     * @return list<string>
     */
    public static function authenticatedOnly(): array
    {
        return array_values(array_map(
            static fn (self $c): string => $c->value,
            array_filter(self::cases(), static fn (self $c): bool => ! $c->permitsAnonymousSession()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
