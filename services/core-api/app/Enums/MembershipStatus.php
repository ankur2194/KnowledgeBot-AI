<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a membership row currently confers anything.
 *
 * `Invited` is NOT active: an invitation that has not been accepted must not authorize a single
 * read, and the difference between "invited" and "removed" is a product distinction rather than an
 * authorization one.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';

    public function isActive(): bool
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
