<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one stored credential. This is CHECK 5 of the six (kb-security-baseline §18.4) for
 * every action that reads or designates a connection.
 */
enum ProviderConnectionStatus: string
{
    case Active = 'active';
    /** The provider rejected the key on the last controlled connection check. */
    case Invalid = 'invalid';
    /** Withdrawn by the organization. The row survives so audit history still resolves. */
    case Revoked = 'revoked';

    public function isUsable(): bool
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
