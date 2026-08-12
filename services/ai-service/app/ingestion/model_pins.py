"""The resolved local model revisions, read from `models.manifest.toml`.

Parsing and OCR are the only stages that still run a model on our own hardware (ADR-030 moved
embedding and reranking onto the provider API), so this is the last place in the service where a
model is named by a **commit sha** rather than by a vendor alias. That difference is the whole
value of the file: a sha is content-addressed, so "these weights produced this index" is a
falsifiable claim here in a way it can never be on the embedding path.

WHY THE REVISIONS ARE READ RATHER THAN RESTATED
----------------------------------------------
`scripts/security/license_gate.py` already reads the manifest and blocks a build on an
unresolved revision. If `parser_cfg_version` carried its own copy of the shas, the two would
drift the first time one was bumped, and the drift is silent in the worst direction: the gate
would be scanning the weights the manifest names while the ingest key claimed the weights the
code names, so a genuine weight change could dedupe straight through as "already processed".
One file, two readers, no second copy.

WHAT A PIN IS AND IS NOT
------------------------
A row here says *which* revision the image is built from. It does not, on its own, make the
running process use it. Docling pins its own layout spec to `revision="main"` — a moving branch
— so `parsing/converter.py` has to pass the revision from this module explicitly; a manifest sha
that no code hands to the downloader is documentation, not a pin, and the symptom is a rebuilt
image parsing the same corpus differently while `docling.__version__` is unchanged.

STRICTER THAN THE GATE, DELIBERATELY
------------------------------------
The gate accepts any 40 hex characters in either case. This module additionally requires them
**lowercase**, because the string is hashed into `parser_cfg_version` and `ocr_cfg_version`:
`8F39AD…` and `8f39ad…` are the same weights and would otherwise be two different config
versions, re-ingesting the whole corpus for a change of case in a TOML file.

A malformed or incomplete manifest is `internal_dependency` with `Origin.SELF` — our own defect,
500, never retryable, because no number of attempts fixes a bad file. It is raised when the
pins are first read rather than at import, so a worker that never parses a document is not
killed by it; a worker that does should read the pins at startup and fail the **container**, not
the document.
"""

from __future__ import annotations

import tomllib
from dataclasses import dataclass
from functools import cache
from pathlib import Path
from typing import Final

from app.core.errors import ErrorClass, KbError, Origin

__all__ = [
    "MANIFEST_PATH",
    "REQUIRED_PINS",
    "REVISION_LENGTH",
    "ModelPin",
    "load_pins",
    "pin",
]

#: `services/ai-service/models.manifest.toml`. Resolved from this file rather than from the
#: working directory: a lookup that only works when the process was started from the service
#: directory fails in the one place it matters, which is a worker container.
MANIFEST_PATH: Final[Path] = Path(__file__).resolve().parents[2] / "models.manifest.toml"

#: A git commit sha, and the gate's own length check.
REVISION_LENGTH: Final[int] = 40

#: The models this service actually loads, as data, so a manifest that quietly lost a row fails
#: here instead of producing a `parser_cfg_version` that is missing an input and therefore no
#: longer changes when that model changes. Ordered, because the order is hashed.
REQUIRED_PINS: Final[tuple[str, ...]] = (
    "docling-layout",
    "docling-tableformer",
    "rapidocr-onnx",
)

_HEX: Final[frozenset[str]] = frozenset("0123456789abcdef")


@dataclass(frozen=True, slots=True)
class ModelPin:
    """One `[[model]]` row, reduced to the fields the ingestion path reads.

    Frozen: a pin mutated after a config version was composed produces an index whose recorded
    identity names weights other than the ones that made it, and both strings look plausible.
    """

    id: str
    repo: str
    repo_host: str
    revision: str
    license: str
    format: str
    shipped: bool

    @property
    def fingerprint(self) -> str:
        """`docling-layout@huggingface:docling-project/docling-layout-heron@8f39ad3c…`

        Repo and host travel with the sha because a sha alone is not an identity: the same 40
        characters mean nothing without the repository they index into, and the `docling-layout`
        row has already pointed at the wrong repository once.
        """
        return f"{self.id}@{self.repo_host}:{self.repo}@{self.revision}"


def _fail(detail: str) -> KbError:
    return KbError(
        ErrorClass.INTERNAL_DEPENDENCY,
        f"{MANIFEST_PATH.name}: {detail}",
        origin=Origin.SELF,
    )


@cache
def load_pins() -> dict[str, ModelPin]:
    """Every required pin, validated, keyed by model id.

    Cached because the manifest cannot change under a running process — it is baked into the
    image beside the weights it describes — and because `parser_cfg_version` is called per
    document.
    """
    try:
        raw = tomllib.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    except OSError as exc:
        raise _fail(f"unreadable ({exc})") from exc
    except tomllib.TOMLDecodeError as exc:
        raise _fail(f"not valid TOML ({exc})") from exc

    rows = raw.get("model") or []
    pins: dict[str, ModelPin] = {}
    for row in rows:
        identifier = str(row.get("id", ""))
        if identifier not in REQUIRED_PINS:
            # Not an error: the manifest may legitimately carry eval-only or unshipped weights
            # this service never loads. It is only the REQUIRED set that must be present.
            continue
        if identifier in pins:
            raise _fail(f"model {identifier!r} appears more than once")
        for field in ("repo", "repo_host", "revision", "license", "format", "shipped"):
            if field not in row:
                raise _fail(f"model {identifier!r} is missing required field `{field}`")

        revision = str(row["revision"])
        if len(revision) != REVISION_LENGTH or set(revision) - _HEX:
            # The same rejection the licence gate makes, and for the same reason: "main" moves,
            # so a scan of it proves nothing about what shipped. Lowercase is ours — see the
            # module docstring.
            raise _fail(
                f"model {identifier!r}: revision {revision!r} is not a "
                f"{REVISION_LENGTH}-character lowercase commit sha"
            )
        if not row["shipped"]:
            raise _fail(
                f"model {identifier!r} is marked `shipped = false` but the ingestion worker "
                "loads it — an unshipped row means its licence was never reviewed for an "
                "artifact a self-hoster receives"
            )

        pins[identifier] = ModelPin(
            id=identifier,
            repo=str(row["repo"]),
            repo_host=str(row["repo_host"]),
            revision=revision,
            license=str(row["license"]),
            format=str(row["format"]),
            shipped=True,
        )

    missing = [name for name in REQUIRED_PINS if name not in pins]
    if missing:
        raise _fail(
            f"no [[model]] entry for {missing} — the config version that folds them in would "
            "silently stop changing when those weights change"
        )
    return pins


def pin(model_id: str) -> ModelPin:
    """One pin by id. Raises rather than returning `None`: a caller that treats a missing pin as
    "no revision to fold in" composes a config version that cannot detect a weight change."""
    pins = load_pins()
    if model_id not in pins:
        raise _fail(f"no pin named {model_id!r}; known pins are {sorted(pins)}")
    return pins[model_id]
