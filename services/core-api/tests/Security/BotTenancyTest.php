<?php

declare(strict_types=1);

use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\BotFallbackEntry;
use App\Models\BotStarterQuestion;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| A bot cannot be reached from, or point at, another organization (§22.5)
|--------------------------------------------------------------------------
|
| A BOT IS THE RETRIEVAL SCOPE, which is what makes this file different from an ordinary isolation
| test. `bot_ids` is one of the four mandatory Qdrant filter terms, so a bot resolved out of the
| wrong organization does not produce an error — it produces a correct-looking answer, at normal
| latency, citing a document the organization never uploaded. And a bot POINTING at another
| organization's provider connection is worse than a read leak: this tenant's conversations are then
| billed to that tenant's provider account and readable in their provider dashboard, with every
| downstream layer agreeing, because it has been told whose credential answers.
|
| THREE LAYERS ARE ASSERTED, AND EACH ONE ALONE WOULD BE ENOUGH ON A GOOD DAY:
|   1. the GLOBAL SCOPE, which fails CLOSED when no organization is bound;
|   2. the same scope's positive behaviour, which returns one organization's rows and no others;
|   3. the COMPOSITE FOREIGN KEYS, which refuse the row even when every layer above is bypassed —
|      and the writes below bypass all of them deliberately, because a repair script, a console
|      command and a service nobody has written yet all reach the database the same way.
|
| `Http::fake()` appears nowhere here and cannot: nothing under test calls the AI service, so there
| is nothing to fake and nothing that would silently pass because a faked response short-circuited a
| real code path.
|
| tests/Feature/BotSchemaTest.php holds the OTHER half — the shape constraints — because those fail
| for a different reason: a red test there is a malformed value, a red test here is a tenant
| boundary.
*/

/**
 * Attempt a write that is expected to be refused, and return the exception or null.
 *
 * DELIBERATELY NOT NAMED `saveAndCatch()`, which tests/Feature/BotSchemaTest.php already declares.
 * Pest declares test-file helpers at FILE SCOPE, so a second declaration under that name is a
 * redeclaration fatal in a full run — and only in a full run, which is the worst possible time to
 * discover it.
 *
 * The nested `transaction()` is a SAVEPOINT: RefreshDatabase holds one transaction per test, and in
 * PostgreSQL a raising statement aborts it, so without the savepoint every assertion after the
 * first expected violation would fail with 25P02 against a statement that is perfectly valid.
 */
function tenancyWriteFails(Model $model): ?QueryException
{
    try {
        $model->getConnection()->transaction(static fn () => $model->save());

        return null;
    } catch (QueryException $e) {
        return $e;
    }
}

it('returns nothing at all when no organization is bound, rather than everything', function (): void {
    // THE DIRECTION IS THE WHOLE TEST. The alternative — apply no predicate when the context is
    // empty — makes the backstop vanish exactly when it is most needed: a pooled queue worker whose
    // context was never set would run every query unscoped and return every tenant's rows, with no
    // exception anywhere. A query that returns nothing is a loud, immediate, debuggable failure; a
    // query that returns everyone is a breach.
    $t = tenantPair();

    // POSITIVE CONTROL FIRST. Without it this test also passes when the bots were never created,
    // when the table is missing, and when the model is pointed at the wrong table.
    $seen = app(TenantContext::class)->runFor(
        $t->b->id,
        static fn (): array => Bot::query()->pluck('id')->all(),
    );

    expect($seen)->toBe([$t->botB->id]);

    // And with nothing bound: not a partial answer, not every answer — none.
    expect(Bot::query()->count())->toBe(0);
    expect(BotDomain::query()->count())->toBe(0);
    expect(BotStarterQuestion::query()->count())->toBe(0);
    expect(BotFallbackEntry::query()->count())->toBe(0);
});

it('never returns one organization\'s bot to the other, canary and all', function (): void {
    $t = tenantPair();

    $context = app(TenantContext::class);

    // POSITIVE CONTROL, FIRST (pest-testing NN2). The canary IS visible to the organization whose
    // content carries it. Without this line the assertion below also passes when the read is broken
    // and returns nothing at all — and that is the assertion people delete first when it is slow.
    $asB = $context->runFor($t->b->id, static fn (): string => Bot::query()->get()->toJson());

    expect(str_contains($asB, $t->canary))->toBeTrue(
        'the canary is not reachable by the organization that planted it, so every absence '
        .'assertion in this suite is vacuous',
    );

    // The real assertion, made against the SERIALIZED rows rather than a named column, so a canary
    // hiding anywhere in the payload still trips it.
    $asA = $context->runFor($t->a->id, static fn (): string => Bot::query()->get()->toJson());

    // str_contains and not ->not->toContain(): `not` treats any failure as success, and
    // toContain() would read a message argument as a second needle.
    expect(str_contains($asA, $t->canary))->toBeFalse(
        'Organization A can read a value planted only in Organization B\'s bot',
    );
    expect(str_contains($asA, $t->botB->id))->toBeFalse();
    expect(str_contains($asA, $t->botB->public_bot_id))->toBeFalse();
});

it('refuses a bot that names another organization\'s provider connection', function (): void {
    // NOT A READ LEAK. A bot pointing at another tenant's connection means this tenant's
    // conversations answered on that tenant's credential — billed to them, visible in their
    // provider dashboard — and every downstream layer agrees, because it has been told whose
    // credential answers.
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $connectionB = ProviderConnection::factory()->recycle($orgB)->create();

    $bot = Bot::factory()->recycle($orgA)->create();
    $bot->provider_connection_id = $connectionB->id;

    expect(tenancyWriteFails($bot))->toBeInstanceOf(QueryException::class);

    // POSITIVE CONTROL: the same write with the organization's OWN connection succeeds, so the
    // refusal above is about the tenant and not about the column being unwritable.
    $connectionA = ProviderConnection::factory()->recycle($orgA)->create();
    $bot->provider_connection_id = $connectionA->id;

    expect(tenancyWriteFails($bot))->toBeNull();
});

it('refuses a bot that names another organization\'s model row', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $connectionA = ProviderConnection::factory()->recycle($orgA)->create();
    $connectionB = ProviderConnection::factory()->recycle($orgB)->create();

    // THE SAME MODEL IDENTIFIER IN BOTH ORGANIZATIONS. A missing organization predicate returns a
    // row that looks exactly right — same vendor, same id — and only the ULID betrays it.
    $shared = 'gpt-5.1-mini';
    $modelA = ProviderModelEntry::factory()->recycle($orgA)->recycle($connectionA)
        ->create(['model' => $shared, 'display_name' => 'ALPHA row']);
    $modelB = ProviderModelEntry::factory()->recycle($orgB)->recycle($connectionB)
        ->create(['model' => $shared, 'display_name' => 'BRAVO row']);

    $bot = Bot::factory()->recycle($orgA)->create();
    $bot->provider_model_id = $modelB->id;

    expect(tenancyWriteFails($bot))->toBeInstanceOf(QueryException::class);

    $bot->provider_model_id = $modelA->id;

    expect(tenancyWriteFails($bot))->toBeNull();
});

it('lets a bot leave its model unset, which is what MATCH SIMPLE is for', function (): void {
    // The composite keys are multi-column and therefore MATCH SIMPLE: unchecked when the nullable
    // half is NULL. That is the property that lets a draft bot exist at all, and it is asserted
    // because the obvious "stricter" spelling — MATCH FULL — would reject every bot created before
    // it was configured, which is every bot.
    $org = Organization::factory()->create();

    $bot = Bot::factory()->recycle($org)->create()->refresh();

    expect($bot->provider_connection_id)->toBeNull()
        ->and($bot->provider_model_id)->toBeNull();
});

it('refuses a widget origin attached to a bot in another organization', function (): void {
    // THE WORST ROW IN THIS FILE if it were ever written. An origin attached across a tenant
    // boundary is a permanent grant that every downstream check AGREES with, because it has been
    // told that this origin belongs to that bot.
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $botB = Bot::factory()->recycle($orgB)->create();

    $domain = new BotDomain;
    $domain->organization_id = $orgA->id;
    $domain->bot_id = $botB->id;
    $domain->origin = 'https://attacker.example';

    expect(tenancyWriteFails($domain))->toBeInstanceOf(QueryException::class);

    // POSITIVE CONTROL: the identical row, correctly scoped, is accepted.
    $botA = Bot::factory()->recycle($orgA)->create();
    $domain->bot_id = $botA->id;

    expect(tenancyWriteFails($domain))->toBeNull();
});

it('refuses a starter question attached to a bot in another organization', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $botB = Bot::factory()->recycle($orgB)->create();

    $question = new BotStarterQuestion;
    $question->organization_id = $orgA->id;
    $question->bot_id = $botB->id;
    $question->question = 'How do I get a refund?';
    $question->sort_order = 0;

    expect(tenancyWriteFails($question))->toBeInstanceOf(QueryException::class);

    $botA = Bot::factory()->recycle($orgA)->create();
    $question->bot_id = $botA->id;

    expect(tenancyWriteFails($question))->toBeNull();
});

it('refuses a fallback chain entry that reaches across a tenant boundary in either direction', function (): void {
    // TWO COMPOSITE KEYS ON ONE ROW, because this row is where two independently-owned entities
    // meet. Each direction is asserted separately: a constraint that only checked the bot would
    // pass the second case, and a constraint that only checked the model would pass the first.
    //
    // This is also the argument the migration makes for why the chain is a TABLE and not a jsonb
    // array of ids: neither refusal below is expressible against jsonb, because jsonb cannot carry
    // a foreign key.
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $connectionA = ProviderConnection::factory()->recycle($orgA)->create();
    $connectionB = ProviderConnection::factory()->recycle($orgB)->create();

    $modelA = ProviderModelEntry::factory()->recycle($orgA)->recycle($connectionA)->create();
    $modelB = ProviderModelEntry::factory()->recycle($orgB)->recycle($connectionB)->create();

    $botA = Bot::factory()->recycle($orgA)->create();
    $botB = Bot::factory()->recycle($orgB)->create();

    // Org A's bot, Org B's model.
    $foreignModel = new BotFallbackEntry;
    $foreignModel->organization_id = $orgA->id;
    $foreignModel->bot_id = $botA->id;
    $foreignModel->provider_model_id = $modelB->id;
    $foreignModel->position = 0;

    expect(tenancyWriteFails($foreignModel))->toBeInstanceOf(QueryException::class);

    // Org A's model, Org B's bot.
    $foreignBot = new BotFallbackEntry;
    $foreignBot->organization_id = $orgA->id;
    $foreignBot->bot_id = $botB->id;
    $foreignBot->provider_model_id = $modelA->id;
    $foreignBot->position = 0;

    expect(tenancyWriteFails($foreignBot))->toBeInstanceOf(QueryException::class);

    // POSITIVE CONTROL: both halves from the same organization is accepted.
    $valid = new BotFallbackEntry;
    $valid->organization_id = $orgA->id;
    $valid->bot_id = $botA->id;
    $valid->provider_model_id = $modelA->id;
    $valid->position = 0;

    expect(tenancyWriteFails($valid))->toBeNull();
});

it('keeps the tenant key out of every bot model\'s fillable set', function (): void {
    // Over-posting a tenant key is an authorization bug with a 200 response. This asserts the
    // ABSENCE by reflection rather than by driving an endpoint, because the endpoints are the next
    // task's and the property has to hold for every one of them before the first is written.
    //
    // `bot_id` is checked on the child models too: it is the ownership edge INSIDE an organization,
    // and a fillable one would let a PATCH move a verified origin from one bot to another without
    // any composite foreign key being able to object — both bots belong to the same tenant.
    //
    // A LOOP OVER INSTANCES RATHER THAN A DATASET OF CLASS STRINGS, because `new $class` on a string
    // resolves to `object` at level 8 and `getFillable()` then has to be reached through an
    // unchecked call — which is the one shape that would let this assertion run against something
    // that is not a model at all.
    $models = [new Bot, new BotDomain, new BotStarterQuestion, new BotFallbackEntry];

    foreach ($models as $model) {
        expect($model)->toBeInstanceOf(Model::class);

        expect($model->getFillable())->not->toContain('organization_id');

        if (! $model instanceof Bot) {
            expect($model->getFillable())->not->toContain('bot_id');
        }
    }
});
