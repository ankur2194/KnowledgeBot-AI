<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Closure;
use Throwable;

/**
 * The request-scoped half of the log contract.
 *
 * `kb-observability-conventions/references/logs-health-audit.md` requires `request_id` and
 * `operation` on EVERY line, and neither is knowable from a Monolog record: one is minted per
 * request, the other is a property of the route. They travel here so a `Log::` call deep inside a
 * service needs no argument threading — exactly the role `_REQUEST_ID` / `_OPERATION` play in
 * `services/ai-service/app/observability/logging.py`.
 *
 * STATIC RATHER THAN A CONTAINER BINDING, and that is a decision about WHERE the value is read
 * rather than about convenience. The formatter is constructed ONCE per channel and cached inside
 * Laravel's LogManager; a formatter holding an injected context object would keep the FIRST
 * request's object for the life of an FPM child or a queue worker. Reading a static at format time
 * is the only shape that cannot go stale, and it is also what keeps
 * `App\Logging\KbJsonFormatter` free of the container — which is what makes it a Unit test rather
 * than a Feature test.
 *
 * PHP HAS NO CONTEXT VARS AND DOES NOT NEED THEM. The Python side uses `ContextVar` because one
 * event loop interleaves many requests on one thread; PHP-FPM serves exactly one request per
 * process at a time, so a plain static is the same guarantee.
 *
 * `operation` IS A CLOSURE, not a string, because of WHEN it is knowable.
 * `App\Http\Middleware\RequestId` is GLOBAL middleware, so it runs BEFORE the router — at which
 * point `$request->route()` is null and there is no operation to bind. The resolver is evaluated
 * at LOG TIME instead, by which point routing has happened, and it still returns null for a line
 * emitted before the route was matched. That is the honest answer, not a missing one.
 */
final class LogContext
{
    private static ?string $requestId = null;

    /** @var (Closure(): ?string)|null */
    private static ?Closure $operationResolver = null;

    private static ?string $operation = null;

    /**
     * Bind the identifiers for one request.
     *
     * OVERWRITES UNCONDITIONALLY. `RequestId` calls this on the way IN and clears it in
     * `terminate()`, never in a `finally` — see the middleware for why — so an entry that
     * overwrites is what makes a missed `terminate()` harmless rather than a request wearing its
     * predecessor's id.
     *
     * @param  (Closure(): ?string)|null  $operationResolver
     */
    public static function bind(?string $requestId, ?Closure $operationResolver = null): void
    {
        self::$requestId = $requestId;
        self::$operationResolver = $operationResolver;
        self::$operation = null;
    }

    /**
     * Pin the operation explicitly. A queued job or an artisan command has no route, so it names
     * itself; an explicit value always outranks the resolver.
     */
    public static function setOperation(?string $operation): void
    {
        self::$operation = $operation;
    }

    public static function requestId(): ?string
    {
        return self::$requestId;
    }

    public static function operation(): ?string
    {
        if (self::$operation !== null) {
            return self::$operation;
        }

        if (self::$operationResolver === null) {
            return null;
        }

        // A resolver that throws must never take a log line with it: logging is fire-and-forget
        // (§19.6) and the line being written is frequently the record of the failure that broke
        // the resolver in the first place.
        try {
            return (self::$operationResolver)();
        } catch (Throwable) {
            return null;
        }
    }

    public static function forget(): void
    {
        self::$requestId = null;
        self::$operationResolver = null;
        self::$operation = null;
    }
}
