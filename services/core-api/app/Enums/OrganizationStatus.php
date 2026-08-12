<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A suspended organization still OWNS its rows — suspension is not deletion, which is a different
 * lifecycle entirely (kb-deletion-and-verification).
 */
enum OrganizationStatus: string
{
    case Active = 'active';
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
