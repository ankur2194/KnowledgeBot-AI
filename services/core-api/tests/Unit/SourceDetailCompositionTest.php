<?php

declare(strict_types=1);

use App\Http\Resources\SourceDetailResource;
use App\Http\Resources\SourceResource;

/*
|--------------------------------------------------------------------------
| SourceDetailResource's composition — finding S4
|--------------------------------------------------------------------------
|
| THE DETAIL PROJECTION IS COMPOSED WITH PHP'S `+`, IN THE PAYLOAD AND IN THE SCHEMA, AND `+` IS
| LEFT-WINS AND SILENT.
|
| That composition is deliberate and worth keeping: the list fields are DERIVED from
| `SourceResource` rather than restated, so a field added to the list reaches the detail with no
| second edit and the two can never disagree about a description. The cost is a failure mode with no
| symptom — if a name ever appears in both halves, most plausibly when a count is promoted onto the
| list row, the base value wins and the detail's own declaration is discarded.
|
| AND NOT ONE OF THE FOUR DRIFT PINS CAN SEE IT. `keyof` is unchanged, because the key set is the
| union either way; the base key is present and required; the base node equals the base node. The
| only trace is `'required' => [...$required, ...array_keys($added)]`, a SPREAD rather than a `+`,
| so a collision emits a DUPLICATED entry in a JSON Schema `required` array — invalid per the spec
| and asserted by nothing.
|
| Two things are asserted here: that the two halves are disjoint TODAY, and that the guard actually
| refuses a collision. The second is what makes the first survivable, because the first is a
| statement about a tree that moves.
|
| This suite boots nothing: `openApiSchemas()` is a pure static on both resources.
*/

it('publishes a detail schema whose two halves declare no key twice', function (): void {
    /** @var array<string, array<string, mixed>> $base */
    $base = SourceResource::openApiSchemas()['SourceResource']['properties'];

    /** @var array<string, array<string, mixed>> $detail */
    $detail = SourceDetailResource::openApiSchemas()['SourceDetailResource']['properties'];

    // Every base property reaches the detail — that is the derivation working, and it is the
    // positive control for the disjointness assertion below: without it, "no collision" would also
    // be satisfied by the base half not being composed in at all.
    expect(array_keys($base))->each(fn ($key) => expect(array_key_exists($key->value, $detail))->toBeTrue());

    /** @var list<string> $required */
    $required = SourceDetailResource::openApiSchemas()['SourceDetailResource']['required'];

    // THE ONE OBSERVABLE SYMPTOM A COLLISION WOULD LEAVE, asserted directly. `required` is built
    // with a spread, so a duplicated name lands here and nowhere else — and a JSON Schema
    // `required` array with a repeated entry is invalid, whatever any generator makes of it.
    expect($required)->toBe(array_values(array_unique($required)));
});

it('refuses a collision rather than silently discarding the detail\'s declaration', function (): void {
    // Reflection, because the guard is private and static and there is deliberately no way to reach
    // it from outside: a collision cannot be produced by anything a caller sends, only by an edit to
    // one of the two resources. This is the test that stops such an edit, so it has to be able to
    // stage one.
    $refuse = new \ReflectionMethod(SourceDetailResource::class, 'refuseCollisions');

    // A pair that does NOT collide is a no-op — the negative control, so the assertion below cannot
    // pass by the method throwing unconditionally.
    $refuse->invoke(null, ['id' => 1, 'name' => 2], ['chunk_count' => 3], 'toArray()');

    expect(fn () => $refuse->invoke(null, ['id' => 1, 'chunk_count' => 2], ['chunk_count' => 3], 'toArray()'))
        ->toThrow(\RuntimeException::class, 'chunk_count');
});
