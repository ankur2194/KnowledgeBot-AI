<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The actor, and the ONLY database fact about authorization.
 *
 * A user is not owned by an organization: membership lives in `organization_users` and one user
 * may belong to several. That is exactly why every policy resolves membership of THE RECORD'S
 * organization — "admin of some organization" is the cross-tenant bug (laravel-rbac-policies NN1),
 * and it is expressible only because this table exists separately from `users`.
 *
 * `is_platform_owner` is a PLATFORM flag (§6.1), deliberately not a role in `organization_users`:
 * it holds platform gates only and reaches no tenant data without an audited impersonation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE users (
                id                 char(26) COLLATE "C" PRIMARY KEY,
                name               text NOT NULL,
                email              text NOT NULL,
                password           text NOT NULL,
                is_platform_owner  boolean NOT NULL DEFAULT false,
                email_verified_at  timestamptz,
                remember_token     varchar(100),
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // GLOBAL uniqueness, not per organization — a login identifier that were unique only
        // per tenant could not identify anyone at the login form, which has no tenant yet.
        $this->run('CREATE UNIQUE INDEX users_email_unique ON users (lower(email))');

        $roles = $this->quotedList(OrgRole::values());
        $statuses = $this->quotedList(MembershipStatus::values());

        $this->run(<<<SQL
            CREATE TABLE organization_users (
                organization_id  char(26) COLLATE "C" NOT NULL
                                 REFERENCES organizations (id) ON DELETE RESTRICT,
                user_id          char(26) COLLATE "C" NOT NULL
                                 REFERENCES users (id) ON DELETE RESTRICT,
                role             text NOT NULL,
                status           text NOT NULL DEFAULT 'active',
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (organization_id, user_id),
                CONSTRAINT organization_users_role_check   CHECK (role   IN ({$roles})),
                CONSTRAINT organization_users_status_check CHECK (status IN ({$statuses}))
            )
        SQL);

        // The primary key already indexes (organization_id, user_id) — org-leading, which is the
        // direction every scoped read goes. This is the OTHER direction ("which organizations does
        // this user belong to"), needed by the org switcher and by the FK from users.
        $this->run('CREATE INDEX organization_users_user_id ON organization_users (user_id)');
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS organization_users');
        $this->run('DROP TABLE IF EXISTS users');
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};
