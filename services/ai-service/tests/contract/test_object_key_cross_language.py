"""The object-storage layout, composed by BOTH languages and compared string for string.

THE TEST NEITHER SIDE CAN WRITE ALONE, and the second file in this repository of that shape —
`test_signing_cross_language.py` is the first, and this one exists because the failure it was
built for actually happened on the layout instead of on the signature.

`App\\Support\\Kb\\ObjectKey` decides where Laravel puts a tenant's bytes.
`app/storage/objects.py` decides where this service looks for them. Two transcriptions of one
layout can each agree with their own documentation and disagree on a string — and when they do,
the symptom is not a mismatch anywhere. It is `NoSuchKey`, reported honestly as a missing object,
about an object that is present under the other spelling. `docs/22` § Q8 predicted this seam
would break first and it had already broken: the Python side composed only
`ObjectKey::originalUpload()`'s spelling, so every PASTED-TEXT source — stored by
`ObjectKey::originalText()` at the same prefix with a `.txt` suffix — failed to ingest with a
message saying its bytes were not there.

WHAT IS COMPARED, AND WHY IT IS EVERY METHOD RATHER THAN THE INTERESTING ONE
-----------------------------------------------------------------------------
All seven. The prefixes matter as much as the keys, and arguably more: the phase-2 purge sweeps
PREFIXES and the verification enumerates the same PREFIXES, so a prefix that disagrees by one
segment produces an object outside everything the sweep walks — certified clean while it
survives, which is non-negotiable 6 failing in the only direction that produces a signed proof of
a deletion that did not happen.

The refusal matrix is compared too. A guard one side applies and the other does not is a value
one plane will write and the other cannot address — the same class of failure, arriving through
the error path instead of the happy one.

THE HARNESS IS `test_signing_cross_language.py`'s, for the same reasons
------------------------------------------------------------------------
`php` directly against the class file, in `knowledgebot/core-api:dev`, no autoloader and no
framework boot: `ObjectKey` has no facades, no HTTP and one import (`InvalidArgumentException`),
so a bare `require` is enough. The repository is mounted read-only at `/repo` — mounting only
`services/core-api` is what produces eleven unrelated failures in the PHP suite — and the
container gets no network, which proves the class needs none.

Skips when the image is not built, and `KB_TEST_REQUIRE_PHP=1` turns the skip into a failure.
Read that file's docstring for why a local `php` binary is not an alternative.
"""

from __future__ import annotations

import base64
import json
import os
import shutil
import subprocess
from pathlib import Path
from typing import Final

import pytest

from app.core.errors import KbError
from app.storage import objects as keys

REPO_ROOT: Final[Path] = Path(__file__).resolve().parents[4]
OBJECT_KEY_PHP: Final[Path] = REPO_ROOT / "services/core-api/app/Support/Kb/ObjectKey.php"
IMAGE: Final[str] = os.environ.get("KB_TEST_CORE_API_IMAGE", "knowledgebot/core-api:dev")

ORG: Final[str] = "01jqz0000000000000000000aa"
SOURCE: Final[str] = "01jqz0000000000000000000bb"
VERSION: Final[str] = "01jqz0000000000000000000cc"
HASH: Final[str] = "a" * 64

#: Every method on `ObjectKey` that produces a key or a prefix, with the Python function that is
#: supposed to be its twin. The PHP name is the wire here — a rename on either side breaks this
#: list rather than silently halving the comparison.
PAIRS: Final[tuple[tuple[str, str, tuple[str, ...]], ...]] = (
    ("orgPrefix", "org_prefix", (ORG,)),
    ("sourcePrefix", "source_prefix", (ORG, SOURCE)),
    ("originalPrefix", "original_prefix", (ORG, SOURCE)),
    ("originalUpload", "original_upload", (ORG, SOURCE, HASH)),
    ("originalText", "original_text", (ORG, SOURCE, HASH)),
    ("versionPrefix", "version_prefix", (ORG, SOURCE, VERSION)),
)

#: Segments both guards must refuse. A separator or a traversal does not produce a BROKEN key —
#: it produces a well-formed key in the wrong place, potentially outside the org prefix, where
#: the database CHECK is the only remaining line and nothing at all guards a read.
ILLEGAL_SEGMENTS: Final[tuple[str, ...]] = (
    "",
    ".",
    "..",
    "../../other-org",
    "org/with/slash",
    "back\\slash",
    "control\x01char",
    "trailing\n",
)

#: Segments both guards must ACCEPT. Parity in this direction is the half a "reject bad input"
#: test never covers, and it is the half that strands an object: a value Laravel writes and
#: Python refuses is bytes on disk that this service cannot address and the purge cannot name.
LEGAL_SEGMENTS: Final[tuple[str, ...]] = (
    ORG,
    HASH,
    "01JQZ0000000000000000000AA",
    "a space",
    "dots.in.the.middle",
    "unicode-é",
)

_DRIVER: Final[str] = r"""<?php
declare(strict_types=1);
require '/repo/services/core-api/app/Support/Kb/ObjectKey.php';
use App\Support\Kb\ObjectKey;

$cases = json_decode(base64_decode('%%CASES%%'), true, 512, JSON_THROW_ON_ERROR);
$out = ['keys' => [], 'segments' => []];

foreach ($cases['pairs'] as $name => $args) {
    $out['keys'][$name] = ObjectKey::{$name}(...$args);
}

// The guard is private, so it is exercised through the narrowest public method that reaches it
// with the value in a SEGMENT position — which is what the Python side does too.
foreach ($cases['segments'] as $value) {
    try {
        ObjectKey::orgPrefix($value);
        $out['segments'][$value] = 'accepted';
    } catch (\InvalidArgumentException $e) {
        $out['segments'][$value] = 'refused';
    }
}

echo json_encode($out, JSON_THROW_ON_ERROR);
"""


def _docker_can_run_the_php_class() -> str | None:
    """``None`` when the PHP side is runnable, otherwise the reason it is not."""
    if not OBJECT_KEY_PHP.is_file():
        return f"{OBJECT_KEY_PHP} does not exist"
    if shutil.which("docker") is None:
        return "docker is not on PATH"
    probe = subprocess.run(  # noqa: S603
        ["docker", "image", "inspect", IMAGE],  # noqa: S607
        capture_output=True,
        check=False,
    )
    if probe.returncode != 0:
        return (
            f"{IMAGE} is not built locally, and there is no CI that builds it either — so this "
            "matrix is skipped unless you build the image yourself. Set KB_TEST_REQUIRE_PHP=1 to "
            "turn the skip into a failure. Build it with `docker compose build core-api`."
        )
    return None


_UNAVAILABLE: Final[str | None] = _docker_can_run_the_php_class()

if _UNAVAILABLE is not None and os.environ.get("KB_TEST_REQUIRE_PHP") != "1":
    pytest.skip(f"PHP ObjectKey unavailable: {_UNAVAILABLE}", allow_module_level=True)


@pytest.fixture(scope="module")
def php() -> dict[str, dict[str, str]]:
    """Every key and every guard verdict, from the real PHP class, in one container run."""
    assert _UNAVAILABLE is None, (
        f"KB_TEST_REQUIRE_PHP=1 but the PHP class is unavailable: {_UNAVAILABLE}"
    )

    payload = json.dumps(
        {
            "pairs": {php_name: list(args) for php_name, _, args in PAIRS},
            "segments": list(ILLEGAL_SEGMENTS + LEGAL_SEGMENTS),
        }
    )
    script = _DRIVER.replace("%%CASES%%", base64.b64encode(payload.encode()).decode("ascii"))

    completed = subprocess.run(  # noqa: S603
        [  # noqa: S607
            "docker",
            "run",
            "--rm",
            "-i",
            "--network",
            "none",
            "--entrypoint",
            "php",
            "-v",
            f"{REPO_ROOT}:/repo:ro",
            IMAGE,
        ],
        input=script.encode("utf-8"),
        capture_output=True,
        check=False,
        timeout=120,
    )
    assert completed.returncode == 0, (
        f"the PHP class did not run:\n{completed.stdout.decode()}\n{completed.stderr.decode()}"
    )
    results: dict[str, dict[str, str]] = json.loads(completed.stdout)
    assert set(results["keys"]) == {php_name for php_name, _, _ in PAIRS}, (
        "the driver dropped or invented a method"
    )
    return results


@pytest.mark.parametrize(("php_name", "python_name", "args"), PAIRS, ids=[p[0] for p in PAIRS])
def test_both_languages_compose_the_same_string(
    php: dict[str, dict[str, str]], php_name: str, python_name: str, args: tuple[str, ...]
) -> None:
    """Character for character. Not "the same shape" and not "the same segments".

    A trailing slash is the difference between a prefix that sweeps a source and one that sweeps
    every source whose id starts with the same characters. A missing suffix is the difference
    between finding a pasted body and reporting it missing.
    """
    ours = getattr(keys, python_name)(*args)
    assert ours == php["keys"][php_name]


def test_the_comparison_is_capable_of_failing(php: dict[str, dict[str, str]]) -> None:
    """The vacuity control. A harness that returns empty strings for everything would agree with
    a Python side that did the same, and report as a green matrix."""
    assert php["keys"]["originalUpload"].startswith(f"org/{ORG}/")
    assert php["keys"]["originalUpload"] != php["keys"]["originalText"]
    assert keys.original_upload(ORG, SOURCE, HASH) != php["keys"]["versionPrefix"]


@pytest.mark.parametrize("value", ILLEGAL_SEGMENTS)
def test_both_guards_refuse_the_same_segments(php: dict[str, dict[str, str]], value: str) -> None:
    assert php["segments"][value] == "refused", "the PHP guard accepted it"
    with pytest.raises(KbError):
        keys.org_prefix(value)


@pytest.mark.parametrize("value", LEGAL_SEGMENTS)
def test_both_guards_accept_the_same_segments(php: dict[str, dict[str, str]], value: str) -> None:
    """The direction that strands an object rather than exposing one.

    A Python guard STRICTER than PHP's does not fail safe: Laravel writes the key, the row's
    CHECK accepts it, and this service refuses to compose the string it needs to read it back —
    so the bytes are unreachable and, worse, the purge never names them either.
    """
    assert php["segments"][value] == "accepted", "the PHP guard refused it"
    assert keys.org_prefix(value) == f"org/{value}/"


def test_the_original_is_a_sibling_of_versions_on_both_sides(
    php: dict[str, dict[str, str]],
) -> None:
    """ADR-066, asserted where a drift would actually be caught.

    `seaweedfs-s3` non-negotiable 1 still draws `original/` INSIDE the version prefix, so this is
    the one property most likely to be "corrected" back by someone reading the skill.
    """
    for side in (php["keys"], {name: getattr(keys, py)(*args) for name, py, args in PAIRS}):
        assert "/versions/" not in side["originalUpload"]
        assert "/versions/" not in side["originalText"]
        assert side["originalUpload"].startswith(side["originalPrefix"])
        assert side["versionPrefix"].startswith(side["sourcePrefix"])
        assert not side["versionPrefix"].startswith(side["originalPrefix"])
