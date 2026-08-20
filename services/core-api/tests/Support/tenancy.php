<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\TenantPair;

/**
 * The two-organization canary harness.
 *
 * THIS IS THE CENTREPIECE OF THE SECURITY SUITE. Every §22.5 isolation test calls tenantPair() and
 * nothing else, so the shape of this fixture is what makes a leaky test harder to write than a
 * correct one.
 *
 * THERE IS DELIBERATELY NO SINGLE-ORGANIZATION HELPER, and there never will be. With one tenant
 * there is nothing to leak, so a one-org fixture passes against code with NO FILTER AT ALL. That is
 * not a gap in the harness; it is the reason the harness exists (pest-testing NN1).
 *
 * Three properties, each fixing a specific way these tests go quietly green:
 *
 *   1. A FRESH CANARY PER TEST. 'CANARY-'.Str::ulid(), regenerated on every call, so a stale Qdrant
 *      point or a warm answer cache left by a previous run can never satisfy the assertion.
 *   2. recycle() ON EVERY FACTORY. for($orgA) fixes one edge; every NESTED factory the definition
 *      resolves still mints its own organization. recycle() pins ONE organization across the whole
 *      graph. The symptom of getting this wrong is an isolation test that passes with the filter
 *      deleted — and BotFactory now RAISES rather than minting one, so the mistake is a named
 *      exception instead of a silent third organization.
 *   3. A POSITIVE CONTROL IS MANDATORY AT THE CALL SITE. Assert the canary IS returned to Org B
 *      before asserting it is NOT returned to Org A. Without it the test also passes when the
 *      surface is broken and returns nothing at all — and that is the assertion people delete first
 *      when it is slow.
 *
 * The canary is planted in ORG B's content and asserted absent from ORG A's raw response BODY, so a
 * canary hiding in a citation title, an export cell, or a cached completion still trips it.
 *
 * ── WHERE THE CANARY LIVES TODAY, AND WHERE IT MOVES ──────────────────────────────────────────
 *
 * IT IS IN ORG B'S BOT WELCOME MESSAGE, and that is a Phase B position rather than the final one.
 * The design has always been that it lives in Org B's INDEXED SOURCE CONTENT, so that a leak
 * through retrieval, a citation title, a cached completion or an export trips it. That needs
 * `KnowledgeSourceFactory::indexed()`, which needs the knowledge_sources / source_items /
 * source_versions / chunks migrations and a real Qdrant container — all of them Phase C.
 *
 * The welcome message is the closest analogue available now and it is not a token gesture: it is
 * tenant-authored text that crosses the wire on the bot list, the bot detail, the widget bootstrap
 * and the hosted-chat first-run screen, which are four of the surfaces Phase B is about to build.
 * A leak through any of them trips this canary today.
 *
 * WHEN PHASE C LANDS, MOVE IT — do not add a second canary. Two canaries mean two assertions to
 * keep in step and a test that can pass on the wrong one. The bot's welcome message keeps carrying
 * a distinguishable value (BotFactory gives every bot one), so nothing regresses by the move.
 */
function tenantPair(): TenantPair
{
    $canary = 'CANARY-'.Str::ulid();

    [$a, $b] = Organization::factory()->count(2)->create()->all();

    $botA = Bot::factory()->recycle($a)->create();

    // ORG B'S BOT CARRIES THE CANARY, and Org A's deliberately does not. The sentence is the one
    // the shipped design used for the indexed source it will eventually move to, kept verbatim so
    // the move is a one-line diff rather than a rewrite.
    $botB = Bot::factory()->recycle($b)->create([
        'welcome_message' => "Refunds are accepted for 30 days. {$canary}",
    ]);

    /*
     * TODO(phase-c): the knowledge-source half of the shipped design, waiting on the
     * knowledge_sources / source_items / source_versions / chunks migrations, on
     * App\Models\KnowledgeSource, and on a real Qdrant container in the `test` Compose profile.
     * When it lands, MOVE the canary here from $botB's welcome message above — do not plant a
     * second one.
     *
     *   // ->indexed() drives the REAL ingestion path into the test Qdrant container.
     *   // QdrantClient(":memory:") ignores the root filter — never use it here.
     *   KnowledgeSource::factory()->recycle($b)->assignedTo($botB)
     *       ->indexed("Refunds are accepted for 30 days. {$canary}")->create();
     *
     * KnowledgeSourceFactory will also carry a crossOrg() state that deliberately assigns a source
     * from one organization to a bot in another — the one row that can span two orgs. It exists
     * only to assert that BOTH the service AND the composite foreign key reject it, which is why
     * assignedTo() must never quietly infer an organization. The target of that composite key
     * already exists: `bots_org_scoped_key` on (organization_id, id).
     */

    return new TenantPair(
        a: $a, b: $b, botA: $botA, botB: $botB, canary: $canary,
        actorA: User::factory()->recycle($a)->orgRole(OrgRole::Admin)->create(),
        actorB: User::factory()->recycle($b)->orgRole(OrgRole::Admin)->create(),
    );
}
