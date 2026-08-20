<?php

declare(strict_types=1);

namespace App\Services\Sources\Upload;

use App\Enums\UploadRejectionReason;
use finfo;
use Illuminate\Http\UploadedFile;
use Normalizer;
use RuntimeException;
use ZipArchive;

/**
 * THE INTAKE GATE. Six steps, in one order, and the order is the security property.
 *
 * ═══ WHY THE ORDER IS THE PROPERTY AND NOT A STYLE ══════════════════════════════════════════
 *
 * `kb-security-baseline/references/file-upload-safety.md` numbers these steps, and every pair of
 * adjacent ones is adjacent for a reason that stops being true if they swap:
 *
 *   1 SIZE, FIRST.        "Rejecting a 4 GB upload after writing it to disk is not a rejection."
 *                         Everything below reads the file — hashing it, sniffing it, walking its
 *                         central directory — so a size check underneath any of them is a size
 *                         check that has already paid for the thing it was going to refuse.
 *   2 EXTENSION, ON THE   NORMALIZE BEFORE VALIDATING, NEVER AFTER. Full-width solidus U+FF0F and
 *     NFKC-NORMALIZED     full-width stop U+FF0E are not `/` or `.` — until NFKC. So
 *     NAME, FINAL         `．．／．．／etc/passwd` passes `".." not in name` and BECOMES
 *     EXTENSION ONLY.     `../../etc/passwd` the moment anything downstream normalizes it.
 *                         Validating first and normalizing second is how a rejected name becomes an
 *                         accepted one, silently, with both checks reading as correct.
 *   3 MIME FROM CONTENT.  Never the client's `Content-Type`, never the extension. Both are
 *                         attacker-controlled. libmagic reads the bytes.
 *   4 CROSS-CHECK.        A mismatch is A REJECTION, not a preference for one signal over the
 *                         other. "A `.xlsx` sniffing as `application/x-dosexec` is a rejection, not
 *                         a warning."
 *   5 OPC ACTIVE CONTENT. `vbaProject.bin` or an `/embeddings/` part, whatever the outer extension —
 *                         a `.docx` can legally carry both. Runs after 4 because it needs to know
 *                         which package this is claiming to be.
 *   6 CONTENT HASH.       For §8.10 duplicate detection and as the reprocessing idempotency key.
 *                         LAST, because it is the only step that reads every byte, and reading every
 *                         byte of a file five earlier steps would have refused is the cost the
 *                         ordering exists to avoid.
 *
 * The decompression caps run inside step 5, before any part name is looked at — see
 * `assertPackageIsSafe()`, and `UploadRejectionReason::ArchiveBomb` for why they are their own
 * audited reason rather than filed under one of the six.
 *
 * A TEST ASSERTS THE ORDER RATHER THAN THE OUTCOME. A file that fails BOTH the extension check and
 * the sniff must be refused by the extension check — `UploadIntakeGateTest` reads the `reason` off
 * the `source.upload.rejected` audit row, so reordering two steps fails the suite even though every
 * refusal is still a refusal.
 *
 * ═══ THE THREE SIZE CHECKS, AND WHY THIS ONE IS NOT REDUNDANT ═══════════════════════════════
 *
 * Step 1 here is the THIRD statement of the per-file ceiling, and the first two are outside this
 * class: PHP's own `upload_max_filesize`/`post_max_size` refuse the part before Laravel is
 * dispatched at all (and `UploadedFile::isValid()` is how that arrives — see below), and
 * `StoreSourceRequest`'s `files.*` `max:` rule runs before the controller body. It is restated here
 * because this class is a SERVICE: nothing in it may assume a particular caller ran a particular
 * FormRequest, and a gate whose first step is somebody else's responsibility has five steps.
 * `kb-security-baseline` also puts the first limit at Traefik, which is `platform-devops-engineer`'s
 * and is the only one of the four that refuses before the body is buffered anywhere.
 *
 * ═══ libmagic IS REQUIRED AND THERE IS NO FALLBACK PATH ═════════════════════════════════════
 *
 * `sniff()` throws a `RuntimeException` — a 500, `internal_dependency` — if `ext-fileinfo` is not
 * loaded. IT DOES NOT FALL BACK TO THE EXTENSION, and that refusal is the entire reason this class
 * exists: an extension-derived MIME is the client's claim wearing the server's voice, and a
 * deployment that quietly degraded to it would pass every test in this repository while enforcing
 * nothing. Failing closed makes a missing extension an outage, which is loud, instead of a silent
 * downgrade of the one check §8.10 requires explicitly. The Dockerfile asserts `fileinfo` and `zip`
 * at build time for the same reason it asserts `pdo_pgsql`.
 *
 * ═══ WHAT THIS CLASS DOES NOT DO ════════════════════════════════════════════════════════════
 *
 * It does not decompress anything. It reads a ZIP central directory to enumerate part names, so
 * every cap it enforces is read from a structure the file's author wrote —
 * `kb-security-baseline`'s layers 1 and 2, both self-reported. THE AUTHORITATIVE THIRD LAYER — count
 * the bytes as they come out of the decompression — DOES NOT EXIST ANYWHERE IN THIS REPOSITORY
 * TODAY. It belongs where the decompression happens, which is the Celery parser, and it is OWED
 * rather than delegated: `grep -rn 'ZipFile\|infolist\|compress_size' services/ai-service/app`
 * returns nothing, so the sentence this paragraph used to carry — that the cap "belongs" to the
 * parser — read as a statement that it was implemented there. Do not read the caps below as backed
 * by a check further down the pipeline until that check is written; they are the only ones running.
 * Owner: `ingestion-engineer`, reported as security finding S2 and not fixable from this side.
 *
 * It does not parse XML. Not `[Content_Types].xml`, not a worksheet — an XML parser over a hostile
 * archive is the XXE surface the data plane pins `lxml >= 6.1.0` and `defusedxml` to survive, and
 * bringing it into the control plane to answer "is this really a .docx" would trade a name
 * comparison for a CVE class.
 *
 * It does not scan for malware. `kb-security-baseline` wires clamd as an integration point and
 * budgets it as the last of seven controls; nothing here runs one, and `UploadRejectionReason` has
 * no token for a control that does not exist.
 *
 * It does not write anything. Objects are written by `SourceObjectWriter`, after the whole batch has
 * passed, so a batch with one bad file leaves no bytes behind.
 */
final class UploadIntake
{
    /**
     * The longest filename this platform will echo.
     *
     * §22.5's fixture set includes "a 300-character name". `source_items.display_name` has no length
     * limit of its own and `AuditLogger::MAX_VALUE_LENGTH` would degrade an over-long value to a
     * fingerprint — which is the correct backstop and a poor primary, because the operator would
     * then be shown an audit row identifying their file by a hash. 255 is the POSIX `NAME_MAX` every
     * filesystem an operator's machine could have produced this name on already enforces, so a name
     * past it did not come from a file picker.
     */
    private const MAX_NAME_LENGTH = 255;

    /**
     * Screen one multipart batch. Nothing is written and nothing is decided about the source.
     *
     * @param  array<int, UploadedFile>  $files  keyed by the part index the client sent
     */
    public function screen(array $files): UploadScreening
    {
        $accepted = [];
        $rejected = [];

        foreach ($files as $index => $file) {
            try {
                $accepted[$index] = $this->admit($file);
            } catch (UploadRejected $refusal) {
                $rejected[$index] = $refusal;
            }
        }

        // ── STEP 6's OTHER HALF, AND IT IS A BATCH PROPERTY RATHER THAN A FILE PROPERTY ───────
        //
        // Run after every file has its hash, because two files can only be duplicates of each other
        // once both have been hashed. A file already refused for its own reason is not re-refused
        // here: its first refusal is the true one and is the one the operator has to fix.
        $seen = [];

        foreach ($accepted as $index => $upload) {
            $first = $seen[$upload->contentHash] ?? null;

            if ($first === null) {
                $seen[$upload->contentHash] = $index;

                continue;
            }

            unset($accepted[$index]);

            $rejected[$index] = new UploadRejected(
                UploadRejectionReason::Duplicate,
                $upload->displayName,
                $upload->mime,
                $upload->byteSize,
                'This file is byte-for-byte identical to `files.'.$first.'` in the same request. Both '
                    .'would be stored at one content-addressed key inside this source, so the second '
                    .'would silently overwrite the first and leave two items pointing at one object '
                    .'with no reference count. Upload it once.',
            );
        }

        return new UploadScreening($accepted, $rejected);
    }

    /**
     * The gate, for one file. Every `throw` below is a step, and they are in order.
     *
     * @throws UploadRejected
     */
    private function admit(UploadedFile $file): AcceptedUpload
    {
        // ── STEP 1: SIZE ────────────────────────────────────────────────────────────────────
        $byteSize = $this->assertSizeIsWithinLimit($file);

        // ── STEP 2: THE NAME, NORMALIZED FIRST AND VALIDATED SECOND ─────────────────────────
        $displayName = $this->normalize($file->getClientOriginalName());
        $extension = $this->assertExtensionIsAllowed($displayName, $byteSize);

        // ── STEP 3: THE TYPE, READ OUT OF THE BYTES ─────────────────────────────────────────
        $path = $this->realPath($file);
        $mime = $this->sniff($path);

        $this->assertMimeIsAllowed($displayName, $mime, $byteSize);

        // ── STEP 4: THE CROSS-CHECK ─────────────────────────────────────────────────────────
        $this->assertMimeMatchesExtension($displayName, $extension, $mime, $byteSize);

        // ── STEP 5: THE PACKAGE, IF IT IS ONE ───────────────────────────────────────────────
        $this->assertPackageIsSafe($path, $displayName, $extension, $mime, $byteSize);

        // ── STEP 6: THE CONTENT HASH ────────────────────────────────────────────────────────
        //
        // `hash_file` streams the file in blocks; it never materialises the body in PHP memory, which
        // is the same property `MultipartUploader` is chosen for one class over. Lowercase hex, which
        // is what `source_items_content_hash_is_hex` requires and what the data plane's
        // `sha256(...).hexdigest()` produces on the other side of the seam.
        $contentHash = hash_file('sha256', $path);

        if ($contentHash === false) {
            throw new RuntimeException(
                'The temporary upload could not be read for hashing. Every step above passed, so this '
                    .'is a storage failure rather than a refusal, and it must not be recorded as one: '
                    .'an integrity value we could not compute is the one thing this path may never '
                    .'guess at, because `source_items.content_hash` is what the data plane verifies '
                    .'the object against on every read.',
            );
        }

        return new AcceptedUpload(
            displayName: $displayName,
            extension: $extension,
            mime: $mime,
            byteSize: $byteSize,
            contentHash: $contentHash,
            localPath: $path,
        );
    }

    /**
     * STEP 1. Returns the size, because every later refusal has to audit it.
     *
     * ── `isValid()` IS THE SIZE CHECK PHP ALREADY RAN ───────────────────────────────────────
     *
     * A part that blew past `upload_max_filesize` arrives as an `UploadedFile` with
     * `UPLOAD_ERR_INI_SIZE` and NO BYTES ON DISK — `getSize()` on it is meaningless and
     * `getRealPath()` may not resolve. Treating that as anything other than a size rejection would
     * mean sniffing a file that is not there, so it is folded into step 1 rather than handled as an
     * error: the operator's remedy is identical, and the audit row's `byte_size` is null because
     * nothing here ever counted one.
     *
     * The other `UPLOAD_ERR_*` values (partial, no tmp dir, cannot write, extension halted) are not
     * size problems and are not the tenant's fault. They are re-raised as an infrastructure failure
     * rather than filed as a refusal, because "your file was rejected" is a lie when the disk was
     * full.
     *
     * @throws UploadRejected
     */
    private function assertSizeIsWithinLimit(UploadedFile $file): int
    {
        if (! $file->isValid()) {
            $error = $file->getError();

            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new UploadRejected(
                    UploadRejectionReason::Size,
                    $this->normalize($file->getClientOriginalName()),
                    null,
                    null,
                    'This file is larger than this deployment accepts and PHP refused the part before '
                        .'it finished arriving. The ceiling is '.UploadLimits::maxBytes().' bytes per '
                        .'file; read it from GET .../sources/upload-limits rather than hardcoding it.',
                );
            }

            throw new RuntimeException(
                'An uploaded part was not written successfully (PHP upload error '.$error.'). This is '
                    .'an infrastructure failure and not a property of the file, so it is not recorded '
                    .'as a rejection: reporting it as one would tell an operator their document was '
                    .'refused when the temporary directory was unwritable.',
            );
        }

        $byteSize = $file->getSize();

        if (! is_int($byteSize)) {
            throw new RuntimeException(
                'An uploaded part reported no size. Every later step of the intake gate audits '
                    .'`byte_size`, so a part whose size cannot be read is an infrastructure failure '
                    .'rather than a refusal.',
            );
        }

        if ($byteSize > UploadLimits::maxBytes()) {
            throw new UploadRejected(
                UploadRejectionReason::Size,
                $this->normalize($file->getClientOriginalName()),
                // NULL, AND DELIBERATELY. A size rejection reads the header and stops — this gate has
                // not sniffed anything, and writing a MIME here would be recording a fact nobody
                // established. `AuditLogger` names exactly this case at the operation.
                null,
                $byteSize,
                'This file is '.$byteSize.' bytes and the per-file ceiling is '.UploadLimits::maxBytes()
                    .' bytes. Nothing has been read out of it: the size limit runs first so that a '
                    .'file this platform will not keep is never hashed, sniffed or opened.',
            );
        }

        return $byteSize;
    }

    /**
     * STEP 2. The NFKC-normalized name must be a bare filename with an allow-listed final extension.
     *
     * ── WHAT "BARE FILENAME" REFUSES, AND WHAT SYMFONY HAS ALREADY DONE TO THE NAME ─────────
     *
     * AN ASCII `../evil.pdf` NEVER REACHES THIS METHOD, AND THIS PARAGRAPH USED TO CLAIM OTHERWISE.
     * `UploadedFile::getClientOriginalName()` returns a value Symfony's own `File::getName()`
     * already sanitized on construction — it replaces `\` with `/` and keeps only the segment after
     * the last `/` — so `../evil.pdf` arrives here as `evil.pdf`, passes the shape check, and IS
     * ACCEPTED. That is the right outcome for the wrong-sounding reason: the storage key is
     * generated (`ObjectKey`), the name is never a path, and `evil.pdf` is a perfectly ordinary
     * display value. `tests/Unit/UploadIntakeGateTest.php` states this at `intakeFile()`, and this
     * docblock now says what the test says. The earlier claim — that traversal "dies here" because
     * we refuse rather than calling `basename()` — described a refusal that never fires.
     *
     * WHAT THE SHAPE CHECK ACTUALLY CATCHES IS EVERYTHING SYMFONY'S SANITIZER CANNOT SEE, and the
     * FULL-WIDTH TRAVERSAL FIXTURE IS THE LOAD-BEARING ONE: `．．／evil.pdf` is U+FF0E U+FF0E U+FF0F,
     * which is not a separator to `getName()` and is not one to a `".." not in name` check either —
     * until NFKC turns it into `../evil.pdf`. Normalizing BEFORE validating is what puts it in front
     * of the shape check, and the shape check is what refuses it. Remove either half and a
     * `display_name` containing a path is written to a column, where
     * `source_items_display_name_is_not_a_path` turns a 422 into a 500 and any consumer that joins a
     * display name to a path has a traversal.
     *
     * REFUSED RATHER THAN STRIPPED, for what does reach here. `ObjectKey::segment()` one directory
     * over argues the general form: refusing structurally beats sanitizing, because a sanitized
     * value is a different value nobody was told about — and the ONLY thing this refusal protects is
     * the `display_name` column and whatever eventually renders it.
     *
     * The null-byte truncation `x.pdf\0.php`, `report.pdf:ads.exe`, and `evil.php.` with its
     * trailing dot all die here — the last of them because `pathinfo()` reports no extension for a
     * name ending in a separator dot, and `report.pdf ` with a trailing space dies because its
     * extension is the four characters `pdf ` and the allow-list is an exact-match map. `CON.pdf`
     * survives, correctly: Windows reserved device names are only dangerous to something that treats
     * the name as a path, and nothing here does.
     *
     * @throws UploadRejected
     */
    private function assertExtensionIsAllowed(string $displayName, int $byteSize): string
    {
        // BUILDS THE EXCEPTION, DOES NOT THROW IT, so every refusal below reads `throw $refuse(...)`
        // at the point it happens. A closure that threw would hide the control flow one level down
        // from the reader and one level down from the analyser.
        $refuse = static fn (string $message): UploadRejected => new UploadRejected(
            UploadRejectionReason::Extension,
            $displayName,
            // Null for the same reason as a size refusal: step 3 has not run.
            null,
            $byteSize,
            $message,
        );

        // ── VALID UTF-8, FIRST, BECAUSE EVERY CHECK BELOW READS THIS VALUE AS TEXT ───────────
        //
        // THIS CHECK IS HERE BECAUSE ITS ABSENCE WAS A REAL HOLE, AND THE COMMENT THAT USED TO SIT
        // FURTHER DOWN IS WHY NOBODY ADDED IT. That comment argued that a name which is not valid
        // UTF-8 "falls through to the extension lookup — which refuses it, because no allow-listed
        // extension survives invalid UTF-8 intact". THAT REASONING IS FALSE, and it is false for the
        // ordinary case rather than an exotic one: the bad bytes only have to land somewhere OTHER
        // than the extension. Measured, for `"\xFFreport.pdf"`:
        //
        //     Normalizer::normalize(…)             => false   (normalize() keeps the name as-is)
        //     mb_strlen(…)                         => 11      (passes the length check)
        //     preg_match('/[\/\\]|[[:cntrl:]]/')   => 0       (byte-mode, so it answers — passes)
        //     preg_match('/\p{Cf}/u')              => FALSE   (not 1, so it passes)
        //     pathinfo(…, PATHINFO_EXTENSION)      => 'pdf'   (pure ASCII, ALLOW-LISTED)
        //
        // The name is admitted, the batch is admitted, `SourceObjectWriter` writes the object, and
        // PostgreSQL then rejects the INSERT with `22021 invalid byte sequence for encoding "UTF8"`.
        // The transaction aborts and the request 500s — AFTER the bytes are in object storage, at
        // `org/{org}/sources/{sourceId}/original/{sha256}` under a source id that never became a
        // `knowledge_sources` row. That is exactly `ObjectKey`'s defect 1 arriving by a third road:
        // an object nothing can delete, in a prefix the phase-2 purge only visits for sources that
        // exist, which verification will certify clean over.
        //
        // WHY IT IS THE FIRST CHECK IN THE METHOD RATHER THAN THE LAST. Every one of the four lines
        // above is Unicode-aware and every one of them answers DIFFERENTLY for a byte sequence that
        // is not a string: `mb_strlen` counts by a substitution rule, a `/u` pattern returns `false`
        // rather than `0` — and `false !== 1` reads as "no match", which is fail-OPEN — and
        // `mb_strtolower` may substitute. Establishing validity first is what makes the three checks
        // below mean what they are written to mean, instead of each carrying its own escape hatch.
        //
        // `mb_check_encoding` AND NOT `Normalizer::normalize() === false`. The two agree on this
        // input, but they are not the same question: `normalize()` failing is one library's opinion
        // about one form, while validity is a property of the bytes and is the property PostgreSQL,
        // `json_encode` on the audit row, and every consumer of `display_name` actually require.
        //
        // THE `extension` TOKEN, and not one of its own: step 2 IS the name gate, which is the same
        // argument `UploadRejectionReason::Extension` already makes for the shape refusal.
        if (! mb_check_encoding($displayName, 'UTF-8')) {
            throw $refuse(
                'This filename is not valid UTF-8. It is not a length, a character or an extension '
                    .'problem: the bytes are not text at all, so nothing downstream can read the name '
                    .'the way it was meant — the database refuses the value outright, and an audit '
                    .'row could not carry it either. Rename the file and upload it again.',
            );
        }

        if ($displayName === '' || mb_strlen($displayName) > self::MAX_NAME_LENGTH) {
            throw $refuse(
                'A filename has to be between 1 and '.self::MAX_NAME_LENGTH.' characters. This one is '
                    .mb_strlen($displayName).' after Unicode normalization, which is past what any '
                    .'file picker can produce.',
            );
        }

        if (
            $displayName === '.'
            || $displayName === '..'
            || preg_match('/[\/\\\\]|[[:cntrl:]]/', $displayName) === 1
        ) {
            throw $refuse(
                'After Unicode normalization this filename is not a bare filename: it contains a path '
                    .'separator, a control character, or names a directory. Normalization runs BEFORE '
                    .'this check on purpose — a full-width solidus is not a separator until it is '
                    .'normalized, so a name validated first and normalized afterwards would have been '
                    .'accepted here and become a path later.',
            );
        }

        // ── INVISIBLE FORMATTING CHARACTERS, REFUSED RATHER THAN STRIPPED ────────────────────
        //
        // `\p{Cf}` is one class covering the whole family: the bidi overrides and isolates
        // (U+202A–U+202E, U+2066–U+2069), the zero-width joiners and marks (U+200B–U+200F), the BOM
        // (U+FEFF), and the Unicode Tags block (U+E0000–U+E007F) that
        // `kb-security-baseline` requires stripped at chunking time because it "maps one-to-one onto
        // printable ASCII, renders as nothing anywhere, and tokenizes as instructions".
        //
        // NONE OF THESE IS CAUGHT BY THE SHAPE CHECK ABOVE — `[[:cntrl:]]` in a byte-mode pattern is
        // 0x00–0x1F and 0x7F, and every one of these is a multi-byte sequence of ordinary bytes —
        // AND NFKC DOES NOT REMOVE THEM, because they have no compatibility mapping. So a
        // `report.pdf` whose name reads in a console as `report.fdp` and is `report.pdf` in bytes
        // passes every content check this gate performs, and the operator approving it cannot see
        // what they are approving. PostgreSQL's own `display_name !~ '[[:cntrl:]]'` does not catch
        // them either: in a UTF-8 database that class is category Cc, and these are Cf.
        //
        // REFUSED AND NOT STRIPPED, for the same reason the shape check refuses rather than calling
        // `basename()`: a stripped name is a different name nobody was told about, on a value we are
        // about to display.
        //
        // THIS CHECK CAN ONLY ANSWER FOR VALID UTF-8, WHICH IS WHY VALIDITY IS ESTABLISHED ABOVE.
        // `preg_match` with `/u` returns `false`, not `0`, for a subject that is not valid UTF-8 —
        // and `false !== 1`, so the refusal below simply does not fire. This comment used to argue
        // that the fall-through was safe because the extension lookup would refuse the name anyway;
        // it is not, the bad bytes only have to sit before the final dot, and the first check in
        // this method now carries the measurement.
        if (preg_match('/\p{Cf}/u', $displayName) === 1) {
            throw $refuse(
                'This filename contains an invisible formatting character — a bidirectional override, '
                    .'a zero-width mark or a tag character. Names like these render as something '
                    .'other than what they are, so nobody reviewing the source list could see what '
                    .'they were approving. Rename the file using visible characters.',
            );
        }

        $extension = mb_strtolower(pathinfo($displayName, PATHINFO_EXTENSION));

        if (in_array($extension, UploadLimits::MACRO_EXTENSIONS, true)) {
            throw $refuse(
                'Macro-enabled Office formats are not accepted on any deployment of this platform — '
                    .'`.'.$extension.'` among them. The document itself is welcome: re-save it as '
                    .'`.docx`, `.xlsx` or `.pptx` and upload that. Nothing here reads a macro, and '
                    .'storing one would mean handing a live payload to a converter or back to a '
                    .'downstream reader.',
            );
        }

        if (UploadLimits::sniffedTypesFor($extension) === null) {
            throw $refuse(
                ($extension === '' ? 'This filename has no extension.' : '`.'.$extension.'` is not an '
                    .'accepted file type.').' The accepted extensions are '
                    .implode(', ', UploadLimits::allowedExtensions()).'. Only the FINAL extension is '
                    .'read, so `invoice.pdf.html` is an HTML file. The list is the same one the '
                    .'document parser is closed to; admitting anything else would produce a source '
                    .'that fails after a worker has already been paid for.',
            );
        }

        return $extension;
    }

    /**
     * STEP 3. libmagic, over the bytes. Never the extension, never the request's `Content-Type`.
     *
     * @throws RuntimeException when libmagic is unavailable — see the class docblock. There is no
     *                          fallback and there must never be one.
     */
    private function sniff(string $path): string
    {
        if (! extension_loaded('fileinfo')) {
            throw new RuntimeException(
                'ext-fileinfo (libmagic) is not loaded, so the upload intake cannot detect a file type '
                    .'from its content. THERE IS DELIBERATELY NO FALLBACK: deriving the type from the '
                    .'extension would be the client\'s claim wearing the server\'s voice, which is the '
                    .'exact check docs/03 §8.10 requires and the exact defect this gate exists to '
                    .'prevent. Uploads fail closed until the extension is installed.',
            );
        }

        $info = new finfo(FILEINFO_MIME_TYPE);
        $mime = $info->file($path);

        if (! is_string($mime) || $mime === '') {
            throw new RuntimeException(
                'libmagic could not read the temporary upload. That is a storage or permissions '
                    .'failure rather than a property of the file: a file whose bytes cannot be read is '
                    .'not a file that was refused.',
            );
        }

        return $mime;
    }

    /**
     * STEP 3's verdict: is the sniffed type on the allow-list at all.
     *
     * `application/octet-stream` is libmagic saying it recognised nothing, and it is never on the
     * list, so it lands here rather than in the cross-check. That distinction is why the two steps
     * have separate audit tokens — see `UploadRejectionReason::MimeSniff`.
     *
     * @throws UploadRejected
     */
    private function assertMimeIsAllowed(string $displayName, string $mime, int $byteSize): void
    {
        if (in_array($mime, UploadLimits::allowedMime(), true)) {
            return;
        }

        throw new UploadRejected(
            UploadRejectionReason::MimeSniff,
            $displayName,
            $mime,
            $byteSize,
            'The content of this file reads as `'.$mime.'`, which this platform does not accept. The '
                .'type is detected from the BYTES and never from the filename or the browser\'s '
                .'`Content-Type` — both of those are chosen by whoever sent the request. Renaming the '
                .'file will not change this answer.',
        );
    }

    /**
     * STEP 4. The sniffed type is allow-listed; is it the type this extension implies.
     *
     * A MISMATCH IS A REJECTION AND NEVER A PREFERENCE. Trusting the sniff and ignoring the
     * extension would store a PDF under `.png` and hand the parser a format its filename lies about;
     * trusting the extension and ignoring the sniff is the check being skipped entirely. The
     * disagreement itself is the finding — a polyglot, a rename, or an upload UI mislabelling its
     * parts — and none of those is something to guess through.
     *
     * @throws UploadRejected
     */
    private function assertMimeMatchesExtension(
        string $displayName,
        string $extension,
        string $mime,
        int $byteSize,
    ): void {
        $expected = UploadLimits::sniffedTypesFor($extension) ?? [];

        if (in_array($mime, $expected, true)) {
            return;
        }

        throw new UploadRejected(
            UploadRejectionReason::MimeMismatch,
            $displayName,
            $mime,
            $byteSize,
            'This file is named `.'.$extension.'` and its content reads as `'.$mime.'`. A `.'.$extension
                .'` may be '.implode(' or ', $expected).'. The two signals disagreeing is itself the '
                .'refusal: neither one is preferred over the other, because a file that is one format '
                .'wearing another format\'s name is either a mislabelled upload or a deliberate one, '
                .'and this platform cannot tell those apart.',
        );
    }

    /**
     * STEP 5, plus the decompression caps and the deferred half of step 4.
     *
     * ── THE ORDER INSIDE THIS METHOD IS ALSO DELIBERATE ─────────────────────────────────────
     *
     *   a. the entry-count cap, BEFORE anything iterates the directory — walking two million entries
     *      to discover there are two million of them is the denial of service, not a defence
     *      against it;
     *   b. the declared-size and ratio caps, from the one pass that reads the directory;
     *   c. the OPC identity check, which is step 4 finishing its job: a `.docx` that sniffed as the
     *      generic `application/zip` is only cross-checked once we can see whether
     *      `word/document.xml` is in it;
     *   d. the active-content refusal, which is step 5 proper.
     *
     * (c) before (d) keeps the step numbering true: a zip with neither an OOXML root part nor a macro
     * is a `mime_mismatch`, which is the more accurate row, and a package that IS a real `.docx` and
     * carries `vbaProject.bin` gets `macro_payload`, which is the row somebody wants to find.
     *
     * ── ONLY ZIP-SHAPED INPUT REACHES HERE ──────────────────────────────────────────────────
     *
     * Gated on the extension being one of the three OPC ones. A PDF that is ALSO a valid zip — a real
     * polyglot shape — sniffed as `application/pdf` and is not opened as an archive here, because
     * treating it as one would mean this class deciding a file is two formats at once, which is the
     * one thing `kb-security-baseline` says a sniffer cannot do. That polyglot's containment is at
     * SERVING time — separate origin, `Content-Disposition: attachment`, `nosniff` — and the parser's
     * own format gate, not here.
     *
     * @throws UploadRejected
     */
    private function assertPackageIsSafe(
        string $path,
        string $displayName,
        string $extension,
        string $mime,
        int $byteSize,
    ): void {
        $rootPart = UploadLimits::OPC_ROOT_PART[$extension] ?? null;

        if ($rootPart === null) {
            return;
        }

        // Builds, never throws — see assertExtensionIsAllowed() for why.
        $refuse = static fn (UploadRejectionReason $reason, string $message): UploadRejected => new UploadRejected($reason, $displayName, $mime, $byteSize, $message);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw $refuse(
                UploadRejectionReason::MimeMismatch,
                'This file is named `.'.$extension.'` and is not a readable package. Every OOXML '
                    .'document is a ZIP archive, so one that will not open is not the document its '
                    .'name claims — a truncated upload, or a different format entirely.',
            );
        }

        try {
            $entries = $zip->count();

            // (a) THE COUNT CAP, BEFORE THE WALK.
            if ($entries > UploadLimits::MAX_ARCHIVE_ENTRIES) {
                throw $refuse(
                    UploadRejectionReason::ArchiveBomb,
                    'This package declares '.$entries.' entries and the ceiling is '
                        .UploadLimits::MAX_ARCHIVE_ENTRIES.'. No legitimate document is assembled from '
                        .'that many parts, and enumerating them all to find out is itself the cost the '
                        .'cap exists to refuse.',
                );
            }

            $declared = 0;
            $packed = 0;
            $names = [];

            for ($index = 0; $index < $entries; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw $refuse(
                        UploadRejectionReason::MimeMismatch,
                        'An entry in this package has an unreadable directory record. A central '
                            .'directory that cannot be walked cannot be checked, and an unchecked '
                            .'archive is not one this platform stores.',
                    );
                }

                $declared += (int) $stat['size'];
                $packed += (int) $stat['comp_size'];
                $names[] = (string) $stat['name'];
            }

            // (b) THE TWO SIZE CAPS. Both read from the CENTRAL DIRECTORY, which the file's author
            //     wrote — see UploadLimits::MAX_DECOMPRESSED_BYTES for why that is stated rather than
            //     hidden, and where the authoritative check lives.
            if ($declared > UploadLimits::MAX_DECOMPRESSED_BYTES) {
                throw $refuse(
                    UploadRejectionReason::ArchiveBomb,
                    'This package declares '.$declared.' bytes of uncompressed content and the ceiling '
                        .'is '.UploadLimits::MAX_DECOMPRESSED_BYTES.'. DOCX, XLSX and PPTX are ZIP '
                        .'archives, so this cap applies to ordinary office documents and not only to '
                        .'things that look like archives.',
                );
            }

            // `max($packed, 1)` and not a guard: a directory claiming a large uncompressed size for
            // zero compressed bytes is an infinite ratio, which is a bomb rather than a division to
            // be avoided.
            if (intdiv($declared, max($packed, 1)) > UploadLimits::MAX_COMPRESSION_RATIO) {
                throw $refuse(
                    UploadRejectionReason::ArchiveBomb,
                    'This package expands '.intdiv($declared, max($packed, 1)).'-fold and the ceiling '
                        .'is '.UploadLimits::MAX_COMPRESSION_RATIO.':1. The ratio cap is the one that '
                        .'catches a bomb built from overlapping entries, which expands fully in a '
                        .'single round of decompression and so passes every nesting-depth check.',
                );
            }

            // (c) STEP 4, FINISHED. The identity of the package, from a name comparison and never
            //     from parsing [Content_Types].xml — see the class docblock.
            if (! in_array($rootPart, $names, true)) {
                throw $refuse(
                    UploadRejectionReason::MimeMismatch,
                    'This file is named `.'.$extension.'` and is a ZIP archive with no `'.$rootPart
                        .'` in it, so it is not the document its name claims. Its content sniffed as `'
                        .$mime.'`, which every OOXML document does — the package\'s own parts are what '
                        .'distinguish one from a plain archive wearing an Office extension.',
                );
            }

            // (d) STEP 5 PROPER.
            //
            // ── THE SEPARATOR IS NORMALIZED BEFORE EITHER CHECK, AND `basename()` IS WHY ──────
            //
            // `basename()` ON POSIX DOES NOT TREAT `\` AS A SEPARATOR — `kb-security-baseline`'s
            // `references/file-upload-safety.md` makes exactly this point ("On POSIX it returns
            // `"..\\..\\evil"` unchanged, because `posixpath` does not treat `\` as a separator"),
            // and Symfony's own `File::getName()` replaces backslashes before basenaming for the
            // same reason. Measured: `basename('word\vbaproject.bin')` is the whole string, so the
            // comparison against `vbaproject.bin` fails, and `str_contains('word\embeddings\…',
            // '/embeddings/')` is false. A central directory spelling its parts with backslashes
            // therefore walked both refusals. `ZipArchive` preserves such a name verbatim, so the
            // spelling survives a round trip and is a name we can genuinely be handed.
            //
            // WHETHER A REAL OPC CONSUMER RESOLVES A BACKSLASH-NAMED ENTRY AS THAT PART IS UNTESTED
            // HERE, AND THIS COMMENT DOES NOT CLAIM IT DOES. The refusal is separator-agnostic
            // because the cost is one `str_replace` and the cost of finding out the other way is a
            // live payload we stored and handed on. Asymmetric, so it is not a close call.
            //
            // (c) ABOVE STAYS AN EXACT MATCH, deliberately: a package whose ROOT part is spelled
            // `word\document.xml` fails the identity check and is refused as `mime_mismatch`, which
            // is fail-closed and the more accurate row. The reachable shape is the MIXED one — a
            // forward-slash root part to pass (c), a backslash payload part to dodge (d) — and that
            // is what `tests/Unit/UploadIntakeGateTest.php` builds.
            foreach ($names as $name) {
                $lower = str_replace('\\', '/', mb_strtolower($name));

                if (basename($lower) === UploadLimits::VBA_PART_BASENAME) {
                    throw $refuse(
                        UploadRejectionReason::MacroPayload,
                        'This document carries a VBA macro project. It is refused whatever the outer '
                            .'extension says — a `.docx` can legally contain one. Nothing on this '
                            .'platform executes a macro; the risk is that we faithfully store a live '
                            .'payload and hand it to a converter or back to a reader. Remove the macros '
                            .'and re-save.',
                    );
                }

                if (str_contains($lower, UploadLimits::EMBEDDINGS_PART_SEGMENT)) {
                    throw $refuse(
                        UploadRejectionReason::MacroPayload,
                        'This document carries an embedded object (`'.$name.'`). An OLE object inside a '
                            .'document is an arbitrary file this platform would store and re-serve '
                            .'without ever having looked at it. Remove the embedded objects, or paste '
                            .'their contents in as text, and re-save.',
                    );
                }
            }
        } finally {
            // ALWAYS, INCLUDING ON A REFUSAL. Every `$refuse()` above throws from inside the open
            // archive, and a `ZipArchive` left open holds a file descriptor for the rest of the
            // request — ten refused files in one batch is ten descriptors, on the path whose entire
            // purpose is to be hostile-input-facing.
            $zip->close();
        }
    }

    /**
     * NFKC, and the reason it is this form rather than NFC.
     *
     * NFC composes and nothing else: `．．／` stays `．．／` under it, so the traversal that step 2
     * refuses would sail through and be normalized by some downstream consumer instead. NFKC applies
     * the COMPATIBILITY mappings, which is precisely what turns full-width solidus into `/` and
     * full-width stop into `.` — the transformation the attack is counting on happening AFTER
     * validation. Doing it before is the whole trick.
     *
     * `Normalizer::normalize()` returns false for input that is not valid UTF-8. That value is kept
     * as-is rather than repaired, because repairing it would be another silent rename — and the name
     * that comes back out of here is therefore NOT GUARANTEED TO BE VALID UTF-8. It is
     * `assertExtensionIsAllowed()`'s first check that refuses it, deliberately and explicitly; this
     * method does not, and must not, be read as having filtered anything.
     */
    private function normalize(string $name): string
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);

        return is_string($normalized) ? $normalized : $name;
    }

    /**
     * The temporary part PHP wrote, as a path.
     */
    private function realPath(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw new RuntimeException(
                'An uploaded part has no readable path on disk. That is an infrastructure failure '
                    .'rather than a refusal — see assertSizeIsWithinLimit() for why the two are never '
                    .'conflated on this path.',
            );
        }

        return $path;
    }
}
