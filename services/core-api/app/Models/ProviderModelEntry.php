<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One mirrored `provider_models` row (docs/11 §16.2).
 *
 * THE CLASS IS NOT CALLED `ProviderModel`, AND THE TABLE IS STILL `provider_models`.
 * `arch()->preset()->laravel()` refuses a `Model` suffix inside App\Models — "ProviderModel
 * extends Model" reads as a base class in every call site and in every stack trace. The table name
 * is fixed by docs/11 and by the data plane, so `$table` is pinned explicitly rather than derived,
 * and `Entry` says what the row actually is: one entry in a connection's model catalog.
 *
 * `capability_flags` is the ROW axis of the capability question. It is READ, never parsed from
 * `model`, and the other axes are not ours at all — they live in
 * services/ai-service/app/providers/capabilities.py with a source per cell.
 *
 * THE QUESTION IS TWO AXES FOR EMBEDDING AND THREE FOR RERANK, and the third is the one an
 * operator will not predict. `assert_row_coherent` (capabilities.py) refuses a flag here on three
 * independent grounds, not one:
 *
 *   1. the vendor publishes no such endpoint;
 *   2. the vendor DOES publish it, but its score scale is uncharacterized, so no
 *      `RerankCalibration` can be constructed and the row could never produce an answer —
 *      `openrouter` + `rerank` is exactly this case and is refused today (capabilities.py:724);
 *   3. the row claims embedding and rerank together; rows are task-exclusive.
 *
 * Reading "publishes no such endpoint" as the whole rule is how an operator concludes an
 * `openrouter` + `rerank` row is savable. It is not: the data plane returns a `validation` 422 at
 * save time. This model exposes the flags as data and draws no conclusion from them.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $provider_connection_id
 * @property string $model
 * @property string $display_name
 * @property array<string, mixed> $capability_flags
 * @property int $context_window
 * @property int $max_output_tokens
 * @property bool $enabled
 */
#[ScopedBy(OrganizationScope::class)]
final class ProviderModelEntry extends Model implements OrgOwned
{
    use HasUlids;

    protected $table = 'provider_models';

    /** @var list<string> */
    protected $fillable = [
        'provider_connection_id', 'model', 'display_name',
        'capability_flags', 'context_window', 'max_output_tokens', 'enabled',
    ];

    public function organizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * @return BelongsTo<ProviderConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    /**
     * The capability flag list, normalized for the wire.
     *
     * The data plane's `ModelCapabilities.supported` is a set of `Capability` values; anything not
     * a list of strings here is a row somebody hand-edited, and the correct reading of a malformed
     * flag set is "claims nothing" rather than "claims everything".
     *
     * @return list<string>
     */
    public function supportedCapabilities(): array
    {
        $supported = $this->capability_flags['supported'] ?? [];

        if (! is_array($supported)) {
            return [];
        }

        return array_values(array_filter($supported, 'is_string'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capability_flags' => 'array',
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
