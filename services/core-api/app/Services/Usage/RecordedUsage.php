<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\Provider;
use App\Enums\UsageEventType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One thing to meter, fully specified, before anything is written.
 *
 * ── EVERY FIELD IS REQUIRED AND THE INVARIANTS ARE CHECKED IN THE CONSTRUCTOR ─────────────────
 *
 * The two rules `usage_events` cannot enforce for itself both live here, and both are enforced by
 * making the wrong object UNCONSTRUCTIBLE rather than by a validation call somebody can skip:
 *
 *   1. `occurredAt` IS DERIVED FROM THE SOURCE ROW, NEVER FROM THE CLOCK. It is a required
 *      constructor argument with no default, because it is half of the dedupe identity: a
 *      partitioned table's unique index must contain the partition key, so two recordings of the
 *      same work under two different instants are both accepted. The migration carries the whole
 *      argument. A default of `now()` here would make `kb:rollup-usage` double-count every hour, in
 *      a direction that reads as ordinary growth.
 *
 *   2. PROVIDER ATTRIBUTION IS PAIRED WITH THE TYPE, IN BOTH DIRECTIONS. A token event names the
 *      vendor and model it was billed against or it is a cost nobody can price; a storage event
 *      names NEITHER, or it attributes our own bytes to a vendor account.
 *      `usage_events_provider_attribution_paired` refuses both shapes in the database — this
 *      refuses them before a transaction is opened, with a sentence rather than a constraint name.
 *
 * ── `dedupeKey` IS THE IDENTITY OF THE WORK, NOT OF THE ROW ───────────────────────────────────
 *
 * It is the stable identifier of the thing being metered — the `provider_calls` ULID for a token
 * event, the `source_items` ULID for a storage one — so that re-deriving the same usage collides
 * instead of adding. It must NEVER be a fresh ULID or a hash of the timestamp: a retry that
 * generates a new key is a duplicate, which on this table is a duplicate charge.
 */
final readonly class RecordedUsage
{
    /**
     * @param  string|null  $botId  null for organization-level usage (storage). Present for a chat
     *                              turn, and composite-guarded against the organization in the
     *                              database.
     * @param  array<string, mixed>  $aggregationMetadata  provenance: which path recorded this and
     *                                                     what it was derived from. Written once,
     *                                                     read whole, never queried by predicate.
     */
    public function __construct(
        public UsageEventType $type,
        public int $quantity,
        public CarbonImmutable $occurredAt,
        public string $dedupeKey,
        public ?string $botId = null,
        public ?Provider $provider = null,
        public ?string $model = null,
        public array $aggregationMetadata = [],
    ) {
        if ($quantity < 0) {
            // A ledger admitting negative quantities admits a row that silently cancels another,
            // and no constraint can tell that from a correction. A refund is a row of the
            // opposite-direction type — see UsageEventType::StorageBytesRemoved.
            throw new InvalidArgumentException(
                'A usage quantity may not be negative. A correction is a row of the '
                .'opposite-direction event type, never a negative quantity: the ledger has no way '
                .'to tell a cancellation from a mistake, and `usage_events_quantity_nonnegative` '
                .'refuses it in the database anyway.',
            );
        }

        if (trim($dedupeKey) === '') {
            throw new InvalidArgumentException(
                'A usage event needs the stable identity of the WORK it meters — the provider call '
                .'id, the source item id — so that re-deriving it collides instead of adding. A '
                .'blank or generated key makes every retry a duplicate charge.',
            );
        }

        $attributed = $type->attributedToProvider();

        if ($attributed && ($provider === null || $model === null)) {
            throw new InvalidArgumentException(
                "A `{$type->value}` event must name the provider AND the model it was billed "
                .'against: a token count nobody can price is not a billing record. '
                .'`usage_events_provider_attribution_paired` refuses it in the database too.',
            );
        }

        if (! $attributed && ($provider !== null || $model !== null)) {
            throw new InvalidArgumentException(
                "A `{$type->value}` event must name NEITHER a provider NOR a model: object storage "
                .'is ours, and attributing our own bytes to a vendor account produces a line in a '
                .'reconciliation that will never match anything.',
            );
        }
    }
}
