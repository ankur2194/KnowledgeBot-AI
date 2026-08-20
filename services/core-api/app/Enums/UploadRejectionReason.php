<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * WHICH GATE REFUSED A FILE — a closed token, never a message.
 *
 * ── IT IS AN ENUM BECAUSE IT IS AUDITED, AND AUDITED VALUES ARE GROUPED BY ───────────────────
 *
 * `AuditLogger::SOURCE_UPLOAD_REJECTED` echoes this as `reason`, and its docblock states the rule
 * this type enforces: "an exception message would be unbounded, would vary by library version, and
 * could echo the parser's reading of a hostile file back into the audit table". An operator asking
 * "is somebody grinding at the extension allow-list" needs a `GROUP BY`, and a free-text column
 * cannot answer that question at any volume.
 *
 * ── SEVEN TOKENS, AND `ArchiveBomb` IS THE ONE THE AUDIT DOCBLOCK DID NOT NAME ───────────────
 *
 * `AuditLogger`'s constant names six — `size`, `extension`, `mime_sniff`, `mime_mismatch`,
 * `macro_payload`, `duplicate` — one per step of `kb-security-baseline`'s six-step gate. The
 * decompression caps (512 MiB declared, ratio 100, 2000 entries;
 * `kb-security-baseline/references/file-upload-safety.md`) are not one of those six steps and are
 * not optional: DOCX, XLSX and PPTX are ZIP archives, so the caps run on ordinary office documents
 * rather than on things that look like archives. Filing them under `macro_payload` would put a
 * resource refusal and an active-content refusal in one bucket and make the bucket unreadable —
 * the two have different remedies, different attackers and different follow-ups. So the token set
 * is seven, the divergence is recorded here and in `AuditLogger`'s own docblock rather than
 * silently applied, and nothing in the audit path validates the VALUE of `reason` (the allow-list
 * names the field, not its members), so this enum is the only thing that closes the set.
 *
 * ── WHAT IS DELIBERATELY NOT A TOKEN ─────────────────────────────────────────────────────────
 *
 * There is no `malware` token. `kb-security-baseline` wires clamd as an INTEGRATION POINT and
 * budgets it as "the last of seven controls, not the first"; nothing in this repository runs a
 * scanner, and a token for a control that does not exist would read in an audit query as a control
 * that never fires.
 *
 * There is no `quota` token either: storage quota is a plan concept with no table behind it yet,
 * and `kb-error-taxonomy` renders it as `tenant_quota` with its own status rather than as a
 * validation refusal keyed on a file.
 */
enum UploadRejectionReason: string
{
    /**
     * STEP 1. Bigger than `UploadLimits::maxBytes()`, or PHP itself refused the part because it
     * exceeded `upload_max_filesize` / `post_max_size`.
     */
    case Size = 'size';

    /**
     * STEP 2. The NFKC-normalized name has no final extension, has one that is not allow-listed, is
     * one of the four macro-enabled Office extensions, or is not a bare filename at all.
     *
     * THE LAST CASE IS HERE AND NOT UNDER A NAME OF ITS OWN because step 2 IS the name gate: it
     * normalizes first and validates second, and a normalized name carrying a separator or a
     * control character is exactly what that ordering exists to catch (`．．／` is not `../` until
     * NFKC). A separate token would split one step's refusals across two rows in a report.
     */
    case Extension = 'extension';

    /**
     * STEP 3. libmagic read the bytes and the type it returned is not on the allow-list at all —
     * including `application/octet-stream`, which is libmagic saying it recognised nothing.
     */
    case MimeSniff = 'mime_sniff';

    /**
     * STEP 4. The sniffed type IS allow-listed and disagrees with the declared extension.
     *
     * A DISTINCT TOKEN FROM `mime_sniff` BECAUSE THE TWO ARE DIFFERENT EVENTS. An unknown type is
     * usually a format we do not support; a KNOWN type under the wrong extension is a polyglot, a
     * rename, or an upload UI that mislabels its parts — and it is the one worth alerting on. The
     * cross-check is a rejection and never a preference for one signal over the other
     * (`kb-security-baseline`: "A `.xlsx` sniffing as `application/x-dosexec` is a rejection, not a
     * warning").
     *
     * It also carries the OPC identity refusal: a `.docx` that sniffs as `application/zip` and
     * contains no `word/document.xml` is a zip wearing an extension, which is the same finding
     * arrived at one layer deeper.
     */
    case MimeMismatch = 'mime_mismatch';

    /**
     * STEP 5. An OPC package carrying `vbaProject.bin` or an `/embeddings/` part, whatever the outer
     * extension — a `.docx` can legally carry both.
     *
     * NEITHER openpyxl NOR python-pptx CAN EXECUTE THEM. The risk is that we faithfully persist a
     * live payload and hand it to LibreOffice or back to a downstream user, which is a hazard the
     * parser sandbox does not cover because it is not the parser that gets hurt.
     */
    case MacroPayload = 'macro_payload';

    /**
     * The decompression caps. See the class docblock for why this token exists at all.
     */
    case ArchiveBomb = 'archive_bomb';

    /**
     * STEP 6's other half. Two files in ONE batch with the same SHA-256.
     *
     * A REFUSAL AND NOT A SILENT DROP, because the storage key is content-addressed within the
     * source: the second write would overwrite the first at the same key and leave two
     * `source_items` rows pointing at one object with no reference count. It would also violate
     * `source_items_org_source_canonical` — the canonical key of an upload IS its object key — so
     * the alternative to this refusal is a 500 from a unique-index violation on a request that is
     * plainly a mistake.
     */
    case Duplicate = 'duplicate';
}
