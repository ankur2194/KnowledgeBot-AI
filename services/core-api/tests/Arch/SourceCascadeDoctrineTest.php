<?php

declare(strict_types=1);

use App\Models\BotSourceAssignment;
use App\Models\Chunk;
use App\Models\DocumentElement;
use App\Models\KnowledgeSource;
use App\Models\Scopes\OrganizationScope;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Policies\KnowledgeSourcePolicy;
use App\Policies\OrgScopedPolicy;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/*
|--------------------------------------------------------------------------
| The six source-cascade models are org-scoped BY CONSTRUCTION
|--------------------------------------------------------------------------
|
| REFLECTION AND NOT arch(), because `#[ScopedBy]` IS AN ATTRIBUTE and Pest's arch DSL cannot see
| one. tests/Arch/DoctrineTest.php carries the same rule commented out as a pending reflection test
| over the WHOLE of App\Models, and it stays commented out for a reason that block states in full:
| enabling it unqualified requires an ANNOTATED exception list, because `OrganizationUser` and
| `OrganizationInvitation` hold `organization_id` and deliberately carry no attribute — the first is
| read BEFORE a tenant context exists, by the middleware whose job is to establish one, and the
| second is read on the guest paths where the token is what identifies the organization.
|
| THIS FILE DOES NOT ENABLE THAT RULE AND MUST NOT BE MISTAKEN FOR IT. It names SIX classes
| explicitly, all of which are unambiguously scoped, so it needs no exception list and cannot
| acquire one by accident. The general rule is still owed, still blocked on the same thing, and
| still described where it belongs.
|
| WHY IT IS WORTH ASSERTING AT ALL, GIVEN THAT THE ATTRIBUTE IS RIGHT THERE IN THE FILE: because
| OrganizationScope FAILS CLOSED. With no bound context it applies `whereRaw('1 = 0')`, so a model
| that LOST the attribute does not break loudly in the suite — it starts returning every
| organization's rows in exactly the code paths where a context IS bound, which is all of them that
| matter. The failure direction of removing this attribute is a breach, not an outage.
|
| Arch/ extends nothing: no container, no database, no facades. This file must keep running on the
| day the application does not boot.
*/

/**
 * The six tables Phase C1 created, as data, so a seventh cannot be added to the cascade without a
 * line here.
 *
 * INSTANCES AND NOT CLASS STRINGS, deliberately. `$model->getFillable()` is the accessor the
 * framework itself consults — `$fillable` is protected, so reflecting on the property would assert
 * something Eloquent does not necessarily read — and constructing a model touches no container, no
 * database and no facade, which is what Arch/ requires. It also keeps the static analyser able to
 * resolve the method: a `class-string` dataset makes every call a method on `object`.
 *
 * @return array<string, array{0: \Illuminate\Database\Eloquent\Model}>
 */
function sourceCascadeModels(): array
{
    return [
        'KnowledgeSource' => [new KnowledgeSource],
        'SourceItem' => [new SourceItem],
        'SourceVersion' => [new SourceVersion],
        'DocumentElement' => [new DocumentElement],
        'Chunk' => [new Chunk],
        'BotSourceAssignment' => [new BotSourceAssignment],
    ];
}

test('carries the organization scope as an attribute', function (\Illuminate\Database\Eloquent\Model $model): void {
    $attributes = (new \ReflectionClass($model))->getAttributes(ScopedBy::class);

    expect($attributes)->not->toBeEmpty(
        $model::class.' has no #[ScopedBy] attribute. OrganizationScope fails CLOSED, so losing it does '
        .'not break the suite — it makes every query on this model return every organization\'s '
        .'rows wherever a tenant context is bound, silently.'
    );

    // The attribute has to name THIS scope. `#[ScopedBy(SomeOtherScope::class)]` satisfies the
    // check above and applies no tenant predicate at all.
    expect($attributes[0]->getArguments())->toContain(OrganizationScope::class);
})->with(fn () => sourceCascadeModels());

test('implements OrgOwned, so a policy can resolve the organization from the record', function (\Illuminate\Database\Eloquent\Model $model): void {
    // This is what makes "admin of some organization" unrepresentable: OrgScopedPolicy::permit()
    // takes an OrgOwned and resolves membership of THAT organization, so there is no argument
    // position a caller could pass a session's current org into.
    expect($model)->toBeInstanceOf(
        OrgOwned::class,
        $model::class.' does not implement OrgOwned, so it cannot be authorized by an org-scoped policy '
        .'and any Gate::authorize() against it would have to take its organization from somewhere '
        .'other than the record.'
    );
})->with(fn () => sourceCascadeModels());

test('declares organization_id outside $fillable, so it cannot be over-posted', function (\Illuminate\Database\Eloquent\Model $model): void {
    // Over-posting a tenant key is an authorization bug with a 200 response. Read through
    // getFillable() rather than through reflection on the property, because $fillable is protected
    // and getFillable() is the accessor the framework itself consults.
    expect($model->getFillable())->not->toContain('organization_id');

    // AND THE OWNERSHIP EDGES WITH IT. A fillable `bot_id` or `source_id` on
    // `bot_source_assignments` would let a PATCH move a live grant between two records of the SAME
    // tenant — which neither composite foreign key can object to, because both belong to that
    // tenant.
    foreach (['bot_id', 'source_id', 'source_item_id', 'source_version_id'] as $edge) {
        expect($model->getFillable())->not->toContain(
            $edge,
            $model::class.' allows mass assignment of `'.$edge.'`, which is an ownership edge: a composite '
            .'foreign key cannot refuse a move between two records of the same organization.'
        );
    }
})->with(fn () => sourceCascadeModels());

test('the knowledge-source policy is org-scoped by construction', function (): void {
    // tests/Arch/DoctrineTest.php already asserts this for EVERY class in App\Policies. Restated
    // here by name because that rule is a suffix-and-parent shape check over a namespace, and a
    // reader looking for the guarantee about THIS policy should find it beside the model rules it
    // belongs with rather than inferring it from a wildcard.
    // Asserted as the DIRECT parent rather than with is_subclass_of(): the parent is what the arch
    // rule in DoctrineTest.php pins, and an intermediate class inserted between the two would
    // satisfy a subclass check while giving every ability somewhere else to make a decision.
    $parent = (new \ReflectionClass(KnowledgeSourcePolicy::class))->getParentClass();

    expect($parent)->toBeInstanceOf(\ReflectionClass::class);
    expect($parent === false ? null : $parent->getName())->toBe(OrgScopedPolicy::class);

    // EVERY PUBLIC ABILITY TAKES `?User` FIRST AND AN OrgOwned RECORD SECOND. That shape is what
    // makes permit() the only reachable decision: an ability taking an organization id, a request,
    // or a bare string would have somewhere to get a tenant from that is not the record.
    $methods = (new \ReflectionClass(KnowledgeSourcePolicy::class))->getMethods(\ReflectionMethod::IS_PUBLIC);

    $abilities = array_values(array_filter(
        $methods,
        static fn (\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === KnowledgeSourcePolicy::class,
    ));

    expect($abilities)->not->toBeEmpty();

    foreach ($abilities as $ability) {
        $parameters = $ability->getParameters();

        expect($parameters)->toHaveCount(
            2,
            'KnowledgeSourcePolicy::'.$ability->getName().'() does not take exactly (?User, $record). '
            .'A third argument is where a tenant that did not come from the record gets in.'
        );

        expect((string) $parameters[0]->getType())->toBe('?App\Models\User');
        expect((string) $parameters[1]->getType())->toBe(KnowledgeSource::class);
        expect((string) $ability->getReturnType())->toBe('Illuminate\Auth\Access\Response');
    }
});
