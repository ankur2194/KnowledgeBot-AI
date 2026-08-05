# The row-verbalizing table serializer

Companion to `SKILL.md`. `RowVerbalizingTableSerializer` and `KbSerializerProvider` live in
`services/ai-service/app/ingestion/parsing/serializers.py`. They exist because Docling's stock
chunking serializer emits a shape `kb-chunking-rules` cannot survive a split of — see the first two
Gotchas in `SKILL.md`, which are the reasoning behind every line below.

```python
# services/ai-service/app/ingestion/parsing/serializers.py
from docling_core.transforms.chunker.hierarchical_chunker import (
    ChunkingDocSerializer, ChunkingSerializerProvider)
from docling_core.transforms.serializer.base import BaseTableSerializer, SerializationResult
from docling_core.transforms.serializer.common import create_ser_result
from docling_core.types.doc import DoclingDocument, TableItem

HDR, ROW = "Columns: ", "Row "


class RowVerbalizingTableSerializer(BaseTableSerializer):
    """One line per row; every column name repeated on every row.

    Docling's own TripletTableSerializer emits `<col-0 value>, <column> = <value>`,
    which keys every cell to column 0 — wrong the moment column 0 is not a key —
    and joins the whole table with ". " into a single line (see Gotchas).
    """

    def _txt(self, cell, doc, ser, **kw) -> str:
        # RichTableCell (nested content in a cell) resolves via _get_text; called
        # WITHOUT doc it returns the literal string "<!-- rich cell -->".
        return (cell._get_text(doc=doc, doc_serializer=ser, **kw) or "").strip() or "—"

    def serialize(self, *, item: TableItem, doc_serializer, doc, **kw) -> SerializationResult:
        grid = item.data.grid   # spanned cells are repeated into every position they
        if not grid:            # cover, which is what flattens a merged/two-row header
            return create_ser_result(text="", span_source=item)
        head = [self._txt(c, doc, doc_serializer, **kw) for c in grid[0]]
        body = grid[1:] if any(c.column_header for c in grid[0]) else grid
        lines = [HDR + "; ".join(head) + "\n"] + [
            f"{ROW}{i + 2} — " + "; ".join(
                f"{h}: {self._txt(c, doc, doc_serializer, **kw)}" for h, c in zip(head, r)
            ) + "\n" for i, r in enumerate(body)]
        cap = doc_serializer.serialize_captions(item=item, **kw).text
        return create_ser_result(text="".join(([cap + "\n"] if cap else []) + lines),
                                 span_source=item)

    def get_header_and_body_lines(self, *, table_text: str, **kw):
        # MUST be overridden. The base returns [] header lines, which is exactly why
        # HybridChunker(repeat_table_header=True) is a silent no-op by default.
        lines = [f"{ln}\n" for ln in table_text.split("\n") if ln.strip()]
        return ([ln for ln in lines if ln.startswith(HDR)],
                [ln for ln in lines if ln.startswith(ROW)])


class KbSerializerProvider(ChunkingSerializerProvider):
    def get_serializer(self, doc: DoclingDocument) -> ChunkingDocSerializer:
        return ChunkingDocSerializer(doc=doc,
                                     table_serializer=RowVerbalizingTableSerializer())
```
