<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Bots\NewSourceAssignment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Let one bot answer from one knowledge source.
 *
 * ── THREE FIELDS, AND WHAT IS NOT HERE IS THE INTERESTING PART ────────────────────────────────
 *
 * `organization_id` is never validated, never posted and never in a DTO: it comes from the
 * authenticated context, and over-posting a tenant key is an authorization bug with a 200 response
 * (`laravel-rbac-policies` NN5). `bot_id` is worse than the usual case and is absent for a stronger
 * reason — it is the OWNERSHIP EDGE of the grant INSIDE the organization, so a fillable one would
 * let a request move a live grant from one bot to another while both composite foreign keys agreed,
 * because both bots belong to that tenant. It comes from the route.
 *
 * `source_id` IS a body field, because it is the other half of the grant and there is nowhere else
 * for it to come from. That makes it a PARAMETER the caller chose rather than a scope, so
 * `BotSourceAssignmentService::add()` resolves it through a repository read whose organization
 * predicate is a required positional argument and renders a 404 — byte-identical to a path with no
 * route — when it names a source this organization does not have.
 *
 * ── NO `exists:` RULE, ON THE ONE FIELD THAT LOOKS LIKE IT WANTS ONE ──────────────────────────
 *
 * `exists:knowledge_sources,id` would query the table with NO organization predicate unless
 * somebody remembered to add one — the exact shape of Filament CVE-2026-48067, where the select
 * query was tenant-scoped and the validation rule for the same field was not. On this field the
 * consequence is not a leak of data but a leak of EXISTENCE: an unscoped rule answers 422 for an id
 * that does not exist anywhere and 200-and-then-something-else for one that exists in another
 * organization, which is an oracle over every customer's document ids rendered as a form error.
 * The existence check is `KnowledgeSourceRepositoryInterface::find($organizationId, …)` and its
 * refusal is a 404.
 *
 * ── `priority` AND `enabled` HAVE DEFAULTS, AND THE DEFAULTS MATCH THE COLUMN DEFAULTS ────────
 *
 * `bot_source_assignments` defaults `priority` to 0 and `enabled` to true, and `toData()` produces
 * exactly those when the fields are absent. The two are stated in both places on purpose: an
 * attribute the INSERT never mentioned reads back as null on the model, so the 201 body and the
 * audit row would both describe a row the database has correctly stored — the same reason
 * `EloquentBotDomainRepository::create()` restates `status`.
 *
 * ── THERE IS NO UPDATE REQUEST TO PAIR WITH THIS ONE ─────────────────────────────────────────
 *
 * `BotSourceAssignmentService`'s docblock carries the argument: the audit catalog defines
 * `bot.source_assignment.created` and `.deleted` and nothing else, so a PATCH would either write no
 * row for a retrieval-scope change or invent an operation. A change of priority or of the off
 * switch is a withdraw and a re-grant.
 */
final class StoreBotSourceAssignmentRequest extends FormRequest
{
    /**
     * The ceiling on `priority`.
     *
     * A BOUND ON HOSTILE INPUT RATHER THAN A DESIGN CONSTRAINT. The column is `integer` with a
     * `>= 0` CHECK, so PHP_INT_MAX is a 500 from an out-of-range insert rather than a 422, and
     * nothing about an operator's preference between at most `MAX_PER_BOT` sources needs more than
     * four digits. It is deliberately far above the cap on the list so that leaving gaps — the
     * ordinary way people sequence things they expect to insert into — stays expressible.
     */
    public const MAX_PRIORITY = 9999;

    /**
     * Authorization is `Gate::authorize()` in the controller, not here. `FormRequest::authorize()`
     * runs BEFORE validation, so a policy call placed in it decides on unvalidated input — and it
     * cannot reach checks 5 and 6 (entity status, rate limit) at all.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `ulid` BEFORE any lookup and `bail` before everything: the id is a route-shaped value
            // and refusing a malformed one on its shape keeps a 200-character probe from reaching a
            // query at all. No `exists:` — see the class docblock.
            'source_id' => ['bail', 'required', 'string', 'ulid'],

            // ABSENT MEANS 0, which is the column default and the floor. A negative value is
            // refused here rather than at the CHECK constraint, because
            // `bot_source_assignments_priority_non_negative` would arrive as SQLSTATE 23514
            // rendered as a 500 for a field the form has an input for.
            'priority' => ['bail', 'sometimes', 'integer', 'min:0', 'max:'.self::MAX_PRIORITY],

            // ABSENT MEANS true. A caller that wanted the grant switched off says so; the ordinary
            // act of assigning a source is the act of letting the bot answer from it.
            'enabled' => ['bail', 'sometimes', 'boolean'],
        ];
    }

    /**
     * The validated body, as a type.
     */
    public function toData(): NewSourceAssignment
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $priority = $data['priority'] ?? null;
        $enabled = $data['enabled'] ?? null;

        return new NewSourceAssignment(
            sourceId: (string) $data['source_id'],
            priority: is_numeric($priority) ? (int) $priority : 0,
            // `!== false` and not a truthy cast: the `boolean` rule admits `"0"`, `0`, `"false"`
            // and `false`, and Laravel normalises all four to the PHP boolean, so the only two
            // values that can arrive here are `true`, `false` and absent. Absent is the default,
            // which is true.
            enabled: ! is_bool($enabled) || $enabled,
        );
    }
}
