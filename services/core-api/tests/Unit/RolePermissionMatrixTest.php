<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\Permission;

/*
|--------------------------------------------------------------------------
| The role x permission matrix — laravel-rbac-policies §6.2-6.5
|--------------------------------------------------------------------------
|
| THE WHOLE TABLE, WRITTEN OUT, ONE ROW PER (ROLE, PERMISSION) PAIR.
|
| Not a set of "an admin can manage providers" spot checks, and not a re-implementation of
| OrgRole::grants()'s match expression — either of those passes against the inverted matrix. The
| expected values below are transcribed from the specification's table and from nowhere else, so this
| file is a second, independent statement of the same fact. When the two disagree, one of them is
| wrong and a human has to decide which; that is the point.
|
| THIS FILE IS A UNIT TEST AND BOOTS NOTHING. Both enums are pure PHP, so the matrix is checkable with
| no container, no database and no request. The two things it deliberately does NOT cover, because
| they need one:
|
|   * MEMBERSHIP STATUS. `OrganizationUser::grants()` is `isActive() && role->grants()`, so a
|     SUSPENDED owner holds nothing — a fact this matrix cannot express and which
|     tests/Feature/AdminInvitationTest.php asserts over HTTP ("403s a suspended member of the
|     organization they are addressing"). A matrix test that forgot it would read as complete.
|   * THE ROUTE-TO-PERMISSION MAPPING. Which permission each endpoint demands is a property of the
|     policies and the controllers; the four role-parameterised tests in AdminInvitationTest are that
|     half.
|
| A PERMISSION CASE ADDED WITHOUT A ROW HERE FAILS THE TOTALITY TEST AT THE BOTTOM, WHICH IS THE ONLY
| REASON THAT TEST EXISTS. `grants()` is a `match ($this)` over the ROLE, so a new Permission case
| silently inherits whatever each role's arm already says — `Owner => true` grants it to owners the
| moment it is declared, and `Admin => $permission !== MembersManageOwner` grants it to admins too.
| Neither is a compile error and neither is visible in a diff of the enum.
*/

/**
 * The specification's table, transcribed. Rows are roles, columns are permission values.
 *
 * @return array<string, array<string, bool>>
 */
function rolePermissionMatrix(): array
{
    return [
        // Everything, including the one action that cannot be undone by the role performing it.
        OrgRole::Owner->value => [
            'providers.view' => true,
            'providers.manage' => true,
            'members.view' => true,
            'members.manage' => true,
            'members.manage_owner' => true,
        ],

        // Everything EXCEPT owner promotion. Creating a second owner is irreversible from an admin's
        // seat, so it is the one permission the second-most-privileged role does not hold.
        OrgRole::Admin->value => [
            'providers.view' => true,
            'providers.manage' => true,
            'members.view' => true,
            'members.manage' => true,
            'members.manage_owner' => false,
        ],

        // Reads the provider configuration because an ingestion operator has to know whether the
        // organization can embed at all — and changes nothing.
        OrgRole::KnowledgeManager->value => [
            'providers.view' => true,
            'providers.manage' => false,
            'members.view' => false,
            'members.manage' => false,
            'members.manage_owner' => false,
        ],

        // Reporting only. Holds no permission in this catalog at all, which is a deliberate row rather
        // than an oversight: an analyst reads conversations and analytics, and neither is gated by a
        // Permission case that exists yet.
        OrgRole::Analyst->value => [
            'providers.view' => false,
            'providers.manage' => false,
            'members.view' => false,
            'members.manage' => false,
            'members.manage_owner' => false,
        ],
    ];
}

/**
 * @return array<string, array{0: string, 1: string, 2: bool}>
 */
function rolePermissionDataset(): array
{
    $rows = [];

    foreach (rolePermissionMatrix() as $role => $permissions) {
        foreach ($permissions as $permission => $granted) {
            $rows[$role.' / '.$permission] = [$role, $permission, $granted];
        }
    }

    return $rows;
}

it('grants exactly what the matrix says', function (string $role, string $permission, bool $granted): void {
    expect(OrgRole::from($role)->grants(Permission::from($permission)))->toBe(
        $granted,
        sprintf(
            'OrgRole::%s->grants(Permission::%s) is %s and the specification says %s. One of the two is '
            .'wrong; a privilege matrix is not a place to guess which.',
            $role,
            $permission,
            $granted ? 'false' : 'true',
            $granted ? 'true' : 'false',
        ),
    );
})->with(fn () => rolePermissionDataset());

it('covers every role and every permission, so a new case cannot inherit a grant silently', function (): void {
    $matrix = rolePermissionMatrix();

    $roles = array_map(static fn (OrgRole $role): string => $role->value, OrgRole::cases());
    $permissions = array_map(static fn (Permission $p): string => $p->value, Permission::cases());

    sort($roles);
    $actualRoles = array_keys($matrix);
    sort($actualRoles);

    expect($actualRoles)->toBe(
        $roles,
        'the matrix above does not name every OrgRole case. A role with no row is a role whose whole '
        .'privilege set is unasserted.',
    );

    foreach ($matrix as $role => $columns) {
        $actualPermissions = array_keys($columns);
        sort($actualPermissions);
        $expected = $permissions;
        sort($expected);

        expect($actualPermissions)->toBe(
            $expected,
            "the row for `{$role}` does not name every Permission case. `grants()` is a match on the "
            .'ROLE, so a new Permission case is silently granted by every arm that returns a blanket '
            .'true — `Owner => true` grants it immediately and `Admin => $permission !== '
            .'MembersManageOwner` grants it too, with no compile error and nothing in the diff.',
        );
    }

    // And the total, so the dataset above cannot shrink unnoticed.
    expect(count(rolePermissionDataset()))->toBe(count($roles) * count($permissions));
});

it('gives exactly one role the owner-promotion permission', function (): void {
    // THE ESCALATION GUARD, ASSERTED AS A COUNT. `Permission::MembersManageOwner` exists so that "only
    // an owner may create another owner" is a one-line policy delegation rather than bespoke logic
    // inside a policy body — OrgScopedPolicy::permit() takes (user, record, permission) and has no
    // argument position for "the role being granted". If a second role ever holds it, the guard is
    // gone and the failure is silent: an admin invites an owner and gets a 201.
    $holders = array_values(array_filter(
        OrgRole::cases(),
        static fn (OrgRole $role): bool => $role->grants(Permission::MembersManageOwner),
    ));

    expect($holders)->toBe([OrgRole::Owner]);
});

it('keeps the role catalog fixed at four, because there is no per-tenant role CRUD', function (): void {
    // The catalog is published CLOSED in the OpenAPI document (SessionMembership.role carries an enum),
    // and it is enforced by a CHECK constraint on organization_users.role. Adding a case is therefore
    // three coordinated changes plus a migration, and this line is where a fourth-and-a-half role
    // announces itself.
    expect(OrgRole::values())->toBe(['owner', 'admin', 'knowledge_manager', 'analyst']);

    expect(Permission::cases())->toHaveCount(5);
});
