<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Resources\AcknowledgementResource;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\Response;

/**
 * A controller-shaped fixture that exists only to be REFLECTED OVER by kb:dump-openapi.
 *
 * Neither method is ever dispatched: tests/Contract/OpenApiDocumentTest.php registers a route
 * pointing at one of them and then runs the dump, which reads the #[ResponseShape] attribute and
 * never calls anything. It lives here rather than inside the spec file because #[ResponseShape] is
 * read by reflection and a reflected attribute needs a real class on a real file.
 *
 * TWO PROBES, ONE CLASS, and each pins a separate refusal-or-acceptance of the dumper:
 *
 *   store()     an EMPTY #[ResponseShape] — the 204 shape. The dump must REFUSE and must name this
 *               action, because operation() would otherwise publish `"properties": []`, a JSON array
 *               where JSON Schema requires an object (D8).
 *   __invoke()  a VALID shape reached through a bare `Route::post($uri, self::class)`, which stores
 *               no `@method`. That form used to abort the dump with "is not a controller action"
 *               because Route::getActionMethod() answered with the class name; it must now publish.
 */
final class ResponseShapeProbe
{
    #[ResponseShape(status: 204, properties: [], description: 'Deliberately bodyless.')]
    public function store(): Response
    {
        return new Response(status: 204);
    }

    #[ResponseShape(status: 200, properties: ['data' => AcknowledgementResource::class])]
    public function __invoke(): AcknowledgementResource
    {
        return AcknowledgementResource::ok();
    }
}
