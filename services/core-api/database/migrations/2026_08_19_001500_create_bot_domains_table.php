<?php

declare(strict_types=1);

use App\Enums\BotDomainStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The widget origin allow-list (docs/02 §8.3, docs/11 §16.3).
 *
 * THIS TABLE IS A SECURITY CONTROL AND NOT A PREFERENCE, and every column below follows from that.
 * A row here is what lets a page on the public internet boot a chat widget that speaks with this
 * organization's credential, on this organization's corpus, against this organization's quota.
 *
 * ═══ EXACT ORIGINS. NO WILDCARDS. NOT EVEN ONE. ═══════════════════════════════════════════════
 *
 * An origin is scheme + host + optional port, serialized lower-case, with no path, no query, no
 * trailing slash and no userinfo — RFC 6454's serialization, which is exactly the string a browser
 * puts in the `Origin` header and exactly the string `postMessage` compares a `targetOrigin`
 * against. `bot_domains_origin_exact` enforces that grammar, and it is written as an ALLOW-LIST
 * pattern rather than as a set of forbidden characters on purpose: every deny-list in this system
 * is a bug waiting for an encoding trick (kb-security-baseline).
 *
 * WHY WILDCARDS ARE REFUSED IN THE SCHEMA RATHER THAN IN A FORM. `*.example.com` reads as "our
 * sites" and means "every host anybody can get a certificate for under example.com" — which on a
 * platform with customer subdomains, a status page, a marketing CMS, or a single stale DNS record
 * pointing at an abandoned bucket is an origin somebody else controls. The comparison this list
 * feeds is byte equality; a pattern would force it to become a matcher, and a matcher is where the
 * bypasses live. There is no `is_wildcard` column and no `*` in the grammar, so the matcher cannot
 * be written without a migration that has to explain itself.
 *
 * The grammar deliberately admits only `http` and `https` and only a lower-case host. `http` is
 * admitted because local development is a real origin; nothing here promotes it to production, and
 * `BotDomainStatus::Pending` is what stops any row from working until somebody says so. An
 * IPv6-literal origin — `http://[::1]:3000` — does NOT match and is refused; that is a known,
 * deliberate gap rather than an oversight, and widening the pattern for it means widening it for
 * bracket parsing, which is the part of URL grammar that has produced the most parser
 * disagreements.
 *
 * ═══ THE COMPOSITE FOREIGN KEY ════════════════════════════════════════════════════════════════
 *
 * `(organization_id, bot_id) -> bots (organization_id, id)`. `organization_id` is denormalized onto
 * this row so the key can exist at all. kb-tenancy-isolation NN1 would be satisfied by the bot_id
 * chain alone — `bot_domains -> bots -> organizations` is NOT NULL the whole way — but only the
 * composite form makes it IMPOSSIBLE for a row to name a bot in another organization. That matters
 * here more than almost anywhere: an origin row attached across a tenant boundary is a permanent
 * grant that every downstream check AGREES with, because it has been told that this origin belongs
 * to that bot.
 *
 * ON DELETE RESTRICT, like every other foreign key in this schema. The transitive set is empty —
 * nothing references `bot_domains` — so `CASCADE` would be SAFE, and it is still not used: bot
 * deletion is an orchestrated flow that does not exist yet, and RESTRICT means an unorchestrated
 * `DELETE FROM bots` fails loudly at this boundary instead of silently discarding an origin
 * allow-list that a security review may later need to reconstruct.
 *
 * ═══ UNIQUENESS ═══════════════════════════════════════════════════════════════════════════════
 *
 * One row per (bot, origin) — the same origin listed twice is not two grants, and a second row
 * carrying a different status would make "is this origin allowed" ambiguous in a way no reader of
 * the console could resolve.
 *
 * THE INDEX LEADS WITH `organization_id` AND THAT COSTS NOTHING IN CONSTRAINT STRENGTH, for the
 * same reason `provider_models_org_connection_model` records at length: `bot_id` functionally
 * determines `organization_id`, because the composite foreign key above requires
 * `(organization_id, bot_id)` to exist in `bots (organization_id, id)` and `id` is that table's
 * primary key. So `UNIQUE (organization_id, bot_id, origin)` admits exactly the same set of tables
 * as `UNIQUE (bot_id, origin)` would, and in exchange the tenant predicate leads, and the
 * two-column prefix is the FK-child index PostgreSQL does not create for you.
 */
return new class extends Migration
{
    public function up(): void
    {
        $statuses = $this->quotedList(BotDomainStatus::values());

        $this->run(<<<SQL
            CREATE TABLE bot_domains (
                id              char(26) COLLATE "C" PRIMARY KEY,
                organization_id char(26) COLLATE "C" NOT NULL
                                REFERENCES organizations (id) ON DELETE RESTRICT,
                bot_id          char(26) COLLATE "C" NOT NULL,

                -- COLLATE "C": this string is compared for exact byte equality against an `Origin`
                -- header and against a postMessage targetOrigin, and never sorted for a human. A
                -- collation-dependent index on it is an index a base-image bump can invalidate.
                origin          text COLLATE "C" NOT NULL,

                status          text NOT NULL DEFAULT 'pending',
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT bot_domains_status_check CHECK (status IN ({$statuses})),

                -- scheme + host + optional port. No path, no query, no fragment, no userinfo, no
                -- trailing slash, no upper case, and no wildcard: the pattern has no metacharacter
                -- for one, so a matcher cannot be introduced without a migration.
                -- `{0,1}` throughout instead of the one-character optional quantifier, because PDO
                -- rewrites that character into a positional placeholder while scanning the
                -- statement.
                CONSTRAINT bot_domains_origin_exact CHECK (
                    origin ~ '^(http|https)://[a-z0-9]([a-z0-9-]{0,61}[a-z0-9]){0,1}(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9]){0,1})*(:[0-9]{1,5}){0,1}\$'
                ),

                CONSTRAINT bot_domains_bot_same_org
                    FOREIGN KEY (organization_id, bot_id)
                    REFERENCES bots (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // ONE INDEX DOING THREE JOBS, exactly as provider_models_org_connection_model does: the
        // uniqueness constraint, the FK-child index for the composite key above, and the read
        // path's index — a widget bootstrap asks "does this bot allow this origin", which is a
        // three-column equality probe this index answers without touching the heap for the match.
        $this->run(
            'CREATE UNIQUE INDEX bot_domains_org_bot_origin '
            .'ON bot_domains (organization_id, bot_id, origin)',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS bot_domains');
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
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};
