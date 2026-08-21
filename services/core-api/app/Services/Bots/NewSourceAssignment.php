<?php

declare(strict_types=1);

namespace App\Services\Bots;

/**
 * The validated body of "let this bot answer from this source".
 *
 * ── THREE FIELDS, AND THE TWO THAT ARE NOT HERE ARE THE INTERESTING PART ─────────────────────
 *
 * `organization_id` is absent because it comes from the authenticated context and over-posting a
 * tenant key is an authorization bug with a 200 response (`laravel-rbac-policies` NN5). `bot_id` is
 * absent because it comes from the ROUTE, and on this table it is worse than the usual case: it is
 * the ownership edge inside the tenant, so a fillable one would let a request move a live grant
 * from one bot to another while both composite foreign keys agreed, because both bots belong to
 * that organization.
 *
 * `source_id` IS here and IS a body field, because it is the other half of the grant and there is
 * nowhere else it can come from. It is a ULID the caller chose, so it is a PARAMETER rather than a
 * scope: `BotSourceAssignmentService::add()` resolves it against an org-scoped repository read and
 * renders a 404 — byte-identical to a path with no route — when it names a source this organization
 * does not have. What stops the cross-organization row is not that check, and the service says so:
 * the composite foreign keys are the guard, and they refuse a row whose bot and source disagree
 * even when the caller is a legitimate member of one of the two.
 */
final readonly class NewSourceAssignment
{
    public function __construct(
        public string $sourceId,
        public int $priority,
        public bool $enabled,
    ) {}
}
