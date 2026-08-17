<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an invitation currently is, DERIVED AND NEVER STORED.
 *
 * There is no `status` column on `organization_invitations` and there must not be. The state is a
 * function of three timestamps — `revoked_at`, `accepted_at`, `expires_at` — and a stored copy is a
 * second spelling of the same fact that goes stale by the passage of time alone: `expired` is the
 * only state in this enum that a row enters with nobody writing to it. A cron that flipped a column
 * to keep up would make every read racy against its own schedule, and a row whose stored status
 * disagreed with its timestamps would be authorized by whichever one the caller happened to read.
 *
 * Consequence, and it is the point: every guest path decides validity from the timestamps, and the
 * four invalid states collapse into ONE indistinguishable client response. A prober holding a
 * guessed token cannot learn whether it was never real, expired, already used, or revoked — that
 * distinction is exactly what would prove a token had once existed.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Accepted = 'accepted';
    case Revoked = 'revoked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
