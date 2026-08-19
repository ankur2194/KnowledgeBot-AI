<?php

declare(strict_types=1);

use App\Enums\SortDirection;
use App\Support\Http\ListQuery;

/*
|--------------------------------------------------------------------------
| The list-query primitive
|--------------------------------------------------------------------------
|
| NOTHING IN THIS APPLICATION PAGINATES YET. Phase B introduces the first paginated list and Phases
| C4, D and E2 reuse it, so this primitive ships before its first endpoint and these tests are the
| only thing standing behind it until one exists.
|
| A UNIT TEST BECAUSE IT CAN BE. `ListQuery` touches no container, no database and no request — the
| only framework type it uses is `Rule::in()`, whose string form is what the contract dumper reads.
| If this ever needs a booted application, that is a signal the primitive has grown a dependency it
| should not have.
*/

/**
 * A validation rule as the contract dumper would publish it.
 *
 * `kb:dump-form-rules` records a rule object by `__toString()` where one exists and by CLASS NAME
 * where it does not, and its own docblock calls a rule that renders as `Closure` a finding rather
 * than noise. This mirrors that reduction so the assertions below are about the published form and
 * not about PHP object identity.
 */
function renderValidationRule(mixed $rule): string
{
    if (is_string($rule)) {
        return $rule;
    }

    // Every class with __toString() implements Stringable implicitly since PHP 8, so this is the
    // check the dumper's `method_exists($rule, '__toString')` makes, expressed as a type.
    if ($rule instanceof \Stringable) {
        return (string) $rule;
    }

    return get_debug_type($rule);
}

it('closes the sortable set to the columns the endpoint named, and to nothing else', function (): void {
    // THE SECURITY-RELEVANT ASSERTION IN THIS FILE. `sort` reaches an ORDER BY, so an open set is a
    // caller choosing which index the query uses at best and injecting at worst. There is no
    // default and no wildcard: the argument is required and positional, so an endpoint cannot get a
    // working list query without stating its own sortable columns out loud.
    $rules = ListQuery::rules(['name', 'created_at']);

    expect(array_map(renderValidationRule(...), $rules['sort']))
        ->toContain('in:"name","created_at"');

    // And the direction, closed by the enum rather than by a literal in a rule string.
    expect(array_map(renderValidationRule(...), $rules['dir']))
        ->toContain('in:"asc","desc"');
});

it('renders every rule as a string the contract dumper can publish', function (): void {
    // `kb:dump-form-rules` records rule objects by `__toString()` where one exists and by CLASS NAME
    // where it does not — and its own docblock calls a rule that renders as `Closure` a finding
    // rather than noise. A rule set that cannot be published is a rule set no client can be
    // generated from, so this asserts the property here, once, rather than discovering it in a
    // FormRequest five tasks from now.
    foreach (ListQuery::rules(['id']) as $field => $rules) {
        expect($rules)->toBeArray("the {$field} rule is not a list");

        foreach ($rules as $rule) {
            expect(renderValidationRule($rule))->not->toContain('Closure');
        }
    }
});

it('bounds the page size in the rule AND again in the constructor', function (): void {
    // NOT BELT-AND-BRACES THEATRE. `fromValidated()` is reachable from a service or a job that never
    // ran a FormRequest, and a clamp that exists only in a validation rule is a clamp that does not
    // exist for any non-HTTP caller. The rule is what produces a 422 for the caller that has a field
    // to key one on; the clamp is what protects the database from the callers that do not.
    $rules = ListQuery::rules(['id']);

    expect($rules['per_page'])->toContain('max:'.ListQuery::MAX_PER_PAGE);

    $query = ListQuery::fromValidated(['per_page' => 100000], defaultSort: 'id');

    expect($query->perPage)->toBe(ListQuery::MAX_PER_PAGE);

    // The other end. A page size of zero is an infinite loop dressed as a request.
    expect(ListQuery::fromValidated(['per_page' => 0], defaultSort: 'id')->perPage)->toBe(1);
    expect(ListQuery::fromValidated(['page' => 0], defaultSort: 'id')->page)->toBe(1);
    expect(ListQuery::fromValidated(['page' => -5], defaultSort: 'id')->page)->toBe(1);
});

it('applies the endpoint\'s own default sort when the caller names none', function (): void {
    // `$defaultSort` HAS NO DEFAULT VALUE, on purpose: every list needs a deterministic order or two
    // reads of an unchanged set are not byte-identical, and which column supplies it is the
    // endpoint's decision. This asserts the fallback fires — the absence of a default in the
    // SIGNATURE is asserted by the fact that this call does not compile without one.
    $query = ListQuery::fromValidated([], defaultSort: 'id');

    expect($query->sort)->toBe('id')
        ->and($query->direction)->toBe(SortDirection::Asc)
        ->and($query->page)->toBe(1)
        ->and($query->perPage)->toBe(ListQuery::DEFAULT_PER_PAGE)
        ->and($query->filter)->toBeNull();

    $descending = ListQuery::fromValidated(
        ['sort' => 'created_at', 'dir' => 'desc'],
        defaultSort: 'id',
    );

    expect($descending->sort)->toBe('created_at')
        ->and($descending->direction)->toBe(SortDirection::Desc);
});

it('treats an empty filter as no filter, in one spelling', function (?string $given): void {
    // TWO SPELLINGS OF "UNFILTERED" IS A CACHE-KEY BUG WAITING TO HAPPEN: an unfiltered list's key
    // would depend on whether the client sent the parameter at all, so the same page would be cached
    // twice and invalidated once. It is also what a client renders "showing results for ''" from.
    expect(ListQuery::fromValidated(['filter' => $given], defaultSort: 'id')->filter)->toBeNull();
})->with([
    'absent' => [null],
    'empty' => [''],
    'whitespace only' => ['   '],
]);

it('trims a filter rather than passing the whitespace through', function (): void {
    expect(ListQuery::fromValidated(['filter' => '  refunds  '], defaultSort: 'id')->filter)
        ->toBe('refunds');
});

it('ignores a direction it does not recognise instead of failing open on the raw string', function (): void {
    // `SortDirection::tryFrom()` and not `from()`. The FormRequest has already refused anything
    // outside the enum, so this path is only reachable from a non-HTTP caller — and for that caller
    // the correct answer is the endpoint's default, not an exception and certainly not the raw
    // string reaching an ORDER BY.
    $query = ListQuery::fromValidated(['dir' => 'sideways'], defaultSort: 'id', defaultDirection: SortDirection::Desc);

    expect($query->direction)->toBe(SortDirection::Desc);
});

it('converts a 1-based page to a 0-based offset in exactly one place', function (int $page, int $perPage, int $offset): void {
    // The only place the conversion happens. A repository doing this arithmetic itself would be a
    // second place to get it wrong, and the symptom — page two missing its first row — reads as a
    // data problem rather than an arithmetic one.
    $query = ListQuery::fromValidated(['page' => $page, 'per_page' => $perPage], defaultSort: 'id');

    expect($query->offset())->toBe($offset);
})->with([
    'the first page starts at zero' => [1, 25, 0],
    'the second page starts one page in' => [2, 25, 25],
    'and the arithmetic follows the applied size' => [3, 10, 20],
]);
