<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The admin audit-trail surface (Phase 6a)
|--------------------------------------------------------------------------
|
|   GET .../audit-logs      one page of this organization's audited events, filterable
|
| ONE ENDPOINT, READ-ONLY, AND NO ROW ROUTE — `AuditLogController` records why a
| `/audit-logs/{auditLog}` would be a binding over a table with a composite primary key and no
| `#[ScopedBy]` backstop, handed to a policy that throws on a platform-scope row.
|
| EVERY ROW IN THIS FILE IS WRITTEN THROUGH `AuditLogger`, THE PRODUCTION WRITER, and never through
| a factory. There is deliberately no `AuditLogFactory` — the model's own docblock says so: "the
| production writer is the only way in and a test that bypasses it is a test of nothing." That is
| what makes the `details` assertions below assertions about the allow-list rather than about a
| fixture somebody wrote by hand.
|
| THE ISOLATION AND REDACTION HALVES ARE NOT HERE. `tests/Security/AuditTrailAccessTest.php` runs
| the two-organization harness, plants a platform-scope row, and greps a serialized response for a
| real sealed credential.
*/

beforeEach(function (): void {
    // Pinned, because the default sort is a clock and two rows written in one request share
    // `created_at` to the microsecond — see the ordering test, where that is the point.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * One organization with a member per role.
 *
 * A SINGLE ORGANIZATION, WHICH IS SAFE HERE ONLY BECAUSE NOTHING IN THIS FILE ASSERTS AN ABSENCE
 * ACROSS TENANTS. `pest-testing` NN1 forbids it for an isolation test; the cross-tenant half is in
 * `tests/Security/`, on `tenantPair()`.
 *
 * @return array{org: Organization, owner: User, admin: User, analyst: User, manager: User}
 */
function auditTrailFixture(): array
{
    $org = Organization::factory()->create();

    return [
        'org' => $org,
        'owner' => User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create(),
        'admin' => User::factory()->recycle($org)->orgRole(OrgRole::Admin)->create(),
        'analyst' => User::factory()->recycle($org)->orgRole(OrgRole::Analyst)->create(),
        'manager' => User::factory()->recycle($org)->orgRole(OrgRole::KnowledgeManager)->create(),
    ];
}

it('returns one page in the fixed envelope, newest first', function (): void {
    $f = auditTrailFixture();
    $logger = app(AuditLogger::class);

    // THREE ROWS AT THREE DISTINCT INSTANTS. The clock is moved between them rather than left to
    // run, because Eloquent writes `created_at` from one `Carbon::now()` per request and rows
    // written in one test would otherwise share it exactly — which is the case the `id` tie-break
    // exists for and is asserted separately below.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-25 09:00:00', 'UTC'));
    $oldest = $logger->record(AuditLogger::LOGOUT, $f['org']->id, $f['owner']->id);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-26 09:00:00', 'UTC'));
    $middle = $logger->record(AuditLogger::LOGIN_SUCCEEDED, $f['org']->id, $f['admin']->id, [
        'email' => $f['admin']->email,
        'mechanism' => 'session',
    ]);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-27 09:00:00', 'UTC'));
    $newest = $logger->record(AuditLogger::LOGIN_FAILED, $f['org']->id, null, [
        'email' => 'nobody@example.test',
        'reason' => 'unknown_address',
    ]);

    assert($oldest instanceof AuditLog && $middle instanceof AuditLog && $newest instanceof AuditLog);

    $response = currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs")
        ->assertOk();

    $response->assertJsonPath('data.meta.total', 3)
        ->assertJsonPath('data.meta.page', 1)
        // NEWEST FIRST. An audit trail is read from the present backwards, and every index on the
        // table is built `created_at DESC` for exactly that; page one under an ascending sort would
        // be a bookmark to the oldest login in the organization's history.
        ->assertJsonPath('data.meta.sort', 'created_at')
        ->assertJsonPath('data.meta.dir', 'desc')
        // ALWAYS NULL — there is no free-text `filter` on this endpoint, and the key stays because
        // `meta` is one shared component.
        ->assertJsonPath('data.meta.filter', null);

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->json('data.audit_logs');

    expect(array_column($rows, 'id'))->toBe([$newest->id, $middle->id, $oldest->id]);

    // `outcome` IS DERIVED FROM THE OPERATION AND NEVER SUBMITTED, which is what makes a row
    // claiming `auth.login.failed` with `outcome = success` unrepresentable.
    expect($rows[0]['operation'])->toBe(AuditLogger::LOGIN_FAILED)
        ->and($rows[0]['outcome'])->toBe(AuditLogger::OUTCOME_FAILURE)
        ->and($rows[1]['outcome'])->toBe(AuditLogger::OUTCOME_SUCCESS)
        // NULL ACTOR IS A REAL VALUE: a failed login has no authenticated identity, and the column
        // carries no foreign key precisely so the row outlives the person it names.
        ->and($rows[0]['actor_id'])->toBeNull()
        ->and($rows[1]['actor_id'])->toBe($f['admin']->id);
});

it('renders the allow-listed details whole, and enriches nothing', function (): void {
    $f = auditTrailFixture();
    $bot = Bot::factory()->recycle($f['org'])->create();

    $row = app(AuditLogger::class)->record(
        AuditLogger::BOT_CREATED,
        $f['org']->id,
        $f['admin']->id,
        // `name` and `slug` are both on `bot.created`'s allow-list; `bot_id` deliberately is NOT,
        // because the subject pair below already names the record and a details field repeating it
        // would be a second spelling of one fact. Passing only listed fields keeps this test about
        // the PROJECTION — the drop-and-warn path is exercised on its own, one test down.
        ['name' => $bot->name, 'slug' => $bot->slug],
        Bot::class,
        $bot->id,
    );

    assert($row instanceof AuditLog);

    /** @var array<string, mixed> $rendered */
    $rendered = currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs?operation=".AuditLogger::BOT_CREATED)
        ->assertOk()
        ->json('data.audit_logs.0');

    // THE SUBJECT IS A (TYPE, ID) PAIR AND THE TYPE IS THE FQCN. That is what every call site
    // writes (`Bot::class`) and what the migration corrected an earlier comment to say — two
    // spellings of one fact means a query for a subject finds half its rows.
    expect($rendered['subject_type'])->toBe(Bot::class)
        ->and($rendered['subject_id'])->toBe($bot->id)
        // `details` IS THE STORED BLOB, UNFILTERED AND UNENRICHED. A read path that resolved the
        // subject or joined anything would be adding fields the write-side allow-list never
        // reviewed, which is exactly how a redaction guarantee stops being one.
        ->and($rendered['details'])->toBe($row->details)
        ->and($rendered['request_id'])->toBe($row->request_id);

    // `organization_id` IS NOT PUBLISHED: every row on this surface belongs to the organization in
    // the path, so the field would be one constant repeated on every row of every page — and its
    // presence would invite a client to believe the endpoint could return more than one.
    expect(array_key_exists('organization_id', $rendered))->toBeFalse();
});

it('drops an unlisted details key before it can ever be read back', function (): void {
    $f = auditTrailFixture();

    // THE WRITE-SIDE ALLOW-LIST IS WHAT MAKES THE READ SAFE, and this asserts it end to end rather
    // than trusting the unit test: `auth.logout` admits NOTHING, so a caller that passes a field
    // anyway has it dropped at the writer and there is nothing for this endpoint to render.
    app(AuditLogger::class)->record(AuditLogger::LOGOUT, $f['org']->id, $f['owner']->id, [
        'api_key' => 'sk-should-never-be-stored-01JQZ',
    ]);

    $body = (string) currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs")
        ->assertOk()
        ->getContent();

    // POSITIVE CONTROL FIRST: the row is really there. Without it the absence below also passes
    // when the write failed and the endpoint returned nothing at all.
    expect($body)->toContain(AuditLogger::LOGOUT);

    // str_contains and not ->not->toContain(): `not` treats any failure as success, and
    // toContain() would read a message argument as a second needle.
    expect(str_contains($body, 'sk-should-never-be-stored-01JQZ'))
        ->toBeFalse('an unlisted details key reached the audit read surface');
    expect(str_contains($body, 'api_key'))
        ->toBeFalse('an unlisted details KEY reached the audit read surface');
});

it('filters by actor, operation, outcome, subject and window', function (): void {
    $f = auditTrailFixture();
    $logger = app(AuditLogger::class);
    $bot = Bot::factory()->recycle($f['org'])->create();

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20 09:00:00', 'UTC'));
    $old = $logger->record(AuditLogger::LOGOUT, $f['org']->id, $f['owner']->id);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-26 09:00:00', 'UTC'));
    $byAdmin = $logger->record(AuditLogger::LOGIN_SUCCEEDED, $f['org']->id, $f['admin']->id, [
        'email' => $f['admin']->email, 'mechanism' => 'session',
    ]);
    $failure = $logger->record(AuditLogger::LOGIN_FAILED, $f['org']->id, null, [
        'email' => 'nobody@example.test', 'reason' => 'unknown_address',
    ]);
    $aboutBot = $logger->record(
        AuditLogger::BOT_CREATED,
        $f['org']->id,
        $f['admin']->id,
        ['name' => $bot->name, 'slug' => $bot->slug],
        Bot::class,
        $bot->id,
    );

    assert($old instanceof AuditLog && $byAdmin instanceof AuditLog);
    assert($failure instanceof AuditLog && $aboutBot instanceof AuditLog);

    $ask = function (string $query) use ($f): array {
        /** @var list<array<string, mixed>> $rows */
        $rows = currentTest()->actingAs($f['owner'])
            ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs?{$query}")
            ->assertOk()
            ->json('data.audit_logs');

        return array_column($rows, 'id');
    };

    // POSITIVE CONTROL FIRST: unfiltered, all four rows are there.
    expect($ask(''))->toHaveCount(4);

    // `audit_logs_org_actor_created (organization_id, actor_id, created_at DESC) WHERE actor_id IS
    // NOT NULL` — the equality predicate proves the partial index's own condition.
    // BOTH SHARE `created_at` — they were written at one frozen instant, which is the shape a
    // single action auditing several children produces — so the ULID tie-break decides, and it
    // follows the sort direction: newest id first under a descending sort.
    expect($ask("actor_id={$f['admin']->id}"))->toBe([$aboutBot->id, $byAdmin->id]);
    expect($ask('operation='.AuditLogger::LOGIN_FAILED))->toBe([$failure->id]);
    expect($ask('outcome='.AuditLogger::OUTCOME_FAILURE))->toBe([$failure->id]);

    // `audit_logs_org_subject_created` — "the audit trail for THIS record", the query the index was
    // built for and the one an admin UI opens with.
    $subject = rawurlencode(Bot::class);
    expect($ask("subject_type={$subject}&subject_id={$bot->id}"))->toBe([$aboutBot->id]);

    // TYPE ALONE IS A LEGITIMATE QUERY and is deliberately allowed even though the partial index
    // cannot serve it — `subject_type = ?` does not prove `subject_id IS NOT NULL`, so it falls to
    // `audit_logs_org_created` plus a filter, bounded to one organization.
    expect($ask("subject_type={$subject}"))->toBe([$aboutBot->id]);

    // THE WINDOW IS HALF-OPEN. Encoded, because `toIso8601String()` renders the offset as `+00:00`
    // and a bare `+` in a query string decodes to a space.
    $from = rawurlencode('2026-08-25T00:00:00+00:00');

    expect($ask("from={$from}"))->not->toContain($old->id);
    expect($ask("from={$from}"))->toHaveCount(3);

    // AN ACTOR FROM ANOTHER ORGANIZATION IS AN EMPTY PAGE AND NOT AN ERROR. There is no `exists:`
    // rule on it — an unscoped one would answer "does this user exist anywhere in the platform" to
    // any member of any organization.
    $foreign = User::factory()->recycle(Organization::factory()->create())->orgRole(OrgRole::Owner)->create();

    expect($ask("actor_id={$foreign->id}"))->toBe([]);
});

it('answers 422 for a value outside the closed operation and outcome vocabularies', function (): void {
    $f = auditTrailFixture();

    // THE DISTINCTION THIS ENDPOINT MAKES. `AuditLogger::record()` THROWS on an operation outside
    // its map, so no row can ever carry one — which makes a filter value outside it a malformed
    // request rather than an empty result set a caller would read as "this never happened".
    foreach (['operation=bot.exploded', 'outcome=maybe'] as $query) {
        currentTest()->actingAs($f['owner'])
            ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs?{$query}")
            ->assertStatus(422)
            ->assertJsonPath('error_class', 'validation');
    }

    // `subject_type` IS THE OPPOSITE AND DELIBERATELY SO: the column has no CHECK, because "the set
    // is open and a refused audit row is worse than a row nobody wrote rules for". A closed rule
    // here would 422 a legitimate query the day a new kind of record is first audited.
    currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs?subject_type=".rawurlencode('App\\Models\\Nothing'))
        ->assertOk()
        ->assertJsonPath('data.meta.total', 0);
});

it('refuses a subject id with no subject type, because an id alone is ambiguous', function (): void {
    $f = auditTrailFixture();

    currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs?subject_id=01jqzzzzzzzzzzzzzzzzzzzzzz")
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['subject_type']]);
});

it('closes the sortable set to the one column every index on the table ends in', function (): void {
    $f = auditTrailFixture();

    currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs?sort=created_at&dir=asc")
        ->assertOk()
        ->assertJsonPath('data.meta.dir', 'asc');

    // `id` IS NOT OFFERED. It is a ULID and carries the same ordering, but it has no index of its
    // own here — the primary key is the composite `(id, created_at)` a partitioned table requires —
    // so publishing it would be a sort with nothing behind it that happens to agree with the one
    // that does.
    foreach (['sort=id', 'sort=operation', 'per_page=101'] as $query) {
        expect(currentTest()->actingAs($f['owner'])
            ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs?{$query}")
            ->status())->toBe(422, "?{$query} was accepted and should not have been");
    }
});

it('paginates deterministically when several rows share one instant', function (): void {
    $f = auditTrailFixture();
    $logger = app(AuditLogger::class);

    // FIVE ROWS AT ONE FROZEN INSTANT — which is not contrived: Eloquent writes `created_at` from
    // one `Carbon::now()` per request, so a single action that audits several children produces
    // exactly this. Without the `id` tie-break a page boundary may repeat or DROP a row, and in an
    // audit trail a missing row is worse than a duplicate.
    for ($i = 0; $i < 5; $i++) {
        $logger->record(AuditLogger::LOGOUT, $f['org']->id, $f['owner']->id);
    }

    $page = function (int $n) use ($f): array {
        /** @var list<array<string, mixed>> $rows */
        $rows = currentTest()->actingAs($f['owner'])
            ->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs?per_page=2&page={$n}")
            ->assertOk()
            ->json('data.audit_logs');

        return array_column($rows, 'id');
    };

    $seen = [...$page(1), ...$page(2), ...$page(3)];

    expect($seen)->toHaveCount(5)
        ->and(array_unique($seen))->toHaveCount(5);
});

// ── the role split ───────────────────────────────────────────────────────────────────────────────

it('serves the owner and the administrator and refuses the other two roles', function (): void {
    $f = auditTrailFixture();
    $url = "/api/v1/organizations/{$f['org']->id}/audit-logs";

    foreach (['owner', 'admin'] as $role) {
        expect(currentTest()->actingAs($f[$role])->getJson($url)->status())
            ->toBe(200, "{$role} was refused the audit trail");
    }

    // NARROWER THAN `conversations.view` ON PURPOSE. An audit row names a COLLEAGUE and carries
    // their IP address and user agent; §6.5 is about chatbot quality and none of its five items is
    // an administrative action, and §6.4's twelve `source.*` operations are not a reason to hand an
    // ingestion operator the credential-rotation rows beside them.
    foreach (['analyst', 'manager'] as $role) {
        $refusal = currentTest()->actingAs($f[$role])->getJson($url);

        expect($refusal->status())->toBe(403, "{$role} was served the audit trail");
        $refusal->assertJsonPath('error_class', 'authorization');
    }
});

it('401s a guest', function (): void {
    $f = auditTrailFixture();

    currentTest()->getJson("/api/v1/organizations/{$f['org']->id}/audit-logs")->assertStatus(401);
});
