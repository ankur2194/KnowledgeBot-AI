<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Enums\Surface;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Who may reach a bot, and what a denial reveals (§22.5)
|--------------------------------------------------------------------------
|
| THE POLICIES ARE EXERCISED DIRECTLY THROUGH THE GATE AND NOT THROUGH ROUTES, because the routes do
| not exist yet — they are the next task's — and the authorization layer has to be correct before
| the first endpoint is mounted on it. That is not a compromise: a Gate check is exactly what a
| controller performs, and testing it here means the endpoint author inherits a proven matrix rather
| than being the first person to find out.
|
| What this file therefore does NOT cover, and must gain when the routes land: route-model binding
| (a foreign `{bot}` under `{organization}` with `->scopeBindings()` must 404 at BINDING time, before
| any policy is constructed), and CHECK 5 — the publish guard's entity-status refusal, which
| `OrgScopedPolicy::permit()` has no argument position for.
|
| ── THE MATRIX IS ASSERTED PER ACTION, NEVER FROM A UNIFORM DATASET ─────────────────────────────
|
| `ProviderModelEntryPolicy`'s docblock records why: a uniform dataset goes green against a `view`
| that has silently been mapped to `bots.manage`. Here the failure would be subtler still. Every
| role that holds `bots.manage` ALSO holds `bots.view`, so a `view` mis-mapped to `bots.manage`
| produces identical answers for owner and admin and differs only for knowledge_manager and analyst
| — the two rows a hand-written dataset is most likely to under-cover, and the two whose grants are
| an extension of the specification rather than a reading of it.
|
| ── ABSENCE IS ASSERTED POSITIVELY ──────────────────────────────────────────────────────────────
|
| Each row of the dataset carries its EXPECTED verdict rather than being split into "allowed" and
| "denied" datasets, so a role that vanished from the catalog fails the totality check in
| tests/Unit/RolePermissionMatrixTest.php rather than quietly shrinking this one.
*/

/**
 * A bot, its organization, and an actor holding $role in that organization.
 *
 * The name is deliberately not `botPair()` or anything ProviderConnectionAccessTest/
 * ProviderModelAccessTest might also want: Pest declares test-file helpers at FILE SCOPE, so two
 * files declaring one name is a redeclaration fatal in a full run and only in a full run.
 *
 * @return array{0: Bot, 1: User}
 */
function botActorFor(OrgRole $role, MembershipStatus $status = MembershipStatus::Active): array
{
    $organization = Organization::factory()->create();

    return [
        Bot::factory()->recycle($organization)->create(),
        User::factory()->recycle($organization)->orgRole($role, $status)->create(),
    ];
}

/**
 * Every (ability, role) pair on the bot itself, with the verdict the specification and the two
 * recorded extensions produce.
 *
 * @return array<string, array{0: string, 1: OrgRole, 2: bool}>
 */
function botAbilityMatrix(): array
{
    $matrix = [
        // §6.3 "Manage bots" is owner + admin. `bots.view` additionally reaches knowledge_manager
        // (Phase C6 assigns sources TO bots) and analyst (Phase E reviews conversations PER bot);
        // §6.4 and §6.5 never mention bots in either direction, so both are extensions of the spec
        // decided with the repo owner and recorded in App\Enums\Permission and OrgRole::grants().
        'view' => [
            OrgRole::Owner->value => true,
            OrgRole::Admin->value => true,
            OrgRole::KnowledgeManager->value => true,
            OrgRole::Analyst->value => true,
        ],
        'update' => [
            OrgRole::Owner->value => true,
            OrgRole::Admin->value => true,
            OrgRole::KnowledgeManager->value => false,
            OrgRole::Analyst->value => false,
        ],
        // Publishing is `update` — there is no `publish` ability, because a `bots.publish`
        // permission would be granted to exactly these two roles. The delete carries the same
        // permission for the same reason.
        'delete' => [
            OrgRole::Owner->value => true,
            OrgRole::Admin->value => true,
            OrgRole::KnowledgeManager->value => false,
            OrgRole::Analyst->value => false,
        ],
        // The origin allow-list, the starter questions and the fallback chain, all authorized
        // against the PARENT bot because two of the three have no row yet when the write starts.
        'manageChildren' => [
            OrgRole::Owner->value => true,
            OrgRole::Admin->value => true,
            OrgRole::KnowledgeManager->value => false,
            OrgRole::Analyst->value => false,
        ],
    ];

    $rows = [];

    foreach ($matrix as $ability => $roles) {
        foreach ($roles as $role => $allowed) {
            $rows[$ability.' / '.$role] = [$ability, OrgRole::from($role), $allowed];
        }
    }

    return $rows;
}

/**
 * The two organization-scoped abilities, which have no bot row to take an organization from.
 *
 * @return array<string, array{0: string, 1: OrgRole, 2: bool}>
 */
function organizationBotAbilityMatrix(): array
{
    $matrix = [
        'viewBots' => [
            OrgRole::Owner->value => true,
            OrgRole::Admin->value => true,
            OrgRole::KnowledgeManager->value => true,
            OrgRole::Analyst->value => true,
        ],
        'createBot' => [
            OrgRole::Owner->value => true,
            OrgRole::Admin->value => true,
            OrgRole::KnowledgeManager->value => false,
            OrgRole::Analyst->value => false,
        ],
    ];

    $rows = [];

    foreach ($matrix as $ability => $roles) {
        foreach ($roles as $role => $allowed) {
            $rows[$ability.' / '.$role] = [$ability, OrgRole::from($role), $allowed];
        }
    }

    return $rows;
}

it('answers each bot ability per role exactly as the matrix says', function (string $ability, OrgRole $role, bool $allowed): void {
    [$bot, $actor] = botActorFor($role);

    expect(Gate::forUser($actor)->inspect($ability, $bot)->allowed())->toBe(
        $allowed,
        sprintf(
            'BotPolicy::%s() for a %s is %s and the matrix says %s. One of the two is wrong; a '
            .'privilege matrix is not a place to guess which.',
            $ability,
            $role->value,
            $allowed ? 'denied' : 'allowed',
            $allowed ? 'allowed' : 'denied',
        ),
    );
})->with(fn () => botAbilityMatrix());

it('answers each organization-scoped bot ability per role exactly as the matrix says', function (string $ability, OrgRole $role, bool $allowed): void {
    $organization = Organization::factory()->create();
    $actor = User::factory()->recycle($organization)->orgRole($role)->create();

    expect(Gate::forUser($actor)->inspect($ability, $organization)->allowed())->toBe($allowed);
})->with(fn () => organizationBotAbilityMatrix());

it('covers every role in the bot matrix, so a new role cannot arrive unasserted', function (): void {
    // The totality guard. `OrgRole::grants()` is a `match ($this)` over the ROLE, so a new role's
    // arm is written once and every ability inherits it — including this policy's, with no compile
    // error and nothing in the diff of this file.
    $roles = array_map(static fn (OrgRole $r): string => $r->value, OrgRole::cases());
    sort($roles);

    foreach (['view', 'update', 'delete', 'manageChildren'] as $ability) {
        $covered = [];

        foreach (array_keys(botAbilityMatrix()) as $key) {
            if (str_starts_with($key, $ability.' / ')) {
                $covered[] = substr($key, strlen($ability.' / '));
            }
        }

        sort($covered);

        expect($covered)->toBe($roles, "BotPolicy::{$ability}() is not asserted for every role");
    }
});

it('denies an owner of one organization on another organization\'s bot', function (): void {
    // "ADMIN OF SOME ORGANIZATION" IS THE CROSS-TENANT BUG, and it is structurally unrepresentable
    // here: `OrgScopedPolicy::permit()` takes the RECORD and resolves membership of ITS
    // organization, so there is no argument position a session's "current org" could enter through.
    // This asserts the outcome anyway, because the structure is only a guarantee for as long as
    // nobody adds a second primitive.
    $t = tenantPair();

    // POSITIVE CONTROL FIRST: each admin is genuinely an admin, of their own organization's bot.
    // Without it this test passes when both actors have no membership row at all, which is the
    // false green that reads as "authorization works".
    expect(Gate::forUser($t->actorA)->inspect('update', $t->botA)->allowed())->toBeTrue();
    expect(Gate::forUser($t->actorB)->inspect('update', $t->botB)->allowed())->toBeTrue();

    foreach (['view', 'update', 'delete', 'manageChildren'] as $ability) {
        expect(Gate::forUser($t->actorA)->inspect($ability, $t->botB)->allowed())->toBeFalse(
            "an admin of organization A was allowed to {$ability} organization B's bot",
        );
        expect(Gate::forUser($t->actorB)->inspect($ability, $t->botA)->allowed())->toBeFalse();
    }
});

it('denies a suspended member of the organization it is addressing', function (): void {
    // The one fact the role x permission matrix cannot express: `OrganizationUser::grants()` is
    // `isActive() && role->grants()`, so a SUSPENDED owner holds nothing. A matrix test that forgot
    // it would read as complete.
    [$bot, $suspendedOwner] = botActorFor(OrgRole::Owner, MembershipStatus::Suspended);

    foreach (['view', 'update', 'delete', 'manageChildren'] as $ability) {
        expect(Gate::forUser($suspendedOwner)->inspect($ability, $bot)->allowed())->toBeFalse();
    }
});

it('denies a user with no membership row at all', function (): void {
    $organization = Organization::factory()->create();
    $bot = Bot::factory()->recycle($organization)->create();

    // No ->orgRole(): a user is not owned by an organization, and membership lives in
    // `organization_users`. This is also the shape a REMOVED member has.
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->inspect('view', $bot)->allowed())->toBeFalse();
});

it('denies a guest, and reaches the policy to do it rather than short-circuiting', function (): void {
    $organization = Organization::factory()->create();
    $bot = Bot::factory()->recycle($organization)->create();

    $response = Gate::forUser(null)->inspect('view', $bot);

    expect($response->allowed())->toBeFalse();

    // The policy methods take `?User`, which is what lets the Gate call them for a guest at all —
    // a non-nullable parameter makes Laravel skip the policy entirely and deny without ever
    // consulting it. That looks identical from here, so the assertion is on the MESSAGE, which only
    // OrgScopedPolicy::refuse() produces.
    expect($response->message())->toBe('This action is unauthorized.');
});

it('denies with 403 on the admin surface and 404 on a public one, from the same policy', function (): void {
    // THE ENUMERATION SPLIT. A 403 on a foreign identifier confirms the row exists; on the public
    // runtime and SDK surfaces that turns the endpoint into an enumeration oracle. The `error_class`
    // is `authorization` in BOTH cases and nothing branches on the rendered status
    // (kb-error-taxonomy footnote 1).
    $t = tenantPair();

    // Default binding: Surface::Admin. `status()` is null, which is Laravel's "use the caller's
    // default", and the caller is `Gate::authorize()` — 403.
    $admin = Gate::forUser($t->actorA)->inspect('view', $t->botB);

    expect($admin->allowed())->toBeFalse()
        ->and($admin->status())->toBeNull()
        ->and($admin->message())->toBe('This action is unauthorized.');

    // instance(), not bind(): the surface must be FIXED for the rest of this test, exactly as
    // App\Http\Middleware\BindSurface fixes it for a request.
    app()->instance(Surface::class, Surface::PublicRuntime);

    $public = Gate::forUser($t->actorA)->inspect('view', $t->botB);

    expect($public->allowed())->toBeFalse()
        ->and($public->status())->toBe(404);

    // AND THE SAME SURFACE MUST STILL ALLOW THE LEGITIMATE CALLER. Without this, a policy that
    // denied everything on a public surface would pass the assertion above — and a widget that
    // 404s for its own bot is a bug that reads as a routing problem.
    expect(Gate::forUser($t->actorB)->inspect('view', $t->botB)->allowed())->toBeTrue();
});
