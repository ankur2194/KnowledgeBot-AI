<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether an unauthenticated end user may reach this bot at all (docs/02 §8.3).
 *
 * THIS IS NOT THE ORIGIN ALLOW-LIST AND NEITHER SUBSTITUTES FOR THE OTHER. `Public` says an
 * anonymous visitor may open a conversation; `bot_domains` says which page may embed the widget
 * that opens it. A public bot with an empty allow-list is reachable from hosted chat and from no
 * embedded widget; a private bot with a populated one is reachable from neither. Collapsing the two
 * into a single "is it exposed" flag is how an origin check ends up skipped for a bot somebody had
 * already marked public.
 *
 * DEFAULT `Private`, IN THE COLUMN AND HERE. A bot created by a form that forgot the field must not
 * be answerable by the internet; the fail-closed direction is the only one where the mistake is a
 * support ticket rather than a disclosure.
 *
 * `Public` and `Private` are semi-reserved words in PHP and are legal as enum case names — class
 * member names have accepted reserved words since PHP 7.0. They are spelled that way because the
 * stored values are `public` and `private` and any other spelling would put a translation table
 * between the enum and the column for no gain.
 */
enum BotAccessMode: string
{
    /** Anyone who can reach the channel may converse, with no account and no invitation. */
    case Public = 'public';
    /** Only an authenticated member of the owning organization may converse. */
    case Private = 'private';

    /**
     * Whether an anonymous end user may open a conversation.
     *
     * Read at the channel boundary IN ADDITION to `BotStatus::isRetrievable()` and to the origin
     * allow-list, never instead of either. All three are separate facts and a bot is reachable only
     * when every one of them agrees.
     */
    public function allowsAnonymous(): bool
    {
        return $this === self::Public;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
