<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `meta` block every paginated list in this API carries.
 *
 * ONE COMPONENT FOR EVERY LIST, on purpose. Bots, sources, conversations and evaluation runs will
 * each publish their own item type and their own envelope, and all four reference THIS component
 * for their pagination block — so a client writes one pagination hook, not four, and a change to
 * the block is one diff rather than a search.
 *
 * ── WHAT A CLIENT DRIVES FROM IT ──────────────────────────────────────────────────────────────
 *
 * TanStack Table under `manualPagination: true` needs either `rowCount` or `pageCount`; this block
 * publishes BOTH (`total` and `total_pages`) rather than making the client derive one from the
 * other, because the derivation is `ceil(total / per_page)` and the client's `per_page` may not be
 * the one the server used — see the next paragraph, which is the entire reason the applied query is
 * echoed.
 *
 * ── THE APPLIED QUERY IS ECHOED BACK, AND THAT IS NOT REDUNDANCY ──────────────────────────────
 *
 * `per_page`, `sort`, `dir` and `filter` are what the SERVER USED, not what the client asked for,
 * and the two can legitimately differ: `ListQuery::fromValidated()` CLAMPS `per_page` to the
 * platform maximum for callers that never ran a FormRequest, and applies the endpoint's default
 * sort when the client named none. A client that assumed its own request parameters were in force
 * would compute the wrong page count from the first clamped response and would keep computing it.
 * Echoing the applied values makes the clamp visible instead of silent.
 *
 * `filter` is echoed as the NORMALIZED value — trimmed, and `null` rather than an empty string,
 * because an empty filter is no filter. A client that renders "showing results for X" reads this
 * field rather than its own input, so the chip it draws matches the rows it got.
 *
 * ── WHY THERE ARE NO LINKS ────────────────────────────────────────────────────────────────────
 *
 * No `next_url`, no `prev_url`, no `first`/`last`. Laravel's paginator will happily generate them
 * and they would be wrong here: this API is consumed by a Next.js admin console, a widget and a
 * mobile app, none of which navigates by following a server-rendered URL, and all of which reach
 * the API through a proxy whose external origin the server does not reliably know. A generated
 * absolute URL is then either wrong or an internal hostname on the wire. Page numbers are
 * origin-independent and the client already owns its own routing.
 *
 * @property-read LengthAwarePaginator<int, mixed> $resource
 */
final class ListMetaResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  LengthAwarePaginator<int, mixed>  $resource
     */
    public function __construct(
        LengthAwarePaginator $resource,
        private readonly ListQuery $query,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paginator = $this->resource;

        return [
            'page' => $paginator->currentPage(),
            // The paginator's, not the query's: they agree today and the paginator is the one that
            // actually sized the SQL, so reading it here means a future repository that adjusts the
            // page size cannot make this block describe a page nobody was served.
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
            'sort' => $this->query->sort,
            'dir' => $this->query->direction->value,
            'filter' => $this->query->filter,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'ListMetaResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'Pagination and applied-query state for one page of a list. The '
                    .'four query fields are what the SERVER USED and not what the client asked '
                    .'for: `per_page` is clamped to the platform maximum and `sort`/`dir` fall back '
                    .'to the endpoint default, so a client that recomputed a page count from its '
                    .'own request parameters would be wrong on every clamped response.',
                'required' => ['page', 'per_page', 'total', 'total_pages', 'sort', 'dir', 'filter'],
                'properties' => [
                    'page' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'The 1-BASED page number this body is. 1-based to match '
                            .'Laravel\'s paginator and every `?page=` a client sends back; the only '
                            .'0-based number in this system is the row offset, which never crosses '
                            .'the wire.',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Rows per page AS APPLIED. May be smaller than requested: '
                            .'the platform caps it, and the cap is what stops a list endpoint from '
                            .'being a query whose cost the caller chooses.',
                    ],
                    'total' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'description' => 'Total matching rows across all pages, within this '
                            .'organization and after the filter. This is TanStack Table\'s '
                            .'`rowCount` under `manualPagination`.',
                    ],
                    'total_pages' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Total pages at the APPLIED `per_page`. Published rather '
                            .'than derived, because the client\'s `per_page` may not be the one the '
                            .'server used. This is TanStack Table\'s `pageCount`. It is 1 and not 0 '
                            .'for an empty list — page one exists and is empty.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'description' => 'The column the rows are ordered by, AS APPLIED. Always '
                            .'one of the endpoint\'s own closed sortable set — a caller-chosen '
                            .'value reaches an ORDER BY, so the set is closed per endpoint and '
                            .'published in that endpoint\'s request rules rather than here.',
                    ],
                    'dir' => [
                        'type' => 'string',
                        'enum' => ['asc', 'desc'],
                        'description' => 'The order direction, as applied.',
                    ],
                    'filter' => [
                        'type' => ['string', 'null'],
                        'description' => 'The free-text filter as applied: trimmed, and NULL rather '
                            .'than an empty string, because an empty filter is no filter and two '
                            .'spellings of "unfiltered" would make an unfiltered list\'s cache key '
                            .'depend on whether the client sent the parameter at all.',
                    ],
                ],
            ],
        ];
    }
}
