<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Chunk;
use App\Models\Citation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * One footnote.
 *
 * ── IT REQUIRES A RECYCLED MESSAGE AND REFUSES TO RUN OTHERWISE ───────────────────────────────
 *
 * `Citation::factory()->recycle($message)`. The message is the tenant fact — this table has no
 * `organization_id`.
 *
 * ── `chunk_id` DEFAULTS TO NULL, AND THAT IS THE STATE THIS TABLE EXISTS FOR ──────────────────
 *
 * A citation whose chunk has been purged still renders, from the four denormalized columns on its
 * own row. That is the property postgresql-patterns asks for a test of by name — "a test deletes a
 * source and asserts the prior transcript still renders label, title, location, and excerpt" — so
 * the DEFAULT is the purged state and `->of($chunk)` is the live one. A factory that always
 * attached a chunk would make that test unwritable without hand-building the row.
 *
 * ── THE LABEL IS UNIQUE WITHIN ITS MESSAGE, SO THE DEFAULT COUNTS ─────────────────────────────
 *
 * `citations_message_label_unique` refuses two `1`s on one answer. A constant default would make
 * every two-citation fixture fail on a constraint, so the default is a per-instance sequence — and
 * `->labelled()` is how a test states a specific footnote number.
 *
 * @extends Factory<Citation>
 */
final class CitationFactory extends Factory
{
    /**
     * The next default label. Per PHP process, which is per test run; the value only has to be
     * distinct within one message and monotonic is the cheapest way to guarantee that.
     */
    private static int $nextLabel = 1;

    protected $model = Citation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $message = $this->requireMessage();

        return [
            'message_id' => $message->id,

            // NULL: the purged state. See the class docblock.
            'chunk_id' => null,

            'label' => (string) self::$nextLabel++,

            // DENORMALIZED AND DISTINGUISHABLE. These four columns are what the transcript renders
            // after the source is gone, so a fixture whose title and excerpt are shared literals
            // would make a cross-tenant leak compare equal to itself.
            'display_title' => implode(' ', (array) $this->faker->unique()->words(3)).'.pdf',
            'location_metadata' => ['page' => $this->faker->numberBetween(1, 40)],
            'excerpt' => $this->faker->unique()->sentence(12),
        ];
    }

    /**
     * The LIVE case: a footnote that still points at its chunk.
     *
     * The chunk is not checked against the message's organization, because this factory has no
     * organization in hand and the schema has no composite key here either — the model's docblock
     * states why that is not a hole (the message is what makes the row tenant-owned, and any surface
     * following the pointer authorizes through the conversation first). A test that wants the
     * cross-tenant pairing writes it deliberately, exactly as `KnowledgeSourceFactory::crossOrg()`
     * does for the one row in this schema that can legitimately span two organizations.
     */
    public function of(Chunk $chunk): static
    {
        return $this->state(fn (): array => ['chunk_id' => $chunk->id]);
    }

    /** A specific footnote number. Unique within the message — `citations_message_label_unique`. */
    public function labelled(string $label): static
    {
        return $this->state(fn (): array => ['label' => $label]);
    }

    /**
     * The four rendered columns, spelled by the caller.
     *
     * @param  array<string, mixed>  $location
     */
    public function rendering(string $title, string $excerpt, array $location = []): static
    {
        return $this->state(fn (): array => [
            'display_title' => $title,
            'excerpt' => $excerpt,
            'location_metadata' => $location,
        ]);
    }

    private function requireMessage(): Message
    {
        $message = $this->getRandomRecycledModel(Message::class);

        if (! $message instanceof Message) {
            throw new RuntimeException(
                'CitationFactory requires a recycled message: '
                .'Citation::factory()->recycle($message). A citation has NO organization_id — it '
                .'reaches one through `citations -> messages -> conversations` — so minting a parent '
                .'here would put this footnote in a third organization nothing in the test can name.',
            );
        }

        return $message;
    }
}
