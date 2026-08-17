<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that remembers, so "the drop is observable" and "the audit-write failure is not
 * silent" are assertions rather than hopes.
 *
 * PSR-3 rather than Log::spy(): tests/Unit has no container and no facades (tests/Pest.php), and the
 * class under test takes a LoggerInterface for exactly this reason.
 *
 * Only messages and levels are kept. The CONTEXT is deliberately not asserted on here — what a
 * context array may contain is KbJsonFormatter::ALLOWED_EXTRA_FIELDS' business and
 * tests/Unit/KbJsonFormatterTest.php's, and duplicating that vocabulary in a second place is how the
 * two drift.
 *
 * In tests/Support/ rather than in the test file for the PSR-4 reason KbSecretFixtures documents.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $lines = [];

    /**
     * @param  mixed  $level
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = [
            'level' => is_scalar($level) ? (string) $level : 'unknown',
            'message' => (string) $message,
        ];
    }

    /**
     * @return list<string>
     */
    public function messagesAt(string $level): array
    {
        $out = [];

        foreach ($this->lines as $line) {
            if ($line['level'] === $level) {
                $out[] = $line['message'];
            }
        }

        return $out;
    }
}
