<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The FIXED role catalog of docs/01 §6.2–6.5. `organization_users.role` is a single scalar — one
 * role per user per organization.
 *
 * Platform Owner (§6.1) is deliberately absent: it is a PLATFORM flag on the user
 * (`users.is_platform_owner`), not an organization role, and it reaches no tenant data without an
 * audited impersonation flow (laravel-rbac-policies NN4).
 */
enum OrgRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case KnowledgeManager = 'knowledge_manager';
    case Analyst = 'analyst';

    /**
     * The role x permission matrix, as data, in one place.
     *
     * KnowledgeManager may NOT reach provider credentials (§6.4 lists them as excluded) — and the
     * embedding designation is on the credential side of that line for the reason Permission
     * records: it selects which credential pays and which vector space a corpus lands in.
     */
    public function grants(Permission $permission): bool
    {
        return match ($this) {
            self::Owner, self::Admin => true,
            self::KnowledgeManager => $permission === Permission::ProvidersView,
            self::Analyst => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
