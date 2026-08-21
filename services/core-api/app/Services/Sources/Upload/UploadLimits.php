<?php

declare(strict_types=1);

namespace App\Services\Sources\Upload;

/**
 * What this platform will accept as an upload: the ceilings, the extension allow-list, and the
 * sniffed types each extension may legitimately be.
 *
 * ── EVERY NUMBER ON THIS PATH IS DEFINED HERE, ONCE, AND NOTHING RESTATES IT ─────────────────
 *
 * `StoreSourceRequest::MAX_FILES` and `::MAX_FILE_KILOBYTES` are DEFINED AS these constants —
 * `public const MAX_FILES = UploadLimits::MAX_BATCH;` — so the rule the validator enforces, the
 * ceiling the intake gate enforces and the number `GET .../sources/upload-limits` publishes are one
 * value with one definition site. `StoreSourceRequest::MAX_FILES`' docblock states the failure that
 * makes this worth the indirection: "two copies of a limit drift, and the drifting copy is the one
 * that ships: a console that believes the batch cap is 20 while the server enforces 10 renders a
 * green upload that 422s." `UploadLimitsEndpointTest` asserts the rendered values EQUAL the
 * FormRequest's constants, so a future edit that hardcodes either one fails the suite.
 *
 * THE DIRECTION IS THIS WAY ROUND BECAUSE `arch()->preset()->laravel()` REQUIRES IT. A FormRequest
 * may not be used outside `App\Http`, and the first draft of this file imported
 * `StoreSourceRequest` to read its constants — which is the same one-definition property pointing
 * the wrong way through a layer boundary, and the arch suite failed it. The names differ on purpose
 * where the layers differ: `MAX_BATCH` is what an upload surface calls it, `MAX_FILES` is what the
 * rule that counts an array calls it, and neither is a copy of the other's value.
 *
 * ── THE KILOBYTE/BYTE CONVERSION HAPPENS EXACTLY HERE, IN `maxBytes()` ───────────────────────
 *
 * `MAX_FILE_KILOBYTES` is 25,600 and its unit is what Laravel's `max:` rule speaks for an uploaded
 * file. `Illuminate\Validation\Concerns\ValidatesAttributes::getSize()` divides
 * `UploadedFile::getSize()` by **1024**, so the rule's "kilobyte" is a KiB and the conversion is
 * `× 1024`, not `× 1000`. Getting that wrong by the 2.4% between the two would produce a
 * `max_bytes` the console pre-checks against that disagrees with the rule the server enforces — the
 * green-upload-that-422s failure again, in the units instead of in the value.
 *
 * ONE CONVERSION SITE, NOWHERE ELSE. `UploadIntake` asks `maxBytes()`; the resource asks
 * `maxBytes()`; the FormRequest keeps speaking kilobytes because that is the rule's unit. Nothing
 * else multiplies or divides by 1024 anywhere on this path.
 *
 * ── `MAX_TEXT_LENGTH` IS NOT HERE AND IS NOT PUBLISHED BY THE LIMITS ENDPOINT ────────────────
 *
 * The paste ceiling stays on `StoreSourceRequest`, where it is a rule about a `content` STRING
 * rather than a property of the upload surface, and `OrgUploadLimits` is a three-field shape a
 * client schema factory is already written against — adding a fourth is a contract change that
 * belongs to the owner of `packages/contracts/src`. It is not unpublished, though: `content`'s
 * `max:500000` lands in `packages/contracts/rules/StoreSourceRequest.json`, dumped from executing
 * `rules()`, which is the sanctioned place a client reads a REQUEST RULE from (docs/22 finding 19).
 * Reported as a tension rather than resolved by widening a shape that is not this file's.
 *
 * ── THE ALLOW-LIST IS THE DATA PLANE'S `ALLOWED_FORMATS`, AND THAT IS NOT A COINCIDENCE ──────
 *
 * `services/ai-service/app/ingestion/parsing/converter.py:146` closes Docling to
 * `PDF, DOCX, PPTX, XLSX, HTML, MD, CSV, IMAGE` — away from the upstream default of every backend,
 * including the JATS, METS-GBS, USPTO and LaTeX ones carrying 2026 XXE and traversal advisories —
 * and its module docstring states the invariant this class is the other half of: "The list must
 * equal the upload allow-list, and a test asserts the converter itself rejects an out-of-list file
 * rather than trusting that Laravel already did." Admitting an extension the converter refuses does
 * not produce a validation error; it produces a source that reaches `failed` after a worker has
 * already been paid for, with an `error_class: parsing` an operator cannot act on.
 *
 * ── `.txt` IS NOT ON THE LIST, AND THE SPEC SAYS IT SHOULD BE ────────────────────────────────
 *
 * docs/03 §8.11 names "Markdown, HTML, and Plain Text" among the supported document handling, and
 * `ALLOWED_FORMATS` has no `TXT` member — Docling has no plain-text backend, only `MD`. The two
 * cannot both be honoured, and this class follows the executing code rather than the prose, because
 * the alternative is the failure in the paragraph above. REPORTED AS A CONTRADICTION, NOT RESOLVED:
 * either the spec drops plain text or `ingestion-engineer` adds a backend for it, and this list
 * moves in the same change as `ALLOWED_FORMATS`.
 *
 * Note that `text/plain` IS an accepted SNIFFED type — for `.md` and `.csv`, whose bytes libmagic
 * very often cannot tell apart from prose. That is not the same statement as accepting `.txt`: step
 * 2 refuses the extension before step 3 ever sniffs.
 *
 * ── SVG IS EXCLUDED, DELIBERATELY AND PERMANENTLY ────────────────────────────────────────────
 *
 * `IMAGE` in the converter's list does not mean "anything with an image MIME type". SVG is XML AND
 * HTML: it executes `<script>`, `on*` handlers, `<foreignObject>` and `href="javascript:"` on direct
 * navigation, and carries XXE before any of that
 * (`kb-security-baseline/references/file-upload-safety.md`). The only safe form is rasterize-and-
 * store-the-raster, which is a data-plane capability nobody has asked for.
 */
final class UploadLimits
{
    /**
     * The divisor Laravel's `max:` rule uses for a file, restated as a name so the multiplication in
     * `maxBytes()` is readable rather than a magic 1024. See the class docblock.
     */
    private const BYTES_PER_KILOBYTE = 1024;

    /**
     * The most files one multipart request may carry. `StoreSourceRequest::MAX_FILES` IS this
     * constant — see the class docblock for why the definition lives on this side of the layer.
     */
    public const MAX_BATCH = 10;

    /**
     * Per-file ceiling, in KIBIBYTES, because that is the unit Laravel's file `max:` rule speaks.
     * 25 MiB. `StoreSourceRequest::MAX_FILE_KILOBYTES` IS this constant.
     */
    public const MAX_FILE_KILOBYTES = 25_600;

    /**
     * The three decompression caps, from `kb-security-baseline/references/file-upload-safety.md`.
     *
     * ALL THREE ARE READ OFF THE ZIP CENTRAL DIRECTORY HERE, WHICH IS ATTACKER-AUTHORED, and that
     * is stated rather than hidden. The reference is explicit that layers 1 and 2 are self-reported
     * and that the only authoritative check is counting bytes while streaming the decompression.
     * Laravel never decompresses an upload, so that check cannot live here — the intake opens the
     * package to enumerate PART NAMES (step 5), which reads the directory and not the data.
     *
     * THE AUTHORITATIVE CHECK IS NOT WRITTEN, ANYWHERE, AND THIS DOCBLOCK USED TO IMPLY IT WAS.
     * Saying it "is the PARSER's job, in the data plane" describes an owner, not an implementation:
     * `grep -rn 'ZipFile\|infolist\|compress_size' services/ai-service/app` returns nothing, so no
     * code in this repository counts bytes out of an OOXML decompression. Until it does, THESE THREE
     * NUMBERS ARE THE ENTIRE DEFENCE and all three are self-reported — a package whose directory
     * declares modest sizes and whose streams do not is refused by nothing. Owed to
     * `ingestion-engineer`; recorded as security finding S2 and deliberately not worked around from
     * this side, because the only workaround available here is decompressing hostile archives inside
     * the control plane.
     *
     * The ratio cap is the one that catches the modern non-recursive bomb: Fifield's
     * overlapping-entry construction (USENIX WOOT 2019) reaches ratios past 28 million to one and
     * expands fully in a single round, so a nesting-depth cap sees nothing wrong with it.
     */
    public const MAX_DECOMPRESSED_BYTES = 512 * 1024 * 1024;

    public const MAX_COMPRESSION_RATIO = 100;

    public const MAX_ARCHIVE_ENTRIES = 2_000;

    /**
     * The macro-enabled Office extensions, refused by name.
     *
     * `kb-security-baseline`: "Reject `.xlsm`/`.docm`/`.pptm`/`.xlsb` outright — we do not need
     * macros." They are already absent from `EXTENSIONS` below, so this list changes no verdict —
     * it changes the MESSAGE, and that is its whole job: "macro-enabled Office formats are not
     * accepted, save as .docx" is actionable where "unsupported file type" is not, and an operator
     * who is told the second one re-uploads the same file.
     *
     * The four the skill names, plus the template and add-in spellings of the same idea, because an
     * allow-list that admits none of them makes this list free to be generous.
     *
     * @var list<string>
     */
    public const MACRO_EXTENSIONS = [
        'docm', 'dotm', 'pptm', 'potm', 'ppsm', 'ppam', 'xlsm', 'xltm', 'xlam', 'xlsb',
    ];

    /**
     * Extension => the sniffed types that extension may legitimately be.
     *
     * ── WHY OOXML ENTRIES ADMIT `application/zip` ────────────────────────────────────────────
     *
     * A `.docx` IS a ZIP archive, and whether libmagic names the specific OOXML type or the generic
     * `application/zip` depends on the producer: the specific answer requires `[Content_Types].xml`
     * or `mimetype` to sit early enough in the archive for magic's window, and plenty of legitimate
     * writers do not put it there. Refusing `application/zip` outright would refuse real documents,
     * and accepting it unconditionally would accept any archive under an Office extension — which
     * is the fixture `kb-security-baseline` names as "a `.docx` sniffing as `application/zip` with
     * no OOXML parts", asserted refused.
     *
     * So the cross-check for these three extensions is completed inside the package, by
     * `OPC_ROOT_PART`: a zip that does not contain the part its extension implies is a
     * `mime_mismatch`. That is a stronger check than the sniff it replaces, not a weaker one.
     *
     * ── WHY THE TEXT FORMATS ADMIT `text/plain` ──────────────────────────────────────────────
     *
     * Markdown and CSV have no magic bytes. libmagic answers `text/plain` for most of both and
     * `text/csv` / `text/markdown` only for the ones it happens to recognise, so requiring the
     * specific type would refuse ordinary files. The cost is bounded and stated: any UTF-8 prose can
     * be uploaded as `.md`, which is a thing this product is FOR.
     *
     * `text/html` is NOT admitted for `.md` even though a markdown file may legally contain HTML —
     * a file libmagic reads as a whole HTML document is an HTML document, and the two go to
     * different Docling backends.
     *
     * @var array<string, list<string>>
     */
    public const EXTENSIONS = [
        'pdf' => ['application/pdf'],

        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
        ],

        'html' => ['text/html'],
        'htm' => ['text/html'],

        'md' => ['text/markdown', 'text/x-markdown', 'text/plain'],
        'markdown' => ['text/markdown', 'text/x-markdown', 'text/plain'],

        'csv' => ['text/csv', 'text/plain'],

        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
    ];

    /**
     * Extension => the OPC part whose presence proves the package is what the extension claims.
     *
     * READ FROM THE CENTRAL DIRECTORY, never by parsing `[Content_Types].xml` — the intake has no
     * business running an XML parser over a hostile archive, which is the whole reason
     * `kb-security-baseline` pins `lxml >= 6.1.0` and `defusedxml` on the side that does. A name
     * comparison cannot be XXE'd.
     *
     * @var array<string, string>
     */
    public const OPC_ROOT_PART = [
        'docx' => 'word/document.xml',
        'xlsx' => 'xl/workbook.xml',
        'pptx' => 'ppt/presentation.xml',
    ];

    /**
     * The part-name substrings that make an OPC package a refusal.
     *
     * `vbaProject.bin` is matched on its BASENAME, case-insensitively, because the part may sit at
     * `word/vbaProject.bin`, `xl/vbaProject.bin` or `ppt/vbaProject.bin` and Windows-authored
     * archives are inconsistent about its case. `/embeddings/` is matched as a path segment —
     * `word/embeddings/oleObject1.bin` — so a file whose NAME merely contains the word is not
     * caught by accident.
     *
     * BOTH VALUES ARE SPELLED WITH FORWARD SLASHES AND THE CALLER NORMALIZES BEFORE COMPARING.
     * `UploadIntake::assertPackageIsSafe()` step (d) runs `str_replace('\\', '/', …)` first, because
     * `basename()` on POSIX does not treat `\` as a separator and a central directory naming its
     * parts `word\vbaProject.bin` would otherwise pass both comparisons. The constants stay in one
     * spelling; the normalization is at the one comparison site.
     */
    public const VBA_PART_BASENAME = 'vbaproject.bin';

    public const EMBEDDINGS_PART_SEGMENT = '/embeddings/';

    /**
     * The per-file ceiling in BYTES. The one conversion site — see the class docblock.
     */
    public static function maxBytes(): int
    {
        return self::MAX_FILE_KILOBYTES * self::BYTES_PER_KILOBYTE;
    }

    /**
     * How many files one multipart request may carry. A method rather than a bare constant read at
     * the call site, so the day a per-plan ceiling arrives there is one place to give it an
     * argument.
     */
    public static function maxBatch(): int
    {
        return self::MAX_BATCH;
    }

    /**
     * Every sniffed type this platform accepts, sorted and de-duplicated.
     *
     * SORTED SO THE DUMPED OpenAPI DOCUMENT IS BYTE-STABLE. `kb:dump-openapi` compares the committed
     * file exactly, and a set whose order followed `EXTENSIONS`' insertion order would churn the
     * contract package every time somebody reordered a row above.
     *
     * @return list<string>
     */
    public static function allowedMime(): array
    {
        $types = array_merge(...array_values(self::EXTENSIONS));

        $unique = array_values(array_unique($types));
        sort($unique, SORT_STRING);

        return $unique;
    }

    /**
     * Every accepted extension, lowercase, sorted. Not published on the wire — see
     * `OrgUploadLimitsResource`, which records why the shape is three fields and not four.
     *
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        $extensions = array_keys(self::EXTENSIONS);
        sort($extensions, SORT_STRING);

        return $extensions;
    }

    /**
     * The sniffed types `$extension` may legitimately be, or null when it is not accepted at all.
     *
     * @return list<string>|null
     */
    public static function sniffedTypesFor(string $extension): ?array
    {
        return self::EXTENSIONS[$extension] ?? null;
    }
}
