"""How a `*_cfg_version` string is composed. One implementation, three callers.

`parser_cfg_version`, `ocr_cfg_version` and `chunker_cfg_version` are components of the ingest
key, which is what decides whether a resubmitted document is "already processed" or a genuine
new version. So the property that matters is not that these strings are unique — it is that
**anything a reviewer could tune must be inside one of them**. A tunable outside the string is a
retune that never reaches a document: the content hash is unchanged, the key matches the
completed run, the admin sees "already processed", and the new settings silently never apply.

The counterpart failure is a string that changes for nothing, which re-embeds and re-indexes an
unchanged corpus at full cost. Both are avoided the same way: the inputs are declared **as data**
so a test can assert that the declared set plus the deliberately-excluded set equals every option
constant the module defines. Neither list is allowed to be a judgement made silently.

CANONICALIZATION, AND WHY THE RULES ARE ASYMMETRIC
--------------------------------------------------
Where the two errors are not equally bad, the rule leans toward the cheap one. A spurious new
version costs money; a missed one costs correctness, permanently, and nobody finds out.

* **Sequence order is significant.** Reordering `ALLOWED_FORMATS` almost certainly means nothing,
  and this will re-version the corpus for it anyway — because the alternative is sorting, and a
  sort cannot tell a set-like tuple from an ordered pipeline like a preprocessing chain, where
  order is the entire meaning.
* **Mapping key order is not significant.** A `dict` is unordered by contract; keys are sorted.
* **Floats go through `repr`**, which is the shortest round-tripping representation and is
  deterministic in CPython. `format(x, ".6f")` would quietly merge two distinct thresholds.
* **Booleans are spelled out** rather than rendered through `int`, so `True` and `1` cannot
  collide — they are different values with different meanings in every option map here.
* **Types are in the payload.** `"300"` and `300` are otherwise the same bytes, and a config
  value that changed from a string to a number changed.

MODEL REVISIONS ARE INPUTS TOO
------------------------------
Docling pins its own model specs to `revision="main"`, a moving branch, so a rebuilt image can
parse the same corpus differently while `docling.__version__` is unchanged. Folding the resolved
commit shas from `models.manifest.toml` in is what makes "reprocess with new parser settings"
falsifiable rather than a claim. They arrive through `model_pins`, never as a second copy.
"""

from __future__ import annotations

import hashlib
from collections.abc import Mapping, Sequence
from pathlib import Path
from typing import Any, Final

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.model_pins import ModelPin

__all__ = ["CFG_DIGEST_CHARS", "canonicalize", "compose"]

#: Hex characters kept from the sha256. The string lands in an ingest key and in a
#: `source_versions` row, where it is compared and never searched, so this is legibility rather
#: than collision resistance — but it is still a fingerprint input: changing it re-versions
#: everything at once.
CFG_DIGEST_CHARS: Final[int] = 12


def canonicalize(value: Any) -> str:
    """One value to one deterministic string. See the module docstring for the rules.

    Raises on a type with no rule rather than falling back to `str()`: a fallback would render
    two different objects identically — most obviously any object without `__str__`, whose
    default repr contains a memory address and would make the digest change on every process.
    """
    if isinstance(value, bool):
        return f"bool:{value}"
    if value is None:
        return "none:"
    if isinstance(value, int):
        return f"int:{value}"
    if isinstance(value, float):
        return f"float:{value!r}"
    if isinstance(value, str):
        return f"str:{value}"
    if isinstance(value, Path):
        return f"path:{value.as_posix()}"
    if isinstance(value, Mapping):
        items = ",".join(
            f"{canonicalize(key)}={canonicalize(item)}"
            for key, item in sorted(value.items(), key=lambda pair: str(pair[0]))
        )
        return f"map:{{{items}}}"
    if isinstance(value, Sequence):
        return "seq:[" + ",".join(canonicalize(item) for item in value) + "]"
    raise KbError(
        ErrorClass.INTERNAL_DEPENDENCY,
        f"no canonical form for {type(value).__name__}; a config version input must have one, "
        "because a value rendered by the default repr carries a memory address and would "
        "change the version on every process start",
        origin=Origin.SELF,
    )


def compose(
    scheme: str,
    *,
    label: str,
    inputs: Mapping[str, Any],
    pins: Sequence[ModelPin] = (),
) -> str:
    """`"parser/v1:docling2.118.0:6a1f0c9e33bd"` — scheme, a readable label, and the digest.

    `scheme` is bumped to force a global reprocess of that stage; `label` is for the human
    reading a `source_versions` row and is **inside the digest too**, so a library upgrade that
    changes nothing else still mints new versions. `inputs` are the option constants, hashed by
    name so a renamed constant is a change; `pins` are the resolved model revisions.

    Deterministic across processes and machines: no clock, no set iteration, no `hash()`.
    """
    if not scheme or not label:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "a config version needs both a scheme and a label; an empty one silently merges "
            "two stages' versions into the same string",
            origin=Origin.SELF,
        )
    if not inputs:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"{scheme}: no inputs — a config version over nothing is a constant, and a "
            "constant cannot detect the retune it exists to detect",
            origin=Origin.SELF,
        )

    lines = [f"scheme={scheme}", f"label={label}"]
    lines += [f"{name}={canonicalize(inputs[name])}" for name in sorted(inputs)]
    lines += [f"pin={pin.fingerprint}" for pin in pins]
    digest = hashlib.sha256("\n".join(lines).encode("utf-8")).hexdigest()
    return f"{scheme}:{label}:{digest[:CFG_DIGEST_CHARS]}"
