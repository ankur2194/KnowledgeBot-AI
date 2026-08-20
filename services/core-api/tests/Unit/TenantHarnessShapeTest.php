<?php

declare(strict_types=1);

use Tests\Support\TenantPair;

/*
|--------------------------------------------------------------------------
| The harness has a shape, and the shape is the security control
|--------------------------------------------------------------------------
|
| A one-organization fixture cannot fail an isolation test. With a single tenant there is nothing to
| leak, so the test passes against code with NO FILTER AT ALL — and it passes quickly, quietly, and
| forever.
|
| Everything below is reflection over the fixture's own shape. No container, no database, no facades,
| no framework boot: this file must keep running on the day the app does not.
|
| What it protects is not a line of application code. It protects the harness from being "simplified"
| — a single $org property, a tenantPair(int $count) with a default of 1, a convenience helper that
| returns one organization for the tests that "only need one". Each of those is a small, reasonable-
| looking diff that silently converts every §22.5 test into decoration, and none of them fails
| anything else.
*/

test('the fixture carries two organizations, and they are not interchangeable', function (): void {
    $properties = array_map(
        fn (\ReflectionProperty $p): string => $p->getName(),
        (new \ReflectionClass(TenantPair::class))->getProperties(),
    );

    // Two organizations, two bots, two actors — as SEPARATE properties, so a test physically
    // cannot read "the" organization. Collapsing any pair into one is the change this catches.
    expect($properties)->toContain('a', 'b', 'botA', 'botB', 'actorA', 'actorB', 'canary');
});

test('there is no singular organization on the fixture to reach for', function (): void {
    $properties = array_map(
        fn (\ReflectionProperty $p): string => strtolower($p->getName()),
        (new \ReflectionClass(TenantPair::class))->getProperties(),
    );

    // `$t->org`, `$t->organization`, `$t->tenant`, `$t->bot`, `$t->actor` would each read perfectly
    // naturally at a call site and would each mean the test author never decided WHICH tenant they
    // meant. Ambiguity at the call site is how a negative assertion ends up run against the org that
    // planted the canary.
    foreach (['org', 'organization', 'tenant', 'bot', 'actor', 'user'] as $singular) {
        expect($properties)->not->toContain($singular);
    }
});

test('the fixture is immutable, so a test cannot repoint an actor mid-assertion', function (): void {
    $class = new \ReflectionClass(TenantPair::class);

    // readonly is what stops `$t->actorA = $t->actorB;` — which would make the negative assertion
    // run as Org B and pass while proving the exact opposite of what it claims.
    expect($class->isReadOnly())->toBeTrue();
    expect($class->isFinal())->toBeTrue();
});

test('every model property is narrowed to its model, and none is left as object', function (): void {
    // WHY A TYPE IS A SECURITY PROPERTY HERE. `$t->botA` and `$t->botB` are the pair the whole
    // fixture exists to keep distinct, and `object` lets a test hand either one to anything. Static
    // analysis runs over tests/ at level 8 and cannot object to a property typed `object`, so the
    // mistake that matters most — passing Org B's record into an assertion written for Org A, which
    // makes a negative assertion run as the organization that PLANTED the canary and pass while
    // proving the opposite of what it claims — would type-check silently.
    //
    // Asserted as "no property is `object`" rather than by naming each type, so the property that
    // matters cannot be lost by a widening that also renames.
    $class = new \ReflectionClass(TenantPair::class);

    $expected = [
        'a' => \App\Models\Organization::class,
        'b' => \App\Models\Organization::class,
        'botA' => \App\Models\Bot::class,
        'botB' => \App\Models\Bot::class,
        'actorA' => \App\Models\User::class,
        'actorB' => \App\Models\User::class,
    ];

    foreach ($class->getProperties() as $property) {
        expect((string) $property->getType())->not->toBe('object', $property->getName().' is untyped');
    }

    foreach ($expected as $name => $type) {
        expect((string) $class->getProperty($name)->getType())->toBe($type);
    }
});

test('the canary is a per-test string, not a shared constant', function (): void {
    $canary = (new \ReflectionClass(TenantPair::class))->getProperty('canary');

    // A class constant or a fixed literal would be satisfiable by a stale Qdrant point or a warm
    // answer cache left behind by an earlier run — the assertion would then pass without the
    // current test having planted anything at all.
    expect((string) $canary->getType())->toBe('string');
    expect($canary->isReadOnly())->toBeTrue();
    expect((new \ReflectionClass(TenantPair::class))->getConstants())->toBe([]);
});

test('tenantPair() is the only entry point and it takes no arguments', function (): void {
    expect(function_exists('tenantPair'))->toBeTrue();

    // Zero parameters on purpose. `tenantPair(int $organizations = 1)` is the one-line change that
    // would make a single-organization fixture available again, and it would look like a
    // flexibility improvement in review.
    expect((new \ReflectionFunction('tenantPair'))->getNumberOfParameters())->toBe(0);
});

test('no single-organization helper exists in the global test namespace', function (): void {
    // There is deliberately no single-org helper, and there never will be. This asserts the absence
    // rather than trusting the convention, because the convention is documented in a file nobody
    // reads while adding a helper.
    foreach (['tenant', 'singleOrg', 'organization', 'org', 'makeOrg', 'seedOrg'] as $forbidden) {
        expect(function_exists($forbidden))->toBeFalse("global helper {$forbidden}() exists");
    }
});
