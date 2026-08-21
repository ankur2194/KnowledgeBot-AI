<?php

declare(strict_types=1);

use App\Enums\BotStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\BotFallbackEntry;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;

use function Pest\Laravel\assertDatabaseHas;

use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| PUT …/bots/{bot}/status — the lifecycle transition
|--------------------------------------------------------------------------
|
| The BEHAVIOUR half of the transition endpoint. The PUBLISH GUARD itself is exercised in
| tests/Feature/BotCrudTest.php, beside the PATCH assertions that prove it runs on the RESULTING
| STATE rather than on a transition — which is the property that would be lost if the guard had
| moved to this endpoint with the field. The authorization and isolation half is
| tests/Security/BotEndpointAccessTest.php, whose endpoint list carries this route so the binding,
| deny-oracle and role assertions cover it without a second file.
|
| WHAT THIS FILE IS FOR: the transitions the guard does NOT touch, the audit row every one of them
| writes, and the response projection.
|
| EVERY FIXTURE IS TWO ORGANIZATIONS, even in the behaviour file — a one-organization fixture passes
| every assertion here against code with the tenant filter deleted.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * Two organizations, one bot each, and org A's bot fully configured so `published` is reachable.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own. Pest declares test-file helpers at FILE
 * SCOPE, so a name another test file already uses is a redeclaration fatal in a full run and only
 * in a full run.
 *
 * @return array{orgA: Organization, orgB: Organization, ownerA: User, botA: Bot, botB: Bot}
 */
function botStatusFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Status Org ALPHA', 'slug' => 'status-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Status Org BRAVO', 'slug' => 'status-bravo']);

    $connection = ProviderConnection::factory()->recycle($orgA)->create(['label' => 'ALPHA key']);
    $model = ProviderModelEntry::factory()->recycle($orgA)->recycle($connection)
        ->supporting(['text'])->create(['model' => 'gpt-5.1']);

    // FULLY CONFIGURED, so `published` is reachable and the transitions below are testing the
    // endpoint rather than re-testing the publish guard.
    $botA = Bot::factory()->recycle($orgA)->usingModel($connection, $model)
        ->create(['name' => 'ALPHA status bot', 'slug' => 'alpha-status']);

    // AND ASSIGNED A SOURCE, which is the guard's THIRD refusal and the newest of the three. It
    // has to come after the bot exists: `assignedTo()` writes `bot_source_assignments`, whose
    // `organization_id` is stated from the RECYCLED organization and checked against BOTH parents
    // by the two composite foreign keys. Without this line every publish below is a 409 naming
    // `PUBLISH_NEEDS_ASSIGNED_SOURCE`, which is the guard working rather than the endpoint failing.
    KnowledgeSource::factory()->recycle($orgA)->assignedTo($botA)
        ->create(['name' => 'ALPHA status corpus']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('status-owner-alpha')]),
        'botA' => $botA,
        'botB' => Bot::factory()->recycle($orgB)->create(['name' => 'BRAVO status bot', 'slug' => 'bravo-status']),
    ];
}

it('moves a bot through every state the guard does not object to', function (): void {
    $fixture = botStatusFixture();

    $url = "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}/status";

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // `testing` IS DELIBERATELY UNGUARDED: it is reachable only from the admin playground by a
    // member of the owning organization, who is the person configuring the bot and is the right
    // audience for a data-plane resolution error. The publish guard exists to stop an END USER
    // meeting one.
    foreach ([BotStatus::Testing, BotStatus::Published, BotStatus::Paused, BotStatus::Published] as $status) {
        currentTest()->putJson($url, ['status' => $status->value], spaHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', $status->value);
    }

    assertDatabaseHas('bots', [
        'id' => $fixture['botA']->id,
        'status' => BotStatus::Published->value,
    ]);

    // ORG B'S BOT IS UNTOUCHED. A transition whose predicate lost its organization term is
    // invisible to every assertion above.
    assertDatabaseHas('bots', [
        'id' => $fixture['botB']->id,
        'status' => BotStatus::Draft->value,
    ]);
});

it('refuses a status outside the vocabulary, from the enum rather than a literal list', function (): void {
    $fixture = botStatusFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}/status",
        ['status' => 'live'],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors('status');

    // AND A BODY THAT NAMES NOTHING. `required` rather than `sometimes|required`, because `status`
    // is the whole of the body: an empty one would return 200 and write a `bot.updated` audit row
    // describing a change that did not happen.
    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}/status",
        [],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('writes one bot.updated audit row per transition, carrying the resulting status', function (): void {
    // "WHO MADE THIS BOT ANSWERABLE BY THE INTERNET, AND WHEN" is exactly the question an incident
    // asks, and without this row the trail answers it with the `bot.created` row from months
    // earlier. The operation is `bot.updated` rather than a `bot.published` of its own, because the
    // transition IS an edit to the bot row and a separate operation would split one subject's
    // history across two names.
    $fixture = botStatusFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}/status",
        ['status' => BotStatus::Published->value],
        spaHeaders(),
    )->assertOk();

    $row = AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_UPDATED)
        ->where('subject_id', '=', $fixture['botA']->id)
        ->latest('created_at')
        ->firstOrFail();

    /** @var array<string, mixed> $details */
    $details = (array) $row->details;

    expect($details['status'])->toBe(BotStatus::Published->value)
        // THE THREE FIELDS THAT DECIDE WHO CAN REACH THE BOT, recorded together: "published" says
        // nothing on its own about whether an anonymous visitor could get to it.
        ->and($details['access_mode'])->toBe($fixture['botA']->access_mode->value)
        ->and($details['answer_mode'])->toBe($fixture['botA']->answer_mode->value)
        ->and($row->actor_id)->toBe($fixture['ownerA']->id);
});

it('does not move the retrieval configuration version', function (): void {
    // `retrieval_configuration_version` MOVES ONLY WHEN A RETRIEVAL KNOB'S VALUE CHANGES, and
    // `status` is not one — publishing a bot changes who may reach it, not what it retrieves. A
    // version that moved here would invalidate every cached answer and make the §21.5 regression
    // gate compare traces that differ only by a number nobody changed.
    $fixture = botStatusFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $before = $fixture['botA']->retrieval_configuration_version;

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}/status",
        ['status' => BotStatus::Testing->value],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.retrieval_configuration_version', $before);
});

it('renders the instruction fields, because reaching this endpoint required bots.manage', function (): void {
    // THE PROJECTION IS RESOLVED WITHOUT A SECOND GATE CALL. `Gate::authorize('update', $bot)` on
    // the line above carries `bots.manage`, which is the permission the projection is gated on, so
    // a second `Gate::allows()` could only agree — at the cost of a second `organization_users`
    // read — or disagree, which would mean the authorization that let the write happen was wrong.
    $fixture = botStatusFixture();

    Bot::query()->withoutGlobalScopes()->whereKey($fixture['botA']->id)->update([
        'system_instruction' => 'STATUSPROBE answer only from the sealed handbook.',
    ]);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}/status",
        ['status' => BotStatus::Testing->value],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.system_instruction', 'STATUSPROBE answer only from the sealed handbook.');
});

it('records the fallback chain on the bot\'s audit row, even though nothing writes it yet', function (): void {
    // THE ONE SUMMARY FIELD WITH NO PRODUCER, asserted anyway. `bot_fallback_models` has no write
    // endpoint in this batch — and `BotController::destroy()` already destroys it, so the same
    // reasoning that closes finding L2 for the origin allow-list applies to it: the chain names
    // `provider_models` rows, i.e. WHICH CREDENTIALS MAY BE BILLED for this bot's answers when the
    // primary model fails. Without this assertion the field would be exercised only in its
    // all-zero shape, and the day a write endpoint lands nobody would find out that the summary was
    // never rendering ids at all.
    $fixture = botStatusFixture();

    $connection = ProviderConnection::factory()->recycle($fixture['orgA'])->create(['label' => 'ALPHA fallback key']);
    $first = ProviderModelEntry::factory()->recycle($fixture['orgA'])->recycle($connection)
        ->supporting(['text'])->create(['model' => 'gpt-5.1-mini']);
    $second = ProviderModelEntry::factory()->recycle($fixture['orgA'])->recycle($connection)
        ->supporting(['text'])->create(['model' => 'gpt-5.1-nano']);

    BotFallbackEntry::factory()->recycle($fixture['orgA'])->recycle($fixture['botA'])->recycle($first)
        ->at(0)->create();
    BotFallbackEntry::factory()->recycle($fixture['orgA'])->recycle($fixture['botA'])->recycle($second)
        ->at(1)->create();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}",
        [],
        spaHeaders(),
    )->assertOk();

    /** @var array<string, mixed> $details */
    $details = (array) AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_DELETED)
        ->where('subject_id', '=', $fixture['botA']->id)
        ->firstOrFail()
        ->details;

    // IN POSITION ORDER, joined into a SCALAR — `sanitize()` drops arrays outright, so the
    // `capabilities` shape is the only one available. The order is the operator's preference order
    // and a summary that lost it would record the set without the chain.
    expect($details['fallback_model_count'])->toBe(2)
        ->and($details['fallback_model_ids'])->toBe($first->id.','.$second->id);
});

it('refuses a transition while the organization is suspended', function (): void {
    $fixture = botStatusFixture();

    Organization::query()->whereKey($fixture['orgA']->id)
        ->update(['status' => OrganizationStatus::Suspended->value]);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}/status",
        ['status' => BotStatus::Published->value],
        spaHeaders(),
    )
        ->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    assertDatabaseHas('bots', [
        'id' => $fixture['botA']->id,
        'status' => BotStatus::Draft->value,
    ]);
});
