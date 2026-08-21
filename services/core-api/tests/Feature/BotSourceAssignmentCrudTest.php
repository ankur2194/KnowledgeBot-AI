<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\SourceState;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\BotSourceAssignment;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Which knowledge sources a bot may answer from — the endpoints
|--------------------------------------------------------------------------
|
| The isolation half — the cross-organization grant, the 403/404 split, the role matrix and the
| binding hops — is tests/Security/BotSourceAssignmentAccessTest.php. This file is the behaviour: the
| envelope, the defaults, the two refusals, the audit rows, and the cascade a bot delete performs
| over grants the request never named.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * One organization, one bot, one owner, and a source with nothing pointed at it yet.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own. Pest declares test-file helpers at FILE
 * SCOPE, so a name another test file already uses is a redeclaration fatal in a full run and only
 * in a full run.
 *
 * @return array{org: Organization, owner: User, bot: Bot, source: KnowledgeSource}
 */
function assignmentFixture(): array
{
    $org = Organization::factory()->create(['name' => 'Assign Org ALPHA', 'slug' => 'assign-alpha']);

    return [
        'org' => $org,
        'owner' => User::factory()->recycle($org)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('assign-owner')]),
        'bot' => Bot::factory()->recycle($org)->create(['name' => 'ALPHA assign bot', 'slug' => 'alpha-assign']),
        'source' => KnowledgeSource::factory()->recycle($org)->create(['name' => 'ALPHA employee handbook']),
    ];
}

it('grants, lists and withdraws, publishing the paginated envelope the console reads', function (): void {
    $fixture = assignmentFixture();

    $url = "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}/source-assignments";

    SpaSession::establish(currentTest(), $fixture['owner']);

    // AN EMPTY LIST IS STILL AN ENVELOPE. `meta` is present on an empty page too — a client that had
    // to branch on its absence would be branching on "did this list have results", which is exactly
    // the question `total` answers, and `apps/web/src/lib/table/envelope.ts` THROWS rather than
    // degrading when it cannot read the shape.
    $empty = currentTest()->getJson($url, spaHeaders())->assertOk();

    expect(array_keys((array) $empty->json('data')))->toBe(['source_assignments', 'meta'])
        ->and($empty->json('data.source_assignments'))->toBe([])
        ->and($empty->json('data.meta.total'))->toBe(0)
        ->and($empty->json('data.meta.sort'))->toBe('priority')
        ->and($empty->json('data.meta.dir'))->toBe('asc');

    // THE DEFAULTS ARE THE COLUMN DEFAULTS. A body naming only `source_id` produces priority 0 and
    // an ENABLED grant, because the ordinary act of assigning a source is the act of letting the
    // bot answer from it.
    $created = currentTest()->postJson($url, ['source_id' => $fixture['source']->id], spaHeaders())
        ->assertCreated()
        ->assertJsonPath('data.source_id', $fixture['source']->id)
        ->assertJsonPath('data.priority', 0)
        ->assertJsonPath('data.enabled', true)
        // THE SOURCE IS NESTED, IN THE SAME SHAPE THE SOURCE LIST PUBLISHES, so a console renders a
        // name without a second request. It is `SourceResource` and not a narrower copy — a second
        // source shape is the drift contract-steward exists to catch.
        ->assertJsonPath('data.source.id', $fixture['source']->id)
        ->assertJsonPath('data.source.name', 'ALPHA employee handbook');

    $assignmentId = (string) $created->json('data.id');

    // AND THE OWNERSHIP COLUMNS ARE NOT RENDERED. The client asked through a URL that already named
    // both, so echoing them adds nothing and puts a tenant identifier into every cached body.
    $body = (array) $created->json('data');

    expect(array_key_exists('organization_id', $body))->toBeFalse()
        ->and(array_key_exists('bot_id', $body))->toBeFalse();

    // THE SAME GRANT COMES BACK ON THE LIST.
    currentTest()->getJson($url, spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.source_assignments.0.id', $assignmentId)
        ->assertJsonPath('data.source_assignments.0.source.name', 'ALPHA employee handbook');

    // A SECOND GRANT FOR THE SAME SOURCE IS A 422 AND NOT A 500. `bot_source_assignments_org_bot_source`
    // is UNIQUE, and without the pre-flight check the duplicate arrives as SQLSTATE 23505 rendered
    // as `internal_dependency` — a bug report about the server for what is plainly a bad request.
    currentTest()->postJson($url, ['source_id' => $fixture['source']->id], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors('source_id');

    // WITHDRAWING IT REMOVES THE GRANT AND NOT THE SOURCE.
    currentTest()->deleteJson($url.'/'.$assignmentId, [], spaHeaders())->assertOk();

    expect(BotSourceAssignment::withoutGlobalScopes()->whereKey($assignmentId)->exists())->toBeFalse()
        ->and(KnowledgeSource::withoutGlobalScopes()->whereKey($fixture['source']->id)->exists())->toBeTrue();

    // DELETE IS NOT IDEMPOTENT. A second one is a 404, because an audit row exists for the first and
    // a 200 for the second would claim this actor withdrew a grant the trail does not record.
    currentTest()->deleteJson($url.'/'.$assignmentId, [], spaHeaders())
        ->assertStatus(404)
        ->assertJsonPath('error_class', 'authorization');
});

it('refuses a grant to a source that is being removed', function (): void {
    $fixture = assignmentFixture();

    $url = "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}/source-assignments";

    SpaSession::establish(currentTest(), $fixture['owner']);

    // PHASE 1 OF THE TWO-PHASE DELETE, written directly rather than through the endpoint so this
    // test is about the assignment refusal and not about the source lifecycle. Both terms are set,
    // because the service requires both to be clear and a fixture that set only one would leave the
    // other half of the predicate unexercised.
    KnowledgeSource::withoutGlobalScopes()->whereKey($fixture['source']->id)->update([
        'status' => SourceState::Deleting->value,
        'deleted_at' => now(),
    ]);

    currentTest()->postJson($url, ['source_id' => $fixture['source']->id], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors('source_id');

    expect(BotSourceAssignment::withoutGlobalScopes()->where('bot_id', $fixture['bot']->id)->count())->toBe(0);

    // A SOURCE THAT IS MERELY PROCESSING IS FINE, and that is the other half of the rule rather
    // than a bonus assertion: assigning while a document is still ingesting is the ordinary console
    // flow, the grant contributes nothing until the item has an active version anyway, and refusing
    // it would force the operator to come back later for no gain.
    $ingesting = KnowledgeSource::factory()->recycle($fixture['org'])
        ->status(SourceState::Parsing)->create(['name' => 'ALPHA mid-ingest']);

    currentTest()->postJson($url, ['source_id' => $ingesting->id], spaHeaders())->assertCreated();
});

it('refuses every write while the organization is suspended, and still serves the list', function (): void {
    $fixture = assignmentFixture();

    $url = "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}/source-assignments";

    SpaSession::establish(currentTest(), $fixture['owner']);

    $created = currentTest()->postJson($url, ['source_id' => $fixture['source']->id], spaHeaders())
        ->assertCreated();

    Organization::withoutGlobalScopes()->whereKey($fixture['org']->id)
        ->update(['status' => OrganizationStatus::Suspended->value]);

    currentTest()->postJson($url, ['source_id' => $fixture['source']->id], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    currentTest()->deleteJson($url.'/'.$created->json('data.id'), [], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    // AND THE READ IS UNAFFECTED, deliberately: seeing which documents a bot may use is exactly
    // what a suspended organization's operator needs to do.
    currentTest()->getJson($url, spaHeaders())->assertOk()->assertJsonPath('data.meta.total', 1);
});

it('audits both the grant and its withdrawal, with the source name beside the ids', function (): void {
    $fixture = assignmentFixture();

    $url = "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}/source-assignments";

    SpaSession::establish(currentTest(), $fixture['owner']);

    $assignmentId = (string) currentTest()
        ->postJson($url, ['source_id' => $fixture['source']->id, 'priority' => 3], spaHeaders())
        ->assertCreated()
        ->json('data.id');

    /** @var array<string, mixed> $created */
    $created = (array) AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_SOURCE_ASSIGNMENT_CREATED)
        ->where('subject_id', '=', $assignmentId)
        ->firstOrFail()
        ->details;

    // `source_name` SITS BESIDE `source_id` AND THAT IS NOT REDUNDANCY. Both parents are hard
    // deletes, so a row carrying only ULIDs resolves to nothing on either end afterwards — and
    // "which documents was this bot allowed to answer from" is precisely the question asked after
    // the fact.
    expect($created['bot_id'])->toBe($fixture['bot']->id)
        ->and($created['source_id'])->toBe($fixture['source']->id)
        ->and($created['source_name'])->toBe('ALPHA employee handbook')
        ->and($created['priority'])->toBe(3)
        ->and($created['enabled'])->toBeTrue();

    currentTest()->deleteJson($url.'/'.$assignmentId, [], spaHeaders())->assertOk();

    /** @var array<string, mixed> $deleted */
    $deleted = (array) AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_SOURCE_ASSIGNMENT_DELETED)
        ->where('subject_id', '=', $assignmentId)
        ->firstOrFail()
        ->details;

    // WHETHER IT WAS LIVE WHEN IT WENT. A removed disabled row granted nothing; a removed enabled
    // one did, and that difference is the whole reading of this row in an investigation.
    expect($deleted['source_name'])->toBe('ALPHA employee handbook')
        ->and($deleted['enabled'])->toBeTrue()
        ->and($deleted['priority'])->toBe(3);
});

it('takes a deleted bot\'s grants with it, and writes a row for each one it destroys', function (): void {
    /*
     * ── THE FOURTH CHILD COLLECTION, AND THE WINDOW IN WHICH NO TEST COULD SEE IT ────────────
     *
     * `bot_source_assignments` references `bots (organization_id, id)` with ON DELETE RESTRICT and
     * landed with Phase C1, but nothing wrote to it until the endpoints in this batch — so
     * `EloquentBotRepository::delete()` was complete for exactly as long as the table was empty,
     * and the first grant would have turned every delete of that bot into SQLSTATE 23503 rendered
     * as a 500. This test is the one that would have caught it, and it could not have existed
     * before there was a way to create a row.
     *
     * REMOVING THE GRANTS SILENTLY WOULD BE THE OTHER HALF OF THE SAME DEFECT. `AuditLogger` makes
     * both assignment operations ON_FAILURE_ABORT on the ground that a retrieval-scope grant
     * reaching DOCUMENTS is the allow-list's argument with more to lose, and the destruction of a
     * grant changes the retrieval scope exactly as much as its creation did.
     */
    $fixture = assignmentFixture();

    $doomed = $fixture['bot'];
    $sibling = Bot::factory()->recycle($fixture['org'])
        ->create(['name' => 'ALPHA sibling', 'slug' => 'alpha-assign-sibling']);

    SpaSession::establish(currentTest(), $fixture['owner']);

    $second = KnowledgeSource::factory()->recycle($fixture['org'])->create(['name' => 'ALPHA policies']);

    foreach ([$fixture['source'], $second] as $source) {
        currentTest()->postJson(
            "/api/v1/organizations/{$fixture['org']->id}/bots/{$doomed->id}/source-assignments",
            ['source_id' => $source->id],
            spaHeaders(),
        )->assertCreated();
    }

    // THE SIBLING GETS ONE TOO. A predicate that lost its `bot_id` term stays inside one tenant, so
    // no cross-tenant assertion anywhere would move — what it costs is every OTHER bot in the
    // organization losing its corpus from a request that returned 200 and named one bot.
    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['org']->id}/bots/{$sibling->id}/source-assignments",
        ['source_id' => $fixture['source']->id],
        spaHeaders(),
    )->assertCreated();

    // POSITIVE CONTROL, FIRST. All three grants are really there, so "the sibling's survived"
    // cannot be satisfied by a fixture that never created it.
    expect(BotSourceAssignment::withoutGlobalScopes()->where('bot_id', $doomed->id)->count())->toBe(2)
        ->and(BotSourceAssignment::withoutGlobalScopes()->where('bot_id', $sibling->id)->count())->toBe(1);

    // AND THE DELETE ITSELF — a 200 rather than the 500 an unhandled ON DELETE RESTRICT produces.
    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['org']->id}/bots/{$doomed->id}",
        [],
        spaHeaders(),
    )->assertOk();

    expect(BotSourceAssignment::withoutGlobalScopes()->where('bot_id', $doomed->id)->count())->toBe(0)
        ->and(BotSourceAssignment::withoutGlobalScopes()->where('bot_id', $sibling->id)->count())->toBe(1);

    // ONE `bot.source_assignment.deleted` ROW PER GRANT, each naming its own source — the trail
    // that survives a hard delete of both parents.
    $names = AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_SOURCE_ASSIGNMENT_DELETED)
        ->get()
        ->map(static fn (AuditLog $row): mixed => ((array) $row->details)['source_name'] ?? null)
        ->all();

    sort($names);

    expect($names)->toBe(['ALPHA employee handbook', 'ALPHA policies']);

    // AND THE `bot.deleted` ROW CARRIES THE TRIPWIRE. A reader who lands on it and sees two grants
    // knows to go looking for the rows above; a reader who saw nothing would conclude the bot had
    // no corpus at all.
    /** @var array<string, mixed> $botRow */
    $botRow = (array) AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_DELETED)
        ->where('subject_id', '=', $doomed->id)
        ->firstOrFail()
        ->details;

    expect($botRow['source_assignment_count'])->toBe(2)
        ->and($botRow['enabled_source_assignment_count'])->toBe(2);
});

it('sorts and filters within one bot, always with a deterministic tie-break', function (): void {
    $fixture = assignmentFixture();

    $url = "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}/source-assignments";

    SpaSession::establish(currentTest(), $fixture['owner']);

    $second = KnowledgeSource::factory()->recycle($fixture['org'])->create(['name' => 'ALPHA travel policy']);

    currentTest()->postJson($url, ['source_id' => $second->id, 'priority' => 1], spaHeaders())->assertCreated();
    currentTest()->postJson($url, ['source_id' => $fixture['source']->id, 'priority' => 0], spaHeaders())->assertCreated();

    // DEFAULT SORT IS `priority` ASCENDING, so the second grant made comes back first.
    currentTest()->getJson($url, spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.source_assignments.0.source_id', $fixture['source']->id)
        ->assertJsonPath('data.source_assignments.1.source_id', $second->id);

    // AND `id` REVERSES IT, because `id` is a ULID and therefore creation order.
    currentTest()->getJson($url.'?sort=id&dir=asc', spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.source_assignments.0.source_id', $second->id)
        ->assertJsonPath('data.meta.sort', 'id');

    // THE FILTER SEARCHES THE SOURCE, because the grant row holds no text a human would type.
    currentTest()->getJson($url.'?filter=travel', spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.meta.filter', 'travel')
        ->assertJsonPath('data.source_assignments.0.source_id', $second->id);

    // A SORT OUTSIDE THE CLOSED SET IS A 422 AND NEVER AN ORDER BY. A caller-chosen sort reaches an
    // `ORDER BY`, so an open set is a caller choosing which index the query uses at best.
    currentTest()->getJson($url.'?sort=source_id', spaHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});
