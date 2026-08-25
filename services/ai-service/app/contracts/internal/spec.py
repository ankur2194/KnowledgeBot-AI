"""The internal seam's OpenAPI document, exported from the routers that serve it.

═══ WHY THIS FILE EXISTS ═══════════════════════════════════════════════════════════════════════

``DumpOpenApiCommand`` on the control plane excludes ``internal/`` on purpose and says why:

    "that seam is FastAPI's own exported document and lives beside this one, and publishing our
    view of it would create two descriptions of one wire."

**That document did not exist.** ``app/contracts/internal/`` held ``__init__.py`` and a
``.gitkeep``, so the deferral's premise was a file nobody had written and the ingestion submission
body was specified by ``IngestionSubmission::toArray()`` and by nothing else — on either plane.
``docs/22`` § Q7. A body with no schema is a body whose optionality, whose nullability and whose
string formats are whatever the current producer happens to emit, discovered by the consumer as a
parse failure or, worse, as a silent coercion.

═══ WHAT IT IS AND IS NOT ══════════════════════════════════════════════════════════════════════

It is **generated, committed, and diffed** — the same generate-and-check contract
``packages/contracts/rules`` and ``packages/design-tokens/generated`` use, and the same one
``kb:dump-openapi --check`` uses on the other plane. `--check` regenerates and compares:

    uv run python -m app.contracts.internal.spec --check      # exits 1 on drift
    uv run python -m app.contracts.internal.spec              # rewrites the artifact

It is **not** a second source of truth. The Pydantic models are; this is their projection, which
is exactly what makes it safe to consume. A hand-maintained copy of a schema is the drift
``contract-steward`` exists to catch.

It covers **only the internal routers**. The public surface is Laravel's and is published by
Laravel; a FastAPI route that is not under ``/internal/`` would be a route clients can reach,
which non-negotiable 3 forbids, so its appearance here is itself a finding.

═══ THE TWO DIVERGENCES Q7 NAMED, AND WHERE EACH LANDED ════════════════════════════════════════

**Timestamps.** ``IngestionSubmission::toArray()`` renders UTC through ``toIso8601String()``,
which emits ``+00:00``; ``pydantic-contracts``:91 specifies ``Z``. Both are valid RFC 3339 and
this seam types them as ``str``, so nothing coerces and nothing breaks. The resolution is that the
EXPORTED SCHEMA is the fixture source rather than either document — and
``tests/contract/test_internal_contract_document.py`` pins that both spellings are accepted, so
whichever the producer emits, a consumer written from this file parses it. Changing the producer
was rejected: the same call appears in eight *client-facing* resources, so a change there is a
public wire change made to satisfy an internal convention.

**Nullability.** Every nullable column reaches the body as a nullable field, and several are
*conditionally* non-null in SQL rather than absolutely — ``storage_key IS NULL OR (content_hash
IS NOT NULL AND mime IS NOT NULL AND byte_size IS NOT NULL)``. A flat ``Optional[str]`` cannot
express that, and a model generated from a sample payload gets it wrong in the permissive
direction. What this document does is make the flat shape *explicit and reviewable*; what it
cannot do is carry the conditional. The conditional lives in the migration and in
``SubmissionItem``'s own docstring, and the honest statement is that a consumer must not infer
"present" from "non-null in the example".
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any, Final

__all__ = ["ARTIFACT", "internal_openapi", "main"]

ARTIFACT: Final[Path] = Path(__file__).with_name("openapi.json")

#: Every path the document is allowed to describe begins with this. A route outside it is
#: reachable by a client, which non-negotiable 3 forbids — so the export refuses rather than
#: publishing it.
INTERNAL_PREFIX: Final[str] = "/internal/"


def internal_openapi() -> dict[str, Any]:
    """Build the document from the live application.

    Imported lazily so that reading this module — which the test does — does not construct a
    FastAPI app as an import side effect.
    """
    from app.main import create_app

    document: dict[str, Any] = dict(create_app().openapi())

    paths = {
        path: item
        for path, item in document.get("paths", {}).items()
        if path.startswith(INTERNAL_PREFIX)
    }
    known = set(document.get("paths", {}))
    outside = sorted(known - set(paths) - {"/health/live", "/health/ready"})
    if outside:
        msg = (
            f"routes outside {INTERNAL_PREFIX} are served by this application: {outside}. "
            "Clients never reach FastAPI (non-negotiable 3), so a public path here is a defect "
            "rather than something to export"
        )
        raise SystemExit(msg)

    document["paths"] = dict(sorted(paths.items()))

    # The components block carries schemas for every model the app knows, including the health
    # ones. Kept whole rather than pruned to the referenced set: a pruner is a second thing to
    # keep correct, and an unreferenced schema costs a consumer nothing.
    return document


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--check",
        action="store_true",
        help="exit 1 if the committed artifact differs from the generated one",
    )
    args = parser.parse_args(argv)

    # `sort_keys` and a trailing newline, so the file is byte-stable across runs and a diff is a
    # real change rather than a dictionary-ordering artifact.
    rendered = json.dumps(internal_openapi(), indent=2, sort_keys=True) + "\n"

    if not args.check:
        ARTIFACT.write_text(rendered, encoding="utf-8")
        return 0

    if not ARTIFACT.is_file():
        sys.stderr.write(f"{ARTIFACT} does not exist; run this module without --check\n")
        return 1
    if ARTIFACT.read_text(encoding="utf-8") != rendered:
        sys.stderr.write(
            f"{ARTIFACT} is out of date. The routers changed and the exported contract did not; "
            "run this module without --check and commit the result\n"
        )
        return 1
    return 0


if __name__ == "__main__":  # pragma: no cover - exercised as a subprocess by the contract test
    raise SystemExit(main())
