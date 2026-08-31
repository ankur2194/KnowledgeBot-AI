<?php

declare(strict_types=1);

use App\Support\Kb\ClientEvents;

/*
|--------------------------------------------------------------------------
| One list, three transcriptions
|--------------------------------------------------------------------------
|
| `app/contracts/internal/chat.py` names the nine frames the data plane EMITS and splits them into
| `CLIENT_FORWARDED_EVENTS` and `INTERNAL_ONLY_EVENTS`. `packages/contracts/src/sse/events.ts` names
| the six a client PARSES. `App\Support\Kb\ClientEvents` decides which reach a client.
|
| THREE COPIES OF ONE LIST IS THE SHAPE FINDING O1 AND ADR-052 BOTH RECURRED THROUGH, and the
| failure is asymmetric: a name that drifts OUT of the forward list is a client feature that quietly
| stops working, and a name that drifts IN is cost data on a customer's marketing site. So the two
| other copies are read AS DATA here rather than restated.
|
| It is a Contract test because it reads across the seam. It does not boot a provider, open a socket
| or need a database — what it needs is the two files, and it fails loudly if either has moved rather
| than passing vacuously over an empty parse.
*/

/**
 * A named `Final[tuple[str, ...]]` from the data plane's chat contract, read as data.
 *
 * PARSED WITH A NARROW PATTERN AND ASSERTED NON-EMPTY. A regex that matched nothing would make every
 * assertion below vacuous — the exact failure mode `pest-testing` calls out for isolation suites,
 * arriving in a parity suite instead.
 *
 * @return list<string>
 */
function pythonEventTuple(string $constant): array
{
    $path = dirname(base_path(), 2).'/services/ai-service/app/contracts/internal/chat.py';

    expect(file_exists($path))->toBeTrue(
        "the data plane's chat contract is not at {$path}; this parity test is asserting nothing",
    );

    $source = (string) file_get_contents($path);

    expect(preg_match(
        '/^'.preg_quote($constant, '/').':\s*Final\[tuple\[str,\s*\.\.\.\]\]\s*=\s*\((.*?)\)/ms',
        $source,
        $match,
    ))->toBe(1, "could not find `{$constant}` in {$path} — the constant was renamed or reshaped");

    // `?? ''` RATHER THAN AN INDEX. The `toBe(1)` above proves the match succeeded, so group 1 is
    // there — but a test whose job is to REPORT a drift must not become the thing that fatals on
    // one, and an empty string falls through to the "parsed to an empty list" assertion below with a
    // message that names the constant.
    preg_match_all('/"([^"]+)"/', $match[1] ?? '', $names);

    $found = $names[1];

    expect($found)->not->toBeEmpty("`{$constant}` parsed to an empty list, so nothing is being compared");

    return $found;
}

it('forwards exactly the names the data plane says are forwardable', function (): void {
    expect(ClientEvents::FORWARDED)
        ->toEqualCanonicalizing(pythonEventTuple('CLIENT_FORWARDED_EVENTS'));
});

it('refuses exactly the names the data plane marks internal-only, plus the heartbeat', function (): void {
    $internal = pythonEventTuple('INTERNAL_ONLY_EVENTS');

    // `heartbeat` IS OURS AND IS NOT ONE OF THE NINE. On the wire it is an SSE COMMENT, inserted by
    // FastAPI's own SSE path after 15 s of generator idleness; `UpstreamStream` surfaces it as a
    // named frame so the relay has one shape to match on. It is on the internal-only list so a
    // caller that asks about it gets `false` rather than a silent fall-through.
    expect(ClientEvents::INTERNAL_ONLY)
        ->toEqualCanonicalizing([...$internal, 'heartbeat']);
});

it('covers every one of the nine frames the data plane emits', function (): void {
    // A NAME IN NEITHER LIST IS DROPPED SILENTLY, which is the safe direction and is still a gap: a
    // frame the pipeline emits and Laravel neither forwards nor consumes is work nobody reads.
    $emitted = pythonEventTuple('EVENT_NAMES');
    $known = [...ClientEvents::FORWARDED, ...ClientEvents::INTERNAL_ONLY];

    expect(array_diff($emitted, $known))->toBe(
        [],
        'the data plane emits a frame this plane has no opinion about; add it to FORWARDED or to '
        .'INTERNAL_ONLY deliberately, because the default is to drop it',
    );
});

it('forwards exactly what the published client union declares', function (): void {
    // THE OTHER END OF THE SAME CONTRACT. `CLIENT_EVENT_NAMES` in packages/contracts is what the
    // widget, hosted chat, mobile and the playground all parse — so a name this plane forwards that
    // the union does not carry is a frame every client ignores, and a name the union carries that
    // this plane drops is a client feature that silently never fires.
    $path = dirname(base_path(), 2).'/packages/contracts/src/sse/events.ts';

    expect(file_exists($path))->toBeTrue("the published SSE contract is not at {$path}");

    $source = (string) file_get_contents($path);

    expect(preg_match('/export const CLIENT_EVENT_NAMES\s*=\s*\[(.*?)\]\s*as const/ms', $source, $match))
        ->toBe(1, 'could not find CLIENT_EVENT_NAMES; the published union was renamed or reshaped');

    preg_match_all("/'([^']+)'/", $match[1] ?? '', $names);

    expect($names[1])->not->toBeEmpty('CLIENT_EVENT_NAMES parsed to an empty list')
        ->and(ClientEvents::FORWARDED)->toEqualCanonicalizing($names[1]);
});

it('keeps the four internal names out of the published client union', function (): void {
    // `packages/contracts/src/sse/events.ts:8` states the rule this asserts: *"a client that can NAME
    // them is a client that can RENDER them."* The union is the thing that makes naming them
    // possible, so the absence is the control and not documentation of one.
    $source = (string) file_get_contents(dirname(base_path(), 2).'/packages/contracts/src/sse/events.ts');

    preg_match('/export type KbEvent =(.*?);/ms', $source, $match);

    $union = $match[1] ?? '';

    expect($union)->not->toBe('', 'could not read the KbEvent union');

    foreach (ClientEvents::INTERNAL_ONLY as $name) {
        expect(str_contains($union, "'{$name}'"))->toBeFalse(
            "`{$name}` is nameable from the published client union, so a client can render it",
        );
    }
});
