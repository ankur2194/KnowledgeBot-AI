"""Docling conversion. Produces elements only; boundaries belong to chunking.

`converter.py` holds the pinned pipeline option set. `serializers.py`, `elements.py` and
`warnings.py` follow: row-wise table serialization, the element rows, and the parse-warning
taxonomy that makes a partial conversion publish as `Ready with warnings` rather than fail.

Structure is the product. Heading level and table membership are the chunker's inputs, and once
a document is flattened to text neither can be recovered.
"""
