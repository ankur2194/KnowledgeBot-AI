<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The direction half of a list query's sort.
 *
 * AN ENUM AND NOT A STRING, because the value reaches an `ORDER BY` clause. A string that arrives
 * from request input and is interpolated into SQL is the one place in a list endpoint where an
 * injection is plausible; a backed enum makes the set of reachable values two, decided by
 * `SortDirection::from()`, and makes the FormRequest's `in:asc,desc` rule the second statement of
 * the same fact rather than the only one.
 *
 * The column half of a sort is NOT an enum, because it is per endpoint: `ListQuery::rules()` takes
 * the sortable column list as an argument and closes it with `Rule::in(...)`, so each endpoint
 * publishes its own set and no shared vocabulary drifts across them.
 */
enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
