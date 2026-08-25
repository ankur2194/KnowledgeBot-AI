# File upload and parsing safety — reference

Depth for `kb-security-baseline`. Spec: `docs/13-security.md` §18.7, `docs/03-functional-knowledge-sources.md` §8.10.

Two distinct attack surfaces, and they need different answers:

1. **The parser.** Docling, PyMuPDF, openpyxl, python-pptx, Pillow, libmagic, and a LibreOffice conversion worker all consume attacker-controlled bytes in-process. Defense is resource limits and a sandbox, not validation — you cannot validate your way out of a bug in a C decoder.
2. **The stored artifact.** A file that survives parsing is still a file we will later serve back to a browser. Defense is where and how it is served, not what it contains.

## Version floors — these are security requirements, not hygiene

| Package | Minimum | Why |
|---|---|---|
| `docling` | **≥ 2.94.0** | Eight 2026 advisories: XXE in the JATS, METS-GBS and USPTO backends; Zip Slip in the EasyOCR model download (→ RCE); LaTeX `\input`/`\includegraphics` traversal; and CVE-2026-47214 — the HTML backend accepted `file://` URIs, `../`, unvalidated redirects and internal-network addresses with no resource limits. |
| `lxml` | **≥ 6.1.0** | CVE-2026-41066: lxml 5.0 turned off entity resolution for the *normal* parsers but left `iterparse()` and `ETCompatXMLParser()` at `resolve_entities=True`. Streaming OOXML readers use `iterparse`. |
| `Pillow` | **≥ 12.3.0** | The 2026-07-20 batch alone: four separate decompression-bomb-check bypasses, a PDF-stream bomb, heap OOB writes in `paste`/`crop`/`RankFilter`/`ImageCmsTransform`, an EPS infinite loop, and a TGA encoder that leaks ~57 KB of adjacent heap into images you re-encode and serve. |
| `PyMuPDF` | **≥ 1.26.7** | CVE-2026-3029, arbitrary file write via `embedded_get`. PyMuPDF bundles MuPDF, and MuPDF's own C-level CVEs are not all mirrored into the PyPI feed — treat OSV as a floor. |
| `defusedxml` | pinned as a **direct** dependency | See openpyxl below — its presence silently changes openpyxl's parser choice. |
| `libmagic1` (OS package) | patched with the base image | `python-magic` is a ctypes wrapper; its empty PyPI advisory record says nothing about the C library underneath. |

Docling configuration: keep `enable_remote_fetch=False` and `enable_local_fetch=False` (both default off post-fix), set `base_path` containment, and **never enable Docling's Playwright page rendering on untrusted HTML** — the current field is `HTMLBackendOptions.render_page` (with `fetch_images` alongside it); both stay off. CVE-2026-44016 was exactly that, scope-changed, confidentiality and integrity high.

## Intake gate — the order matters

1. **Size limit first**, enforced at Traefik *and* in Laravel, before the body is buffered. Rejecting a 4 GB upload after writing it to disk is not a rejection.
2. **Extension allow-list** on the normalized name: lowercase, final extension only. `invoice.pdf.html` ends in `.html`. Reject `.xlsm`/`.docm`/`.pptm`/`.xlsb` outright — we do not need macros.
3. **MIME detection from content** (`python-magic` / libmagic), never from the client's `Content-Type` or the extension. §8.10 requires this explicitly. OWASP on signature checks: *"This should not be used on its own, as bypassing it is pretty common and easy."*
4. **Cross-check**: the sniffed type must be in the MIME allow-list *and* consistent with the declared extension. A `.xlsx` sniffing as `application/x-dosexec` is a rejection, not a warning.
5. **Reject OPC packages carrying `vbaProject.bin` or an `/embeddings/` part**, whatever the outer extension — a `.docx` can legally carry both. openpyxl and python-pptx cannot execute them; the risk is that we faithfully persist a live payload and hand it to LibreOffice or to a downstream user.
6. **Content hash** (SHA-256) for §8.10's duplicate detection and as the reprocessing idempotency key.

## Never use the uploaded filename as a path

Generate the storage key yourself — **and do not hand-build it either.** `App\Support\Kb\ObjectKey::originalUpload()` and its data-plane twin `app/storage/objects.py` are the only two things that compose one, and they are compared string-for-string by a contract test that runs both languages. The key is `org/{org_id}/sources/{source_id}/original/{content_hash}`, with **no `versions/` segment**: this paragraph used to require one and call it load-bearing, and ADR-066 removed it, because the request holding the uploaded bytes structurally cannot know a version id — `source_versions` needs an `ingest_key` and three `*_cfg_version` values that only exist after parsing. The real property it was reaching for survives intact and is the one to keep: **a sweep and a verification that build the prefix differently silently cover different halves of the store** (`seaweedfs-s3`), which is why there is one composer per runtime and not one per call site. Keep the user's filename in a `display_name` column, and re-emit it only inside `Content-Disposition: attachment; filename*=UTF-8''<pct-encoded>`, where it is a string and not a path.

The content hash is the whole key here, so **`ObjectKey` appends no extension to an upload.** An upload's type is a *sniff*; putting a value derived from attacker-influenced text into a path is precisely what this section forbids, and step 4 above is the reason there is nothing trustworthy to append. `source_items.mime` is the authority for every reader. A *pasted-text* body is the one exception and it is not one — `originalText()` appends `.txt` to bytes we generated ourselves from a validated UTF-8 string.

This makes structurally impossible what filtering only *attempts*: traversal, null-byte truncation (`x.pdf\0.php`), Windows reserved device names — which stay reserved **with an extension**, so `CON.pdf` is still the console — NTFS alternate data streams (`report.pdf:payload.exe`), and trailing dots or spaces silently stripped by Win32, which defeats extension allow-lists.

Unicode normalization deserves its own line, because it defeats the obvious check. Full-width solidus U+FF0F `／` and full-width stop U+FF0E `．` are not `/` or `.` — until NFKC. So `．．／．．／etc/passwd` passes `".." not in name` and becomes `../../etc/passwd` the moment anything normalizes it. **If you normalize, normalize before validating, never after.**

`os.path.basename` closes roughly one of these. On POSIX it returns `"..\\..\\evil"` unchanged, because `posixpath` does not treat `\` as a separator — which matters the moment that string reaches SMB, a Windows host, or a layer that normalizes backslashes.

## Archive extraction — the modern shape of the problem

**Correction to the folklore:** current CPython's `ZipFile.extract()` / `extractall()` already strip absolute paths, drive letters, leading separators, **all `..` components**, and the illegal Windows characters `: < > | " ? *`. Classic Zip Slip through `extractall()` is not exploitable there. The live risks are:

- **Hand-rolled extraction** — `open(os.path.join(dest, info.filename), "wb")`. This is where every real 2026 CVE lives (Docling CVE-2026-44017 → RCE by overwriting Python files; PyMuPDF CVE-2026-3029).
- **`zipfile.Path`** — the docs say outright that sanitizing member names is the caller's responsibility.
- **`tarfile`** — always pass `filter="data"` explicitly rather than relying on the version-dependent default. <!-- UNVERIFIED: PEP 706 flipped the default in a specific CPython release; passing the argument makes the version stop mattering. -->

Containment check: `Path(dest, name).resolve().is_relative_to(base.resolve())`. Prefer it to `os.path.commonpath` string comparison — fewer ways to hold it wrong.

## Decompression bombs

**DOCX, XLSX, and PPTX are ZIP archives.** Every rule below applies to ordinary office documents, which is the part teams forget until a 200 KB spreadsheet OOMs a worker.

```python
import zipfile

MAX_TOTAL, MAX_RATIO, MAX_ENTRIES, CHUNK = 512 << 20, 100, 2_000, 64 << 10

with zipfile.ZipFile(path) as zf:
    infos = zf.infolist()
    if len(infos) > MAX_ENTRIES:
        raise Rejected("entry count")
    declared = sum(i.file_size for i in infos)          # central-directory claim
    packed   = sum(i.compress_size for i in infos) or 1
    if declared > MAX_TOTAL or declared / packed > MAX_RATIO:
        raise Rejected("declared size or ratio")
    written = 0
    for info in infos:                                  # never .read(), never extractall()
        with zf.open(info) as src:
            while chunk := src.read(CHUNK):
                written += len(chunk)
                if written > MAX_TOTAL:                 # the only authoritative check
                    raise Rejected("actual size")
```

All three layers are required. `ZipInfo.file_size` and `compress_size` are read from the central directory, which is attacker-authored — layers 1 and 2 are self-reported. The **ratio cap** is what catches the modern non-recursive bomb: David Fifield's overlapping-entry construction (USENIX WOOT 2019) has each local file header claim the same quoted deflate stream, grows quadratically in input size, reaches ratios past 28 million to one, and **expands fully in a single round of decompression** — a recursion-depth cap sees nothing wrong with it. Cap nesting depth as well, per §8.10's "archive expansion limit", but never rely on it alone. Add a wall-clock timeout: a bomb under your byte cap can still be CPU-pathological.

Python's own docs put it plainly: *"Never extract archives from untrusted sources without prior inspection."*

The same reasoning applies in the crawler: `Content-Encoding: gzip` decompresses after your byte cap on the wire, so cap the decompressed size separately.

## XML bombs and XXE

The stdlib parsers — `sax`, `etree`, `minidom`, `pulldom`, `xmlrpc` — remain **billion-laughs and quadratic-blowup vulnerable by default** on current Python. External entity expansion and DTD retrieval are safe; expansion DoS is not. Use the `defusedxml` equivalents for all of them.

For `lxml`, configure the parser explicitly and pass it at every call:

```python
parser = etree.XMLParser(resolve_entities=False, load_dtd=False,
                         no_network=True, huge_tree=False)
```

A bare `etree.parse(f)` uses the default parser. `huge_tree=True` disables libxml2's entity-expansion ceiling — never set it on untrusted input. **`defusedxml.lxml` is deprecated and slated for removal; do not use it.** This exact four-flag configuration is what the Docling maintainers shipped to fix CVE-2026-44018.

**The openpyxl trap, read from `openpyxl/xml/functions.py`:** `fromstring` is hardened via an lxml parser with `resolve_entities=False`, but the streaming `iterparse` — the path that reads worksheet XML, i.e. the bulk of an untrusted XLSX — falls back to `xml.etree.ElementTree.iterparse` **unless `defusedxml` is importable in the environment**. It is toggled by `OPENPYXL_DEFUSEDXML` / `OPENPYXL_LXML`, both defaulting to `"True"` in the sense of *use if available*. So: pin `defusedxml` as a direct dependency and assert `openpyxl.DEFUSEDXML is True` at worker startup. A transitive dependency quietly disappearing downgrades your XML hardening with no error and no test failure.

`python-pptx` is clean here — `etree.XMLParser(remove_blank_text=True, resolve_entities=False)` in `pptx/oxml/__init__.py`.

**SVG is XML *and* HTML.** Direct navigation or inline injection executes `<script>`, `on*` handlers, `<foreignObject>`, `href="javascript:"`, and outbound `<image href>` / CSS `url()` — plus XXE before any of that. Exclude SVG from the allow-list, or rasterize it in the sandbox and store only the raster. <!-- UNVERIFIED: neither the OWASP File Upload nor XXE cheat sheet currently has an SVG section; this is doctrine, not citation. -->

## Image decompression bombs

Pillow's `MAX_IMAGE_PIXELS` defaults to **89,478,485**; above it you get a `DecompressionBombWarning`, above **2×** it a `DecompressionBombError`. A warning is not a defense — it is non-fatal and printed once. Either run `warnings.simplefilter("error", Image.DecompressionBombWarning)` or set the limit low enough that your real threshold falls in the error band.

Two things it does not cover:

- **Frame count.** The check receives `im.size`, one frame's dimensions; `n_frames` is never consulted. A 50 000-frame GIF/APNG/TIFF passes and then exhausts memory across `seek()`. Cap `getattr(im, "n_frames", 1)` and cap `width × height × n_frames` yourself.
- **Any decoder not registered with `Image.register_open()`.** `BdfFontFile`, `PcfFontFile`, `FontFile.compile`, and `GdImageFile` all reach `Image.new()` / `Image.frombytes()` with attacker-controlled dimensions and never invoke the guard — four separate CVEs fixed in 12.3.0, one exploitable to 48× the error threshold. Pixel limits also say nothing about *compressed-stream* bombs: the FITS GZIP bomb (CVE-2026-40192) and the PDF stream bomb (CVE-2026-59200, where `zlib.decompress(bufsize=…)` was mistaken for a cap — it is a hint) are both unbounded reads.

The single control that closes most of that surface: **allow-list the decoders**, `Image.open(fp, formats=["JPEG", "PNG", "WEBP", "GIF"])`. It is Pillow's own documented workaround for the FITS advisory and it also neutralizes the GD, BDF, PCF, EPS, and JP2 paths.

## Active content

§18.7 requires stripping active content from converted artifacts and never executing macros. In PDFs the constructs that matter: `/JavaScript` and `/JS`, `/OpenAction`, `/AA` (additional actions on open, close, focus, print), `/Launch`, `/EmbeddedFile` and `/Filespec`, `/SubmitForm` and `/ImportData` (AcroForm exfiltration primitives), and the legacy `/RichMedia`, `/Sound`, `/Movie` handlers.

PyMuPDF exposes `Document.scrub(...)` (v1.16.14+), every option defaulting `True`, covering JavaScript, embedded files, file attachments, hidden text, metadata, XML metadata, links, form fields and thumbnails. Two caveats for the review checklist: **the documented parameter list has no explicit option for `/Launch` or `/OpenAction`** — do not assume they are removed; assert their absence in the serialized output. And `scrub()` runs *after* MuPDF's C parser has already consumed the hostile file, so it is a sanitizer, not a gate. <!-- UNVERIFIED: whether scrub()'s javascript/remove_links handling incidentally strips /Launch and /OpenAction is not stated in the docs. -->

**Password-protected files:** reject with a clear terminal state, never guess, never accept the password through an unencrypted or logged channel. An encrypted archive is also opaque to the malware scanner, so "we'll scan after decrypting" reverses the order of the gate.

## Polyglots and content sniffing

A polyglot is one byte stream simultaneously valid in two formats: GIFAR (GIF header at offset 0, ZIP central directory at the tail — one parser reads forward, the other backward), a JPEG whose COM segment is a complete HTML document, a PDF that is also a valid ZIP, phar-JPEG. libmagic answers *"what is the first format this matches?"*, not *"is this exclusively that format?"*, and on a polyglot it answers **correctly** — `image/jpeg` — while the file is also valid HTML. A better sniffer does not fix this, because the browser is the thing being attacked.

What fixes it, at serving time:

- **A separate registrable domain** for user content — not a path, not a cookie-sharing subdomain. Successful HTML execution then lands in an origin with no session, no CSP relaxations, no same-site cookies. This is OWASP's first-priority control.
- `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff` on every such response. `nosniff` makes the browser honor the declared `Content-Type` instead of sniffing, "preventing XSS attacks where user-uploaded content is executed as HTML" (MDN).
- Set the response `Content-Type` from **our** sniffed value, from the allow-list. `application/octet-stream` is a fine default.
- Private bucket; access via a short-lived presigned URL or a Laravel proxy route running the six checks from `SKILL.md`. Never a public bucket.
- **Re-encode where you can.** Running an image through Pillow and writing a fresh JPEG destroys the polyglot's non-image half — the single most effective control for images, and OWASP recommends it directly.
- If the admin UI previews a document, it previews the *converted artifact* in a sandboxed iframe, never the original bytes inline.

## Sandboxing and timeouts

Parsing runs in a Celery worker container with **no network namespace at all** (`--network none`), a read-only root filesystem plus a size-capped `tmpfs` scratch, input mounted read-only, a non-root user, `--cap-drop=ALL --security-opt no-new-privileges`, cgroup v2 `memory.max` with `memory.swap.max=0` (so a bomb OOM-kills the worker instead of thrashing the host), `cpu.max`, `pids.max`, and one document per process instance with no reuse. Treat converted output as untrusted too — it came out of a process that could have been compromised. <!-- UNVERIFIED: the specific cgroup/seccomp parameters are synthesized from standard practice; the "run in a sandboxed environment" instruction itself is sourced, from Docling's own advisories. -->

The no-network rule is not paranoia. LibreOffice CVE-2024-12426 exfiltrated arbitrary environment variables and config values on *document open*, through URL construction. A container with no network namespace neutralizes it entirely.

**LibreOffice deserves its own paragraph.** It is a multi-million-line C++ office suite whose 2026 advisories are a wall of heap overflows in import filters (PPT colour tables, Calc formula nesting, tracked-changes type confusion, DXF polylines, EMF+ gradients, OOXML text boxes, AgileEngine encryption parameters), on top of a 2024–2025 history of *macro and script execution bypasses* — CVE-2024-3044 (on-click handlers ran scripts without warning), CVE-2024-6472 (trust in non-validated macro signatures), CVE-2025-1080 (`vnd.libreoffice.command` URL scheme invoking internal macros with arbitrary arguments). Pin to the latest supported branch, patch on *their* cadence not ours, pass `-env:UserInstallation=file:///tmp/<unique>` per invocation (concurrent runs otherwise corrupt each other's profile), set macro security to maximum, and assume exploitation — the sandbox is what makes it worthless.

**The timeout gotcha:** Celery's `soft_time_limit` raises `SoftTimeLimitExceeded` from a Python-level signal handler. A worker stuck inside MuPDF, libmagic, Pillow's C decoder, or a LibreOffice subprocess never returns to the interpreter, so the soft limit never fires and the task hangs until the queue drains behind it. Always set the hard `time_limit` too — that one kills the child — and give `subprocess.run` its own `timeout=` plus a `kill()`. Build the LibreOffice command as an argument list, never `shell=True`.

Enforce §8.10's structural caps as well: maximum pages, sheets, slides, embedded images, extracted characters. They bound the work *after* acceptance, which is where a legitimate-looking 4 000-page PDF does its damage.

## Malware scanning

Wire §18.7's integration point as a clamd `INSTREAM` scan at intake, before parsing, over a **Unix domain socket** — ClamAV's own docs state that clamd "does not currently protect or authenticate traffic coming over the TCP socket". Key on the content hash so reprocessing does not rescan.

The defaults that will burn you, from `clamd.conf.sample`:

| Option | Default | Consequence |
|---|---|---|
| `AlertExceedsMax` | **`no`** | A file that blows past any limit below returns **OK** — a silent scan failure indistinguishable from a clean verdict. **Set it to `yes`** and fail closed on `Heuristics.Limits.Exceeded`. |
| `StreamMaxLength` | 100M | Connection closed past this. Must be ≥ your upload cap, and "connection closed" must be a hard failure, not a pass. |
| `MaxFileSize` | 100M | Larger files skipped — *including files inside archives*. ClamAV cannot scan above 2 GB at all. |
| `MaxScanSize` | 400M | Total data scanned per input file. |
| `MaxRecursion` / `MaxFiles` | 17 / 10000 | Nested-archive depth and member count. |
| `MaxScanTime` | 120 s | Guards against files that lock a scanning thread. |

Its real limits, stated so nobody treats it as the gate: signature-based, so it misses anything targeted or novel; blind to encrypted archives; and **it solves a different problem from everything else in this document** — a zip bomb, a polyglot, a scripted SVG, an XXE payload, and a prompt-injection document are none of them "malware" and none will be flagged. It is also itself a long-lived C parser consuming hostile input, so run it as a dedicated unprivileged user in its own container, and monitor `freshclam` database age explicitly — a stale database is a silently degrading control with no alarm on it. Budget it as the last of seven controls, not the first; OWASP lists it under "additional protections".

## Test fixtures (§22.5 "malicious filenames", "oversized files")

`../../../etc/passwd`, `..\\..\\windows\\win.ini`, `x.pdf\x00.php`, `CON.pdf`, `report.pdf:ads.exe`, `evil.php.` with a trailing dot, a 300-character name, `．．／．．／etc/passwd` in full-width characters, a name containing U+202E, a `.docx` sniffing as `application/zip` with no OOXML parts, a `.docx` containing `vbaProject.bin`, a nested and an overlapping zip bomb, a 50 000-frame GIF, an SVG with an external entity, an XLSX with a billion-laughs `sharedStrings.xml`, a PDF with `/OpenAction /JavaScript`, and a JPEG/HTML polyglot — asserted refused, *or* served with `Content-Disposition: attachment` and `nosniff` from the file origin.
