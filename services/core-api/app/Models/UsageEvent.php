<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Provider;
use App\Enums\UsageEventType;
use App\Models\Scopes\OrganizationScope;
use App\Support\Casts\JsonObjectCast;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the quota ledger.
 *
 * ── IT HOLDS `organization_id` DIRECTLY AND IS SCOPED ────────────────────────────────────────
 *
 * `#[ScopedBy(OrganizationScope::class)]` is the BACKSTOP; the explicit `organization_id` predicate
 * in `App\Repositories\Eloquent\EloquentUsageEventRepository` is the mechanism. Both, always: on a
 * queue worker whose context is stale the scope reads that same stale context, so the two layers
 * fail together unless the argument is explicit (`laravel-control-plane`).
 *
 * ── `occurred_at` IS THE PARTITION KEY AND IS NEVER `now()` ──────────────────────────────────
 *
 * It is DERIVED FROM THE SOURCE ROW — the provider attempt's own timestamp, the moment the upload
 * was admitted — because `usage_events_dedupe` must contain the partition key and therefore cannot
 * catch the same work recorded twice under two different instants. The migration carries the full
 * argument. `App\Services\Usage\UsageRecorder` is the only writer and takes it as a required
 * argument, which is what keeps that rule enforceable in one place.
 *
 * ── NO `updated_at`, BECAUSE THE TABLE IS APPEND-ONLY ────────────────────────────────────────
 *
 * `UPDATED_AT = null` rather than `$timestamps = false`: `created_at` IS wanted (it is the only
 * evidence that a row was derived by `kb:rollup-usage` hours after the fact rather than recorded
 * live), and turning timestamps off entirely would lose it. A correction to this ledger is a new row
 * of the opposite-direction type, never an UPDATE — which is why `storage.bytes.removed` exists.
 *
 * ── NOTHING HERE IS A CREDENTIAL AND NOTHING HERE CAN BECOME ONE ─────────────────────────────
 *
 * `provider` is the closed vendor vocabulary and `model` is a vendor model id. There is no
 * connection reference on this row at all, deliberately: the ledger answers "how much", and "which
 * credential paid" is `provider_calls`' column, on the table that is already the billing record.
 * Carrying it here would put a second copy of the credential-to-usage mapping in a table that
 * outlives the connection.
 *
 * @property string $id
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property string $organization_id
 * @property string|null $bot_id
 * @property UsageEventType $event_type
 * @property int $quantity
 * @property Provider|null $provider
 * @property string|null $model
 * @property string $dedupe_key
 * @property array<string, mixed> $aggregation_metadata
 * @property \Carbon\CarbonImmutable $created_at
 */
#[ScopedBy(OrganizationScope::class)]
final class UsageEvent extends Model implements OrgOwned
{
    /** @use HasFactory<UsageEventFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Append-only: there is no `updated_at` column, and a row is never updated.
     */
    public const UPDATED_AT = null;

    protected $table = 'usage_events';

    /**
     * EMPTY, AND IT STAYS EMPTY. No client input reaches this table — every column is an
     * attribution the server resolves, a measurement the server takes, or provenance the server
     * writes. A field added here would be the first one a request could set, and every one of them
     * is a number an organization is metered on. `UsageRecorder` assigns each attribute explicitly,
     * exactly as `AuditLogger` does for `audit_logs`.
     *
     * @var list<string>
     */
    protected $fillable = [];

    public function organizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The bot this usage is attributed to, WHERE THERE IS ONE. Null for storage, which is an
     * organization-level fact.
     *
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `immutable_datetime`, matching the rest of this schema: a Carbon instance a caller can
            // mutate in place is one whose value can change between the read that decided a period
            // and the write that recorded it.
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'event_type' => UsageEventType::class,
            'provider' => Provider::class,
            // `integer`, matching `bigint` — PHP integers are 64-bit on every platform this runs on,
            // so a token count past 2^31 survives the round trip. On a 32-bit build it would not,
            // which is a deployment constraint rather than a cast this file can express.
            'quantity' => 'integer',
            // NOT the plain `array` cast. `json_encode([])` is `[]`, a JSON ARRAY, and
            // `usage_events_aggregation_metadata_is_object` refuses it — so an event with no
            // provenance would fail its INSERT rather than storing `{}`.
            'aggregation_metadata' => JsonObjectCast::class,
        ];
    }
}
