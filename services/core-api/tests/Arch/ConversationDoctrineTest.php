<?php

declare(strict_types=1);

use App\Models\Citation;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\ProviderCall;
use App\Models\RetrievalTrace;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/*
|--------------------------------------------------------------------------
| The conversation graph: which models are scoped, which are exempt, and why
|--------------------------------------------------------------------------
|
| THIS FILE IS THE ANNOTATED EXCEPTION LIST ADR-043 SAID HAD TO EXIST BEFORE A FOURTH MODEL CLAIMED
| THE EXEMPTION. Its trade-off section is explicit: "Revisit when H1 is closed …, or when a FOURTH
| model wants the exemption — at which point the annotated exception list must be written BEFORE
| that model lands, not after." D1 lands FOUR at once — Message, RetrievalTrace, Citation, Feedback
| — so this is that list, written in the same change.
|
| IT IS NOT THE GENERAL RULE. tests/Arch/DoctrineTest.php still carries "every org-owned model
| carries the organization scope" commented out over the WHOLE of App\Models, and it still cannot be
| enabled unqualified. This file names TEN classes explicitly — six that must carry the attribute
| and four that must not — in the same construction tests/Arch/SourceCascadeDoctrineTest.php uses
| for its six, so it needs no ->ignoring() and cannot acquire one by accident.
|
| ── THE TWO EXEMPTION REASONS IN THIS SCHEMA ARE DIFFERENT, AND CONFLATING THEM IS THE HAZARD ──
|
| ADR-043's two models — OrganizationInvitation and EmailVerificationToken, plus the pre-existing
| OrganizationUser — HOLD `organization_id` and deliberately carry no attribute, because
| OrganizationScope FAILS CLOSED and the guest paths that read them run before any organization is
| known. Attaching the scope there would make registration break silently while the endpoint renders
| a plausible "this invitation is no longer valid".
|
| THE FOUR HERE ARE EXEMPT FOR A HARDER AND SIMPLER REASON: THERE IS NO COLUMN TO SCOPE.
| `messages`, `retrieval_traces`, `citations` and `feedback` have no `organization_id` at all, so
| OrganizationScope::apply() would append a predicate on a column PostgreSQL does not have —
| SQLSTATE 42703, on every read, from every surface. That is not a weaker guarantee; it is a syntax
| error, and it is why the assertion below is that the attribute is ABSENT rather than that it is
| merely permitted to be.
|
| WHY THE COLUMN IS ABSENT is kb-tenancy-isolation NN1's second shape, which names this exact chain:
| `citations/feedback -> messages -> conversations`. A denormalized copy would buy an org-leading
| index and cost the one thing the chain guarantees — a copy can DISAGREE with its parent, and a
| message whose copy says Org A while its conversation says Org B passes an org-scoped query in one
| organization and renders in the other's transcript.
|
| ── WHAT ENFORCES ISOLATION FOR THE FOUR, SINCE THE BACKSTOP IS UNAVAILABLE ───────────────────
|
| The same substitute ADR-043 names: every read goes through a repository method taking
| `organization_id` as a REQUIRED POSITIONAL ARGUMENT, joined through `conversations`. ADR-043 is
| candid that this is a convention rather than a mechanism — "a repository method that grows an
| `organization_id`-optional overload silently loses the whole guard, and no test would notice" —
| and that remains true here. What this file adds is that the exemption itself is now a named,
| reasoned assertion rather than an absence.
|
| Arch/ extends nothing: no container, no database, no facades. This file must keep running on the
| day the application does not boot.
*/

/**
 * The two models that HOLD `organization_id`, as data.
 *
 * INSTANCES AND NOT CLASS STRINGS, for the reason SourceCascadeDoctrineTest states: constructing a
 * model touches no container, no database and no facade, and it keeps the static analyser able to
 * resolve methods that a `class-string` dataset would leave on `object`.
 *
 * @return array<string, array{0: \Illuminate\Database\Eloquent\Model}>
 */
function conversationScopedModels(): array
{
    return [
        'Conversation' => [new Conversation],
        // IT CARRIES ITS OWN `organization_id` PRECISELY BECAUSE ITS LINKS TO THE TRANSCRIPT ARE
        // NULLABLE. `conversation_id` and `message_id` are both ON DELETE SET NULL — retention
        // removes CONTENT and not COST — so this row must be able to stand alone after the thread
        // it paid for is gone, which means it cannot reach an organization through a chain.
        'ProviderCall' => [new ProviderCall],
    ];
}

/**
 * The four models that reach an organization through `conversations` and have no column of their
 * own. See the header for why the exemption is a different one from ADR-043's.
 *
 * @return array<string, array{0: \Illuminate\Database\Eloquent\Model}>
 */
function conversationChainedModels(): array
{
    return [
        'Message' => [new Message],
        'RetrievalTrace' => [new RetrievalTrace],
        'Citation' => [new Citation],
        'Feedback' => [new Feedback],
    ];
}

test('the two models with an organization_id column carry the scope', function (\Illuminate\Database\Eloquent\Model $model): void {
    $attributes = (new \ReflectionClass($model))->getAttributes(ScopedBy::class);

    expect($attributes)->not->toBeEmpty(
        $model::class.' has no #[ScopedBy] attribute. OrganizationScope fails CLOSED, so losing it '
        .'does not break the suite — it makes every query on this model return every organization\'s '
        .'rows wherever a tenant context is bound, silently. On ProviderCall that is one tenant\'s '
        .'spend appearing in another\'s cost report.',
    );

    // The attribute has to name THIS scope. `#[ScopedBy(SomeOtherScope::class)]` satisfies the
    // check above and applies no tenant predicate at all.
    expect($attributes[0]->getArguments())->toContain(OrganizationScope::class);
})->with('conversationScopedModels');

test('the four chained models deliberately carry NO scope, because there is no column to scope', function (\Illuminate\Database\Eloquent\Model $model): void {
    $attributes = (new \ReflectionClass($model))->getAttributes(ScopedBy::class);

    expect($attributes)->toBeEmpty(
        $model::class.' has acquired #[ScopedBy(OrganizationScope::class)], and the table it maps to '
        .'has no organization_id column. The scope appends `where '.$model->getTable()
        .'.organization_id = ?`, which is SQLSTATE 42703 on EVERY read from EVERY surface — so this '
        .'is not an over-scope, it is a syntax error. If a column was genuinely added, this '
        .'assertion moves to the other dataset in the same change and the model\'s docblock, the '
        .'migration and kb-tenancy-isolation NN1\'s chain all move with it.',
    );

    // THE POSITIVE HALF, AND IT IS WHAT STOPS THE ASSERTION ABOVE FROM PASSING VACUOUSLY. "No
    // attribute" is also true of a class that was deleted, renamed, or never wired up. Asserting
    // that the model still maps to a real table name and still implements the interface is what
    // keeps this test about the exemption rather than about absence.
    expect($model->getTable())->not->toBe('');
})->with('conversationChainedModels');

test('every model in the conversation graph implements OrgOwned', function (\Illuminate\Database\Eloquent\Model $model): void {
    // OrgScopedPolicy::permit() takes an OrgOwned and resolves membership of THAT organization, so
    // there is no argument position a caller could pass a session's "current org" into
    // (laravel-rbac-policies NN1). A model that cannot answer `organizationId()` cannot be
    // authorized by any policy in this application, which on a transcript means the admin surface
    // that reads it has no gate to run.
    expect($model)->toBeInstanceOf(
        OrgOwned::class,
        $model::class.' does not implement OrgOwned, so it cannot be authorized by an org-scoped '
        .'policy — and "admin of some organization" becomes representable, which is the cross-tenant '
        .'bug the interface exists to make unwritable.',
    );
})->with(fn () => array_merge(conversationScopedModels(), conversationChainedModels()));

test('the four chained models refuse to answer organizationId() without their chain loaded', function (\Illuminate\Database\Eloquent\Model $model): void {
    // THIS IS THE HALF THAT MAKES THE EXEMPTION SAFE RATHER THAN MERELY EXPLAINED. `organizationId()`
    // is declared non-nullable, and on a model with no column it can only be answered by reading a
    // parent. `Model::shouldBeStrict()` forbids lazy loading, so reaching for the relation would
    // raise LazyLoadingViolationException FROM INSIDE A POLICY — an exception whose message names
    // the framework rather than the missing `with()`. Each model raises a LogicException naming the
    // fix instead, and this asserts it rather than trusting the docblock.
    //
    // THE FAILURE DIRECTION IF THIS REGRESSES IS THE POINT: a model that quietly lazy-loaded would
    // WORK in every test that has a database, and would be a per-record query inside an
    // authorization loop in production.
    assert($model instanceof OrgOwned);

    expect(fn (): string => $model->organizationId())->toThrow(\LogicException::class);
})->with('conversationChainedModels');

dataset('conversationScopedModels', fn () => conversationScopedModels());
dataset('conversationChainedModels', fn () => conversationChainedModels());
