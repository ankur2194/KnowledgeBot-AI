<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audited event: who did what, to which record, from where, and how it went.
 *
 * ═══ NON-NEGOTIABLE 9 IS THE ACCEPTANCE CRITERION FOR THIS CLASS ════════════════════════════
 *
 * *"No provider credential reaches a client, a log, an API response, or an audit detail."* This
 * resource is the one place where the last two of those meet, so it is worth being exact about
 * where the guarantee comes from: IT IS THE WRITE PATH'S, NOT THIS CLASS'S.
 *
 * `AuditLogger::OPERATIONS` allow-lists `details` PER OPERATION and declares each admitted field
 * either `ECHOED` or `FINGERPRINTED` — an unlisted key does not reach the table whether it is
 * `api_key`, `plaintext` or `x`, and a bearer value is HMAC-hashed under `<key>_fingerprint` with
 * the plaintext never entering the kept set under any key. So `details` cannot contain a credential
 * because none was ever stored.
 *
 * WHAT THIS CLASS MUST NOT DO IS WIDEN THAT. It renders `details` WHOLE and adds nothing: no
 * hydration of the subject, no join to `provider_connections`, no "helpfully" resolved masked key.
 * A read path that enriched a row would be adding fields the write-side allow-list never reviewed,
 * which is exactly how a redaction guarantee stops being one.
 * `tests/Security/AuditTrailAccessTest.php` asserts it against a real sealed fixture credential:
 * seal, rotate, read the endpoint, grep the serialized body.
 *
 * ═══ `details` IS RENDERED WHOLE, WITH NO KEY FILTER, AND THAT IS THE SAFE DIRECTION ════════
 *
 * A read-side denylist would be the tempting extra belt and it is the wrong one: a denylist is a
 * list of the names somebody thought of, and it fails OPEN on the first field added after it was
 * written. It would also make this surface disagree with the table, so an operator investigating an
 * incident would see a redacted row and have no way to know a field had been withheld. The
 * allow-list at the writer fails CLOSED and is reviewed per operation; adding a second, weaker
 * filter here would move where reviewers look without moving what is stored.
 *
 * ═══ WHAT IS PUBLISHED THAT A READER MIGHT NOT EXPECT ═══════════════════════════════════════
 *
 * `ip_address` and `user_agent` are PII about a colleague and they are published, because an audit
 * trail that cannot say where an action came from cannot answer the question it exists for. That is
 * part of why `audit.view` is Owner/Admin and is withheld from the Analyst and the Knowledge
 * Manager — the surveillance surface is the reason for the narrow grant, not an accident of it.
 *
 * `request_id` is the ONLY bridge between this table and telemetry: an audit row and the log lines
 * for the same request share it, which is what lets an investigator move between two stores that
 * must never BE one store.
 *
 * `organization_id` IS NOT PUBLISHED. Every row on this surface belongs to the organization in the
 * path — the repository predicates on it and NULL-org platform rows are excluded — so the field
 * would be one constant repeated on every row of every page, and its presence would invite a client
 * to believe the endpoint could return more than one.
 */
final class AuditLogResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(AuditLog $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $log = $this->resource;

        return [
            'id' => $log->id,
            'operation' => $log->operation,
            'outcome' => $log->outcome,
            'actor_id' => $log->actor_id,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            // `inet` COMES BACK AS A STRING and is cast to one explicitly rather than left to
            // json_encode: PDO hands the column back as a string already, and stating it here means
            // a driver that ever returned an object would fail the type rather than serialize it.
            'ip_address' => $log->ip_address === null ? null : (string) $log->ip_address,
            'user_agent' => $log->user_agent,
            'request_id' => $log->request_id,
            // WHOLE, UNFILTERED, AND NEVER ENRICHED — see the class docblock.
            'details' => $log->details,
            'created_at' => $log->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'AuditLogResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One audited event from this organization\'s trail. Rows are '
                    .'APPEND-ONLY — the application role holds no UPDATE and no DELETE on the '
                    .'table, and retention is a partition drop rather than a row delete — so an id '
                    .'read here names a row that will never change. THERE IS NO ROW-LEVEL '
                    .'ENDPOINT: the list is the whole surface, because a per-row read would be a '
                    .'second query shape over a table whose only tenancy is an explicit '
                    .'organization predicate.',
                'required' => [
                    'id', 'operation', 'outcome', 'actor_id', 'subject_type', 'subject_id',
                    'ip_address', 'user_agent', 'request_id', 'details', 'created_at',
                ],
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'ULID of the audit row.'],
                    'operation' => [
                        'type' => 'string',
                        'enum' => array_keys(AuditLogger::OPERATIONS),
                        'description' => 'What happened, from a CLOSED vocabulary: the writer '
                            .'refuses an operation that is not in its map, because an unmapped name '
                            .'means nobody has decided what that event may record. The same set is '
                            .'accepted by `?operation=`.',
                    ],
                    'outcome' => [
                        'type' => 'string',
                        'enum' => [AuditLogger::OUTCOME_SUCCESS, AuditLogger::OUTCOME_FAILURE],
                        'description' => 'DERIVED FROM THE OPERATION and never submitted, so no row '
                            .'can claim `auth.login.failed` with `outcome = success`. Two '
                            .'operations rather than one with a varying outcome is the pattern this '
                            .'trail uses wherever both endings are auditable.',
                    ],
                    'actor_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'ULID of the member who acted. NULL for an unauthenticated '
                            .'event — a failed login — and for anything the platform did with no '
                            .'person behind it. There is no foreign key: the actor may be deleted '
                            .'and the row must remain, which is what "append-only and outlives the '
                            .'record it describes" means.',
                    ],
                    'subject_type' => [
                        'type' => ['string', 'null'],
                        'description' => 'What was acted ON, as the fully-qualified model class — '
                            .'for example `App\\Models\\Bot`. THE SET IS OPEN, unlike `operation`: '
                            .'auditing a new kind of record needs no schema change, so a client '
                            .'should treat this as an opaque discriminator and read values off rows '
                            .'rather than composing them. Null exactly when `subject_id` is null; '
                            .'the database refuses a half-specified pair.',
                    ],
                    'subject_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'ULID of the record acted on. Pair it with `subject_type` '
                            .'in `?subject_type=&subject_id=` to get that record\'s own trail.',
                    ],
                    'ip_address' => [
                        'type' => ['string', 'null'],
                        'description' => 'Where the request came from, as an IPv4 or IPv6 literal. '
                            .'Stored as a network type rather than text so a trail can be read with '
                            .'network predicates. PII ABOUT A COLLEAGUE, which is part of why this '
                            .'surface is withheld from the reporting and ingestion roles. Null for '
                            .'anything raised by a queued job or a console command.',
                    ],
                    'user_agent' => [
                        'type' => ['string', 'null'],
                        'description' => 'The client\'s declared user agent, truncated at 512 '
                            .'characters. ATTACKER-CONTROLLED FREE TEXT on the unauthenticated '
                            .'paths: escape it at render.',
                    ],
                    'request_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'The bridge to telemetry, and the only one. This row and '
                            .'the log lines for the same request carry the same value, which is '
                            .'what lets an investigator move between two stores that must never be '
                            .'one store.',
                    ],
                    'details' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => 'What the operation recorded, ALLOW-LISTED PER OPERATION AT '
                            .'WRITE TIME. The key set differs by operation and is not enumerated '
                            .'here — read defensively. NO CREDENTIAL, TOKEN OR KEY FRAGMENT CAN '
                            .'APPEAR: an unlisted field never reaches the table, and every bearer '
                            .'value is admitted only as `<name>_fingerprint`, a keyed HMAC that '
                            .'answers "was THIS the value" without being derivable back to it. `{}` '
                            .'when the operation records nothing beyond its columns, which is the '
                            .'honest shape for something like a logout.',
                    ],
                    'created_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'When the event was recorded. Also the PARTITION KEY, which '
                            .'is why the default sort and every index on this table are built '
                            .'around it.',
                    ],
                ],
            ],
        ];
    }
}
