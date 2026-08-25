<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PendingSourceObject;
use App\Support\Kb\ObjectKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One write-ahead reservation, for a test that needs a STALE one — which is every test of the
 * sweep, because a fresh reservation is precisely what the grace window exists to skip.
 *
 * ── IT REQUIRES A RECYCLED ORGANIZATION AND MINTS THE SOURCE ID ITSELF ────────────────────────
 *
 * The organization must be recycled for the reason every factory here states: one that mints its
 * own puts the row in a THIRD organization, and an isolation test built on such a fixture passes
 * with the tenant filter deleted.
 *
 * The SOURCE id is the opposite case and is minted here on purpose. `pending_source_objects` has NO
 * foreign key to `knowledge_sources` — that is the whole point of the table, which records an
 * intention that may never become a row — so a factory that insisted on a real source could only
 * ever produce the case the sweep is NOT for.
 *
 * ── THE KEY COMES FROM `ObjectKey`, NEVER FROM AN INTERPOLATED STRING ─────────────────────────
 *
 * `pending_source_objects_key_is_tenant_scoped` compares the key against the row's own
 * `organization_id`, so a hand-built key is a 23514 inside a factory, which reads like a schema
 * bug. Building it the way production does also means a test asserting "the sweep deleted the
 * object" is asserting it about a key shaped like a real one.
 *
 * @extends Factory<PendingSourceObject>
 */
final class PendingSourceObjectFactory extends Factory
{
    protected $model = PendingSourceObject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = $this->getRandomRecycledModel(Organization::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'PendingSourceObjectFactory requires a recycled organization: '
                .'PendingSourceObject::factory()->recycle($org). Letting it mint its own would put '
                .'the reservation in a THIRD organization, and the sweep test would then be '
                .'sweeping a tenant nothing else in the fixture can name.',
            );
        }

        // LOWERCASE, because `HasUlids::newUniqueId()` is and `KnowledgeSource::create()` mints
        // this id that way. A fixture that used the uppercase form would still satisfy every
        // constraint — `char(26) COLLATE "C"` has no case rule — and would quietly stop matching
        // the `source_items.source_id` of any row a test built through the real path.
        $sourceId = Str::lower((string) Str::ulid());

        return [
            'organization_id' => $organization->id,
            'source_id' => $sourceId,
            'storage_key' => ObjectKey::originalUpload(
                $organization->id,
                $sourceId,
                // A syntactically valid sha256: sixty-four lowercase hex characters, which
                // `ObjectKey` checks and a shorter random string would fail.
                bin2hex(random_bytes(32)),
            ),
            // FRESH BY DEFAULT, so a test that forgets `->stale()` finds the sweep correctly
            // SKIPPING the row rather than accidentally exercising the delete path.
            'created_at' => CarbonImmutable::now('UTC'),
        ];
    }

    /**
     * Older than any grace window this application will be configured with.
     *
     * Seven days rather than "grace + a minute": a test that pinned the boundary would start
     * failing the day `kb.upload_orphan_grace_minutes` is retuned, and the boundary itself has its
     * own test that sets the config explicitly.
     */
    public function stale(): static
    {
        return $this->state(fn (): array => ['created_at' => CarbonImmutable::now('UTC')->subDays(7)]);
    }

    /** A reservation for a source and key the caller already knows — the claimed-key cases. */
    public function forKey(string $sourceId, string $storageKey): static
    {
        return $this->state(fn (): array => [
            'source_id' => $sourceId,
            'storage_key' => $storageKey,
        ]);
    }
}
