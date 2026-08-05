# Chunking Strategy

> Part of the **KnowledgeBot AI** specification — §14, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 14. Chunking Strategy

## 14.1 Principles

Chunking should preserve meaning and structure rather than split only by character count.

The chunker should understand:

- Headings.
- Paragraphs.
- Lists.
- Tables.
- Pages.
- Slides.
- Sheets.
- Sections.
- Captions.

## 14.2 Recommended Defaults

Initial configurable targets:

- Approximately 350 to 700 tokens per chunk.
- Approximately 10 to 15 percent overlap where structural continuity requires it.
- Smaller chunks for FAQs and precise policies.
- Larger chunks for explanatory prose when coherence matters.

These are starting values and must be tuned through evaluation.

## 14.3 Parent and Child Context

Each chunk should retain parent metadata such as:

- Document title.
- Heading path.
- Page or slide.
- Sheet and table.
- Source URL.

A parent-child retrieval enhancement may later retrieve small child chunks but return a larger parent section to the LLM.

## 14.4 Tables

Tables should be represented with headers repeated or associated with each relevant row group.

A table chunk should not lose the meaning of columns.

Large tables should be divided by logical row groups with stable sheet, table, and range metadata.

## 14.5 Repeated Content Removal

The normalizer should identify repeated:

- Headers.
- Footers.
- Cookie notices.
- Navigation menus.
- Legal boilerplate repeated on every page.

Removal rules must be conservative so that important repeated policies are not accidentally deleted.

## 14.6 Chunk Metadata

Every chunk should include:

- Chunk identifier.
- Organization identifier.
- Source identifier.
- Source item identifier.
- Source version identifier.
- Bot-assignment identifiers or an efficient access mapping.
- Sequence number.
- Parent element identifier.
- Heading path.
- Page, slide, sheet, row range, or URL.
- Language.
- Content type.
- Token count.
- Content hash.
- Created time.
- Effective and expiry times when applicable.
- Embedding model identifier.
- Parser version.
- Chunker version.

