<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\Eloquent\EloquentAuditLogPartitionRepository;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| audit_logs — the schema, the grant, and the two survival properties
|--------------------------------------------------------------------------
|
| These are Feature tests against real PostgreSQL because every property below is a property of the
| DATABASE, not of the PHP around it: range partitioning, a composite primary key, the absence of a
| foreign key, and an ACL. None of them can be proven against a mock, and three of them are exactly
| the kind of thing a later migration silently undoes.
|
| ONE OF THEM CANNOT BE PROVEN AND SAYS SO RATHER THAN PASSING, AND IT IS NOT A HARNESS ARTEFACT.
| `postgres-test` is created with POSTGRES_USER=kb_test and the real `postgres` service with
| POSTGRES_USER=knowledgebot; initdb makes both SUPERUSERS, and a superuser bypasses every ACL check.
| Measured 2026-08-13 on PostgreSQL 18.4: after the migration's REVOKE, `UPDATE audit_logs SET …`
| still succeeds for `knowledgebot`. So the runtime refusal is unobservable HERE and absent in the
| deployment; closing it needs a non-superuser application role in infrastructure/docker/. The test
| below asserts the ACL itself — which is what the migration actually did, and is true on a superuser
| connection — and SKIPS the runtime half with a message naming the reason. A test that ran the UPDATE
| and asserted "no exception" would pass today and keep passing after somebody deleted the REVOKE.
*/

/**
 * @return array<string, mixed>
 */
function auditCatalogue(string $sql): array
{
    $rows = Schema::getConnection()->select($sql);
    $first = $rows[0] ?? null;

    return is_object($first) ? get_object_vars($first) : [];
}

function auditConnectionIsSuperuser(): bool
{
    $row = auditCatalogue('SELECT rolsuper AS value FROM pg_roles WHERE rolname = current_user');
    $value = $row['value'] ?? false;

    // PDO_PGSQL renders booleans as 't'/'f'; (bool) 'f' is TRUE, so compare the spellings.
    return $value === true || $value === 't' || $value === 1 || $value === '1';
}

/**
 * Does `$role` hold `$privilege` on `audit_logs`, asked of a NAMED subject?
 *
 * ── WHY `has_table_privilege` IS SAFE HERE AND IS NOT SAFE IN THE OBVIOUS FORM ────────────────────
 * `writesRevoked()` reads `pg_class.relacl` and deliberately never calls this function, because
 * `has_table_privilege(current_user, …)` returns **`t`** for a superuser — the ACL says no, the function
 * says yes, and a test built on it passes for the wrong reason on exactly the machine where the control
 * is missing. That trap is about the SUBJECT being implicit.
 *
 * Naming the subject removes it: measured, `has_table_privilege('kb_app', 'audit_logs', 'UPDATE')` is
 * `f` while the same question about a superuser is `t`. So this is the only way this suite can say
 * anything about a role it is not connected as — which is the whole point, since the role that must be
 * refused is the application role and the suite runs as a superuser-owner by design.
 */
function auditRoleHasPrivilege(string $role, string $privilege): bool
{
    $row = auditCatalogue(sprintf(
        "SELECT has_table_privilege('%s', 'audit_logs', '%s') AS value",
        $role,
        $privilege,
    ));
    $value = $row['value'] ?? false;

    return $value === true || $value === 't' || $value === 1 || $value === '1';
}

/** Is `$role` present on this server, and non-superuser? Both halves gate the probe above. */
function auditRoleIsNonSuperuser(string $role): bool
{
    $row = auditCatalogue(sprintf(
        "SELECT count(*) FILTER (WHERE NOT rolsuper) AS value FROM pg_roles WHERE rolname = '%s'",
        $role,
    ));

    return (int) ($row['value'] ?? 0) === 1;
}

it('is range-partitioned on created_at with created_at in the primary key', function (): void {
    $shape = auditCatalogue(<<<'SQL'
        SELECT c.relkind AS relkind,
               pg_get_partkeydef(c.oid) AS partkey
          FROM pg_class c
         WHERE c.oid = to_regclass('audit_logs')
    SQL);

    // 'p' — a partitioned table. Not 'r': a plain audit_logs is a table whose retention has to be a
    // DELETE, which bloats it permanently and which the application role has no privilege to run.
    expect($shape['relkind'])->toBe('p')
        ->and($shape['partkey'])->toBe('RANGE (created_at)');

    $key = auditCatalogue(<<<'SQL'
        SELECT string_agg(a.attname, ',' ORDER BY k.ord) AS columns
          FROM pg_constraint c
          CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord)
          JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
         WHERE c.conrelid = to_regclass('audit_logs')
           AND c.contype = 'p'
    SQL);

    // postgresql-patterns:166 — a partitioned table's key must include the partition key, and
    // converting later is a full rewrite of the one table that must never be rewritten.
    expect($key['columns'])->toBe('id,created_at');
});

it('has no outbound foreign key, on the parent or on any partition', function (): void {
    // The audit row outlives the record it describes. RESTRICT would make the trail block the
    // deletion it is the evidence of; CASCADE or SET NULL would delete or blank the evidence.
    $count = auditCatalogue(<<<'SQL'
        SELECT count(*) AS value
          FROM pg_constraint c
         WHERE c.contype = 'f'
           AND (
               c.conrelid = to_regclass('audit_logs')
               OR c.conrelid IN (SELECT inhrelid FROM pg_inherits WHERE inhparent = to_regclass('audit_logs'))
           )
    SQL);

    expect((int) $count['value'])->toBe(0);
});

it('is writable the moment it exists, with the current month and the next partitioned', function (): void {
    $partitions = app(EloquentAuditLogPartitionRepository::class);
    $now = CarbonImmutable::now('UTC');

    expect($partitions->partitionNames())
        ->toContain(EloquentAuditLogPartitionRepository::nameFor($now))
        ->toContain(EloquentAuditLogPartitionRepository::nameFor($now->addMonth()));
});

it('records the UPDATE/DELETE revoke in the ACL of the parent and of every partition', function (): void {
    $partitions = app(EloquentAuditLogPartitionRepository::class);

    expect($partitions->writesRevoked(EloquentAuditLogPartitionRepository::PARENT))->toBeTrue(
        'audit_logs still grants UPDATE or DELETE to the application role: append-only is enforced '
        .'by the grant, not by discipline (postgresql-patterns:197)'
    );

    foreach ($partitions->partitionNames() as $name) {
        // The half that is easy to miss: privileges are NOT inherited through the partition
        // hierarchy, so a partition with the owner's default ACL is a fully writable back door one
        // underscore away from the parent.
        expect($partitions->writesRevoked($name))->toBeTrue(
            "partition {$name} still grants UPDATE or DELETE to the application role"
        );
    }
});

it('refuses an UPDATE and a DELETE at the database', function (): void {
    if (auditConnectionIsSuperuser()) {
        // ── A CONDITIONAL HARD FAILURE, NOT AN UNCONDITIONAL SKIP (D21) ─────────────────────────────
        // A superuser bypasses every ACL check, so this refusal genuinely cannot be demonstrated from a
        // superuser connection — measured, the UPDATE succeeds after the REVOKE. That much has not
        // changed. What was wrong was skipping SILENTLY in every environment, because the environment
        // where the control is MISSING and the environment where it is merely unobservable produced the
        // same green tick.
        //
        // WHETHER THE SPLIT IS EXPECTED IS DERIVED FROM CONFIG, NOT FROM A FLAG. The obvious design is
        // an env var like `KB_EXPECT_DB_ROLE_SPLIT`, and it is worse twice over: `env()` outside the
        // config directory is banned here (it returns null once the config is cached, and larastan and
        // the Arch suite both enforce it), and a separate assertion about the deployment can disagree
        // with the deployment. Two DIFFERENT role names in `database.connections` IS the split — that is
        // what `apply-roles.sh` sets up and what `pgsql_ddl` exists for — so it cannot drift.
        //
        // Split configured AND the connection is a superuser means the app is connecting with more
        // authority than intended, which is a real misconfiguration rather than an unobservable control.
        $splitConfigured = config('database.connections.pgsql.username')
            !== config('database.connections.pgsql_ddl.username');

        if ($splitConfigured) {
            expect(false)->toBeTrue(
                'The role split is configured (pgsql and pgsql_ddl name different roles), so the '
                .'application role is expected to be a non-superuser — and the connection role is a '
                .'SUPERUSER, which bypasses every ACL check and makes audit_logs writable regardless of '
                .'the REVOKE. Run scripts/ops/postgres-roles.sh, then recreate the app containers.'
            );
        }

        currentTest()->markTestSkipped(
            'The connection role is a SUPERUSER, and a superuser bypasses every ACL check — measured '
            .'2026-08-13: the UPDATE succeeds after the REVOKE. This suite runs against postgres-test '
            .'as a superuser-owner BY DESIGN (RefreshDatabase needs TRUNCATE on every table), so the '
            .'refusal is unobservable here rather than absent. What covers it instead: the ACL '
            .'assertion above, the named-subject probe below, scripts/ops/preflight.sh section 8, and '
            .'and the role-split check preflight.sh performs. Point DB_DDL_USERNAME at a distinct role to '
            .'make this a failure rather than a skip.'
        );
    }

    $row = app(AuditLogger::class)->record(
        AuditLogger::LOGOUT,
        null,
        (string) Str::ulid(),
    );

    expect($row)->not->toBeNull();

    // Each statement goes inside its own savepoint: an error aborts the enclosing transaction, and
    // RefreshDatabase has one open around the whole test.
    $attempt = static function (string $sql): void {
        Schema::getConnection()->transaction(
            static fn () => Schema::getConnection()->statement($sql)
        );
    };

    expect(static fn () => $attempt("UPDATE audit_logs SET operation = 'tampered'"))
        ->toThrow(QueryException::class)
        ->and(static fn () => $attempt('DELETE FROM audit_logs'))
        ->toThrow(QueryException::class);
});

it('denies UPDATE, DELETE and TRUNCATE to the APPLICATION role, asked of it by name', function (): void {
    // THE HALF THE SKIP ABOVE CANNOT REACH, and it runs on this harness. The suite connects as a
    // superuser-owner, so it cannot be REFUSED a write — but it can ask PostgreSQL what a DIFFERENT,
    // named role is allowed to do, and the role that matters is the one the request-serving containers
    // authenticate as.
    $role = (string) config('database.connections.pgsql.username');

    if (! auditRoleIsNonSuperuser($role)) {
        // Gated on the subject being a real, non-superuser role, because `has_table_privilege` answers
        // `t` for a superuser and the probe would then assert the opposite of what it means. Where the
        // split is not deployed the app role IS the superuser, so there is nothing here to measure.
        currentTest()->markTestSkipped(
            "The application role '{$role}' is absent or is a superuser, so a privilege probe about it "
            .'is either impossible or meaningless. This becomes a real assertion after '
            .'scripts/ops/postgres-roles.sh has run and DB_USERNAME points at the non-superuser role.'
        );
    }

    foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
        expect(auditRoleHasPrivilege($role, $privilege))->toBeFalse(
            "role '{$role}' holds {$privilege} on audit_logs. Append-only is then enforced by "
            .'discipline rather than by the grant, which is the state D21 exists to end.'
        );
    }

    // POSITIVE CONTROL: the same probe, same subject, same function — and the two privileges the
    // application legitimately needs must be PRESENT. Without this, a role that had been granted
    // nothing at all would satisfy every assertion above while the application could not function.
    foreach (['SELECT', 'INSERT'] as $privilege) {
        expect(auditRoleHasPrivilege($role, $privilege))->toBeTrue(
            "role '{$role}' cannot {$privilege} on audit_logs, so every audited action fails"
        );
    }
});

it('refuses an UPDATE and a DELETE in PHP too, before the driver ever sees it', function (): void {
    app(AuditLogger::class)->record(AuditLogger::LOGOUT, null, (string) Str::ulid());

    $row = AuditLog::query()->firstOrFail();

    // Belt and braces, and both are documented as such: the grant is the mechanism, these two make
    // the mistake a sentence at the call site instead of a 42501 in production.
    expect(function () use ($row): void {
        $row->operation = 'tampered';
        $row->save();
    })->toThrow(\LogicException::class)
        ->and(static fn () => $row->delete())->toThrow(\LogicException::class);
});

it('keeps four secret-shaped values out of the row as PostgreSQL stores it', function (): void {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();

    // Low-entropy, self-describing values on purpose — see auditSecretFixture() in
    // tests/Unit/AuditLoggerTest.php for why a realistic-looking key literal is a red, unwaivable
    // secret-scan finding rather than a better test.
    $secrets = [
        'token' => 'audit-fixture-invite-token-not-a-secret',
        'password' => 'audit-fixture-password-not-a-secret',
        'api_key' => 'audit-fixture-api-key-not-a-secret',
        'provider_credential' => 'audit-fixture-credential-not-a-secret',
    ];

    app(AuditLogger::class)->record(
        AuditLogger::INVITATION_CREATED,
        $organization->id,
        $actor->id,
        $secrets + ['email' => 'invitee@example.test', 'role' => 'member'],
        'organization_invitation',
        (string) Str::ulid(),
    );

    // Read the ROW BACK OUT OF THE DATABASE as text. Asserting on the model instance would assert on
    // the array the writer built, which is the thing under test rather than the evidence.
    $stored = auditCatalogue(<<<'SQL'
        SELECT audit_logs::text AS value FROM audit_logs LIMIT 1
    SQL);

    $raw = (string) ($stored['value'] ?? '');

    expect($raw)->not->toBe('');

    // EVERY planted secret, not the one a reader would remember to check (kb-security-baseline:164).
    foreach ($secrets as $key => $secret) {
        expect(str_contains($raw, $secret))->toBeFalse(
            "the plaintext of '{$key}' is in the audit row; audit_logs is append-only, so it cannot "
            .'be removed'
        );
    }

    /** @var AuditLog $row */
    $row = AuditLog::query()->firstOrFail();

    expect($row->details)->toHaveKey('token_fingerprint')
        ->and($row->details)->not->toHaveKey('token')
        ->and($row->details['email'])->toBe('invitee@example.test')
        ->and($row->details['role'])->toBe('member');
});

it('stores an empty details as a JSON object, not a JSON array', function (): void {
    // json_encode([]) is `[]`. One array-shaped row makes every `details->>'…'` predicate in a future
    // admin surface silently skip it, which is why the column has a jsonb_typeof CHECK and why the
    // writer omits the attribute instead of setting it.
    app(AuditLogger::class)->record(AuditLogger::LOGOUT, null, (string) Str::ulid());

    $shape = auditCatalogue('SELECT jsonb_typeof(details) AS value, details::text AS raw FROM audit_logs LIMIT 1');

    expect($shape['value'])->toBe('object')
        ->and($shape['raw'])->toBe('{}');
});

it('keeps the audit row after the entity it describes is deleted', function (): void {
    $actor = User::factory()->create();
    $subject = User::factory()->create();

    app(AuditLogger::class)->record(
        AuditLogger::ROLE_CHANGED,
        (string) Str::ulid(),
        $actor->id,
        ['from_role' => 'member', 'to_role' => 'admin'],
        // `User::class`, NOT the string `'user'`. D32: `subject_type` holds a fully-qualified class
        // name, because that is what every writer in `app/` produces via `::class`, it is
        // compiler-checked where a literal is not, and it matches Laravel's morph convention. Two
        // spellings of one fact in a free-text column means a query for a subject finds half its rows —
        // and a test is a writer too.
        User::class,
        $subject->id,
    );

    $subjectId = $subject->id;
    $actorId = $actor->id;

    // No FK, so neither delete is blocked and neither cascades. `users` has no inbound reference here
    // because no membership rows were created.
    $subject->delete();
    $actor->delete();

    expect(User::query()->whereKey([$subjectId, $actorId])->count())->toBe(0);

    /** @var AuditLog $row */
    $row = AuditLog::query()->firstOrFail();

    // The whole point of the table: the evidence outlives the record.
    //
    // `details` IS ASSERTED KEY BY KEY, AND NOT WITH `toBe` ON THE WHOLE ARRAY, because `jsonb` does not
    // preserve insertion order: PostgreSQL stores object keys sorted by (length, bytes), so this row
    // reads back as `['to_role' => …, 'from_role' => …]` — the shorter key first. `toBe` is `===`, which
    // on a string-keyed array requires the same key ORDER, so the obvious whole-array assertion failed
    // on correct data, on every PostgreSQL version, for a reason that looks nothing like key ordering.
    // Found by 5A. Per-key `toBe` keeps the values strictly compared (`toEqual` would let `'1'` match
    // `1`), and the count keeps the assertion CLOSED so a third key cannot appear unnoticed.
    expect($row->subject_id)->toBe($subjectId)
        ->and($row->actor_id)->toBe($actorId)
        ->and($row->details['from_role'] ?? null)->toBe('member')
        ->and($row->details['to_role'] ?? null)->toBe('admin')
        ->and($row->details)->toHaveCount(2);
});

it('resolves the logger with no service-provider binding', function (): void {
    // #[Give] on the constructor is what lets this unit ship without editing AppServiceProvider. If
    // that stops working, every audited action fails with a container error rather than a bad row.
    expect(app(AuditLogger::class))->toBeInstanceOf(AuditLogger::class);
});

it('creates future partitions idempotently', function (): void {
    $partitions = app(EloquentAuditLogPartitionRepository::class);
    $now = CarbonImmutable::now('UTC');

    expect(Artisan::call('kb:create-audit-partitions', ['--months' => 4]))->toBe(0, Artisan::output());

    // Current month plus four. Asserted by NAME rather than by count, because the migration created
    // the first two at migrate time and a suite that straddles a month boundary would otherwise see
    // six relations and fail for a reason that is not the property under test.
    $expected = [];

    for ($offset = 0; $offset <= 4; $offset++) {
        $expected[] = EloquentAuditLogPartitionRepository::nameFor($now->addMonths($offset));
    }

    foreach ($expected as $name) {
        expect($partitions->partitionNames())->toContain($name);
    }

    // The second run is the property: this is scheduled, it runs twice on a bad day, and a command
    // that failed on re-run is a command whose failure alert everyone learns to ignore.
    expect(Artisan::call('kb:create-audit-partitions', ['--months' => 4]))->toBe(0);
    expect(str_contains(Artisan::output(), 'already has the full runway'))->toBeTrue();

    foreach ($expected as $name) {
        expect($partitions->partitionNames())->toContain($name);
    }
});

it('plans a prune by month boundary and changes nothing on a dry run', function (): void {
    $partitions = app(EloquentAuditLogPartitionRepository::class);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-13 12:00:00', 'UTC'));

    try {
        // Inside a 24-month window on 2026-08: 2024-08 is the oldest month still retained (its rows
        // reach 2024-08-31, which is under 24 months old), and 2024-07 is the first one outside.
        $partitions->ensureMonth(CarbonImmutable::parse('2024-07-01', 'UTC'));
        $partitions->ensureMonth(CarbonImmutable::parse('2024-08-01', 'UTC'));

        expect(Artisan::call('kb:prune-audit-partitions', ['--dry-run' => true]))->toBe(0);

        $output = Artisan::output();

        expect(str_contains($output, 'audit_logs_2024_07'))->toBeTrue($output)
            ->and(str_contains($output, 'would detach + drop'))->toBeTrue($output)
            // The retained month must NOT appear in the plan. This is the off-by-one that would
            // delete a month of evidence still inside the retention promise.
            ->and(str_contains($output, 'audit_logs_2024_08'))->toBeFalse($output);

        // The boundary month is retained, and the dry run touched nothing.
        expect($partitions->partitionNames())
            ->toContain('audit_logs_2024_07')
            ->toContain('audit_logs_2024_08');
    } finally {
        Carbon::setTestNow();
    }
});

it('refuses to prune for real inside an open transaction', function (): void {
    // RefreshDatabase holds a transaction open around every test, so this is the state the suite is
    // permanently in — and it is the same state a naive `DB::transaction(fn () => Artisan::call(…))`
    // would create in production. DETACH … CONCURRENTLY is rejected in a transaction block (25001),
    // and the non-concurrent fallback would take ACCESS EXCLUSIVE on audit_logs.
    expect(Schema::getConnection()->transactionLevel())->toBeGreaterThan(0);

    expect(Artisan::call('kb:prune-audit-partitions'))->toBe(1);
    expect(str_contains(Artisan::output(), 'Refusing to run inside an open transaction'))->toBeTrue();
});

it('refuses a retention window below the floor without --force', function (): void {
    expect(Artisan::call('kb:prune-audit-partitions', ['--months' => 1, '--dry-run' => true]))->toBe(1);
    expect(str_contains(Artisan::output(), 'Refusing a 1-month audit retention window'))->toBeTrue();
});

it('refuses to drop a partition that is still attached', function (): void {
    $partitions = app(EloquentAuditLogPartitionRepository::class);
    $name = EloquentAuditLogPartitionRepository::nameFor(CarbonImmutable::now('UTC'));

    // The guard that stops a retention change from being a one-line accident: dropping a live
    // partition removes rows inside the window and leaves nothing to distinguish it afterwards.
    expect(static fn () => $partitions->dropDetached($name))
        ->toThrow(\InvalidArgumentException::class);
});

it('refuses SQL for a relation name that is not an audit partition', function (): void {
    $partitions = app(EloquentAuditLogPartitionRepository::class);

    // Identifiers cannot be bound, so the name validation is the only thing between the catalogue and
    // a DROP. Including for names that came out of pg_class.
    expect(static fn () => $partitions->rowCount('users'))
        ->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => $partitions->detachConcurrently('audit_logs_2026_08"; DROP TABLE users; --'))
        ->toThrow(\InvalidArgumentException::class);
});
