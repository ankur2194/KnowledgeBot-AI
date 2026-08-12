"""Structure-aware chunking. Boundaries follow document structure, never character counts.

`chunker.py` owns the orchestration and the chunk metadata schema; `table_chunker.py` owns
header-bound row groups. Still to come: `policy.py`, `grouper.py` and `boilerplate.py`.

Chunking reads parsed structure, never text something flattened first, and it is a pure
function of (elements, config) — publication verifies an expected chunk total before anything
activates, so a nondeterministic chunker fails publication intermittently on documents that
succeed on retry.
"""
