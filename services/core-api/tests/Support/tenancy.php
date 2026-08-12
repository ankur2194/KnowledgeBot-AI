<?php

declare(strict_types=1);

use Tests\Support\TenantPair;

/**
 * The two-organization canary harness.
 *
 * THIS IS THE CENTREPIECE OF THE SECURITY SUITE, and the SIGNATURE ships now — before the models
 * exist — because that is the part which has to be right from day one. Every §22.5 isolation test
 * calls tenantPair() and nothing else, so the shape of this fixture is what makes a leaky test
 * harder to write than a correct one.
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
 *      deleted.
 *   3. A POSITIVE CONTROL IS MANDATORY AT THE CALL SITE. Assert the canary IS returned to Org B
 *      before asserting it is NOT returned to Org A. Without it the test also passes when the
 *      surface is broken and returns nothing at all — and that is the assertion people delete first
 *      when it is slow.
 *
 * The canary is planted in ORG B's content and asserted absent from ORG A's raw response BODY, so a
 * canary hiding in a citation title, an export cell, or a cached completion still trips it.
 *
 * KnowledgeSourceFactory will also carry a crossOrg() state that deliberately assigns a source from
 * one organization to a bot in another — the one row that can span two orgs. It exists only to
 * assert that BOTH the service AND the composite foreign key reject it, which is why assignedTo()
 * must never quietly infer an organization.
 */
function tenantPair(): TenantPair
{
    /*
     * TODO(factories): the body below is the shipped design, waiting on the models. Uncomment it
     * one line at a time as each factory lands. DO NOT CHANGE THE SIGNATURE.
     *
     *   $canary = 'CANARY-'.Str::ulid();
     *
     *   [$a, $b] = Organization::factory()->count(2)->create()->all();
     *
     *   $botA = Bot::factory()->recycle($a)->create();
     *   $botB = Bot::factory()->recycle($b)->create();
     *
     *   // ->indexed() drives the REAL ingestion path into the test Qdrant container.
     *   // QdrantClient(":memory:") ignores the root filter — never use it here.
     *   KnowledgeSource::factory()->recycle($b)->assignedTo($botB)
     *       ->indexed("Refunds are accepted for 30 days. {$canary}")->create();
     *
     *   return new TenantPair(
     *       a: $a, b: $b, botA: $botA, botB: $botB, canary: $canary,
     *       actorA: User::factory()->recycle($a)->orgRole(OrgRole::Admin)->create(),
     *       actorB: User::factory()->recycle($b)->orgRole(OrgRole::Admin)->create(),
     *   );
     */
    throw new \RuntimeException(
        'tenantPair() is scaffolded but not implemented: it needs the Organization, Bot, '
        .'KnowledgeSource and User factories. Uncomment the body in tests/Support/tenancy.php.',
    );
}
