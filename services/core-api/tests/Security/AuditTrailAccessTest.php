<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Factories\ProviderConnectionFactory;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The audit trail: one organization's rows, no platform rows, and no credential (§22.5, NN9)
|--------------------------------------------------------------------------
|
| THIS TABLE HAS EXACTLY ONE TENANCY LAYER AND IT IS AN ARGUMENT. `AuditLog` deliberately carries no
| `#[ScopedBy(OrganizationScope::class)]`: the scope appends `organization_id = ?`, which is FALSE
| for the NULL-org platform rows, so attaching it would make every one of them unreachable through
| Eloquent forever, from every surface, with no error. So there is no backstop under
| `EloquentAuditLogRepository::paginate()`'s explicit predicate — delete that one line and the
| endpoint returns the whole platform's audit trail to any member of any organization, at normal
| latency, in a well-formed envelope.
|
| EVERY FIXTURE IS `tenantPair()`. With one tenant there is nothing to leak, so a one-org fixture
| passes against code with no filter at all.
*/

const AUDIT_ROTATION_CREDENTIAL = 'kb-audit-rotation-credential-DO-NOT-LOG-8kq3';

it('never returns another organization\'s audit rows and 403s a foreign trail', function (): void {
    $t = tenantPair();
    $logger = app(AuditLogger::class);

    $mine = $logger->record(AuditLogger::LOGOUT, $t->a->id, $t->actorA->id);
    $theirs = $logger->record(AuditLogger::LOGIN_SUCCEEDED, $t->b->id, $t->actorB->id, [
        'email' => $t->actorB->email,
        'mechanism' => 'session',
    ]);

    assert($mine instanceof AuditLog && $theirs instanceof AuditLog);

    // POSITIVE CONTROL, FIRST. Org A's own trail answers and contains Org A's row — without this
    // the absence below also passes when the endpoint is broken and returns nothing at all.
    $response = currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->a->id}/audit-logs")
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.audit_logs.0.id', $mine->id);

    $body = (string) $response->getContent();

    // Over the RAW BODY, so a foreign value hiding in a key nobody thought to check still trips it.
    // str_contains and not ->not->toContain(): `not` treats any failure as success.
    expect(str_contains($body, $theirs->id))
        ->toBeFalse('Org B\'s audit row id reached Org A\'s trail');
    expect(str_contains($body, (string) $t->actorB->email))
        ->toBeFalse('Org B\'s member email reached Org A\'s trail');

    // AND ORG B'S TRAIL IS A 403 — not a 404. The admin API is authenticated and is not
    // enumeration-sensitive; `error_class` is `authorization` either way and nothing branches on
    // the status.
    currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->b->id}/audit-logs")
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

it('never shows a platform-scope row to an org-scoped reader', function (): void {
    // ══ THE ROW THIS TABLE EXISTS TO KEEP AND THIS ENDPOINT EXISTS TO WITHHOLD ═══════════════
    //
    // `organization_id` is NULLABLE, and a NULL is not a gap — it is an event that belongs to no
    // tenant: a failed login for an address that is not a user, a platform-scope action. §6.1
    // assigns those to the PLATFORM OWNER, a flag on the user rather than an organization role, on
    // a surface that does not exist yet.
    //
    // THE EXCLUSION IS CORRECT BY THREE-VALUED LOGIC — `NULL = '01J…'` is NULL and not TRUE — WHICH
    // IS EXACTLY WHY IT IS ASSERTED HERE. Nobody has to decide anything for it to happen, so the
    // change that undoes it is a one-line `orWhereNull()` added "so operators can see login
    // failures too", which reads like a feature request and is a boundary change.
    $t = tenantPair();
    $logger = app(AuditLogger::class);

    $scoped = $logger->record(AuditLogger::LOGOUT, $t->a->id, $t->actorA->id);
    $platform = $logger->record(AuditLogger::LOGIN_FAILED, null, null, [
        'email' => 'stranger@example.test',
        'reason' => 'unknown_address',
    ]);

    assert($scoped instanceof AuditLog && $platform instanceof AuditLog);

    // POSITIVE CONTROL ON THE ROW ITSELF: it really was written, and it really has no organization.
    // Without this the assertion below would pass against a writer that silently refused the row —
    // which is the failure mode the nullable column exists to prevent, so a test that could not
    // tell them apart would be defending the wrong thing.
    expect(AuditLog::query()->whereKey($platform->id)->value('organization_id'))->toBeNull();

    $response = currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->a->id}/audit-logs")
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.audit_logs.0.id', $scoped->id);

    $body = (string) $response->getContent();

    expect(str_contains($body, $platform->id))
        ->toBeFalse('a platform-scope audit row reached an organization-scoped reader');
    expect(str_contains($body, 'stranger@example.test'))
        ->toBeFalse('a platform-scope audit row\'s email reached an organization-scoped reader');

    // AND NO FILTER REACHES IT EITHER. A caller naming the platform row's own operation still gets
    // an empty page: the organization predicate is applied before any filter and is not one of
    // them.
    currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->a->id}/audit-logs?operation=".AuditLogger::LOGIN_FAILED)
        ->assertOk()
        ->assertJsonPath('data.meta.total', 0);
});

it('returns no part of a provider credential, from a connection whose key really was sealed', function (): void {
    // ══ NON-NEGOTIABLE 9 ON THE READ PATH ══════════════════════════════════════════════════
    //
    // The guarantee belongs to the WRITER: `AuditLogger::OPERATIONS` allow-lists `details` per
    // operation and admits a bearer value only as a keyed `<name>_fingerprint`. What this test
    // proves is that the READ PATH DID NOT WIDEN IT — that `AuditLogResource` renders the stored
    // blob and enriches nothing — by driving a real rotation and grepping the SERIALIZED RESPONSE
    // rather than the row.
    $t = tenantPair();

    $owner = User::factory()->recycle($t->a)->orgRole(OrgRole::Owner)->create();
    $connection = ProviderConnection::factory()->recycle($t->a)->create();

    // ── POSITIVE CONTROL ONE: THE FIXTURE CREDENTIAL IS GENUINELY ENCRYPTED AT REST ──────────
    //
    // This is the assertion `pest-testing` calls out by name — "a secret-redaction test passes
    // because the key was never used" — in its strongest form: if the fixture never sealed
    // anything, every absence below is vacuous. `last_four` proves the plaintext reached the vault,
    // and the raw column read proves what landed in the row is NOT that plaintext.
    expect($connection->last_four)->toBe(substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4));

    // tenancy-exempt: a raw column read of a single row this test just created, by primary key, to
    // prove the CIPHERTEXT is not the plaintext. It goes through DB::table() because the whole
    // point is to bypass the model's decrypting cast — reading it through the accessor would assert
    // that decryption works, which is the opposite of what is being checked here.
    $stored = DB::table('provider_connections')->where('id', '=', $connection->id)->first();

    assert($stored !== null);

    $ciphertext = base64_encode((string) $stored->credential_ciphertext);

    expect(str_contains($ciphertext, base64_encode(ProviderConnectionFactory::FIXTURE_CREDENTIAL)))
        ->toBeFalse('the fixture credential is stored in plaintext, so every assertion below is vacuous');

    // ── DRIVE A REAL ROTATION, WHICH IS THE ONE AUDITED OPERATION THAT HANDLES A CREDENTIAL ──
    //
    // A real SPA session rather than actingAs(): `RotateProviderCredentialRequest` carries a
    // `current_password:web` rule, which needs a session store to compare against.
    SpaSession::establish(currentTest(), $owner);

    currentTest()->putJson(
        "/api/v1/organizations/{$t->a->id}/provider-connections/{$connection->id}/credential",
        ['current_password' => UserFactory::PASSWORD, 'credential' => AUDIT_ROTATION_CREDENTIAL],
        spaHeaders(),
    )->assertOk();

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/audit-logs?operation=".AuditLogger::PROVIDER_CREDENTIAL_ROTATED,
        spaHeaders(),
    )->assertOk();

    $body = (string) $response->getContent();

    // ── POSITIVE CONTROL TWO: THE ROTATION ROW IS ACTUALLY IN THIS BODY ─────────────────────
    //
    // Without it every absence below iterates an empty page, which is the second half of the same
    // trap: a redaction test that passes because the surface returned nothing.
    $response->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.audit_logs.0.operation', AuditLogger::PROVIDER_CREDENTIAL_ROTATED)
        // The row DOES carry the two version numbers, which identify WHICH key was live without
        // being derivable back to it. Asserted positively so the absences cannot pass by the
        // `details` blob being empty.
        ->assertJsonPath('data.audit_logs.0.details.credential_version', 2);

    expect($response->json('data.audit_logs.0.details.key_version'))->toBeInt();

    // ── AND NOW THE ABSENCES, OVER THE SERIALIZED BODY ──────────────────────────────────────
    foreach ([
        'the sealed fixture credential' => ProviderConnectionFactory::FIXTURE_CREDENTIAL,
        'the rotated credential' => AUDIT_ROTATION_CREDENTIAL,
        // THE LAST FOUR of the new key, which is the only derived form that may be rendered
        // anywhere in this API and still has no business in an append-only table.
        'the new key\'s last four' => substr(AUDIT_ROTATION_CREDENTIAL, -4),
        'the actor\'s password' => UserFactory::PASSWORD,
        // The KEK itself, which wraps every data key. It is a file-delivered secret in every real
        // environment and a literal in the suite, so this is a cheap check that a wrapped data key
        // never went out beside the row it belongs to.
        'the key-encrypting key' => (string) config('kb.credentials.kek'),
    ] as $label => $needle) {
        // POSITIVE CONTROL THREE, AND IT IS THE ONE MOST EASILY LOST. A needle that resolved to the
        // empty string would make `str_contains($body, '')` return TRUE and the assertion fail
        // loudly — but the tempting repair is a `continue`, which turns the missing needle into a
        // silently skipped check. `config('kb.credentials.kek')` is null under `artisan` and is a
        // literal only because phpunit.xml sets KB_KEK; asserting it here is what stops a config
        // rename from quietly removing a check from this list.
        expect($needle)->not->toBe('', "the needle for {$label} is empty, so nothing was checked");

        expect(str_contains($body, $needle))
            ->toBeFalse("the audit read surface returned {$label}");
    }

    // AND NO CREDENTIAL-SHAPED KEY, whatever its value. `credential_version` is an integer count
    // and is exempt by exact name; anything else matching is a defect in the allow-list or in this
    // projection.
    /** @var array<string, mixed> $details */
    $details = $response->json('data.audit_logs.0.details');

    foreach (array_keys($details) as $key) {
        $key = (string) $key;

        if ($key === 'credential_version') {
            continue;
        }

        expect((bool) preg_match('/credential|api[_-]?key|secret|password|token|ciphertext|kek|last_four|masked/i', $key))
            ->toBeFalse("the audit read surface published a credential-shaped details key: {$key}");
    }
});

it('never lets a foreign actor or subject filter widen the organization predicate', function (): void {
    $t = tenantPair();
    $logger = app(AuditLogger::class);

    $logger->record(AuditLogger::LOGOUT, $t->a->id, $t->actorA->id);

    $theirs = $logger->record(
        AuditLogger::BOT_CREATED,
        $t->b->id,
        $t->actorB->id,
        ['name' => $t->botB->name, 'slug' => $t->botB->slug],
        \App\Models\Bot::class,
        $t->botB->id,
    );

    assert($theirs instanceof AuditLog);

    // EVERY FILTER BELOW NAMES A ROW THAT EXISTS — IN THE OTHER ORGANIZATION. A predicate applied
    // instead of the tenant one rather than beside it, or an ungrouped `orWhere`, returns Org B's
    // row here. The correct answer to all three is an empty page.
    $subject = rawurlencode(\App\Models\Bot::class);

    foreach ([
        "actor_id={$t->actorB->id}",
        "subject_type={$subject}&subject_id={$t->botB->id}",
        'operation='.AuditLogger::BOT_CREATED,
    ] as $query) {
        $response = currentTest()->actingAs($t->actorA)
            ->getJson("/api/v1/organizations/{$t->a->id}/audit-logs?{$query}")
            ->assertOk();

        expect($response->json('data.meta.total'))
            ->toBe(0, "?{$query} returned rows from another organization");
        expect(str_contains((string) $response->getContent(), $theirs->id))
            ->toBeFalse("?{$query} leaked another organization's audit row id");
    }
});

it('refuses a suspended owner, whose role alone would grant audit.view', function (): void {
    $t = tenantPair();

    // `OrganizationUser::grants()` is `isActive() && role->grants()`, which the unit-level role
    // matrix structurally cannot express — so it is asserted over HTTP, on the surface it protects,
    // with the role that holds every permission there is.
    $suspended = User::factory()->recycle($t->a)
        ->orgRole(OrgRole::Owner, MembershipStatus::Suspended)->create();

    currentTest()->actingAs($suspended)
        ->getJson("/api/v1/organizations/{$t->a->id}/audit-logs")
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

it('never writes an audit row for reading the audit trail', function (): void {
    // §18.11's list is credential changes, user and role changes, bot publish and configuration
    // changes, source lifecycle, EXPORTS and retention changes. A paginated read is none of them,
    // and auditing one would be self-referential in the direction that hurts: every page turn
    // appends a row to the table being paged, so the newest page becomes mostly a record of
    // somebody looking at it. An EXPORT of this table IS on §18.11's list and is not this endpoint.
    $t = tenantPair();

    app(AuditLogger::class)->record(AuditLogger::LOGOUT, $t->a->id, $t->actorA->id);

    $before = AuditLog::query()->count();

    currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->a->id}/audit-logs")
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1);

    currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->a->id}/audit-logs?page=2")
        ->assertOk();

    expect(AuditLog::query()->count())->toBe(
        $before,
        'reading the audit trail appended to it, so the trail is now partly a record of itself',
    );
});

it('cannot be written through, because the model and the grant both refuse', function (): void {
    $t = tenantPair();

    $row = app(AuditLogger::class)->record(AuditLogger::LOGOUT, $t->a->id, $t->actorA->id);

    assert($row instanceof AuditLog);

    // THERE IS NO WRITE ROUTE, so this asserts the layer under it rather than an HTTP status: the
    // migration REVOKES UPDATE and DELETE on the table and every partition from the application
    // role, and the model refuses both in PHP first so the failure is a sentence at the call site
    // rather than SQLSTATE 42501 in a request nobody can explain. Neither guard is the mechanism;
    // the grant is — and this is what stops a future write endpoint from being one line away.
    $loaded = AuditLog::query()->whereKey($row->id)->firstOrFail();

    expect(fn () => $loaded->delete())->toThrow(\LogicException::class);

    $loaded->outcome = AuditLogger::OUTCOME_FAILURE;

    expect(fn () => $loaded->save())->toThrow(\LogicException::class);

    // AND THE ROW IS UNMOVED. Without this the two refusals above could both be satisfied by a
    // model that threw AFTER writing.
    expect(AuditLog::query()->whereKey($row->id)->value('outcome'))->toBe(AuditLogger::OUTCOME_SUCCESS);
});

it('keeps the two surfaces apart: an audit reader is not a conversation reader', function (): void {
    // THE TWO PERMISSIONS ARE NOT THE SAME SHAPE AND THIS IS WHERE THAT BECOMES OBSERVABLE.
    // `conversations.view` is Owner/Admin/Analyst; `audit.view` is Owner/Admin. An Analyst reads
    // transcripts and is refused the trail — which is the whole reason two cases were added rather
    // than one, and it is asserted here because the unit-level matrix proves the grants and not the
    // wiring.
    $t = tenantPair();

    $analyst = User::factory()->recycle($t->a)->orgRole(OrgRole::Analyst)->create();

    currentTest()->actingAs($analyst)
        ->getJson("/api/v1/organizations/{$t->a->id}/conversations")
        ->assertOk();

    currentTest()->actingAs($analyst)
        ->getJson("/api/v1/organizations/{$t->a->id}/audit-logs")
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    // And the Knowledge Manager holds neither, which is the other half of the split: §6.4's twelve
    // `source.*` operations are not a reason to hand an ingestion operator the credential-rotation
    // rows beside them, and "review parsed content" is the source detail projection rather than a
    // customer's words.
    $manager = User::factory()->recycle($t->a)->orgRole(OrgRole::KnowledgeManager)->create();

    foreach (['conversations', 'audit-logs'] as $surface) {
        expect(currentTest()->actingAs($manager)
            ->getJson("/api/v1/organizations/{$t->a->id}/{$surface}")
            ->status())->toBe(403, "a knowledge manager was served /{$surface}");
    }
});
