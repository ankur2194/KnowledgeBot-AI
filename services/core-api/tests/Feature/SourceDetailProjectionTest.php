<?php

declare(strict_types=1);

use App\Enums\ChunkContentType;
use App\Enums\ChunkIndexStatus;
use App\Enums\DocumentElementKind;
use App\Enums\OrgRole;
use App\Enums\SourceState;
use App\Models\Chunk;
use App\Models\DocumentElement;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| What GET .../sources/{source} says is inside a source
|--------------------------------------------------------------------------
|
| The detail projection exists because the console could not state the consequence of a delete in
| numbers: `SourceService::delete()` writes a child summary to the AUDIT ROW, where no client can
| read it, and `SourceResource` publishes no counts at all. `chunk_count` is the figure that
| confirmation is asking for — how many vectors the deletion removes and how many a rebuild would
| re-embed at a provider's per-token price.
|
| EVERY NUMBER IS OVER THE LIVE VERSIONS, which is what makes the fixtures below shaped the way they
| are: each one plants a version an item POINTS AT and a version it does not, so a projection that
| counted rows rather than reachable rows would be visibly wrong rather than plausibly high.
|
| THE ROWS ARE WRITTEN FIELD BY FIELD RATHER THAN THROUGH FACTORIES, because there are none for
| `source_items`, `source_versions`, `document_elements` or `chunks` — the only fixture that would
| produce them is `KnowledgeSourceFactory::indexed()`, which is deferred on a live provider
| embedding call inside the suite. A hand-written row is the honest substitute for a projection
| test: it asserts arithmetic over rows whose contents this file chose, which is exactly what a
| count test needs, and it makes no claim about what the real ingestion path would have written.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * One organization, one owner.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own. Pest declares test-file helpers at FILE
 * SCOPE, so a name another test file already uses is a redeclaration fatal in a full run and only
 * in a full run.
 *
 * @return array{org: Organization, owner: User}
 */
function detailFixture(): array
{
    $org = Organization::factory()->create(['name' => 'Detail Org ALPHA', 'slug' => 'detail-alpha']);

    return [
        'org' => $org,
        'owner' => User::factory()->recycle($org)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('detail-owner')]),
    ];
}

/**
 * One `source_items` row.
 *
 * `canonical_key` is the only column with no default that this file has to choose, and the shape
 * check refuses a blank one.
 */
function detailItem(Organization $org, KnowledgeSource $source, string $key): SourceItem
{
    $item = new SourceItem;
    $item->organization_id = $org->id;
    $item->source_id = $source->id;
    $item->canonical_key = $key;
    $item->save();

    return $item;
}

/**
 * One `source_versions` row, activated or not.
 *
 * The two digests are lowercase hex by CHECK — `char(64)` alone would accept sixty-four spaces, and
 * an UPPERCASE digest compares unequal under `COLLATE "C"` — so they are built rather than typed.
 *
 * @param  array<string, mixed>  $warnings
 */
function detailVersion(
    Organization $org,
    SourceItem $item,
    int $number,
    bool $activate,
    array $warnings = [],
): SourceVersion {
    $version = new SourceVersion;
    $version->organization_id = $org->id;
    $version->source_item_id = $item->id;
    $version->version_number = $number;
    $version->content_hash = hash('sha256', $item->id.':content:'.$number);
    $version->ingest_key = hash('sha256', $item->id.':key:'.$number);
    $version->parser_cfg_version = 'parser/v1:docling-2.118';
    $version->ocr_cfg_version = 'ocr/v1:rapidocr';
    $version->chunker_cfg_version = 'chunker/v1:structure';
    $version->embedding_model_version = 'emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1';
    $version->status = $warnings === [] ? SourceState::Ready : SourceState::ReadyWithWarnings;
    $version->warning_summary = $warnings;

    if ($activate) {
        $version->activated_at = CarbonImmutable::now();
    }

    $version->save();

    if ($activate) {
        // THE POINTER IS WHAT MAKES IT LIVE, and it is written separately from `activated_at` on
        // purpose: the projection reads through `current_version_id` and never through the
        // timestamps, so a fixture that set only the timestamps would pass against a projection
        // that had quietly switched to the other reading.
        SourceItem::withoutGlobalScopes()->whereKey($item->id)
            ->update(['current_version_id' => $version->id]);
    }

    return $version;
}

/**
 * One `document_elements` row.
 */
function detailElement(
    Organization $org,
    SourceVersion $version,
    int $seq,
    string $text,
    ?int $page = null,
    ?int $slide = null,
    ?string $sheet = null,
): DocumentElement {
    $element = new DocumentElement;
    $element->organization_id = $org->id;
    $element->source_version_id = $version->id;
    $element->seq = $seq;
    $element->kind = DocumentElementKind::Text;
    $element->text = $text;
    $element->page = $page;
    $element->slide = $slide;
    $element->sheet = $sheet;
    $element->char_start = $seq * 100;
    $element->char_end = $seq * 100 + mb_strlen($text);
    $element->save();

    return $element;
}

/**
 * One `chunks` row derived from one element.
 */
function detailChunk(
    Organization $org,
    KnowledgeSource $source,
    SourceItem $item,
    SourceVersion $version,
    DocumentElement $element,
    int $seq,
): Chunk {
    $chunk = new Chunk;
    $chunk->organization_id = $org->id;
    $chunk->source_id = $source->id;
    $chunk->source_item_id = $item->id;
    $chunk->source_version_id = $version->id;
    $chunk->seq = $seq;
    $chunk->document_element_id = $element->id;
    // `document_element_id` must be IN the set — the CHECK says so, because a row where the two
    // disagree is a citation pointing at a paragraph the chunk does not contain.
    $chunk->element_ids = [$element->id];
    $chunk->heading_path = [];
    $chunk->char_start = $element->char_start;
    $chunk->char_end = $element->char_end;
    $chunk->lang = 'en';
    $chunk->content_type = ChunkContentType::Prose;
    $chunk->token_count = 42;
    $chunk->content_hash = hash('sha256', $element->id.':chunk:'.$seq);
    $chunk->text = $element->text;
    $chunk->vector_point_id = (string) Str::uuid();
    $chunk->index_status = ChunkIndexStatus::Indexed;
    $chunk->embedding_model_id = $version->embedding_model_version;
    $chunk->parser_version = $version->parser_cfg_version;
    $chunk->chunker_version = $version->chunker_cfg_version;
    $chunk->save();

    return $chunk;
}

it('counts only what the active-version pointers reach, and names the single live version', function (): void {
    $fixture = detailFixture();
    $org = $fixture['org'];

    $source = KnowledgeSource::factory()->recycle($org)
        ->status(SourceState::Ready)->create(['name' => 'ALPHA handbook']);

    $item = detailItem($org, $source, 'upload:handbook.pdf');

    // A SUPERSEDED VERSION WITH CONTENT OF ITS OWN. The item does not point at it, so nothing below
    // may count it: a projection that counted rows rather than REACHABLE rows would report a delete
    // as removing chunks nothing could retrieve.
    $retired = detailVersion($org, $item, 1, activate: false);
    $ghost = detailElement($org, $retired, 0, 'A paragraph nobody can retrieve.', page: 1);
    detailChunk($org, $source, $item, $retired, $ghost, 0);

    // AND THE LIVE ONE: three elements across two pages, two of which became chunks.
    $live = detailVersion($org, $item, 2, activate: true, warnings: ['ocr_text_unplaced' => 3]);

    $first = detailElement($org, $live, 0, 'Refunds are accepted for 30 days.', page: 1);
    $second = detailElement($org, $live, 1, 'Exchanges are accepted for 60 days.', page: 1);
    $third = detailElement($org, $live, 2, 'Contact support for anything else.', page: 2);

    detailChunk($org, $source, $item, $live, $first, 0);
    detailChunk($org, $source, $item, $live, $third, 1);

    SpaSession::establish(currentTest(), $fixture['owner']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$org->id}/sources/{$source->id}",
        spaHeaders(),
    )->assertOk();

    // POSITIVE CONTROL FIRST: the list fields are still there. The detail projection ADDS to
    // `SourceResource` rather than replacing it, and a client reading the detail must not have to
    // know that.
    $response->assertJsonPath('data.id', $source->id)
        ->assertJsonPath('data.name', 'ALPHA handbook')
        ->assertJsonPath('data.status', SourceState::Ready->value)
        ->assertJsonPath('data.status_permits_retrieval', true);

    // THE COUNTS, ALL OVER THE LIVE VERSION ONLY. The retired version contributed one element and
    // one chunk and neither appears here.
    $response->assertJsonPath('data.item_count', 1)
        ->assertJsonPath('data.active_version_count', 1)
        ->assertJsonPath('data.element_count', 3)
        ->assertJsonPath('data.chunk_count', 2)
        ->assertJsonPath('data.page_count', 2)
        ->assertJsonPath('data.slide_count', 0)
        ->assertJsonPath('data.sheet_count', 0);

    // THE LIVE VERSION, NAMED, because this source has exactly ONE item.
    $response->assertJsonPath('data.active_version.id', $live->id)
        ->assertJsonPath('data.active_version.source_item_id', $item->id)
        ->assertJsonPath('data.active_version.version_number', 2)
        ->assertJsonPath('data.active_version.status', SourceState::ReadyWithWarnings->value)
        ->assertJsonPath('data.active_version.ocr_cfg_version', 'ocr/v1:rapidocr');

    // THE WARNINGS ARE CODES AND COUNTS AND NOT THE VALUES BEHIND THEM. `warning_summary`'s key set
    // belongs to the data plane and its value shapes are unenumerated on this side, so publishing
    // the values would put an unbounded, unschema'd, tenant-derived blob on an admin page.
    $response->assertJsonPath('data.warnings.0.code', 'ocr_text_unplaced')
        ->assertJsonPath('data.warnings.0.versions', 1)
        ->assertJsonPath('data.warnings_truncated', false);

    expect(str_contains((string) $response->getContent(), '"ocr_text_unplaced":3'))
        ->toBeFalse('the warning VALUE was published, and only the key and a count may be');

    // THE PREVIEW IS THE LIVE ELEMENTS IN DOCUMENT ORDER, and the retired version's paragraph is
    // absent from it for the same reason it is absent from the counts.
    $preview = (string) $response->json('data.content_preview');

    expect($preview)->toBe(implode("\n\n", [
        'Refunds are accepted for 30 days.',
        'Exchanges are accepted for 60 days.',
        'Contact support for anything else.',
    ]));

    expect(str_contains($preview, 'nobody can retrieve'))->toBeFalse(
        'the preview reached a version no item points at, so it is showing content nothing can '
        .'retrieve as though it were the document',
    );

    $response->assertJsonPath('data.content_preview_truncated', false);

    // AND THE SECOND ELEMENT IS IN THE PREVIEW WHILE CONTRIBUTING NO CHUNK, which is what makes
    // `element_count` and `chunk_count` different numbers rather than two names for one.
    expect(str_contains($preview, 'Exchanges'))->toBeTrue();
});

it('reports zeroes and a null preview for a source whose ingestion has never completed', function (): void {
    // THE STATE ALMOST EVERY SOURCE IS IN FOR ITS FIRST MINUTE, and the one a projection validated
    // only against a populated fixture would describe wrongly. There is an item and a version; the
    // item points at nothing, so nothing is retrievable and every number is zero — even though rows
    // exist in `document_elements` and `chunks`.
    $fixture = detailFixture();
    $org = $fixture['org'];

    $source = KnowledgeSource::factory()->recycle($org)
        ->status(SourceState::Parsing)->create(['name' => 'ALPHA in flight']);

    $item = detailItem($org, $source, 'upload:in-flight.pdf');
    $pending = detailVersion($org, $item, 1, activate: false);
    $element = detailElement($org, $pending, 0, 'Not published yet.', page: 1);
    detailChunk($org, $source, $item, $pending, $element, 0);

    SpaSession::establish(currentTest(), $fixture['owner']);

    currentTest()->getJson("/api/v1/organizations/{$org->id}/sources/{$source->id}", spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.item_count', 1)
        ->assertJsonPath('data.active_version_count', 0)
        // NULL BECAUSE NOTHING IS LIVE, not because the source has more than one item. The two
        // reasons are different and `active_version_count` is what tells them apart.
        ->assertJsonPath('data.active_version', null)
        ->assertJsonPath('data.element_count', 0)
        ->assertJsonPath('data.chunk_count', 0)
        ->assertJsonPath('data.page_count', 0)
        ->assertJsonPath('data.warnings', [])
        // NULL AND NOT AN EMPTY STRING. "Nothing has been extracted yet" and "the first element is
        // blank" are different facts, and two spellings of the first is a branch every renderer has
        // to have and one of them forgets.
        ->assertJsonPath('data.content_preview', null)
        ->assertJsonPath('data.content_preview_truncated', false);
});

it('sums locators per version for a multi-item source and refuses to name one active version', function (): void {
    // A CRAWL, OR A MULTI-FILE UPLOAD. `count(distinct page)` over the whole set would collapse
    // page 1 of the first item into page 1 of the second and report two; grouping per version and
    // summing after reports four, which is the number of pages there actually are. For the ordinary
    // single-item source the two are identical, which is why the wrong one is easy to ship.
    $fixture = detailFixture();
    $org = $fixture['org'];

    $source = KnowledgeSource::factory()->recycle($org)->crawl('https://docs.example.com')
        ->status(SourceState::Ready)->create(['name' => 'ALPHA site']);

    foreach (['page-one', 'page-two'] as $index => $key) {
        $item = detailItem($org, $source, 'crawl:'.$key);
        $version = detailVersion($org, $item, 1, activate: true, warnings: ['html_boilerplate_stripped' => true]);

        foreach ([1, 2] as $page) {
            $element = detailElement(
                $org,
                $version,
                $page - 1,
                "Content of {$key} page {$page}.",
                page: $page,
            );

            detailChunk($org, $source, $item, $version, $element, $page - 1);
        }

        expect($index)->toBeLessThan(2);
    }

    SpaSession::establish(currentTest(), $fixture['owner']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$org->id}/sources/{$source->id}",
        spaHeaders(),
    )->assertOk();

    $response->assertJsonPath('data.item_count', 2)
        ->assertJsonPath('data.active_version_count', 2)
        // TWO PAGES PER VERSION, SUMMED. A global `count(distinct page)` would say 2.
        ->assertJsonPath('data.page_count', 4)
        ->assertJsonPath('data.element_count', 4)
        ->assertJsonPath('data.chunk_count', 4)
        // NULL BECAUSE THE SOURCE HAS MORE THAN ONE ITEM. Activation is a pointer on the ITEM and
        // there is no source-level counterpart, so "the current version of this source" is a set
        // rather than a value — `SourceResource`'s docblock states exactly that, and this shape
        // does not contradict it.
        ->assertJsonPath('data.active_version', null)
        // ONE CODE, TWO VERSIONS. The count is of VERSIONS carrying the key, which is what makes it
        // say "both crawled pages had boilerplate stripped" rather than "there was a warning".
        ->assertJsonPath('data.warnings.0.code', 'html_boilerplate_stripped')
        ->assertJsonPath('data.warnings.0.versions', 2);
});

it('bounds the preview, in the number of elements and in characters', function (): void {
    /*
     * THE PREVIEW IS TENANT-AUTHORED CONTENT ON A PAGE AN ADMINISTRATOR READS (non-negotiable 7),
     * and it is bounded in the QUERY rather than only in PHP: one serialized table can be megabytes,
     * so a row cap alone would still pull the whole column across the wire. Both bounds are asserted
     * here because each hides the other's absence — a fixture with few long elements exercises only
     * the character cap, and a fixture with many short ones only the element cap.
     */
    $fixture = detailFixture();
    $org = $fixture['org'];

    $source = KnowledgeSource::factory()->recycle($org)
        ->status(SourceState::Ready)->create(['name' => 'ALPHA long document']);

    $item = detailItem($org, $source, 'upload:long.pdf');
    $live = detailVersion($org, $item, 1, activate: true);

    // ONE ELEMENT LONGER THAN THE WHOLE PREVIEW, so the character cap is what cuts it, and then
    // enough further elements that the element cap would also have been reached.
    detailElement($org, $live, 0, str_repeat('A', 4000), page: 1);

    for ($seq = 1; $seq <= 40; $seq++) {
        detailElement($org, $live, $seq, "Paragraph {$seq}.", page: 1);
    }

    SpaSession::establish(currentTest(), $fixture['owner']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$org->id}/sources/{$source->id}",
        spaHeaders(),
    )->assertOk();

    $preview = (string) $response->json('data.content_preview');

    // BOUNDED IN CHARACTERS. The exact ceiling is the repository's constant and is deliberately not
    // restated here — what this asserts is that a 4,000-character element does not come back whole
    // and that what does come back is a prefix of it.
    expect(mb_strlen($preview))->toBeLessThan(4000)
        ->and(mb_strlen($preview))->toBeGreaterThan(0)
        ->and(str_starts_with($preview, 'AAAA'))->toBeTrue();

    $response->assertJsonPath('data.content_preview_truncated', true);

    // AND THE ELEMENT COUNT PROVES THE READ WAS BOUNDED RATHER THAN THE STRING MERELY TRIMMED: 41
    // elements exist, the preview reached the cap long before them, and `element_count` still
    // reports all of them because it is an aggregate rather than a read of rows.
    $response->assertJsonPath('data.element_count', 41);

    // THE ELEMENT CAP ON ITS OWN, with elements short enough that the character cap cannot fire.
    $shortSource = KnowledgeSource::factory()->recycle($org)
        ->status(SourceState::Ready)->create(['name' => 'ALPHA many short paragraphs']);

    $shortItem = detailItem($org, $shortSource, 'upload:short.pdf');
    $shortLive = detailVersion($org, $shortItem, 1, activate: true);

    for ($seq = 0; $seq < 40; $seq++) {
        detailElement($org, $shortLive, $seq, 'p'.$seq, page: 1);
    }

    $shortResponse = currentTest()->getJson(
        "/api/v1/organizations/{$org->id}/sources/{$shortSource->id}",
        spaHeaders(),
    )->assertOk();

    $shortPreview = (string) $shortResponse->json('data.content_preview');

    // Forty tiny paragraphs joined by a blank line are nowhere near the character cap, so the only
    // thing that can have cut this is the element cap — and the flag has to say so, or a console
    // renders forty paragraphs' worth of ellipsis-free text that is not the document.
    expect(mb_strlen($shortPreview))->toBeLessThan(500);
    $shortResponse->assertJsonPath('data.content_preview_truncated', true);
    $shortResponse->assertJsonPath('data.element_count', 40);
});

it('leaves the source LIST untouched, so a page of sources is not a hundred queries', function (): void {
    // THE DETAIL PROJECTION IS FOUR EXTRA STATEMENTS PER SOURCE. Folding them into the list would
    // turn a page of twenty-five into a hundred and one, which is why `SourceResource` publishes no
    // counts and `SourceDetailResource` is a separate component. This asserts the split is real
    // rather than merely intended.
    $fixture = detailFixture();
    $org = $fixture['org'];

    $source = KnowledgeSource::factory()->recycle($org)
        ->status(SourceState::Ready)->create(['name' => 'ALPHA listed']);

    $item = detailItem($org, $source, 'upload:listed.pdf');
    $live = detailVersion($org, $item, 1, activate: true);
    $element = detailElement($org, $live, 0, 'Listed content.', page: 1);
    detailChunk($org, $source, $item, $live, $element, 0);

    SpaSession::establish(currentTest(), $fixture['owner']);

    $row = (array) currentTest()->getJson("/api/v1/organizations/{$org->id}/sources", spaHeaders())
        ->assertOk()
        ->json('data.sources.0');

    foreach (['chunk_count', 'element_count', 'active_version', 'content_preview'] as $key) {
        expect(array_key_exists($key, $row))->toBeFalse(
            "the sources LIST published `{$key}`, so every page of the list now costs four "
            .'aggregate statements per row',
        );
    }

    // And the detail does carry it, so the absence above is a split rather than the projection
    // simply not working.
    currentTest()->getJson("/api/v1/organizations/{$org->id}/sources/{$source->id}", spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.chunk_count', 1);
});
