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

    /**
     * RETAINED DELIBERATELY, WITH NO PRODUCER. `organization_invitations` owns pending state: an
     * invitation creates no membership row at all, and the row is created `Active` on acceptance.
     *
     * So nothing writes this case, which is normally the dead-case smell Permission's docblock
     * warns about. It stays for two reasons. Removing it is a `DROP CONSTRAINT` /
     * `ADD CONSTRAINT ... NOT VALID` / `VALIDATE CONSTRAINT` migration against
     * `organization_users_status_check` on a live table, for zero functional benefit. And a
     * membership status that means "does not confer anything yet" is a shape a future flow (an
     * SSO-provisioned or import-staged member) may legitimately want back — at which point the
     * invitation table is still the owner of INVITATION state specifically, and this case would
     * mean something else.
     *
     * If it is ever revived, `isActive()` returning false for it is the property that matters.
     */
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
