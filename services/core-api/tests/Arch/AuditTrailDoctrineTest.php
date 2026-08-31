<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/*
|--------------------------------------------------------------------------
| audit_logs: the exemption, written down where a rule can read it
|--------------------------------------------------------------------------
|
| `AuditLog`'s own docblock has said since ADR-041 that the reflection rule sketched in
| tests/Arch/DoctrineTest.php — "every org-owned model carries the organization scope" — WILL FAIL
| ON THIS CLASS the day it is enabled, and that it "needs an explicit exemption there naming this
| docblock, exactly as OrganizationUser does". THIS FILE IS THAT EXEMPTION, made live rather than
| left as a sentence waiting for a rule that is still commented out.
|
| ── WHY IT IS A FILE OF ITS OWN AND NOT A LINE IN DoctrineTest ─────────────────────────────────
|
| The same construction tests/Arch/ConversationDoctrineTest.php and
| tests/Arch/SourceCascadeDoctrineTest.php use: name the classes EXPLICITLY, in the file that
| carries the reasoning, so the rule needs no `->ignoring(...)` and cannot acquire one by accident.
| A bare `->ignoring('App\Models\AuditLog')` inside the general rule would strip exactly the
| reasoning that keeps this model correct, which is the mistake ADR-043 names.
|
| ── THE EXEMPTION IS A THIRD KIND, DIFFERENT FROM BOTH THE OTHERS IN THIS TREE ────────────────
|
| ADR-043's models — OrganizationUser, OrganizationInvitation, EmailVerificationToken — HOLD
| `organization_id` and skip the scope because they are read on paths that run BEFORE any
| organization is known, and OrganizationScope fails CLOSED.
|
| ConversationDoctrineTest's four — Message, RetrievalTrace, Citation, Feedback — skip it because
| THERE IS NO COLUMN TO SCOPE: the scope would be SQLSTATE 42703 on every read.
|
| `audit_logs` is neither. The column EXISTS and it is NULLABLE, and the NULL is load-bearing: an
| org-less event is a real event (a failed login for an address that belongs to no user, a
| platform-scope action), and `organization_id = ?` is FALSE for a NULL. So attaching the scope
| would not over-scope this table — it would make every platform row unreachable through Eloquent
| forever, from every surface, with no error, and the endpoint would render "no audit history" for
| exactly the rows an intrusion investigation opens with. That is the most plausible-looking wrong
| answer this table could give.
|
| ── WHAT ENFORCES ISOLATION INSTEAD, AND HOW STRONG IT IS ────────────────────────────────────
|
| One thing: `AuditLogRepositoryInterface::paginate()` takes `organization_id` as a REQUIRED
| POSITIONAL argument, and `EloquentAuditLogRepository` applies it as the first predicate. There is
| nothing underneath it. ADR-043 is candid that this substitute is a convention rather than a
| mechanism — "a repository method that grows an `organization_id`-optional overload silently loses
| the whole guard, and no test would notice" — and that is as true here as it was there.
| tests/Security/AuditTrailAccessTest.php is the runtime half; this file is the shape half.
|
| Arch/ extends nothing: no container, no database, no facades. It must keep running on the day the
| application does not boot.
*/

test('the audit log deliberately carries NO organization scope', function (): void {
    $attributes = (new \ReflectionClass(AuditLog::class))->getAttributes(ScopedBy::class);

    expect($attributes)->toBeEmpty(
        'AuditLog has acquired #[ScopedBy(OrganizationScope::class)]. The column is NULLABLE and the '
        .'scope appends `organization_id = ?`, which is FALSE for a NULL — so every platform-scope '
        .'row (a failed login for an unknown address, a platform action) becomes unreachable through '
        .'Eloquent from every surface, forever, with no error. The endpoint would render "no audit '
        .'history" for exactly the rows an investigation starts from. The tenancy substitute is the '
        .'required positional organization_id on AuditLogRepositoryInterface::paginate().',
    );

    // THE POSITIVE HALF, AND IT IS WHAT STOPS THE ASSERTION ABOVE FROM PASSING VACUOUSLY. "No
    // attribute" is also true of a class that was deleted, renamed or never wired up.
    expect((new AuditLog)->getTable())->toBe('audit_logs');

    // AND THE SCOPE CLASS STILL EXISTS, so this file is asserting an absence of something real
    // rather than an absence of a typo.
    expect(class_exists(OrganizationScope::class))->toBeTrue();
});

test('the audit log implements OrgOwned but refuses to answer for a platform-scope row', function (): void {
    $log = new AuditLog;

    // IT IMPLEMENTS THE INTERFACE, because `OrganizationPolicy::viewAuditLog()` resolves membership
    // of a record's organization and `permit()` takes an OrgOwned — so a model that could not
    // answer `organizationId()` could not be authorized by any policy in this application.
    expect($log)->toBeInstanceOf(OrgOwned::class);

    // AND IT THROWS RATHER THAN RETURNING A SENTINEL when the row has none. `OrgOwned::
    // organizationId()` is declared non-nullable; an empty string here would be a lie the policy
    // layer would then compare against a real organization id and deny, which reads as a permission
    // bug rather than as what it is.
    //
    // THIS IS ALSO WHY THERE IS NO `AuditLogPolicy` AND NO ROW ENDPOINT. A per-row policy on this
    // table would be unreachable for exactly the platform rows, and reachable only through an
    // exception. `AuditLogController` authorizes against the ORGANIZATION instead.
    expect(fn (): string => $log->organizationId())->toThrow(\LogicException::class);
});

test('the audit log refuses to be updated or deleted through Eloquent', function (): void {
    // NEITHER GUARD IS THE MECHANISM — the migration REVOKES UPDATE and DELETE on the table and on
    // every partition from the application role, so both come back as SQLSTATE 42501. The PHP
    // refusals exist so the failure is a LogicException with a sentence in it at the call site, in a
    // unit test, before it is ever a driver error in a request a reviewer waved through.
    //
    // ASSERTED HERE BECAUSE PHASE 6a GAVE THIS TABLE A READ PATH. Until now nothing loaded an
    // AuditLog through Eloquent at all, so `$log->save()` on a LOADED row was unreachable by
    // construction; a reader is one `->update()` away from making it reachable.
    $log = new AuditLog;

    expect(fn () => $log->delete())->toThrow(\LogicException::class);

    // `performUpdate()` is protected and is reached through `save()` on a model that EXISTS. It is
    // asserted by reflection here rather than by driving a save, because Arch/ boots nothing and a
    // save would need a connection.
    $method = (new \ReflectionClass(AuditLog::class))->getMethod('performUpdate');

    expect($method->getDeclaringClass()->getName())->toBe(
        AuditLog::class,
        'AuditLog no longer overrides performUpdate(), so a loaded row can be saved: the refusal '
        .'falls through to the database grant and surfaces as SQLSTATE 42501 in a request instead '
        .'of as a sentence at the call site.',
    );
});

test('the audit log has an empty $fillable, so no request payload can reach a column', function (): void {
    // `'details' => $request->all()` is the failure `kb-security-baseline` names for this table, and
    // mass assignment is what turns it from a thing somebody has to write into a thing that happens
    // by default. There is no factory for this model for the same reason: the production writer is
    // the only way in.
    $fillable = (new \ReflectionClass(AuditLog::class))->getDefaultProperties()['fillable'] ?? null;

    expect($fillable)->toBe([], 'AuditLog gained a $fillable entry — probably "for the factory"');
});
