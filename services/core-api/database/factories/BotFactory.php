<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * The retrieval scope. `bot_ids` is one of the four mandatory Qdrant filter terms, so a bot fixture
 * that is quietly attached to the wrong organization defeats an isolation assertion without
 * touching a single line of production code.
 *
 * THE FAILURE THIS FACTORY IS SHAPED AROUND: `Bot::factory()->for($orgA)` fixes ONE edge. Every
 * NESTED factory this definition resolves — the provider connection, the knowledge source, the
 * starter questions — still mints its own organization. `recycle($orgA)` is what pins them all, and
 * the symptom of getting it wrong is an isolation test that passes with the tenant filter deleted.
 * So: every relation resolved here must be resolvable from the recycled organization, and none of
 * them may call `Organization::factory()` directly.
 *
 * COLUMNS THIS MUST PRODUCE (docs/11 §16):
 *
 *   id                        ULID.
 *   organization_id           NOT NULL. Direct ownership, not inherited.
 *   name                      DISTINGUISHABLE between the two orgs of a pair.
 *   slug                      unique PER ORGANIZATION. Every rate-limit and cache key built from it
 *                             carries org_id first, because a slug alone is not globally unique —
 *                             that is a shipped CVE class (WSO2 CVE-2025-13475), not a hypothetical.
 *   status                    draft | active | disabled
 *   provider_connection_id    FK, same organization. Resolve from the recycled org.
 *   model                     pinned model id from the snapshot, never a display name.
 *   system_prompt             the bot's own instructions. Retrieved content can never edit this;
 *                             an injection fixture belongs in the source text, not here.
 *   settings                  jsonb — retrieval top-k, thresholds, fallback configuration.
 *   created_at / updated_at
 *
 * RELATED TABLES a fuller fixture may need: `bot_domains` (widget origin allow-list — the
 * unlisted-origin rejection test needs a bot with a KNOWN list, so never randomize it), and
 * `bot_starter_questions`.
 *
 * FORWARD REFERENCE, DELIBERATELY IN PROSE: this factory builds App\Models\Bot, which does not
 * exist yet. The binding is NOT written as `@extends Factory<\App\Models\Bot>` plus
 * `protected $model = \App\Models\Bot::class;` because a `::class` pointer at a class that does
 * not exist is five level-8 errors per file, and phpstan.neon carries no baseline on purpose
 * (ADR-020). Nothing is lost by omitting it: Factory::modelName() resolves
 * Database\Factories\BotFactory -> App\Models\Bot by convention, so the binding is
 * identical the moment the model lands. RESTORE BOTH LINES in the PR that creates the model, in
 * the same commit as this paragraph's deletion.
 *
 * @extends Factory<\Illuminate\Database\Eloquent\Model>
 */
final class BotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        throw new RuntimeException(
            'BotFactory is scaffolded but not implemented: it needs App\Models\Bot and the bots '
            .'migration. Every nested factory it resolves must be recycle()-safe — see this class '
            .'docblock.',
        );
    }

    /**
     * Give this bot a known widget origin allow-list.
     *
     * Never randomize this list. The origin-rejection test it exists for — asserting that an origin
     * NOT on the allow-list is refused — has not been written yet, and neither has this method's
     * body. When both land, a faker-generated allow-list would make that assertion depend on faker
     * never colliding with the origin the test sends, so the caller passes origins explicitly.
     *
     * @param  list<string>  $origins
     */
    public function withOrigins(array $origins): static
    {
        throw new RuntimeException('BotFactory::withOrigins() is scaffolded but not implemented.');
    }
}
