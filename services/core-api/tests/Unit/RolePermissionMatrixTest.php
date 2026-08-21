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
            'bots.view' => true,
            'bots.manage' => true,
            // §6.2 gives the Owner every source operation the Administrator has and then some.
            'sources.view' => true,
            'sources.manage' => true,
            'sources.upload' => true,
            'sources.assign' => true,
            'members.view' => true,
            'members.manage' => true,
            'members.manage_owner' => true,
        ],

        // Everything EXCEPT owner promotion. Creating a second owner is irreversible from an admin's
        // seat, so it is the one permission the second-most-privileged role does not hold.
        OrgRole::Admin->value => [
            'providers.view' => true,
            'providers.manage' => true,
            // §6.3, "Manage bots", verbatim. The only bot permission an admin does not hold is one
            // that does not exist: publish and delete are both `bots.manage`, because a separate
            // case would be granted to exactly these two roles and a permission nobody grants
            // differently fails silently in both directions.
            'bots.view' => true,
            'bots.manage' => true,
            // §6.3's "Manage bots" sits inside a role the spec describes as managing the
            // organization's configuration; the source operations §6.4 gives the Knowledge Manager
            // are a subset of what an Administrator may do, never a set an Administrator lacks.
            'sources.view' => true,
            'sources.manage' => true,
            'sources.upload' => true,
            'sources.assign' => true,
            'members.view' => true,
            'members.manage' => true,
            'members.manage_owner' => false,
        ],

        // Reads the provider configuration because an ingestion operator has to know whether the
        // organization can embed at all — and changes nothing.
        //
        // `bots.view` IS AN EXTENSION OF THE SPECIFICATION, NOT A READING OF IT. §6.4 never mentions
        // bots in either direction; the grant was decided with the repo owner against Phase C6,
        // which has this role assign knowledge sources TO bots — an assignment screen is a list of
        // bots, so a role that cannot read one cannot do the job §6.4 does give them. Stated here
        // as well as in Permission::BotsView because this file is the INDEPENDENT statement of the
        // matrix, and an extension recorded only in the code it justifies is not independent.
        OrgRole::KnowledgeManager->value => [
            'providers.view' => true,
            'providers.manage' => false,
            'bots.view' => true,
            'bots.manage' => false,
            // ALL FOUR SOURCE PERMISSIONS, AND UNLIKE `bots.view` THIS IS A READING OF THE SPEC
            // RATHER THAN AN EXTENSION OF IT. §6.4 is a six-item list and every item is a source
            // operation: upload documents, add websites, review parsed content, trigger
            // reprocessing, disable/archive/delete sources, view freshness. `sources.assign` is
            // C6's, and it is what `bots.view` was granted to this role FOR — the assignment screen
            // is a list of bots, so the two grants stand or fall together.
            'sources.view' => true,
            'sources.manage' => true,
            'sources.upload' => true,
            'sources.assign' => true,
            'members.view' => false,
            'members.manage' => false,
            'members.manage_owner' => false,
        ],

        // Reporting only, and NO LONGER AN ALL-FALSE ROW — which is the single most surprising line
        // in this file for anyone who read the previous version.
        //
        // `bots.view` is an EXTENSION OF THE SPECIFICATION decided with the repo owner: §6.5 never
        // mentions bots in either direction, and Phase E has an analyst review conversations PER
        // BOT. A transcript is uninterpretable without the bot that produced it — the name, the
        // model and the answer mode are what make it readable. Nothing else in this catalog moves:
        // an analyst still reaches no credential, no member and no write.
        OrgRole::Analyst->value => [
            'providers.view' => false,
            'providers.manage' => false,
            'bots.view' => true,
            'bots.manage' => false,
            // NO SOURCE PERMISSION, INCLUDING `sources.view`, AND THE TEMPTING ARGUMENT FOR IT IS
            // REFUSED DELIBERATELY. Phase E has an analyst review conversations, and a transcript
            // cites sources — but `citations` denormalizes label, display title, location and
            // excerpt onto the citation row precisely so a transcript survives the source being
            // purged, so conversation review reads NOTHING from `knowledge_sources`. Granting it
            // would widen an analyst's reach to every document title, tag and crawl URL in the
            // organization to serve a screen that does not read them. Silence is not a grant.
            'sources.view' => false,
            'sources.manage' => false,
            'sources.upload' => false,
            'sources.assign' => false,
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

    // PINNED BY COUNT HERE AND BY NAME EVERYWHERE ELSE, which is the opposite of what
    // AuditLoggerTest concluded for its own operation set — and the difference is what the
    // assertion is for. This line is not an inventory of the catalog: the matrix above already
    // names every case four times over and fails if one is missing. It is a guard on the DATASET
    // SIZE, so that a permission added with a full set of matrix rows still trips one line that a
    // human has to look at, because widening the privilege catalog is not a thing that should be
    // possible to do entirely mechanically. Phase C1 took it from 7 to 11.
    expect(Permission::cases())->toHaveCount(11);
});
