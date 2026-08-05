# Functional Scope — Knowledge Sources, Crawling, Deletion

> Part of the **KnowledgeBot AI** specification — §8.8–8.17, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 8.8 Knowledge Source Types

Supported source categories:

- Uploaded document.
- Uploaded image.
- Single web page.
- URL list.
- Sitemap.
- Manual text entry.
- Markdown entry.

Future source categories:

- Google Drive.
- SharePoint.
- Notion.
- Confluence.
- Git repositories.
- Helpdesk systems.
- Database records.

The future categories should use a connector contract but are not part of the initial implementation.

## 8.9 Source Lifecycle

A source will have one of these states:

- Draft.
- Queued.
- Fetching.
- Parsing.
- Normalizing.
- Chunking.
- Embedding.
- Indexing.
- Ready.
- Ready with warnings.
- Failed.
- Disabled.
- Deleting.
- Deleted.
- Archived.

The currently active source version remains searchable until a new version is fully indexed and activated. This prevents incomplete knowledge during reprocessing.

## 8.10 File Upload Requirements

The upload system will support:

- Drag-and-drop upload.
- File browser selection.
- Multiple files per batch.
- Configurable maximum file size.
- Configurable per-organization storage limits.
- MIME detection independent of filename extension.
- Duplicate detection using content hashes.
- Progress display.
- Cancellation before processing begins.
- Clear status and error reporting.
- Reprocessing with changed parser settings.

Security checks will include:

- Extension allow-list.
- MIME allow-list.
- File-size limit.
- Archive expansion limit.
- Decompression-bomb protection.
- Malware scanning integration point.
- Filename normalization.
- Path traversal prevention.
- Parser timeout.
- Maximum pages, sheets, and slides limits.

## 8.11 Supported Document Handling

### PDF

The platform should extract:

- Embedded text.
- Page boundaries.
- Headings and paragraphs when detectable.
- Lists.
- Tables.
- Captions.
- Images and their locations.
- Document metadata.
- Page numbers for citations.

Scanned or image-based pages should use OCR.

The parser should record warnings for:

- Pages with little or no readable content.
- Low OCR confidence.
- Unsupported encryption.
- Password protection.
- Corrupted pages.
- Very large images.
- Unrecognized tables.

### Word Documents

The platform should preserve:

- Heading hierarchy.
- Paragraph order.
- Lists.
- Tables.
- Hyperlinks.
- Headers and footers when useful.
- Footnotes when supported.
- Section boundaries.

### Excel Workbooks

The platform should treat each sheet as a structured unit.

It should extract:

- Workbook name.
- Sheet name.
- Used cell ranges.
- Table headers.
- Cell values.
- Displayed formula results when available.
- Named tables when available.
- Repeated header relationships.

It should avoid converting a large sheet into an unstructured wall of text.

Chunks should identify:

- Sheet name.
- Table or region name.
- Row range.
- Column headers.

### PowerPoint Presentations

The platform should extract:

- Slide title.
- Slide body text.
- Speaker notes when enabled.
- Tables.
- Image references.
- Slide number.

Each citation should identify the slide number.

### Markdown, HTML, and Plain Text

The platform should preserve:

- Heading hierarchy.
- Lists.
- Tables.
- Code sections as text when allowed.
- Links.
- Section boundaries.

### Images

Image processing should support:

- OCR.
- Basic metadata.
- Optional image description through a configured multimodal model.
- Association with a parent PDF page, document, slide, or standalone source.

The first release should use OCR and optional visual description. Dedicated visual vector retrieval may be added later.

## 8.12 Website Crawling

The crawler will support:

- A single URL.
- A manually supplied list of URLs.
- XML sitemap URL.
- Sitemap index files.
- Multiple nested sitemaps.
- Same-domain deep crawling with a configured depth.
- Include patterns.
- Exclude patterns.
- Maximum page count.
- Crawl delay.
- Request timeout.
- Per-domain concurrency limit.
- User-agent configuration.
- JavaScript rendering for approved pages.
- Main-content extraction.
- HTML-to-Markdown normalization.
- Canonical URL handling.
- Redirect handling.
- Duplicate content detection.

The crawler must respect platform security and administrator policy. Robots.txt behavior should be configurable but should default to respecting it.

## 8.13 Crawl Security

The crawler is an SSRF-sensitive component and must be isolated.

It must reject or restrict:

- Loopback addresses.
- Private network ranges unless explicitly approved for a controlled installation.
- Link-local addresses.
- Cloud metadata endpoints.
- Non-HTTP protocols.
- Excessive redirects.
- DNS rebinding behavior.
- URLs containing embedded credentials.
- Extremely large responses.
- Unsupported content types.

Network egress rules should restrict the crawler container where practical.

## 8.14 Periodic Recrawling

Each crawl source may have:

- Manual-only mode.
- Daily schedule.
- Weekly schedule.
- Monthly schedule.
- Custom cron-like schedule controlled by administrators.

A periodic crawl will:

1. Re-read the sitemap or URL list.
2. Compare discovered URLs with known URLs.
3. Check ETag and Last-Modified headers when available.
4. Fetch content when required.
5. Calculate a normalized-content hash.
6. Skip unchanged pages.
7. Create a new page version for changed content.
8. Add newly discovered pages.
9. Mark missing pages according to policy.
10. Publish the new version only after successful indexing.

Missing-page policies:

- Keep previously indexed content and report it as missing.
- Disable after one missing crawl.
- Disable after a configurable number of consecutive missing crawls.
- Delete automatically after a retention period.

The default should be conservative: disable after repeated confirmation rather than deleting immediately because of one temporary sitemap or server failure.

## 8.15 Manual Text Knowledge

Administrators may create small knowledge entries directly in the admin panel.

Examples:

- Business hours.
- Support policy.
- Product disclaimers.
- Temporary announcements.

Manual entries will support title, body, tags, effective date, expiry date, bot assignments, and version history.

## 8.16 Source Preview and Inspection

For every source, administrators should be able to view:

- Original filename or URL.
- File type.
- Content hash.
- Source status.
- Current active version.
- Last successful processing time.
- Last crawl time.
- Next scheduled crawl.
- Number of pages, slides, sheets, sections, and chunks.
- Parser and OCR warnings.
- Extracted normalized content.
- Chunk boundaries.
- Vector indexing status.
- Assigned bots.
- Deletion state.

## 8.17 Source Deletion and Knowledge Removal

Knowledge removal is a first-class requirement.

### Immediate Logical Removal

When a source is disabled or deletion begins, new retrieval requests must exclude it immediately using source-status filters and source-version activation rules.

### Physical Removal

The deletion workflow will remove:

- Dense vectors.
- Sparse vectors.
- Chunk payloads.
- Derived normalized documents.
- Extracted images when no longer referenced.
- OCR outputs.
- Cached retrieval results.
- Cached answers tied to the source version.
- Original uploaded objects according to retention settings.

### Cascading Identity

Every vector point must be traceable to:

- Organization.
- Bot assignment.
- Source.
- Source item.
- Source version.
- Document element.
- Chunk.

Deletion queries must use stable identifiers, not text matching.

### Verification

After deletion, a verification job will confirm:

- No active chunks remain in PostgreSQL.
- No matching vector points remain in Qdrant.
- No active source assignment remains.
- No retrievable cache entry remains.

An administrator should be able to see deletion completion and any remaining retention obligations.

