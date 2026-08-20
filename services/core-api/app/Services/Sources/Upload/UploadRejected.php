<?php

declare(strict_types=1);

namespace App\Services\Sources\Upload;

use App\Enums\UploadRejectionReason;
use RuntimeException;

/**
 * One file refused by one step of the intake gate.
 *
 * ── IT CARRIES THE AUDIT ROW'S FIELDS, NOT JUST A MESSAGE ────────────────────────────────────
 *
 * `source.upload.rejected` publishes `display_name`, `mime`, `byte_size` and `reason`, and every one
 * of those has to be true of THE MOMENT OF REFUSAL rather than reconstructed afterwards: `mime` is
 * null when the refusal happened before sniffing (a size rejection reads the header and stops), and
 * a caller that re-derived it would either sniff a file it had already refused — running libmagic
 * over bytes the gate exists to keep away from a library — or write a null it had not established.
 * `AuditLogger::sanitize()` skips a null silently, so the key's ABSENCE is the meaningful thing and
 * this object is what preserves it.
 *
 * ── THE MESSAGE IS FOR THE OPERATOR AND NEVER FOR THE AUDIT ROW ──────────────────────────────
 *
 * `getMessage()` reaches the 422's per-file `errors` map, where an admin reads it. It never reaches
 * `details` — `reason` does, as a closed token — for the reason `AuditLogger` states at the
 * constant: a message is unbounded, varies by library version, and can echo a parser's reading of a
 * hostile file into an append-only table.
 *
 * ── IT NAMES NO PATH ─────────────────────────────────────────────────────────────────────────
 *
 * Not the temporary path PHP wrote the part to, not the storage key it would have been given. A
 * refusal message is rendered in a console and may be copied into a ticket; a message that quotes an
 * absolute filesystem path is an infrastructure detail leaving the boundary for no benefit at all.
 */
final class UploadRejected extends RuntimeException
{
    /**
     * @param  string  $displayName  the name the file was offered under, NFKC-normalized where the
     *                               gate got far enough to normalize it and raw where it did not
     * @param  string|null  $sniffedMime  what libmagic read out of the bytes, or null when the
     *                                    refusal preceded the sniff
     * @param  int|null  $byteSize  as reported by PHP for the part, or null when PHP itself refused
     *                              the part and there is no size to report
     */
    public function __construct(
        public readonly UploadRejectionReason $reason,
        public readonly string $displayName,
        public readonly ?string $sniffedMime,
        public readonly ?int $byteSize,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The `details` payload for `AuditLogger::SOURCE_UPLOAD_REJECTED`, assembled here so the four
     * fields and their null rules live beside the object that knows them.
     *
     * @return array<string, mixed>
     */
    public function auditDetails(): array
    {
        return [
            'display_name' => $this->displayName,
            'mime' => $this->sniffedMime,
            'byte_size' => $this->byteSize,
            'reason' => $this->reason->value,
        ];
    }
}
