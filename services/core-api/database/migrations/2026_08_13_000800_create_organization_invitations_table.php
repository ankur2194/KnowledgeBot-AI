<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The pending half of membership — and the SINGLE OWNER of "invited" state.
 *
 * An invitation creates NO membership row. `organization_users` gains a row, `Active`, only on
 * acceptance. The rejected alternative — insert an `organization_users` row with status `invited`
 * and flip it later — means every membershipFor() caller has to reason about a row that confers
 * nothing, in the one query that decides authorization. `MembershipStatus::Invited` therefore has no
 * producer and is retained deliberately; see the comment on that enum case.
 *
 * `token_hash` IS 32 RAW BYTES OF sha256 IN A `bytea`, AND THE PLAINTEXT TOKEN IS NEVER STORED.
 * Not `char(64)` holding hex: postgresql-patterns puts binary in `bytea`, and the hex form doubles
 * the width of the one index on the guest read path. The unique index over it is NOT the equality
 * oracle that skill bars — that rule is about reversible ciphertext, and this column cannot be
 * probed by anyone who does not already hold the token.
 *
 * The four CHECK constraints are the invariants the service would otherwise have to remember at
 * every call site. The lowercase one in particular is what makes the token/email comparison in
 * `/auth/register` decidable: a mixed-case row is unreachable by the normalised lookup that has to
 * find it, so the database refuses to create one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = $this->quotedList(OrgRole::values());

        $this->run(<<<SQL
            CREATE TABLE organization_invitations (
                id               char(26) COLLATE "C" PRIMARY KEY,
                organization_id  char(26) COLLATE "C" NOT NULL
                                 REFERENCES organizations (id) ON DELETE RESTRICT,
                email            text NOT NULL,
                role             text NOT NULL,
                token_hash       bytea NOT NULL,
                invited_by_id    char(26) COLLATE "C" NOT NULL
                                 REFERENCES users (id) ON DELETE RESTRICT,
                accepted_by_id   char(26) COLLATE "C"
                                 REFERENCES users (id) ON DELETE RESTRICT,
                expires_at       timestamptz NOT NULL,
                accepted_at      timestamptz,
                revoked_at       timestamptz,
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT organization_invitations_role_check
                    CHECK (role IN ({$roles})),
                -- The invariant the register/accept normalisation depends on, enforced by the
                -- database rather than by every caller remembering it.
                CONSTRAINT organization_invitations_email_lowercase
                    CHECK (email = lower(email)),
                -- An invitation is accepted OR revoked, never both. num_nonnulls is the same idiom
                -- the embedding designation uses for its pair.
                CONSTRAINT organization_invitations_terminal_once
                    CHECK (num_nonnulls(accepted_at, revoked_at) <= 1),
                -- Acceptance always has an actor, and an actor always means acceptance. Written as
                -- an equality of two NULL tests so both directions are one constraint.
                CONSTRAINT organization_invitations_accepted_has_actor
                    CHECK ((accepted_at IS NULL) = (accepted_by_id IS NULL))
            )
        SQL);

        // THE lookup, and the only read on the guest path. UNIQUE so a double-insert or an
        // (impossible) digest collision is a 23505 rather than an ambiguous first().
        $this->run(
            'CREATE UNIQUE INDEX organization_invitations_token_hash '
            .'ON organization_invitations (token_hash)'
        );

        // At most ONE live invitation per (organization, email), proven by the database. Same class
        // of race as source_versions_one_active_per_item: two admins invite the same person
        // concurrently, both read "nothing pending", both insert, and the second COMMIT gets 23505.
        // PARTIAL, so accepted and revoked rows accumulate without blocking a re-invite.
        $this->run(
            'CREATE UNIQUE INDEX organization_invitations_one_pending_per_email '
            .'ON organization_invitations (organization_id, email) '
            .'WHERE accepted_at IS NULL AND revoked_at IS NULL'
        );

        // The admin list. ORG-LEADING, always — never (created_at, organization_id), which makes the
        // tenant predicate a filter over every org's rows.
        $this->run(
            'CREATE INDEX organization_invitations_org_created '
            .'ON organization_invitations (organization_id, created_at DESC)'
        );

        // The expiry sweep: partial, so it indexes only the rows the pruner cares about.
        $this->run(
            'CREATE INDEX organization_invitations_pending_expires '
            .'ON organization_invitations (expires_at) '
            .'WHERE accepted_at IS NULL AND revoked_at IS NULL'
        );

        // PostgreSQL indexes NEITHER side of a foreign key for you, and an unindexed referencing
        // column turns a future user purge into a sequential scan of this table per row.
        $this->run(
            'CREATE INDEX organization_invitations_invited_by_id '
            .'ON organization_invitations (invited_by_id)'
        );
        $this->run(
            'CREATE INDEX organization_invitations_accepted_by_id '
            .'ON organization_invitations (accepted_by_id)'
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS organization_invitations');
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
