<?php

declare(strict_types=1);

namespace App\Services\Sources\Upload;

use Aws\Command;
use Aws\S3\MultipartUploader;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Stream one accepted upload into object storage.
 *
 * ── `MultipartUploader`, NEVER `Storage::put($key, file_get_contents($path))` ────────────────
 *
 * `seaweedfs-s3`'s Definition of done says it in those words, and the gotcha it comes from is
 * specific: "PHP dies with `Allowed memory size exhausted` on a file well under
 * `upload_max_filesize`" — because `Storage::put($key, $contents)` and `Storage::get($key)` both
 * materialise the whole object in PHP memory. `memory_limit` is per PROCESS, so the ceiling that
 * matters is not the 25 MB per-file cap but ten of them in one batch arriving at an FPM worker that
 * is also holding a request, a connection pool and an OTel span buffer.
 *
 * `MultipartUploader` reads the source stream in parts and uploads each one, so peak memory is the
 * part size and not the file size, whatever the file size turns out to be. It is also the only
 * uploader whose behaviour does not change if `StoreSourceRequest::MAX_FILE_KILOBYTES` is ever
 * raised past the 64 MiB threshold `seaweedfs-s3` sets for large files: today every accepted upload
 * is under that threshold and therefore a single-part multipart upload, which costs two extra round
 * trips and buys the property that raising the cap is a one-constant change rather than a rewrite.
 *
 * ── ABANDONED PARTS ARE SOMEBODY ELSE'S SWEEP, AND THAT IS DELIBERATE ───────────────────────
 *
 * A `MultipartUploader` that dies mid-upload leaves parts that `ListObjectsV2` cannot see and that
 * `assert_prefix_empty()` therefore cannot see either. `seaweedfs-s3` names the one reclaimer —
 * the `sweep-abandoned-multipart-uploads` Celery beat entry, daily, aborting uploads older than
 * 24 h — and explicitly rules out a bucket lifecycle rule, which is a silent no-op without an
 * admin server and a lifecycle worker. Nothing is added here: a second reclaimer racing a 24-hour
 * one would abort uploads that are still running.
 *
 * ── THE FILESYSTEM FALLBACK IS FOR `Storage::fake('s3')` AND FOR NOTHING ELSE ───────────────
 *
 * `Storage::fake('s3')` swaps the disk for a LOCAL one, which has no `getClient()` and no S3 API
 * behind it — so a writer that only knew `MultipartUploader` could not be exercised by a Feature
 * test at all, and the alternative (binding an interface and swapping it per test) means the
 * production path is the one nothing ever runs.
 *
 * SO THE BRANCH IS ON THE ADAPTER TYPE AND IT IS STATED RATHER THAN HIDDEN. `AwsS3V3Adapter` is
 * the only adapter a real `s3` disk can produce, so in every deployed configuration the first
 * branch is taken; the second exists because a fake disk is a fake disk. IT IS NOT A DEGRADED
 * SECURITY MODE — both branches STREAM (`writeStream()` does not buffer either), both write the
 * same generated key, and neither one has an opinion about what the bytes are. Contrast
 * `UploadIntake::sniff()`, which refuses to fall back at all: there the fallback would be a
 * DIFFERENT AND WEAKER CHECK, and here it is the same write through a different driver.
 *
 * `tests/Arch/StringLevelDoctrineTest.php` rule 6 asserts this file names `MultipartUploader` and
 * `AwsS3V3Adapter` in CODE rather than in this docblock, and that the upload path names neither
 * `file_get_contents` nor `readfile`, so the rule survives an edit that reads as a
 * simplification.
 *
 * ── INTEGRITY IS OUR SHA-256 IN POSTGRESQL, NEVER THE ETAG ─────────────────────────────────
 *
 * This method returns nothing and reads no ETag. `seaweedfs-s3` non-negotiable 5, and the reason is
 * that SeaweedFS's ETag semantics differ from AWS's, from MinIO's, AND from SeaweedFS's own by
 * write path: hex MD5 for a single-part PUT even when it internally autochunks, `{md5}-N` where N
 * is the FILER CHUNK COUNT for objects written through other paths, and AWS-compatible
 * `md5(concat(part md5s))-N` for multipart — correct only since June 2026. The value that decides
 * whether these bytes are the bytes we accepted is `source_items.content_hash`, computed by step 6
 * of the intake before this class is called, and re-verified by `get_verified()` every time the data
 * plane reads the object.
 */
final class SourceObjectWriter
{
    /**
     * 64 MiB, from `seaweedfs-s3` — "Threshold 64 MiB, part size 64 MiB", the same arithmetic
     * `TransferConfig(multipart_threshold=64<<20, multipart_chunksize=64<<20)` states on the boto3
     * side. Keeping the two equal is what makes a file that crosses the threshold on one runtime
     * cross it on the other.
     */
    private const PART_BYTES = 64 * 1024 * 1024;

    /**
     * Write `$upload`'s bytes to `$key`. The key is built by `ObjectKey` and is never derived from
     * anything the client sent.
     *
     * ── THE OBJECT IS WRITTEN BEFORE THE ROWS EXIST, AND THAT ORDERING IS CHOSEN ────────────
     *
     * Object storage does not participate in a PostgreSQL transaction, so the only two orderings are
     * "orphan object, no row" and "row pointing at nothing". `SourceService::create()` already
     * argues the same choice for a pasted body and it holds identically here: the orphan is a
     * byte-for-byte-identical object at a content-addressed key that the next attempt overwrites and
     * a sweep can collect, while the second is a source whose ingestion fails on every attempt with
     * `error_class: storage` and needs an operator.
     */
    public function write(string $key, AcceptedUpload $upload): void
    {
        $disk = Storage::disk('s3');

        $stream = fopen($upload->localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException(
                'The temporary upload could not be opened for reading. Nothing has been stored and no '
                    .'row has been written, so the request fails whole rather than leaving a source '
                    .'pointing at an object that is not there.',
            );
        }

        try {
            if ($disk instanceof AwsS3V3Adapter) {
                (new MultipartUploader($disk->getClient(), $stream, [
                    'bucket' => (string) config('filesystems.disks.s3.bucket'),
                    'key' => $key,
                    'part_size' => self::PART_BYTES,
                    // THE SNIFFED TYPE, which is the only type anything on this path has established.
                    // It is metadata on the object and NOT what decides how the file is served back:
                    // `seaweedfs-s3` routes every download through a Laravel route that sets
                    // `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff` from a
                    // separate user-content origin, because a polyglot is right about its first
                    // format and dangerous about its second.
                    'before_initiate' => static function (Command $command) use ($upload): void {
                        $command['ContentType'] = $upload->mime;
                    },
                ]))->upload();

                return;
            }

            // The fake-disk branch. See the class docblock — same key, same streaming, different
            // driver, and no security property differs between the two.
            $disk->writeStream($key, $stream);
        } finally {
            // `MultipartUploader` does not close the stream it was handed, and `writeStream()` closes
            // it only on some drivers. Closing an already-closed resource is a warning rather than an
            // error, so the guard is on the resource still being one.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
