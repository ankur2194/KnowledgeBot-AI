<?php

declare(strict_types=1);

namespace App\Services\Sources\Upload;

/**
 * One file that passed every step of the intake gate, described by what the gate ESTABLISHED.
 *
 * ── NOTHING ON THIS OBJECT CAME FROM THE CLIENT UNCHECKED ────────────────────────────────────
 *
 * `mime` is the sniffed type and never the request's `Content-Type` (`kb-security-baseline` §18.7
 * refuses the client's declaration outright). `byteSize` is PHP's count of the bytes actually
 * written to the temporary part, not a `Content-Length`. `contentHash` was computed over those same
 * bytes. `displayName` is the one tenant-authored value here, NFKC-normalized and proven to be a
 * bare filename, and it is bound for `source_items.display_name` — a column, never a path.
 *
 * ── `localPath` IS THE ONLY MUTABLE THING IN SIGHT, AND IT HAS A LIFETIME ────────────────────
 *
 * PHP deletes the temporary upload at the end of the request, so this object is valid for the
 * duration of the request that built it and no longer. It is never queued, never cached and never
 * put in a job payload — `IngestionSubmission` carries the STORAGE KEY, and its docblock records
 * why the bytes never travel: a document body in a submission body is a document body in a Valkey
 * job payload, in `failed_jobs`, and in every span that instruments request bodies.
 */
final readonly class AcceptedUpload
{
    /**
     * @param  string  $displayName  the user's filename, normalized, for `source_items.display_name`
     * @param  string  $extension  the lowercase final extension the name was accepted under
     * @param  string  $mime  the type libmagic read out of the bytes
     * @param  int  $byteSize  the size of the stored object, in bytes
     * @param  string  $contentHash  sha256 hexdigest of the raw bytes, lowercase, 64 characters —
     *                               the value `source_items_content_hash_is_hex` checks and the
     *                               value `get_verified()` compares against on the data-plane side
     * @param  string  $localPath  the temporary part PHP wrote; valid for this request only
     */
    public function __construct(
        public string $displayName,
        public string $extension,
        public string $mime,
        public int $byteSize,
        public string $contentHash,
        public string $localPath,
    ) {}
}
