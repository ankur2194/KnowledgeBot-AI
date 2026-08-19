<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one entry in a bot's widget origin allow-list.
 *
 * THE STATUS IS PART OF A SECURITY CONTROL, so the only value that permits an embed is `Active` and
 * the check is written positively — `$domain->status->permitsEmbedding()` — never as "not
 * disabled". A negative test passes for a value nobody has thought of yet, which is the shape
 * kb-tenancy-isolation NN5 warns about one layer down: a positive predicate excludes the row nobody
 * anticipated, a negative one admits it.
 *
 * `Pending` EXISTS BECAUSE VERIFICATION IS A SEPARATE STEP FROM ENTRY. An operator pastes an origin
 * into a form; whether that origin is under their control is not something the form knows. The row
 * therefore starts unusable and is promoted deliberately. A single boolean would force the choice
 * between "typed origins work immediately" — an open redirect on somebody else's domain waiting for
 * a typo — and "typed origins never work until code runs", which is unexplainable in a console.
 *
 * `Disabled` is retained rather than deleted so an operator can turn an origin off for an
 * investigation and back on afterwards without retyping it, and so an audit entry naming the row
 * still resolves.
 */
enum BotDomainStatus: string
{
    /** Entered, not yet verified. Embedding is refused. */
    case Pending = 'pending';
    /** Verified and live. The one value that permits an embed. */
    case Active = 'active';
    /** Withdrawn by the organization. The row survives so audit history still resolves. */
    case Disabled = 'disabled';

    /**
     * Whether a widget served from this origin may boot.
     *
     * Positively expressed on purpose. This is one term of the check the widget bootstrap performs;
     * the others are the bot's status, its access mode, and an EXACT match on the origin string.
     *
     * ── CARRY-FORWARD FOR WHOEVER WRITES THAT BOOTSTRAP: AN EMPTY LIST IS A DENIAL ────────────
     *
     * This predicate is per ROW, so the case it cannot express is the one to get right: a bot with
     * NO `bot_domains` rows at all. That must deny every origin. "No allow-list configured" reads
     * naturally as "unrestricted" — it is how allow-lists are misread everywhere — and here that
     * reading is an embed on any site on the internet, because `published` + `access_mode = public`
     * is precisely what makes a bot answerable ANONYMOUSLY, on the organization's credential and
     * against its quota. Nothing upstream catches it: the publish guard (`BotService::
     * assertPublishable()`) says nothing about `access_mode` and nothing about this table, by
     * design — publishing a public bot with no origins yet is a legitimate intermediate state, and
     * hosted chat serves it correctly. The refusal belongs at the embed check, expressed as "some
     * active row matches this exact origin", which is false for an empty set by construction — and
     * never as "no row forbids it", which is true for an empty set for the same reason.
     */
    public function permitsEmbedding(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
