<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Enums\Surface;

/**
 * A middleware that is the CREDENTIAL BOUNDARY of a public surface.
 *
 * ═══ IT EXISTS SO THE OPENAPI DUMPER CAN ASK A QUESTION INSTEAD OF MATCHING A NAME ══════════
 *
 * `DumpOpenApiCommand::securityFor()` has to answer "does this route have a credential boundary at
 * all", because publishing `security: []` for a route that has one under-claims, and publishing a
 * scheme for a route that has none describes a door that is not there. On the admin surface it
 * answers by reading the `auth:sanctum` middleware off the stack — a framework string that is
 * already the fact.
 *
 * The two public surfaces have no such string: the chat-session bearer is not resolved by Laravel's
 * `auth:` guard (the subject is not a `User` and no guard could produce one), and the SDK's boundary
 * is a browser-set header rather than a credential at all. The obvious alternatives were both worse:
 *
 *   NAMING THE MIDDLEWARE CLASSES in the dumper creates an
 *   `App\Console\Commands -> App\Http\Middleware` edge, which `arch()->preset()->laravel()` refuses —
 *   correctly, because a console command reaching into the HTTP layer is how a command ends up
 *   depending on request state.
 *
 *   MATCHING A CLASS-NAME STRING silences the arch rule by making the dependency invisible to it,
 *   and a rename then breaks the dump at runtime with a message about a route rather than about a
 *   rename.
 *
 * So the middleware DECLARES what it guards, and the dumper reads the declaration. A middleware
 * added to one of these groups without implementing this is a route the dump refuses to publish,
 * which is the correct failure: an unguarded route on a public surface is the finding.
 *
 * ═══ IT IS NOT AN AUTHORIZATION MECHANISM AND MUST NOT BECOME ONE ══════════════════════════
 *
 * Nothing branches on this at request time. It is read by one console command, once per dump. The
 * middleware's actual behaviour — resolving a bearer, refusing an origin, binding the tenant context
 * — is the mechanism; this only lets a document describe it.
 */
interface PublicSurfaceGuard
{
    /**
     * The surface this middleware is the boundary of.
     *
     * The same value the middleware binds into the container for `OrgScopedPolicy` to read, so a
     * class that answered one thing here and bound another would be describing a document it does
     * not produce — and both are one line apart in the same file.
     */
    public static function guardedSurface(): Surface;
}
