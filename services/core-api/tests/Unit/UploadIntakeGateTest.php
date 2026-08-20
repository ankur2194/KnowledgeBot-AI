<?php

declare(strict_types=1);

use App\Enums\UploadRejectionReason;
use App\Services\Sources\Upload\UploadIntake;
use App\Services\Sources\Upload\UploadLimits;
use Illuminate\Http\UploadedFile;

/*
|--------------------------------------------------------------------------
| The upload intake gate — the STEPS, and above all the ORDER
|--------------------------------------------------------------------------
|
| A Unit test on purpose: `UploadIntake` takes no constructor argument, touches no database and
| opens no connection, so the gate is testable with no container, no fake disk and no HTTP round
| trip — which is what makes it cheap enough to assert every branch rather than a representative
| one. The endpoint's half — the storage key, the two audit operations, the all-or-nothing batch —
| is tests/Feature/SourceUploadTest.php.
|
| WHAT THESE ASSERT IS THE `reason` TOKEN AND NOT MERELY "IT WAS REFUSED", AND THAT IS THE WHOLE
| DESIGN OF THIS FILE. `kb-security-baseline`'s six steps are ordered, and every pair is ordered for
| a reason that stops being true if they swap — a size check under the sniff is a size check that
| already paid for the read it was going to refuse, and an extension check under the sniff is a name
| validated after something else normalized it. A test that only asserted "422" would pass against
| every permutation of the six, so the fixtures below are built to fail TWO steps at once and the
| assertion names which one answered.
|
| MUTATION-CHECKED. Swapping steps 2 and 3 in `UploadIntake::admit()` turns
| `refuses on the extension a file that would also fail the sniff` from `extension` to `mime_sniff`
| and the test fails; restoring it passes. The same for step 1 against step 2.
|
| THE BYTES ARE REAL. Every fixture below is a byte string libmagic genuinely reads as the type the
| test claims — asserted directly in the first test, so a libmagic version that changed its answer
| shows up as one obvious failure rather than as six confusing ones.
*/

/**
 * 300 characters plus an extension, from §22.5's fixture list. A constant rather than an expression
 * inside the dataset, because a dataset is evaluated at collection time and a `str_repeat` there
 * reads as a computation somebody might later "simplify" into a literal nobody can count.
 */
const OVER_LONG_UPLOAD_NAME = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    .'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    .'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    .'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    .'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    .'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf';

/**
 * An `UploadedFile` over real bytes, with the exact name the client offered.
 *
 * `$test: true` is what makes `isValid()` true without `is_uploaded_file()`, which can only ever be
 * true inside a real request. NOTE WHAT SYMFONY DOES TO THE NAME BEFORE THE GATE SEES IT:
 * `UploadedFile::getName()` replaces `\` with `/` and keeps only the segment after the last `/`, so
 * an ASCII `../evil.pdf` arrives here as `evil.pdf`. That is the "validate first" half of the
 * normalization hazard, and it is precisely why the traversal fixture below is written in FULL-WIDTH
 * characters: those are not separators until NFKC, so they survive Symfony untouched and the gate's
 * own ordering is the only thing standing between them and a `display_name` containing a path.
 */
function intakeFile(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'kb-intake-');

    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/** A real, minimal PDF. libmagic reads it as `application/pdf`. */
function intakePdfBytes(): string
{
    return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

/** A DOS/PE executable header. libmagic reads it as `application/x-dosexec`, which is on no list. */
function intakeExecutableBytes(): string
{
    return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00"
        .str_repeat("\x00", 48)."\x80\x00\x00\x00".str_repeat("\x00", 64)."PE\x00\x00L\x01";
}

/** A 1×1 PNG. */
function intakePngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    );
}

/**
 * An OPC package with whatever parts the caller names.
 *
 * @param  array<string, string>  $parts  part name => body
 */
function intakeZipBytes(array $parts): string
{
    $path = tempnam(sys_get_temp_dir(), 'kb-zip-').'.zip';

    $zip = new \ZipArchive;
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    foreach ($parts as $name => $body) {
        $zip->addFromString($name, $body);
    }

    $zip->close();

    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * The three parts that make a package a real, minimal `.docx`.
 *
 * @return array<string, string>
 */
function intakeDocxParts(): array
{
    return [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org'
            .'/package/2006/content-types"/>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships/>',
        'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="x"><w:body><w:p><w:r>'
            .'<w:t>Refunds are accepted for 30 days.</w:t></w:r></w:p></w:body></w:document>',
    ];
}

/**
 * A ZIP whose CENTRAL DIRECTORY declares a size the data does not have.
 *
 * ── THIS IS THE BOMB'S REAL SHAPE, NOT A STAND-IN FOR IT ─────────────────────────────────────
 *
 * `kb-security-baseline/references/file-upload-safety.md`: "`ZipInfo.file_size` and `compress_size`
 * are read from the central directory, which is attacker-authored — layers 1 and 2 are
 * self-reported." `ZipArchive::statIndex()` reports exactly those fields, so patching them IS
 * building the attack rather than simulating it — and it makes a 600 MB fixture cost a few hundred
 * bytes instead of a few seconds of deflate, which is the difference between a test that runs and a
 * test somebody deletes.
 *
 * The uncompressed size sits at offset 24 of each `PK\x01\x02` central-directory record.
 * `ZipArchive::open()` without `CHECKCONS` does not cross-check it against the local headers, and
 * nothing in `UploadIntake` ever reads an entry's DATA, so the patched value is what the gate sees —
 * which is the point being tested.
 *
 * @param  array<string, string>  $parts
 */
function intakeZipDeclaring(array $parts, int $declaredPerEntry): string
{
    $raw = intakeZipBytes($parts);
    $offset = 0;

    while (($position = strpos($raw, "PK\x01\x02", $offset)) !== false) {
        $raw = substr_replace($raw, pack('V', $declaredPerEntry), $position + 24, 4);
        $offset = $position + 4;
    }

    return $raw;
}

// ── step 3, and the fixtures themselves ──────────────────────────────────────────────────────────

it('reads the type out of the bytes, and every fixture below is the type it claims', function (): void {
    // THE POSITIVE CONTROL FOR THE WHOLE FILE. Every refusal below depends on libmagic answering a
    // particular way; if a version bump changed one of these answers, six tests would fail for
    // reasons that read as bugs in the gate. This one fails first and says what actually moved.
    $sniff = new \finfo(FILEINFO_MIME_TYPE);

    expect($sniff->buffer(intakePdfBytes()))->toBe('application/pdf')
        ->and($sniff->buffer(intakeExecutableBytes()))->toBe('application/x-dosexec')
        ->and($sniff->buffer(intakePngBytes()))->toBe('image/png');
});

it('accepts a real document and describes it from what it established, not from what it was told', function (): void {
    $bytes = intakePdfBytes();

    $screening = (new UploadIntake)->screen([0 => intakeFile('Employee Handbook.pdf', $bytes)]);

    expect($screening->hasRejections())->toBeFalse();

    $accepted = $screening->accepted[0];

    expect($accepted->displayName)->toBe('Employee Handbook.pdf')
        ->and($accepted->extension)->toBe('pdf')
        // SNIFFED. The `UploadedFile` was constructed with a null client MIME on purpose: nothing on
        // this path may read one, so there is nothing to read.
        ->and($accepted->mime)->toBe('application/pdf')
        ->and($accepted->byteSize)->toBe(strlen($bytes))
        // The value `source_items_content_hash_is_hex` checks and the data plane re-verifies the
        // object against on every read.
        ->and($accepted->contentHash)->toBe(hash('sha256', $bytes))
        ->and($accepted->contentHash)->toMatch('/^[0-9a-f]{64}$/');
});

// ── the ORDER ────────────────────────────────────────────────────────────────────────────────────

it('refuses on the EXTENSION a file that would also fail the sniff, because step 2 precedes step 3', function (): void {
    // FAILS BOTH: `.exe` is on no allow-list, AND `application/x-dosexec` is on no allow-list. Only
    // the ORDER decides which of the two answers, so this assertion is the order.
    //
    // MUTATION CHECK: moving `assertMimeIsAllowed()` above `assertExtensionIsAllowed()` in
    // UploadIntake::admit() turns this into `mime_sniff` and this test fails. Every refusal is still
    // a refusal — which is exactly why asserting "it was refused" would not have caught it.
    $screening = (new UploadIntake)->screen([0 => intakeFile('payload.exe', intakeExecutableBytes())]);

    expect($screening->accepted)->toBe([])
        ->and($screening->rejected[0]->reason)->toBe(UploadRejectionReason::Extension)
        // AND THE AUDIT ROW CARRIES NO MIME, which is the same ordering fact seen from the audit
        // table: a refusal before the sniff cannot report a sniffed type, and `AuditLogger` skips a
        // null silently so the key's ABSENCE is what says "we never looked".
        ->and($screening->rejected[0]->sniffedMime)->toBeNull();
});

it('refuses on SIZE a file that would also fail the extension, because step 1 precedes step 2', function (): void {
    // Over the ceiling AND named `.exe`. Step 1 answers.
    //
    // MUTATION CHECK: moving the size check below the extension check turns this into `extension`.
    $oversized = UploadedFile::fake()->create('payload.exe', UploadLimits::MAX_FILE_KILOBYTES + 1);

    $screening = (new UploadIntake)->screen([0 => $oversized]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::Size)
        // The size IS reported — step 1 established it — while the MIME is not.
        ->and($screening->rejected[0]->byteSize)->toBeGreaterThan(UploadLimits::maxBytes())
        ->and($screening->rejected[0]->sniffedMime)->toBeNull();
});

it('accepts a file of exactly the published ceiling, so the comparison is not off by one', function (): void {
    // The boundary in the direction that matters: an operator whose file is exactly `max_bytes` was
    // told by the limits endpoint that it would be accepted.
    $exact = UploadedFile::fake()->create('report.pdf', UploadLimits::MAX_FILE_KILOBYTES);

    expect($exact->getSize())->toBe(UploadLimits::maxBytes());

    // It still fails — on the SNIFF, because a fake file of zero bytes' worth of content is not a
    // PDF — and that is the assertion: the size step let it through.
    $screening = (new UploadIntake)->screen([0 => $exact]);

    expect($screening->rejected[0]->reason)->not->toBe(UploadRejectionReason::Size);
});

// ── step 2: the name ─────────────────────────────────────────────────────────────────────────────

it('normalizes the filename BEFORE validating it, so full-width traversal is refused', function (): void {
    // `．．／` is U+FF0E U+FF0E U+FF0F — NOT `../` until NFKC, which is why Symfony's own
    // `getName()` sanitization leaves it completely intact and why a `".." not in name` check reads
    // as passing. After NFKC it is `../evil.pdf`, whose extension is a perfectly good `pdf`.
    //
    // MUTATION CHECK: normalizing AFTER the shape check (or not at all) accepts this file and writes
    // `../evil.pdf` into `display_name`, where `source_items_display_name_is_not_a_path` refuses it
    // as a 500 instead of a 422 — and where any consumer that joins a display name to a path has a
    // traversal.
    $screening = (new UploadIntake)->screen([
        0 => intakeFile("\u{FF0E}\u{FF0E}\u{FF0F}evil.pdf", intakePdfBytes()),
    ]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::Extension)
        // The name recorded on the audit row is the NORMALIZED one, which is what an investigator
        // needs to see: the raw form renders as innocuous.
        ->and($screening->rejected[0]->displayName)->toBe('../evil.pdf');
});

it('refuses the malicious-filename fixture set from docs/17 §22.5', function (string $name): void {
    // EVERY ONE OF THESE CARRIES REAL PDF BYTES, so the content is never what refuses them — the
    // name is, at step 2, before anything opens the file. That is what makes this a test of the name
    // gate rather than a test that hostile files happen to be hostile.
    $screening = (new UploadIntake)->screen([0 => intakeFile($name, intakePdfBytes())]);

    expect($screening->accepted)->toBe([])
        ->and($screening->rejected[0]->reason)->toBe(UploadRejectionReason::Extension);
})->with([
    // Null-byte truncation: `x.pdf` to anything that stops at the NUL, `.php` to anything that does
    // not. A control character, so the shape check answers.
    'null-byte truncation' => ["x.pdf\x00.php"],
    // An NTFS alternate data stream. The final extension is `pdf:ads.exe`, which is on no list.
    'alternate data stream' => ['report.pdf:ads.exe'],
    // Win32 strips the trailing dot, so `evil.php.` becomes `evil.php`. `pathinfo()` reports NO
    // extension for a name ending in one, which refuses it here without anyone modelling Win32.
    'trailing dot' => ['evil.php.'],
    // Win32 strips a trailing space too. Here the extension is the four characters `pdf ` and the
    // allow-list is an exact-match map, so it never has to be special-cased.
    'trailing space' => ['report.pdf '],
    // 300 characters, from the fixture list. Past POSIX NAME_MAX, so no file picker produced it.
    'over-long name' => [OVER_LONG_UPLOAD_NAME],
    // A right-to-left override: renders in a console as `fdp.exe` while being `exe.pdf` in bytes.
    // NFKC does NOT touch it — U+202E has no compatibility mapping — so the extension really is
    // `pdf` and every content check would pass. It is refused as an invisible formatting character,
    // which is the same family kb-security-baseline strips at chunking time and the same reasoning:
    // a name no reviewer can read correctly is a name nobody can approve.
    'bidi override' => ["exe\u{202E}fdp.pdf"],
    // Zero-width joiner. Two files whose names are indistinguishable on screen and different in
    // bytes is a phishing primitive against whoever reads the source list.
    'zero-width character' => ["hand\u{200B}book.pdf"],
]);

// ── step 3 and step 4, which are different findings ──────────────────────────────────────────────

it('refuses a type it does not recognise as `mime_sniff`, with the extension allow-listed', function (): void {
    // `.md` IS accepted. The content is not, and libmagic answering `application/x-dosexec` is the
    // whole of the refusal: renaming the file changes nothing, which the message says.
    $screening = (new UploadIntake)->screen([0 => intakeFile('notes.md', intakeExecutableBytes())]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::MimeSniff)
        // Reported, because step 3 established it. This is the row somebody greps for.
        ->and($screening->rejected[0]->sniffedMime)->toBe('application/x-dosexec');
});

it('refuses a polyglot as `mime_mismatch`: content that sniffs as one type under another type\'s name', function (): void {
    // BOTH SIGNALS ARE INDIVIDUALLY FINE. `.png` is allow-listed; `application/pdf` is allow-listed.
    // The DISAGREEMENT is the finding, and it is a rejection rather than a preference for either
    // one — trusting the sniff would store a PDF the parser is told is an image, and trusting the
    // extension is the check not happening.
    $screening = (new UploadIntake)->screen([0 => intakeFile('diagram.png', intakePdfBytes())]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::MimeMismatch)
        ->and($screening->rejected[0]->sniffedMime)->toBe('application/pdf');
});

it('refuses a macro-enabled Office extension outright, before it ever opens the package', function (): void {
    // A REAL, WELL-FORMED XLSX-SHAPED PACKAGE under an `.xlsm` name. Nothing about its contents is
    // wrong; the extension is refused by name, because "we do not need macros" is a decision made
    // once rather than a property inspected per file.
    $screening = (new UploadIntake)->screen([
        0 => intakeFile('budget.xlsm', intakeZipBytes([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook/>',
        ])),
    ]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::Extension)
        ->and($screening->rejected[0]->getMessage())->toContain('Macro-enabled');
});

// ── step 5: the package ──────────────────────────────────────────────────────────────────────────

it('accepts a real OPC package', function (): void {
    $screening = (new UploadIntake)->screen([0 => intakeFile('Handbook.docx', intakeZipBytes(intakeDocxParts()))]);

    expect($screening->hasRejections())->toBeFalse()
        ->and($screening->accepted[0]->extension)->toBe('docx');
});

it('refuses a zip wearing an Office extension, because the root part is the real cross-check', function (): void {
    // The fixture `kb-security-baseline` names: "a `.docx` sniffing as `application/zip` with no
    // OOXML parts". `application/zip` IS accepted for `.docx` — plenty of legitimate writers produce
    // packages libmagic cannot name more precisely — so the identity check has to happen inside the
    // package, and `word/document.xml` is it.
    $screening = (new UploadIntake)->screen([
        0 => intakeFile('invoice.docx', intakeZipBytes(['readme.txt' => 'nothing to see'])),
    ]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::MimeMismatch)
        ->and($screening->rejected[0]->getMessage())->toContain('word/document.xml');
});

it('refuses an OPC package carrying vbaProject.bin, whatever the outer extension says', function (): void {
    $screening = (new UploadIntake)->screen([
        0 => intakeFile('Handbook.docx', intakeZipBytes(
            intakeDocxParts() + ['word/vbaProject.bin' => "\xD0\xCF\x11\xE0macro"],
        )),
    ]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::MacroPayload)
        ->and($screening->rejected[0]->getMessage())->toContain('VBA macro');
});

it('refuses an OPC package carrying an /embeddings/ part', function (): void {
    $screening = (new UploadIntake)->screen([
        0 => intakeFile('Report.docx', intakeZipBytes(
            intakeDocxParts() + ['word/embeddings/oleObject1.bin' => "\xD0\xCF\x11\xE0ole"],
        )),
    ]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::MacroPayload)
        ->and($screening->rejected[0]->getMessage())->toContain('embedded object');
});

// ── the decompression caps, one fixture each ─────────────────────────────────────────────────────

it('refuses an archive past the ENTRY cap', function (): void {
    $parts = intakeDocxParts();

    for ($i = 0; $i <= UploadLimits::MAX_ARCHIVE_ENTRIES; $i++) {
        $parts["word/media/image{$i}.bin"] = 'x';
    }

    $screening = (new UploadIntake)->screen([0 => intakeFile('Deck.docx', intakeZipBytes($parts))]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::ArchiveBomb)
        // WHICH cap, from the message, because all three share one audit token: `reason` groups
        // "somebody is sending us bombs" and the message says which shape.
        ->and($screening->rejected[0]->getMessage())->toContain('entries');
});

it('refuses an archive past the DECLARED-SIZE cap, read from the directory the attacker wrote', function (): void {
    // 600 MiB declared per entry, past the 512 MiB total, in a file of a few hundred bytes. See
    // intakeZipDeclaring(): the central directory is self-reported, which is why the cap is on it
    // AND why the authoritative streamed-byte check belongs where the decompression happens.
    $screening = (new UploadIntake)->screen([
        0 => intakeFile('Handbook.docx', intakeZipDeclaring(intakeDocxParts(), 600 * 1024 * 1024)),
    ]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::ArchiveBomb)
        ->and($screening->rejected[0]->getMessage())->toContain('uncompressed content');
});

it('refuses an archive past the RATIO cap, which is the one a nesting-depth check cannot see', function (): void {
    // Declared 4 MB per entry — comfortably under the 512 MiB total — against a few dozen packed
    // bytes. Ratio in the thousands. Fifield's overlapping-entry bomb has exactly this signature and
    // expands fully in a single round, so a recursion cap sees nothing wrong with it.
    $screening = (new UploadIntake)->screen([
        0 => intakeFile('Handbook.docx', intakeZipDeclaring(intakeDocxParts(), 4 * 1024 * 1024)),
    ]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::ArchiveBomb)
        ->and($screening->rejected[0]->getMessage())->toContain('expands');
});

// ── the batch ────────────────────────────────────────────────────────────────────────────────────

it('screens every file rather than stopping at the first refusal, and keys each on the part index', function (): void {
    $screening = (new UploadIntake)->screen([
        0 => intakeFile('Handbook.pdf', intakePdfBytes()),
        1 => intakeFile('payload.exe', intakeExecutableBytes()),
        2 => intakeFile('diagram.png', intakePdfBytes()),
    ]);

    // THE INDEXES ARE THE REQUEST'S OWN. A renumbered list would render a per-file error against the
    // wrong row in the console, which is worse than a banner because it is confidently wrong.
    expect(array_keys($screening->accepted))->toBe([0])
        ->and(array_keys($screening->rejected))->toBe([1, 2])
        ->and($screening->rejected[1]->reason)->toBe(UploadRejectionReason::Extension)
        ->and($screening->rejected[2]->reason)->toBe(UploadRejectionReason::MimeMismatch);
});

it('refuses the SECOND of two byte-identical files and keeps the first', function (): void {
    // The storage key is content-addressed within the source, so both would land at ONE key: the
    // second write would overwrite the first and leave two items pointing at one object with no
    // reference count — and `source_items_org_source_canonical` would refuse the second row anyway,
    // as a 500 for a request that is plainly a mistake.
    $bytes = intakePdfBytes();

    $screening = (new UploadIntake)->screen([
        0 => intakeFile('handbook.pdf', $bytes),
        1 => intakeFile('handbook-copy.pdf', $bytes),
    ]);

    expect(array_keys($screening->accepted))->toBe([0])
        ->and($screening->rejected[1]->reason)->toBe(UploadRejectionReason::Duplicate)
        // The FIRST file's index is named, so an operator can see which of the two to keep.
        ->and($screening->rejected[1]->getMessage())->toContain('files.0');
});

it('does not re-refuse a file that already failed its own step as a duplicate', function (): void {
    // Two identical executables. Each fails step 2 on its own, and neither reaches a hash — so the
    // duplicate pass has nothing to compare and the operator is told the true reason twice rather
    // than a true reason and a misleading one.
    $bytes = intakeExecutableBytes();

    $screening = (new UploadIntake)->screen([
        0 => intakeFile('a.exe', $bytes),
        1 => intakeFile('b.exe', $bytes),
    ]);

    expect($screening->rejected[0]->reason)->toBe(UploadRejectionReason::Extension)
        ->and($screening->rejected[1]->reason)->toBe(UploadRejectionReason::Extension);
});
