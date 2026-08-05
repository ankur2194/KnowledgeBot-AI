---
name: kb-chunking-rules
description: Structure-aware chunking doctrine for KnowledgeBot AI — how parsed document elements become chunks in services/ai-service. Use whenever writing or tuning the chunker, sizing chunks, serializing tables, stripping repeated boilerplate, or adding a chunk metadata field. Boundaries follow document structure, never character counts, and every chunk carries the full metadata schema or citation and deletion both break. Pairs with docling-parsing (makes the elements) and qdrant-hybrid-search (stores the result).
---

# Structure-Aware Chunking Rules

Chunker config `chunker/v1`. Token counts measured with the **BGE-M3 tokenizer** (XLM-RoBERTa SentencePiece), never characters and never `tiktoken`.
**Authoritative spec:** docs/09-chunking.md §14, docs/03-functional-knowledge-sources.md §8.11, docs/07-rag-query-pipeline.md §12.13 & §12.16, docs/11-data-model.md §16.4

## Non-negotiables

- **Boundaries are structural, not arithmetic.** A chunk ends at a heading, paragraph, list, row-group, page, slide, or sheet edge — never mid-sentence and never mid-row. A mid-sentence cut still embeds cleanly; it just embeds a different meaning, so the failure is silent and only visible as "retrieval is bad for that one document".
- **Every chunk carries the full metadata schema below.** Citation (§12.16) and deletion (§8.17) both read it. A chunk missing `page`/`slide`/`sheet`+`row_range` is uncitable — the UI has no excerpt to open; a chunk missing `source_version_id` is undeletable by identifier, and text-matched deletion is forbidden.
- **A table chunk without its column headers is a defect, not a degradation.** Assert on it in the chunker; do not log a warning and continue.
- **Repeated-content removal is conservative and per-source.** Remove a block only when it is both high-frequency *and* structurally chrome. Legal boilerplate looks exactly like nav boilerplate to a frequency counter, and deleting it means the bot cannot cite a disclaimer it is required to cite.
- **Chunking config is part of version identity, and chunking is deterministic.** Changing sizes, overlap, or serialization changes `chunker_version`, which mints new source versions and forces reprocessing (`kb-source-lifecycle`) — never a quiet in-place edit. Output must be a pure function of (elements, config); no LLM decides a boundary, because §13.5 verifies expected chunk counts before activating a version and a nondeterministic chunker fails publication intermittently.
- **The sizes below are starting values to be tuned by evaluation** (docs/16-evaluation.md), not defended by intuition. Change them behind an eval run that shows the delta, and bump `chunker_version`.

## How we use it

### What this skill does not own

| Concern | Owner |
|---|---|
| Documents → `document_elements` (parsing, OCR, layout, table structure) | `docling-parsing` |
| Turning chunk text into dense/sparse vectors, tokenizer loading | `bge-m3-embeddings` |
| Storing chunks as points, payload indexes, filters, search | `qdrant-hybrid-search` |
| Semantics of `org_id` / `bot_ids` and the mandatory filter | `kb-tenancy-isolation` |
| Version creation, activation, retirement | `kb-source-lifecycle` |

### Layout — the pass runs between §13.2 stage 10 (enrichment) and stage 12 (embedding)

```
services/ai-service/app/ingestion/chunking/
├── policy.py           # per-content_type size targets — the only tunable knobs
├── grouper.py          # elements → groups, maintains the heading stack
├── table_chunker.py    # header-bound row groups (below)
├── boilerplate.py      # frequency + structure repeated-block detection
├── metadata.py         # ChunkMetadata — the schema below, one source of truth
└── chunker.py          # orchestration; emits chunks linked to element ids
```

### Size policy

| `content_type` | Target tokens | Overlap | Why |
|---|---|---|---|
| `faq` | 120–250 — one Q+A, never two | none | The Q+A pair *is* the retrieval unit; overlap blurs two answers together |
| `policy` | 250–450 — one clause | none | Must be quotable verbatim; precision beats coherence |
| `prose` | 500–700 | 10–15%, one sentence-boundary window | Argument spans paragraphs |
| `table_rows` / `sheet_region` | header + as many whole contiguous rows as fit ≤700 | header repeat only | Rows are independent; prose overlap would just duplicate rows |
| `slide` | whole slide — title + body + notes + tables, even at 80 tokens | none | The slide is the citation unit (§12.16) |
| `code` | whole block, atomic | none | A split code block is unrunnable and unretrievable |

Overlap applies **only inside a continuous prose run** — never across a heading, page, slide, sheet, or table boundary. Overlap across a structural edge buys nothing and costs an index slot per chunk.

**The embedded text is not the raw text.** Every chunk is embedded with a prefix built from its own metadata (`Refund Policy > Cancellations > Non-refundable items` / `[Page 7]`, then the body) — it makes a chunk reading "these are not refundable" retrievable by "refund policy". Getting identifying context into the chunk is the single largest measured lever in RAG: Snowflake moved SEC-filing accuracy from ~50–60% to 72–75% with deterministic document-level metadata alone, and it *beat* per-chunk LLM-generated context, which lost 5.8 pts. So do this before reaching for [contextual retrieval](https://www.anthropic.com/engineering/contextual-retrieval) (35–67% failure reduction, but $1.02/M tokens and only with prompt caching). Two consequences: the prefix counts **inside** the token budget, and `content_hash` must cover **exactly the string sent to the embedder** — see Gotchas.

### Table chunking (the case people get wrong)

Serialize tables **row-wise, with every column name repeated in every row** — not as a markdown/HTML grid. On the ConfQuestions benchmark this lifted P@1 from 0.382 (markdown) / 0.368 (HTML) to 0.528, ~35% relative ([RAGonite](https://arxiv.org/abs/2412.10571)). Embed header **and** rows, never headers alone (Recall@10 0.741 vs 0.208 on FeTaQA) and never an LLM summary in place of the table — summaries retrieve *worse* than the table itself ([TARGET](https://arxiv.org/html/2505.11545)).

```python
# services/ai-service/app/ingestion/chunking/table_chunker.py
from typing import Callable, Iterator
from .elements import TableElement   # owned by `docling-parsing`, never built here
# TableElement: .element_id .heading_path .caption .sheet (XLSX only) .table_ref
# (named region, e.g. "Q3_Plans") .header (merged / multi-row headers ALREADY
# flattened to one row) .rows .first_row_number (1-based, as shown in Excel).

MAX_TOKENS = 700

def _row(header: list[str], n: int, cells: list[str]) -> str:
    # Row-wise verbalization, not a pipe grid: every row repeats every column
    # name, so one row stays interpretable after retrieval tears it off the grid.
    return f"Row {n} — " + "; ".join(f"{h}: {v.strip() or '—'}"
                                     for h, v in zip(header, cells))

def _render(el: TableElement, rows: list[list[str]], first: int) -> str:
    head = [" > ".join(el.heading_path + [el.caption or el.table_ref or "Table"])]
    if el.sheet:
        head += [f"Sheet: {el.sheet} · Table: {el.table_ref or '—'}"]
    head += [f"Rows {first}–{first + len(rows) - 1} of {len(el.rows)}", ""]
    return "\n".join(head + [_row(el.header, first + i, r)
                             for i, r in enumerate(rows)])

def chunk_table(el: TableElement, count: Callable[[str], int]) -> Iterator[dict]:
    """Split by WHOLE rows. Column names repeat in every row — that is the whole
    point; after a split, a bare `4,999 | 25` is two unlabelled integers."""
    def emit(rows, first):
        return {"text": _render(el, rows, first), "content_type": "table_rows",
                "row_range": [first, first + len(rows) - 1], "sheet": el.sheet,
                "table_ref": el.table_ref, "document_element_id": el.element_id}

    overhead = count(_render(el, el.rows[:1], el.first_row_number))
    buf, first, used = [], el.first_row_number, 0
    for offset, row in enumerate(el.rows):
        cost = count(_row(el.header, first, row))
        if buf and overhead + used + cost > MAX_TOKENS:
            yield emit(buf, first)
            first, buf, used = el.first_row_number + offset, [], 0
        buf.append(row)
        used += cost
    if buf:
        yield emit(buf, first)
```

**Worked example.** `Pricing.xlsx`, sheet `Q3 Plans`, named table `Q3_Plans`, `A1:D41` — header in row 1, 40 data rows.

*Wrong (what a character splitter does):* one 2,800-token CSV blob, hard-truncated to 700 → rows 19–41 vanish from the index and the bot answers "the Enterprise plan is not listed". Or split at 700 characters → chunk 2 opens `Pro | 4,999 | 25 | Yes` with no header, and the bot answers *"the Pro plan includes 4,999 seats"*.

*Right:* three chunks, each row self-describing, each chunk stably located —

```
Pricing > Q3 Plans > Q3_Plans         ← heading_path + caption/table_ref
Sheet: Q3 Plans · Table: Q3_Plans     ← disambiguates two tables on one sheet
Rows 2–15 of 40                       ← citation label; "of 40" tells the model
                                        it is looking at a slice, not the whole
Row 2 — Plan: Starter; Monthly price (INR): 999; Seats included: 3; Priority support: No
Row 3 — Plan: Pro; Monthly price (INR): 4,999; Seats included: 25; Priority support: Yes
…
```

→ `row_range: [2, 15]`, `sheet`, `table_ref`, `content_type: "table_rows"`. Any single row survives being retrieved alone. The citation reads *"Pricing.xlsx — Q3 Plans, rows 2–15"* (§12.16) and packing (§12.13) knows this is a table it must not cut.

### Chunk metadata schema

Every chunk carries all of these. `(+)` marks fields not enumerated in §14.6 that citation, deletion, or dedup need in practice — add them and say so in the ADR.

| Field | Type | Source | Why it exists |
|---|---|---|---|
| `chunk_id` | ULID `char(26)` | chunker | The relational chunk identity (`postgresql-patterns`). Deletion targets identifiers, never text (§8.17) — but the **vector point id is not derived from this**; it is the deterministic `uuid5` of `kb-source-lifecycle`, because a chunk id minted per run is not stable across a replay |
| `org_id` | UUID | source record | Mandatory tenant filter on every query — `kb-tenancy-isolation` |
| `source_id` | UUID | source | "Delete this source" fan-out |
| `source_item_id` | UUID | source item | A sitemap page re-versions and deletes independently of its source |
| `source_version_id` | UUID | version | Active-version filter; retirement deletes on this alone (§13.5) |
| `bot_ids` | UUID[] or access key | bot assignment | Bot-scoped retrieval filter; shape is `kb-tenancy-isolation`'s call |
| `seq` | int | chunker | Document order; packing merges adjacent chunks (§12.13) and needs it |
| `document_element_id` | UUID | Docling element | Traceability required by §8.17 cascading identity |
| `element_ids` `(+)` | UUID[] | grouper | A prose chunk spans several elements; the singular field loses all but one |
| `parent_element_id` | UUID | element tree | Small-to-big: retrieve the child, return the enclosing section |
| `heading_path` | string[] | heading stack | Embedded prefix **and** the citation's "where in the document" |
| `page` / `page_end` `(+)` | int? | PDF | Citation label; `page_end` set only when a chunk legitimately crosses a break |
| `slide` | int? | PPTX | Citation label (§8.11 requires the slide number) |
| `sheet` | string? | XLSX | Citation label |
| `table_ref` `(+)` | string? | named table/region | Disambiguates two tables on one sheet — "rows 2–15" alone is ambiguous |
| `row_range` | [int,int]? | table/sheet | Citation "rows 2–15"; also re-maps after a re-chunk |
| `url` | string? | crawl | Citation link |
| `anchor` `(+)` | string? | HTML id / heading slug | Deep-links the citation to the section, not the page top |
| `char_start`/`char_end` `(+)` | int | normalized doc | The excerpt highlight §12.16 promises; page number alone cannot produce it |
| `lang` | BCP-47 | detector | Multilingual filtering and reranking |
| `content_type` | enum | element type | Drives size policy, packing, and table-integrity checks |
| `token_count` | int | BGE-M3 tokenizer | Context budget (§12.13); over-length guard |
| `content_hash` | sha256 | chunker | Idempotency (§13.3) — unchanged chunk skips re-embedding |
| `overlap_of` `(+)` | UUID? | chunker | Lets packing drop a near-duplicate instead of spending two evidence slots |
| `created_at`, `effective_at`/`expires_at` | timestamptz(?) | chunker / source | Audit; and time-scoped policies retrieval must exclude once expired |
| `embedding_model_id` | string | config | e.g. `bge-m3@<rev>`; a mixed-model collection is silently wrong |
| `parser_version`, `chunker_version` | string | `docling-parsing` / this skill | Reproducibility, and the reprocess trigger when config changes but content does not (§13.3) |

## Gotchas

- **Symptom: "the Pro plan includes 4,999 seats."** A table was split and every chunk after the first lost the header row, so the model matched values to columns positionally and guessed. Fix: `chunk_table` repeats the column names in every row and asserts that no `table_rows` chunk ships without them. Merged or two-row headers must be flattened by `docling-parsing` first — a spanned cell silently shifts every column to its right. **Do not assume Docling's `HybridChunker(repeat_table_header=True)` covers you:** `get_header_and_body_lines` is implemented only on the Markdown/HTML table serializers, while the *default* chunking serializer is `TripletTableSerializer`, which does not override it — so the base returns no header lines and the header prefix is never emitted. Choose the serializer deliberately rather than trusting the flag name.
- **Symptom: one document retrieves badly while the rest of the corpus is fine, and its chunks start mid-word.** Something applied a hard token cap *after* the structural pass — usually a defensive truncate before `encode()`. Fix: the cap belongs to the grouper; the embed step must **raise** on an over-length chunk, never truncate. Truncated text embeds perfectly well, it just means something else.
- **Symptom: the top-5 evidence is the same paragraph three times, and a second relevant source never makes the context.** Overlap applied at structural boundaries, so near-identical chunks compete for every slot. Chroma's eval shows overlap is a bad trade even before this: recursive 400/0 → 400/200 *lost* recall (89.5%→88.1%) and a third of Precision_Ω (17.7%→13.9%), and the OpenAI Assistants 800/400 default scored worst of everything tested (Precision_Ω 4.7%). Fix: overlap only inside a prose run, keep it ≤15%, and set `overlap_of` so packing collapses the family to one.
- **Symptom: the bot quotes the cancellation clause but the citation says only "Terms.pdf, page 7".** `heading_path` was not carried, so there is nothing to name the section. Fix: the grouper maintains a heading stack across the whole element walk; `heading_path` may be `[]` only for genuinely flat documents. Same failure hides behind a missing `anchor` on crawled pages — the citation link opens the page top and the user cannot find the sentence.
- **Symptom: the bot cannot produce a legally required disclaimer that is visibly on every page of the site.** Three separate mechanisms cause this and none of them is your code: **trafilatura hard-deletes `<footer>`, `<aside>`, `<nav>`, `<form>` by tag** in `MANUALLY_CLEANED`, before any scoring — no threshold or `favor_recall` brings a footer disclaimer back. **jusText** marks any block under 70 chars or with link density >0.2 as bad and treats document edges as bad, which is exactly where notices live. **Crawl4AI's `fit_markdown`** gives link and `<strong>` tags weight 0, so it deletes linked and bolded words *mid-sentence* leaving grammatical but gutted text (issue #582, closed as intended design) — and its docs advertise removing "disclaimers" as a feature. Fix: pre-extract legal subtrees before any extractor sees the HTML; keep both `raw_markdown` and `fit_markdown`; **label rather than delete** — Docling already emits `PAGE_HEADER`/`PAGE_FOOTER`/`FOOTNOTE`, so filter at index time and keep every removal reversible and auditable via a `removed_block` record.
- **Symptom: a standard safety notice or licence clause is missing from every document that contains it.** Corpus-wide near-duplicate removal. FineWeb measured this inverting the quality signal: global MinHash dropped ~80% of data and what *survived* was worse than what was cut — canonical, well-written text is the most duplicated, so it is deleted first. Fix: dedup within a document or a site section, never corpus-wide, and byte-exact rather than MinHash.
- **Symptom: an unchanged file re-uploaded re-embeds the entire corpus** — or worse, a renamed heading leaves stale vectors serving the old text. Both are the same bug: `content_hash` and the embedder input disagree. Hash **exactly** the bytes sent to `encode()`, including the heading prefix. Hash narrower than the input → stale vectors; wider (page footers, run timestamps, a re-generated date line) → an infinite re-embed bill.
- **Symptom: retrieval works for short chunks and silently fails for long ones, with no error anywhere.** `BGEM3FlagModel` defaults to `query_max_length=512` and `passage_max_length=512` and calls the tokenizer with `truncation=True` — so a 700-token chunk is indexed from its first ~512 tokens and the rest is discarded without a warning. Worse, sentence-transformers reads `max_seq_length: 8192` from `sentence_bert_config.json`, so **the two loaders truncate the same model at different lengths**. Fix: pass the length explicitly and assert it at startup; `bge-m3-embeddings` owns the call.
- **Symptom: chunks overflow the embedder, or Hindi/Arabic documents come out half the intended size.** Token counts estimated as `len(text)//4` or with `tiktoken`. BGE-M3 uses XLM-RoBERTa SentencePiece (250k vocab, unigram) — a different algorithm *and* vocabulary from `cl100k_base`, and the divergence is largest exactly on non-Latin scripts. Fix: count with the real tokenizer. Never size chunks toward BGE-M3's 8192 ceiling: BAAI's own maintainer recommends ~512, and RusBEIR measured quality plateauing by ~2048 — 8192 is a limit, not a target.
- **Symptom: an answer inverts a policy — "yes, water damage is covered."** A list was split between its introducing stem ("The following are **not** covered:") and its items, so the items chunk reads as an inclusion list. Fix: a list's stem is bound to its items; a list that will not fit is split into stem + items *repeating the stem*, exactly like a table header. This is the highest-severity chunking bug we have, because the answer is confidently wrong rather than absent.
- **Symptom: version activation fails intermittently on a file that processes fine on retry** (§13.5 expected-chunk-count check). The chunker is nondeterministic — dict iteration order, a language detector fed a short string, or threaded element processing. Fix: chunking is a pure function of (elements, config); golden-file test the chunk count and hashes for each format fixture.
- **Symptom: a citation points at page 7 but the excerpt is on page 8.** A chunk crossed a PDF page break and inherited only its first element's page. Fix: chunks cross a page boundary only to complete an unterminated sentence, and then must set `page_end`. <!-- UNVERIFIED: whether Docling reliably marks a paragraph as continued across a page break for scanned PDFs -->
- **Symptom: a spreadsheet answers correctly for the first sheet and hallucinates for the rest.** The whole workbook was flattened into one text stream, so `sheet` is wrong for everything past the first. §8.11 requires each sheet be treated as a structured unit — chunk per sheet, never per workbook.

## Official docs

- [Docling — chunking / HybridChunker](https://docling-project.github.io/docling/concepts/chunking/) and [serialization](https://docling-project.github.io/docling/concepts/serialization/) — the tokenizer-aware structure-preserving chunker our grouper follows, and the table/picture serializer hooks a custom table serializer plugs into.
- [Anthropic — Contextual Retrieval](https://www.anthropic.com/news/contextual-retrieval) — prepending generated context before embedding; the paid upgrade over our metadata prefix.
- [Chroma — Evaluating Chunking Strategies for Retrieval](https://research.trychroma.com/evaluating-chunking) — the eval methodology to copy, and the overlap numbers quoted above. Note its finding that well-parametrised recursive splitting is a *strong* baseline; beat it, don't assume you have.
- [RAGonite](https://arxiv.org/abs/2412.10571) (row verbalization, P@1 0.528 vs 0.382 markdown) and [TARGET](https://arxiv.org/html/2505.11545) (header+rows vs headers-only; LLM summaries retrieve worse) — the evidence behind our table serializer.
- [Is Semantic Chunking Worth the Computational Cost?](https://aclanthology.org/2025.findings-naacl.114/) — NAACL 2025: embedding-breakpoint chunking does not pay. Do not confuse it with structure-aware chunking, which does.
- [LangChain — ParentDocumentRetriever](https://python.langchain.com/docs/how_to/parent_document_retriever/) and [LlamaIndex — AutoMergingRetriever](https://docs.llamaindex.ai/en/stable/examples/retrievers/auto_merging_retriever/) — reference implementations of the small-to-big pattern `parent_element_id` enables. Both dedupe parent ids, so retrieving k children returns *fewer* than k parents — the effective k shrinks unpredictably while each unit grows.
- [BGE-M3](https://huggingface.co/BAAI/bge-m3) and [FlagEmbedding `m3.py`](https://github.com/FlagOpen/FlagEmbedding/blob/master/FlagEmbedding/inference/embedder/encoder_only/m3.py) — the tokenizer, the 8192 ceiling that bounds but does not set our chunk size, and the 512 truncation default that will bite you.

## Definition of done

- [ ] No chunk boundary falls mid-sentence, mid-row, or between a list stem and its items — asserted in the chunker, covered by a golden-file test per format (PDF, DOCX, XLSX, PPTX, HTML, MD).
- [ ] Every `table_rows` chunk repeats the column names in every row and carries a `row_range`; asserted, not warned. A test takes one row in isolation and confirms it is still interpretable.
- [ ] `passage_max_length` is asserted ≥ `MAX_TOKENS + 2` at startup, so an over-length chunk raises instead of being silently truncated to 512. The `+ 2` is the `<s>`/`</s>` pair the tokenizer adds and our `token_count` excludes; asserting bare `MAX_TOKENS` leaves a two-token overhang that truncates the tail of a maximum-size chunk (`bge-m3-embeddings`).
- [ ] Re-running the chunker on an unchanged fixture produces identical chunk counts, `seq` values, and `content_hash`es.
- [ ] `token_count` comes from the BGE-M3 tokenizer (asserted against a Devanagari fixture, not `len(text)//4`) and `content_hash` equals `sha256` of the exact string passed to the embedder.
- [ ] Every metadata field in the table above is populated or explicitly `None`; a schema test fails on an unset required field.
- [ ] A citation smoke test renders page / slide / sheet+`row_range` / URL+`anchor` for one fixture of each source type and opens the excerpt via `char_start`/`char_end`.
- [ ] Boilerplate removal on a crawl fixture keeps a per-page legal disclaimer and emits a `removed_block` record for each removal.
- [ ] Size or overlap changes ship with an eval run (docs/16-evaluation.md) and a bumped `chunker_version`.
