"""OCR engine selection and the untrusted-image decode envelope.

Docling invokes OCR; this package decides which engine, at what DPI, whether a page needs it at
all, and what the resulting confidence numbers mean. The engine is pinned by class — auto
selection resolves differently per host, so the same scan yields different text on a laptop and
in the worker image while the config version string is identical and nothing reprocesses.

Confidence is normalized here and propagated into warnings rather than discarded: a document
that parsed "successfully" at 40% confidence is a support ticket waiting to happen.
"""
