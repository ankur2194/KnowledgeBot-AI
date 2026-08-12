<?php

declare(strict_types=1);

use App\Services\Embedding\EmbeddingCandidate;
use App\Services\Embedding\EmbeddingReadiness;

/*
|--------------------------------------------------------------------------
| The verdict object (finding C1)
|--------------------------------------------------------------------------
|
| No framework boot: this is a parser and an invariant, and neither needs a container.
|
| WHAT IS NOT TESTED HERE, DELIBERATELY: the resolution RULE. Whether two eligible connections
| disagreeing on (provider, model) is a hard failure, whether an unknown vendor fails closed,
| whether the tiebreak is total — all of that lives in
| services/ai-service/app/providers/embedding_selection.py and is tested there. A test of it here
| would be a second specification of the rule, and the whole point of the round trip is that there
| is only one.
*/

it('parses a ready verdict, and the selected pair is the vector space', function (): void {
    $readiness = EmbeddingReadiness::fromResponse([
        'selected' => [
            'connection_id' => '01JQZ0000000000000000000AA',
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'caps' => ['supported' => ['embedding'], 'context_window' => 8192, 'max_output_tokens' => 0],
        ],
        'eligible' => [[
            'connection_id' => '01JQZ0000000000000000000AA',
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'caps' => ['supported' => ['embedding'], 'context_window' => 8192, 'max_output_tokens' => 0],
        ]],
        'rejected' => [],
        'explanation' => '',
    ]);

    expect($readiness->isReady())->toBeTrue()
        ->and($readiness->selected)->toBeInstanceOf(EmbeddingCandidate::class);

    // provider AND model, because the PAIR is what EmbeddingSpace derives the collection name
    // from. A test that asserted only the connection id would pass while the model moved.
    expect($readiness->selected?->provider)->toBe('openai')
        ->and($readiness->selected?->model)->toBe('text-embedding-3-large')
        ->and($readiness->selected?->connectionId)->toBe('01JQZ0000000000000000000AA');
});

it('keeps every rejection even on a ready verdict', function (): void {
    // An operator asking "why is my Anthropic key not being used" needs the answer whether or not
    // some other connection saved the day. Dropping rejections on success is the small
    // simplification that makes the screen unable to explain itself.
    $readiness = EmbeddingReadiness::fromResponse([
        'selected' => [
            'connection_id' => '01JQZ0000000000000000000AA',
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'caps' => ['supported' => ['embedding'], 'context_window' => 8192, 'max_output_tokens' => 0],
        ],
        'rejected' => [[
            'connection_id' => '01JQZ0000000000000000000BB',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reason' => 'vendor_has_no_endpoint',
            'detail' => 'capabilities.PROVIDER_TASKS records anthropic as unverified',
        ]],
        'explanation' => '',
    ]);

    expect($readiness->rejected)->toHaveCount(1)
        ->and($readiness->rejected[0]->reason)->toBe('vendor_has_no_endpoint')
        ->and($readiness->rejected[0]->provider)->toBe('anthropic');
});

it('parses an unselected verdict and keeps the explanation verbatim', function (): void {
    // Verbatim matters: the upload path raises this same string, so the banner and the error must
    // say the same words. A paraphrase on this side is a second copy that drifts.
    $explanation = 'This organization has no embedding-capable provider connection, so it cannot '
        .'ingest any document.';

    $readiness = EmbeddingReadiness::fromResponse([
        'selected' => null,
        'eligible' => [],
        'rejected' => [],
        'explanation' => $explanation,
    ]);

    expect($readiness->isReady())->toBeFalse()
        ->and($readiness->explanation)->toBe($explanation);
});

it('refuses a verdict that is both selected and explained', function (): void {
    // The invariant the data-plane model enforces, enforced again on this side. A readiness that
    // carried both would let a caller draw the blocking banner AND proceed to embed.
    expect(fn (): EmbeddingReadiness => EmbeddingReadiness::fromResponse([
        'selected' => [
            'connection_id' => '01JQZ0000000000000000000AA',
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'caps' => ['supported' => ['embedding'], 'context_window' => 8192, 'max_output_tokens' => 0],
        ],
        'explanation' => 'but also this organization cannot embed',
    ]))->toThrow(\RuntimeException::class);
});

it('refuses a verdict that is neither selected nor explained', function (): void {
    // The other half of the same invariant. An unselected readiness with no explanation renders a
    // blocking banner with no text in it, which an operator reads as a UI bug.
    expect(fn (): EmbeddingReadiness => EmbeddingReadiness::fromResponse([
        'selected' => null,
        'explanation' => '',
    ]))->toThrow(\RuntimeException::class);
});
