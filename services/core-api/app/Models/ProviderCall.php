<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProviderCallStatus;
use App\Models\Scopes\OrganizationScope;
use App\Support\Casts\JsonObjectCast;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\ProviderCallFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt against one provider (docs/11 §16.6): the billing record, the latency record, and the
 * only place a failure is attributed to a vendor rather than to the platform.
 *
 * ── ONE ROW IS ONE ATTEMPT, NOT ONE TURN ──────────────────────────────────────────────────────
 *
 * A turn that failed on the primary model and succeeded on a fallback writes TWO rows.
 * `Message::providerCall()` names the one that produced the text; `Message::providerCalls()` is all
 * of them. `ProviderCallStatus` has no `fell_back` value for the reason its docblock gives: a single
 * row would have to choose one connection, one token count and one cost for two vendors, and the
 * failed attempt's tokens are real money.
 *
 * ── IT CARRIES `organization_id` AND IS SCOPED, UNLIKE THE REST OF THE GRAPH ──────────────────
 *
 * `conversation_id` and `message_id` are both NULLABLE and both `ON DELETE SET NULL`, because
 * retention removes CONTENT and not COST. So this row must be able to stand alone after the thread
 * it paid for is gone — which is exactly why it holds its own `organization_id` and `bot_id` and why
 * `#[ScopedBy]` is correct here and impossible on `messages`.
 *
 * THE CONSEQUENCE FOR ANYTHING THAT MUST ADD UP: aggregate on `organization_id` and `bot_id`, which
 * never null, and never through `Conversation::providerCalls()`, which silently loses every call
 * whose thread has been swept.
 *
 * ── NOTHING HERE TOUCHES A CREDENTIAL, AND NOTHING HERE CAN ───────────────────────────────────
 *
 * `provider_connection_id` is a REFERENCE — a ULID — and the key behind it never leaves the vault.
 * This model does not import `App\Support\Crypto\CredentialVault` and no method it calls reaches
 * one. `provider_request_id` is the VENDOR'S identifier for the request and is not a credential: it
 * is what a support ticket to the provider quotes, and it is the only field on this row that lets
 * somebody else reproduce our failure.
 *
 * ── THERE IS NO `cost()` ACCESSOR THAT SUMS ACROSS ROWS ───────────────────────────────────────
 *
 * `estimated_cost` carries `estimated_cost_currency` beside it, and an accessor that added two rows
 * would have to decide what to do when they disagree. There is no right answer to that at the model
 * layer, so the question stays where the reporting query is, which is the only place a conversion
 * rate could come from.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bot_id
 * @property string|null $conversation_id
 * @property string|null $message_id
 * @property string $provider_connection_id
 * @property string $model_id
 * @property string|null $provider_request_id
 * @property ProviderCallStatus $status
 * @property int|null $input_tokens
 * @property int|null $cache_read_tokens
 * @property int|null $cache_write_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property string|null $estimated_cost
 * @property string|null $estimated_cost_currency
 * @property int|null $first_token_latency_ms
 * @property int|null $total_latency_ms
 * @property array<string, mixed> $fallback_metadata
 * @property string|null $error_class
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class ProviderCall extends Model implements OrgOwned
{
    /** @use HasFactory<ProviderCallFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'provider_calls';

    /**
     * EMPTY, AND IT STAYS EMPTY.
     *
     * NO CLIENT INPUT REACHES THIS TABLE. Every column is either an attribution the server resolves
     * (`organization_id`, `bot_id`, `provider_connection_id`, `model_id`), a measurement the server
     * takes (the five token counters, the two latencies, the cost), or a verdict the server reaches
     * (`status`, `error_class`, `fallback_metadata`). There is no field a caller may name, so an
     * empty allow-list is not a placeholder waiting to be filled in — a field added here would be
     * the first one a request could set, and every one of them is a number somebody is billed for.
     *
     * The writer assigns each attribute explicitly, exactly as `AuditLogger` does for `audit_logs`.
     * Unlike that model there IS a factory, because these rows are read by the analytics surface and
     * a fixture is how it gets tested; the factory sets attributes directly rather than through mass
     * assignment.
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
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * The thread this attempt was made for, WHILE IT STILL EXISTS. Null after retention swept it.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The turn this attempt was made for, WHILE IT STILL EXISTS. Null after retention swept it.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * The credential that was billed. A REFERENCE only — see the class docblock.
     *
     * `ON DELETE RESTRICT`, so this row makes its connection undeletable. That is this step's brief
     * stated as settled, and the migration carries the reasoning.
     *
     * @return BelongsTo<ProviderConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    /**
     * The catalog row that answered. Also `ON DELETE RESTRICT` — see the migration for why that is
     * the larger commitment of the two and what it obliges `ProviderModelService::delete()` to do.
     *
     * @return BelongsTo<ProviderModelEntry, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(ProviderModelEntry::class, 'model_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProviderCallStatus::class,
            'input_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            // NO `decimal:` CAST AND NO `float`, DELIBERATELY. `decimal:8` returns a STRING with a
            // fixed scale, which is what the @property annotation says and what the reporting query
            // needs — but applying it here would ALSO round on write, silently, in PHP, before
            // PostgreSQL ever sees the value. `numeric(16, 8)` is exact and the driver hands it back
            // as a string already; leaving the column uncast is what keeps the arithmetic in the
            // database, where a price multiplied by a token count in the millions belongs.
            // `float` would be the same binary rounding error 2026_08_19_001200 refuses for prices.
            'first_token_latency_ms' => 'integer',
            'total_latency_ms' => 'integer',
            // JsonObjectCast AND NOT `array`. The built-in cast writes `[]` for an empty map, whose
            // `jsonb_typeof` is `array` and not `object` — so every unremarkable primary attempt,
            // which is the overwhelming majority of rows, would be refused by
            // `provider_calls_fallback_metadata_is_object`.
            'fallback_metadata' => JsonObjectCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
