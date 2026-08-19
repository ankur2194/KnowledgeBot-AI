<?php

declare(strict_types=1);

use App\Enums\SortDirection;
use App\Http\Resources\ListMetaResource;
use App\Support\Http\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Support\ListEnvelopeProbe;

/*
|--------------------------------------------------------------------------
| The paginated list envelope
|--------------------------------------------------------------------------
|
| THE ENVELOPE IS AN OBJECT WRAPPING THE ARRAY, never a bare array, and the reason is mechanical:
| `#[ResponseShape]` maps a response KEY to a resource class and cannot express "an array of", and
| tests/Contract/OpenApiDocumentTest.php requires every published component to carry
| `additionalProperties: false`, which an array-typed schema cannot.
| `ProviderConnectionCollectionResource` left room for exactly this and said why; this is that room
| being used, and the array did not move.
|
| The consumer here is Tests\Support\ListEnvelopeProbe rather than a production collection resource,
| because the bot list that uses this primitive is the next task's and the item type is its shape
| decision. The probe's own docblock records why it lives in tests/Support and not in
| app/Http/Resources — a probe found by OpenApiDocumentTest's glob would be asserted against as
| though it were a real endpoint's body.
|
| `ListMetaResource` itself IS in app/Http/Resources and IS discovered there, so its component name
| and its closed-and-total shape are already asserted by that file. What is asserted here is the
| part that discovery cannot reach: that `toArray()` emits exactly what the schema declares, and
| that the ENVELOPE the trait builds is closed and total too.
*/

/**
 * A paginator over $total rows, showing page $page at $perPage.
 *
 * Items are irrelevant to everything under test — the envelope publishes them through the item
 * resource, and the meta block never looks at them — so they are integers.
 *
 * @return LengthAwarePaginator<int, mixed>
 */
function metaPaginator(int $total, int $perPage, int $page): LengthAwarePaginator
{
    $items = array_slice(range(1, max($total, 1)), ($page - 1) * $perPage, $perPage);

    return new LengthAwarePaginator($total === 0 ? [] : $items, $total, $perPage, $page);
}

it('publishes both rowCount and pageCount rather than making the client derive one', function (): void {
    // TanStack Table under `manualPagination: true` needs one of the two, and deriving the missing
    // one is `ceil(total / per_page)` — where the client's `per_page` may not be the one the server
    // used. Publishing both is what makes a clamped response safe to read.
    $meta = (new ListMetaResource(
        metaPaginator(total: 57, perPage: 25, page: 2),
        new ListQuery(page: 2, perPage: 25, sort: 'name', direction: SortDirection::Desc, filter: 'ref'),
    ))->toArray(Request::create('/'));

    expect($meta['page'])->toBe(2)
        ->and($meta['per_page'])->toBe(25)
        ->and($meta['total'])->toBe(57)
        ->and($meta['total_pages'])->toBe(3);
});

it('echoes the APPLIED query, which is the whole reason those fields are there', function (): void {
    // The applied values, not the requested ones. `ListQuery::fromValidated()` clamps `per_page` for
    // callers that never ran a FormRequest and falls back to the endpoint's default sort — so a
    // client that assumed its own parameters were in force would compute the wrong page count from
    // the first clamped response and keep computing it.
    $meta = (new ListMetaResource(
        metaPaginator(total: 3, perPage: 10, page: 1),
        ListQuery::fromValidated(['per_page' => 100000, 'filter' => '  refunds '], defaultSort: 'id'),
    ))->toArray(Request::create('/'));

    expect($meta['sort'])->toBe('id')
        ->and($meta['dir'])->toBe('asc')
        // Normalized, not raw: a client rendering "showing results for X" reads this rather than its
        // own input, so the chip it draws matches the rows it got.
        ->and($meta['filter'])->toBe('refunds');
});

it('reports one page for an empty list, not zero', function (): void {
    // Page one exists and is empty. `total_pages: 0` would make a paginator render "page 1 of 0",
    // and every client that clamps its current page into `[1, pageCount]` would clamp it to zero.
    $meta = (new ListMetaResource(
        metaPaginator(total: 0, perPage: 25, page: 1),
        ListQuery::fromValidated([], defaultSort: 'id'),
    ))->toArray(Request::create('/'));

    expect($meta['total'])->toBe(0)
        ->and($meta['total_pages'])->toBe(1)
        ->and($meta['filter'])->toBeNull();
});

it('emits exactly the fields its schema declares, in both directions', function (): void {
    // The property tests/Contract/OpenApiDocumentTest.php enforces for every published resource,
    // asserted here for the meta block specifically because it is the one component every future
    // list references. A field added to `toArray()` and not to the schema fails this; so does the
    // reverse.
    $emitted = (new ListMetaResource(
        metaPaginator(total: 1, perPage: 25, page: 1),
        ListQuery::fromValidated([], defaultSort: 'id'),
    ))->toArray(Request::create('/'));

    $schema = ListMetaResource::openApiSchemas()['ListMetaResource'];

    expect(array_keys($schema['properties']))->toEqualCanonicalizing(array_keys($emitted))
        ->and($schema['required'])->toEqualCanonicalizing(array_keys($emitted))
        ->and($schema['additionalProperties'])->toBeFalse();
});

it('builds an envelope that is closed, total, and references the item component rather than inlining it', function (): void {
    // THE ITEM SCHEMA IS CONTRIBUTED BY THE ITEM RESOURCE AND REFERENCED HERE. That is what makes an
    // item type ONE component in the generated client — the same component the create, read and
    // update actions all return — instead of an anonymous copy per list.
    $schemas = ListEnvelopeProbe::openApiSchemas();

    expect($schemas)->toHaveKey('ListEnvelopeProbe')
        // The trait returns the meta component alongside the envelope, so a consumer cannot publish
        // an envelope whose `$ref` points at a component nobody contributed. A dangling `$ref` is
        // not a dump failure; it is a generated client with a missing type, found by the person
        // importing it.
        ->and($schemas)->toHaveKey('ListMetaResource');

    $envelope = $schemas['ListEnvelopeProbe'];

    expect($envelope['additionalProperties'])->toBeFalse()
        ->and($envelope['required'])->toEqualCanonicalizing(['items', 'meta'])
        ->and(array_keys($envelope['properties']))->toEqualCanonicalizing(['items', 'meta'])
        ->and($envelope['properties']['items']['items'])
        ->toBe(['$ref' => '#/components/schemas/AcknowledgementResource'])
        ->and($envelope['properties']['meta']['$ref'])
        ->toBe('#/components/schemas/ListMetaResource');
});

it('emits the list and the meta block together, including on an empty page', function (): void {
    // BOTH KEYS, ALWAYS. A client that had to branch on `meta` being absent would be branching on
    // "did this list have results", which is exactly the question `total` answers.
    $request = Request::create('/');
    $query = ListQuery::fromValidated([], defaultSort: 'id');

    $populated = (new ListEnvelopeProbe(metaPaginator(total: 3, perPage: 25, page: 1), $query))
        ->toArray($request);

    expect(array_keys($populated))->toEqualCanonicalizing(['items', 'meta'])
        ->and($populated['items'])->toHaveCount(3);

    $empty = (new ListEnvelopeProbe(metaPaginator(total: 0, perPage: 25, page: 1), $query))
        ->toArray($request);

    expect(array_keys($empty))->toEqualCanonicalizing(['items', 'meta'])
        ->and($empty['items'])->toBe([])
        ->and($empty['meta']['total'])->toBe(0);
});

it('serializes an empty page as a JSON array and the meta block as a JSON object', function (): void {
    // The shape a generated client is typed against. An empty PHP array encodes as `[]` and an
    // empty PHP map ALSO encodes as `[]`, which is the bug App\Support\Casts\JsonObjectCast exists
    // for one layer down — so the property is asserted here rather than assumed: `items` must be an
    // array even when empty, and `meta` must be an object always.
    $json = json_encode(
        (new ListEnvelopeProbe(metaPaginator(total: 0, perPage: 25, page: 1), ListQuery::fromValidated([], defaultSort: 'id')))
            ->toArray(Request::create('/')),
        JSON_THROW_ON_ERROR,
    );

    expect($json)->toContain('"items":[]')
        ->and($json)->toContain('"meta":{');
});
