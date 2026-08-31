<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Support\Kb\ClientEvents;

/*
|--------------------------------------------------------------------------
| The forward allow-list
|--------------------------------------------------------------------------
|
| This is the security boundary for four internal-only frames, and the union it protects is shared
| with a widget running inside an iframe on a page the customer controls. Getting it wrong publishes
| per-attempt token counts, the tenant's provider `connection_id`, the model ladder and the whole
| retrieval trace — including the organization id and every allowed version id — onto a stranger's
| marketing site.
|
| Every assertion below names the actor type explicitly. There is no "default actor" in these tests:
| the whole content of the rule is WHO is asking.
*/

it('forwards exactly the six client-facing frames, to every actor type', function (ActorType $actor): void {
    foreach (ClientEvents::FORWARDED as $name) {
        expect(ClientEvents::allows($name, $actor))
            ->toBeTrue("`{$name}` is one of the six clients parse and must reach a {$actor->value}");
    }
})->with([
    'anonymous session' => ActorType::AnonymousSession,
    'user' => ActorType::User,
    'scheduler' => ActorType::Scheduler,
    'system' => ActorType::System,
]);

it('refuses all four internal frames to a non-admin actor', function (ActorType $actor): void {
    foreach (ClientEvents::INTERNAL_ONLY as $name) {
        // `$diagnostics: true` IS PASSED DELIBERATELY on the negative case. The two conditions are
        // AND-ed, so a caller that wrongly resolved the permission still forwards nothing to an
        // actor that is not a user — and a test that passed `false` here would pass against an
        // implementation that ORed them.
        expect(ClientEvents::allows($name, $actor, diagnostics: true))
            ->toBeFalse(
                "`{$name}` carries cost data or internal topology and must never reach a "
                ."{$actor->value}, whatever a caller believes about its permissions",
            );
    }
})->with([
    'anonymous session' => ActorType::AnonymousSession,
    'scheduler' => ActorType::Scheduler,
    'system' => ActorType::System,
]);

it('refuses all four internal frames to a user who does not hold the diagnostics permission', function (): void {
    foreach (ClientEvents::INTERNAL_ONLY as $name) {
        expect(ClientEvents::allows($name, ActorType::User, diagnostics: false))->toBeFalse(
            "`{$name}` reached a signed-in user with no diagnostics permission",
        );
    }
});

it('passes retrieval.trace to an admin actor, and only that one', function (): void {
    // THE ONE EXCEPTION, AND IT IS WHAT MAKES THE D5 PLAYGROUND POSSIBLE without a second streaming
    // endpoint: same relay, same frames, one predicate.
    expect(ClientEvents::allows('retrieval.trace', ActorType::User, diagnostics: true))->toBeTrue();

    // AND THE EXCEPTION IS NOT A CATEGORY. The playground gets the trace; it does not get the cost
    // data or the routing topology, because neither is diagnostics — they are billing and internal
    // architecture, and an admin is still a client.
    expect(ClientEvents::allows('provider.usage', ActorType::User, diagnostics: true))->toBeFalse()
        ->and(ClientEvents::allows('provider.fallback', ActorType::User, diagnostics: true))->toBeFalse()
        ->and(ClientEvents::allows('heartbeat', ActorType::User, diagnostics: true))->toBeFalse();
});

it('is an allow-list, so an unknown frame name is dropped rather than passed through', function (string $name): void {
    // A TENTH FRAME ADDED ON THE DATA PLANE ARRIVES HERE UNNAMED. Under a deny-list it would reach
    // every widget on the internet and nothing would say so; under an allow-list it is dropped and
    // the omission shows up as a missing feature rather than as a leak.
    expect(ClientEvents::allows($name, ActorType::User, diagnostics: true))->toBeFalse()
        ->and(ClientEvents::allows($name, ActorType::AnonymousSession))->toBeFalse();
})->with([
    'a frame nobody has invented yet' => 'provider.cost',
    'a near-miss on a forwarded name' => 'message.completed',
    'a near-miss on an internal name' => 'retrieval.traces',
    'the empty string' => '',
    'a prefix of a forwarded name' => 'token ',
]);

it('keeps the two lists disjoint and complete', function (): void {
    // THE SAME THREE IMPORT-TIME ASSERTIONS `app/contracts/internal/chat.py` makes about its own
    // constants, made here about ours. A name in both lists would be forwarded by the first branch
    // and never reach the second, so the internal-only entry would be decoration.
    expect(array_intersect(ClientEvents::FORWARDED, ClientEvents::INTERNAL_ONLY))->toBe([])
        ->and(ClientEvents::FORWARDED)->toBe(array_values(array_unique(ClientEvents::FORWARDED)))
        ->and(ClientEvents::INTERNAL_ONLY)->toBe(array_values(array_unique(ClientEvents::INTERNAL_ONLY)))
        ->and(ClientEvents::INTERNAL_ONLY)->toContain(ClientEvents::DIAGNOSTIC);
});
