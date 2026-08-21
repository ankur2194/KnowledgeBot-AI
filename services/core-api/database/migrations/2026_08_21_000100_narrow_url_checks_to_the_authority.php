<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `knowledge_sources_origin_url_scheme` AND `source_items_url_scheme`, NARROWED TO THE AUTHORITY.
 *
 * ═══ WHAT THE OLD PATTERN SAID, AND WHAT IT MEANT TO SAY ══════════════════════════════════════
 *
 * Both constraints were `~ '^https{0,1}://[^@[:space:]]+$'`, which forbids `@` ANYWHERE after the
 * scheme. The hazard being refused is `https://user:password@host/` — credentials in a URL, which
 * a crawler would send to a host we do not control, and which land verbatim in every log line and
 * span attribute that carries the target. That is a real refusal and it is kept.
 *
 * The pattern also refused `@` in the PATH, which is not that hazard and is not rare. Mastodon,
 * Medium and a dozen other platforms put a handle at `/@name`, and a documentation site can
 * perfectly well address a page as `/u/a@b.com`. Those are ordinary crawl targets.
 *
 * ═══ WHY THIS IS A CORRECTNESS FIX AND NOT A LOOSENING ════════════════════════════════════════
 *
 * The FormRequest that guards the write side validated with Laravel's `url:http,https`, which
 * permits `@` in both the userinfo group and the path. So a URL with a handle in it PASSED
 * validation and then violated this CHECK on INSERT — surfacing as an unconverted `QueryException`
 * and a 500 on a route whose documented failure shape is a per-field 422. The two halves are
 * brought into agreement here and in `StoreSourceRequest`, and they agree on the narrower,
 * meaningful rule rather than on the wider accidental one.
 *
 * ═══ THE PATTERN ══════════════════════════════════════════════════════════════════════════════
 *
 *   ^https{0,1}://          the two schemes, unchanged
 *   [^@/?#[:space:]]+       the AUTHORITY: at least one character, no `@` (so no userinfo), and
 *                           none of the three delimiters that would end it early
 *   ([/?#][^[:space:]]*)?   optionally a path, query or fragment, in which `@` is ordinary
 *   $
 *
 * Whitespace stays refused throughout: a URL carrying a raw space is a parsing accident on the way
 * in, and `[:space:]` also covers the newline that would let one column value forge a second log
 * line.
 *
 * ═══ WHY A NEW MIGRATION RATHER THAN AN EDIT ══════════════════════════════════════════════════
 *
 * The create migrations have been applied. Editing an applied migration changes nothing in a
 * database that has already run it and silently diverges every environment from every other, which
 * is the failure mode a migration ledger exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE knowledge_sources DROP CONSTRAINT knowledge_sources_origin_url_scheme'
        );
        DB::statement(
            'ALTER TABLE knowledge_sources ADD CONSTRAINT knowledge_sources_origin_url_scheme '
            ."CHECK (origin_url IS NULL OR origin_url ~ '^https{0,1}://[^@/?#[:space:]]+([/?#][^[:space:]]*)?\$')"
        );

        DB::statement('ALTER TABLE source_items DROP CONSTRAINT source_items_url_scheme');
        DB::statement(
            'ALTER TABLE source_items ADD CONSTRAINT source_items_url_scheme '
            ."CHECK (url IS NULL OR url ~ '^https{0,1}://[^@/?#[:space:]]+([/?#][^[:space:]]*)?\$')"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE knowledge_sources DROP CONSTRAINT knowledge_sources_origin_url_scheme'
        );
        DB::statement(
            'ALTER TABLE knowledge_sources ADD CONSTRAINT knowledge_sources_origin_url_scheme '
            ."CHECK (origin_url IS NULL OR origin_url ~ '^https{0,1}://[^@[:space:]]+\$')"
        );

        DB::statement('ALTER TABLE source_items DROP CONSTRAINT source_items_url_scheme');
        DB::statement(
            'ALTER TABLE source_items ADD CONSTRAINT source_items_url_scheme '
            ."CHECK (url IS NULL OR url ~ '^https{0,1}://[^@[:space:]]+\$')"
        );
    }
};
