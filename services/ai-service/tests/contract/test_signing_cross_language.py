"""The KB1 canonical string, computed by BOTH languages and compared byte for byte.

THE ONE TEST NEITHER SIDE CAN WRITE ALONE. `InternalRequestSignerTest.php` asserts the PHP
signer against PHP's idea of the format; `tests/unit/test_canonical_string.py` asserts two
Python transcriptions against each other. Both are green today and neither can see the failure
that matters: the two languages agreeing on a *description* of the format and
disagreeing on its *bytes*. That failure is a 401 on a request that looks correct in both logs,
and it is invisible to every test that stays inside one runtime.

So this file runs `App\\Services\\Internal\\InternalRequestSigner` for real, in a container, over
a matrix of header sets, and compares the bytes it returns with `app/core/signing.py`'s.

WHY THIS FILE IS THE ONE EXCEPTION TO THE TIER'S OWN RULE
----------------------------------------------------------
`tests/contract/README.md` says this tier is `ASGITransport` plus fakes. Nothing is faked here
and there is no ASGI app in sight — because the thing under test is the *other implementation*,
and a fake of it would be a third transcription asserting that the two transcriptions we wrote
agree with the transcription we wrote. It is still a contract test in the only sense that
matters: the subject is a wire format shared by two runtimes.

The container runs `php` directly against the class file. No autoloader, no `composer install`,
no framework boot — `InternalRequestSigner` deliberately has no facades and no HTTP (its own
docblock says so), so a bare `require` is enough. The repository is mounted at `/repo`; mounting
only `services/core-api` is what produces eleven unrelated failures in the PHP suite, so the
whole tree goes in, read-only.

SKIPPING, AND HOW TO STOP IT SKIPPING
--------------------------------------
The image `knowledgebot/core-api:dev` is built by `docker compose build` locally and, in CI, by
the `core-api` step of `ci.yml`'s `images` job. That step used to be `push: false, load: false`
with no `tags:` — the layers went to the build cache and nothing reached the runner's Docker
daemon — so this file skipped on every pull request and its whole matrix, controls included,
reported as one `1 skipped` line (finding B1; the older wording here, "no workflow builds an
image today (finding O22)", was true when O22 was raised and stopped being true when that job
landed). The
build now carries `load: true` and `tags: knowledgebot/core-api:dev`, and the SAME job runs this
file with `KB_TEST_REQUIRE_PHP=1` — jobs do not share a daemon, so it has to be the same job.
With that variable set the skip becomes a failure, which is what keeps a renamed tag or a dropped
`load:` from turning the file green by skipping it again. A local `php` binary is not an
alternative — the class uses constructor promotion, `readonly` and `#[SensitiveParameter]`, so
anything below PHP 8.2 fails to parse.
"""

from __future__ import annotations

import base64
import json
import os
import re
import shutil
import subprocess
from pathlib import Path
from typing import Final

import pytest

from app.core.signing import canonical_string
from tests.support.signing import PREFIX

REPO_ROOT: Final[Path] = Path(__file__).resolve().parents[4]
_INTERNAL: Final[Path] = REPO_ROOT / "services/core-api/app/Services/Internal"
SIGNER_PHP: Final[Path] = _INTERNAL / "InternalRequestSigner.php"
CLIENT_PHP: Final[Path] = _INTERNAL / "InternalAiClient.php"
IMAGE: Final[str] = os.environ.get("KB_TEST_CORE_API_IMAGE", "knowledgebot/core-api:dev")

SECRET: Final[str] = "test-hmac-secret-k1"
KEY_ID: Final[str] = "k1"
METHOD: Final[str] = "POST"
PATH: Final[str] = "/internal/v1/embedding/readiness"

#: `InternalAiClient::embeddingReadiness()`'s own header set — including `X-KB-Config-Version`,
#: which the client now sends (`InternalAiClient.php`, inside the single signed `$headers` array).
#: It was absent for several revisions and the gap was pinned by an `xfail(strict=True)` on
#: `test_the_control_plane_client_sends_the_headers_the_context_dependency_requires`; that marker
#: XPASSed the day the header landed and was removed, which is the marker doing its job.
READINESS_HEADERS: Final[dict[str, str]] = {
    "X-KB-Org-Id": "01JQZ0000000000000000000AA",
    "X-KB-Actor-Type": "user",
    "X-KB-Operation": "embedding.readiness",
    "X-KB-Request-Id": "01JQZ0000000000000000000RR",
    "X-KB-Contract-Version": "v1",
    "X-KB-Config-Version": "7",
    "X-KB-Deadline": "1786000000000",
    "X-KB-Timestamp": "1786000000",
}

#: Every case is a `(header set, body)` pair, and each one names a way the two sides can drift.
CASES: Final[dict[str, tuple[dict[str, str], bytes]]] = {
    "the-deployed-readiness-set": (READINESS_HEADERS, b'{"connections":[],"designated":null}'),
    "empty-body": (READINESS_HEADERS, b""),
    # THE CASE THIS FILE EXISTS FOR. `':'` is 0x3A and `'-'` is 0x2D, so a line-sort and a
    # tuple-sort disagree exactly when one name is a strict prefix of another. No shipped name
    # is, so nothing else in either suite can tell the two apart.
    "one-name-is-a-strict-prefix-of-another": (
        READINESS_HEADERS | {"X-KB-Org-Id-2": "01JQZ0000000000000000000BB"},
        b"{}",
    ),
    "three-way-prefix-chain": (
        {
            "X-KB-Timestamp": "1786000000",
            "X-KB-A": "1",
            "X-KB-A-B": "2",
            "X-KB-A-B-C": "3",
            "X-KB-A0": "4",
        },
        b"{}",
    ),
    # PHP `trim()` strips " \t\n\r\0\x0B"; Python `str.strip()` also strips every Unicode
    # whitespace character. Only the five below may be trimmed on either side.
    "values-needing-a-trim": (
        READINESS_HEADERS | {"X-KB-Operation": " \t chat.execute \r\n"},
        b"{}",
    ),
    # PHP's default sort flag compares numeric-looking strings numerically. `SORT_STRING` is
    # explicit in the signer for exactly this; the assertion is what keeps it explicit.
    "all-digit-values": (
        {"X-KB-Timestamp": "1786000000", "X-KB-Config-Version": "10", "X-KB-Seq": "9"},
        b"{}",
    ),
    "leading-zero-timestamp-is-passed-through-not-reparsed": (
        READINESS_HEADERS | {"X-KB-Timestamp": "01786000000"},
        b"{}",
    ),
    "the-signature-header-is-excluded-from-its-own-canonical-string": (
        READINESS_HEADERS | {"X-KB-Signature": "k1:deadbeef"},
        b"{}",
    ),
    "no-kb-headers-at-all": ({}, b"{}"),
    "a-body-that-is-not-ascii": (READINESS_HEADERS, '{"q":"café ☕"}'.encode()),
    "a-body-with-a-nul-byte": (READINESS_HEADERS, b'{"q":"a\x00b"}'),
    "an-empty-header-value": (READINESS_HEADERS | {"X-KB-Actor-Id": ""}, b"{}"),
}

#: The PHP side, fed to `php` on **stdin**. Nothing is mounted but the repository, and the case
#: matrix rides inside the script as base64 rather than as a second mount, because pytest's
#: `tmp_path_factory` root is mode 0700 and the image's `app` user cannot traverse it — a
#: failure that reads as "Could not open input file" and sends the reader looking at PHP.
_DRIVER: Final[str] = """<?php
declare(strict_types=1);

// No autoloader and no framework: InternalRequestSigner has no facades, no HTTP and no
// container dependency, which is exactly what its own docblock promises and what makes this
// a `require` rather than a `composer install`.
require '/repo/services/core-api/app/Services/Internal/InternalRequestSigner.php';

$cases = json_decode(base64_decode('%%CASES%%', true), true, 512, JSON_THROW_ON_ERROR);
$out = [];

foreach ($cases as $name => $case) {
    $signer = new \\App\\Services\\Internal\\InternalRequestSigner(
        prefix: $case['prefix'],
        keyId: $case['key_id'],
        secret: $case['secret'],
    );
    $body = base64_decode($case['body'], true);
    // base64 both ways: the canonical string contains NUL and newlines, and JSON transport of a
    // raw binary string is exactly where an encoding would be introduced by the harness itself.
    $out[$name] = [
        'canonical' => base64_encode(
            $signer->canonicalString($case['method'], $case['path'], $body, $case['headers']),
        ),
        'signature' => $signer->sign($case['method'], $case['path'], $body, $case['headers']),
    ];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
"""


def _docker_can_run_the_php_signer() -> str | None:
    """``None`` when the PHP side is runnable, otherwise the reason it is not."""
    if not SIGNER_PHP.is_file():
        return f"{SIGNER_PHP} does not exist"
    if shutil.which("docker") is None:
        return "docker is not on PATH"
    probe = subprocess.run(  # noqa: S603
        ["docker", "image", "inspect", IMAGE],  # noqa: S607
        capture_output=True,
        check=False,
    )
    if probe.returncode != 0:
        return (
            f"{IMAGE} is not built locally. `ci.yml`'s `images` job builds it with "
            "`load: true` and this tag, and runs this file in that same job with "
            "KB_TEST_REQUIRE_PHP=1 — so a skip here means a local tree without the image, or "
            "a job that lost the `load:`/`tags:` pair. Build it with `docker compose build "
            "core-api`."
        )
    return None


_UNAVAILABLE: Final[str | None] = _docker_can_run_the_php_signer()

if _UNAVAILABLE is not None and os.environ.get("KB_TEST_REQUIRE_PHP") != "1":
    pytest.skip(f"PHP signer unavailable: {_UNAVAILABLE}", allow_module_level=True)


@pytest.fixture(scope="module")
def php_results() -> dict[str, dict[str, str]]:
    """Every case, signed by the real PHP class, in one container run.

    Module-scoped because the container costs about a second and the matrix is a pure function
    of this file — running it per case would make the suite's cost the matrix's size and
    discourage exactly the growth this file wants.
    """
    assert _UNAVAILABLE is None, (
        f"KB_TEST_REQUIRE_PHP=1 but the PHP signer is unavailable: {_UNAVAILABLE}"
    )

    payload = json.dumps(
        {
            name: {
                "prefix": PREFIX,
                "key_id": KEY_ID,
                "secret": SECRET,
                "method": METHOD,
                "path": PATH,
                "body": base64.b64encode(body).decode("ascii"),
                "headers": headers,
            }
            for name, (headers, body) in CASES.items()
        }
    )
    script = _DRIVER.replace("%%CASES%%", base64.b64encode(payload.encode()).decode("ascii"))

    completed = subprocess.run(  # noqa: S603
        [  # noqa: S607
            "docker",
            "run",
            "--rm",
            "-i",
            # No network: this proves the signer needs none, and keeps a test that shells out
            # from being able to reach anything if the class ever grows an import that does.
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
        f"the PHP signer did not run:\n{completed.stdout.decode()}\n{completed.stderr.decode()}"
    )
    results: dict[str, dict[str, str]] = json.loads(completed.stdout)
    assert set(results) == set(CASES), "the driver dropped or invented a case"
    return results


def _ours(headers: dict[str, str], body: bytes) -> bytes:
    return canonical_string(
        method=METHOD,
        path=PATH,
        timestamp=headers.get("X-KB-Timestamp", ""),
        body=body,
        kb_headers=headers,
        prefix=PREFIX,
    )


@pytest.mark.parametrize("case", list(CASES))
def test_both_languages_build_the_same_canonical_bytes(
    php_results: dict[str, dict[str, str]], case: str
) -> None:
    """Byte for byte. Not "the same fields", not "the same length" — the bytes are the input to
    an HMAC, so anything short of equality is a 401."""
    headers, body = CASES[case]
    theirs = base64.b64decode(php_results[case]["canonical"])

    assert _ours(headers, body) == theirs


def test_the_comparison_is_capable_of_failing(php_results: dict[str, dict[str, str]]) -> None:
    """Positive control for the whole file. Every assertion above is an equality, and an
    equality between two things that are both empty is green. One case's PHP output must not
    match a *different* case's Python output."""
    headers, body = CASES["the-deployed-readiness-set"]
    other = base64.b64decode(php_results["one-name-is-a-strict-prefix-of-another"]["canonical"])

    assert _ours(headers, body) != other


def test_the_prefix_case_actually_reorders_the_lines(
    php_results: dict[str, dict[str, str]],
) -> None:
    """The prefix case is only evidence if PHP genuinely puts the longer name first. Without
    this line, a PHP implementation that had silently switched to a tuple sort would agree with
    a Python implementation that had done the same, and the pair would be green and wrong.
    """
    canonical = base64.b64decode(
        php_results["one-name-is-a-strict-prefix-of-another"]["canonical"]
    ).decode()
    lines = canonical.split("\n")[5:]

    assert lines.index("x-kb-org-id-2:01JQZ0000000000000000000BB") < lines.index(
        "x-kb-org-id:01JQZ0000000000000000000AA"
    )


def test_a_python_signature_is_what_php_would_have_produced(
    php_results: dict[str, dict[str, str]],
) -> None:
    """One level up from the canonical string: the same secret, the same key id, the same
    ``key_id:hex`` rendering. ``verify`` is asserted against this in the same breath, so the
    thing that accepts the wire and the thing that produced it are joined here rather than in
    two files that could each be wrong."""
    import hashlib
    import hmac as hmac_module

    from app.core.keys import KeyRing
    from app.core.signing import verify

    headers, body = CASES["the-deployed-readiness-set"]
    canonical = _ours(headers, body)
    digest = hmac_module.new(SECRET.encode(), canonical, hashlib.sha256).hexdigest()

    assert php_results["the-deployed-readiness-set"]["signature"] == f"{KEY_ID}:{digest}"

    key_id, _, provided = php_results["the-deployed-readiness-set"]["signature"].partition(":")
    ring = KeyRing(request={KEY_ID: SECRET.encode()}, callback={})
    assert verify(provided, key_id, canonical, ring=ring)


def test_php_signs_whatever_it_is_handed_which_is_why_the_client_hands_it_x_kb_only(
    php_results: dict[str, dict[str, str]],
) -> None:
    """THE GAP, PINNED FROM THE SIDE THAT CANNOT CLOSE IT.

    ``InternalRequestSigner`` filters on ``X-KB-Signature`` and nothing else: hand it
    ``content-type`` and it signs ``content-type``. The verifier cannot work that way — it
    rebuilds the set from the request's real headers, which always carry ``content-type`` and
    ``traceparent`` — so the Python side filters on the ``x-kb-`` prefix, and the two agree
    only because ``InternalAiClient`` builds an X-KB-only array.

    Nothing in the PHP class enforces that. This test asserts both halves: that the divergence
    is real (so nobody "fixes" the Python filter to match PHP), and that the client's array is
    still X-KB-only (so the property the whole scheme rests on is checked rather than assumed).
    """
    headers, body = CASES["the-deployed-readiness-set"]
    polluted = headers | {"content-type": "application/json"}

    # A one-off run rather than a matrix entry: this case must NOT be in CASES, because every
    # entry there is asserted equal and this one is deliberately not.
    assert _ours(polluted, body) == _ours(headers, body), (
        "the Python side must ignore a non-X-KB header; it recomputes from the real request"
    )

    # And the client's array, read from the source rather than described.
    keys = _client_header_names()
    assert keys, "could not read InternalAiClient's header array; the shape moved"
    assert all(key.startswith("x-kb-") for key in keys), (
        f"InternalAiClient hands the signer a non-X-KB header: {sorted(keys)}. The signer signs "
        "everything it is given and the verifier signs only X-KB-*, so that request 401s."
    )


def test_the_control_plane_client_sends_the_headers_the_context_dependency_requires() -> None:
    """Reads the other plane's source and states, as an assertion, what it is missing.

    `kb-internal-api-contracts` marks ``X-KB-Config-Version`` **always** required, and
    ``app/api/deps.py:request_context`` enforces it. ``InternalAiClient::embeddingReadiness()``
    does not send it, so that call 422s at the boundary — a real, shipped drift between the two
    planes, owned by `control-plane-engineer`.

    THE GAP IS CLOSED AND THE MARKER IS GONE. This carried ``xfail(strict=True)`` — a finding
    recorded in prose is a finding nobody re-reads — precisely so that the day the control plane
    added the header, the XPASS would itself be the failure saying "delete the marker".
    ``InternalAiClient::embeddingReadiness()`` now sends ``X-KB-Config-Version``, the XPASS
    fired, and this is a plain assertion again. It stays as one: it is the only artifact that
    reads the other plane's header array rather than describing it, so it is what would notice
    the header being dropped again.
    """
    sent = _client_header_names()

    assert {"x-kb-request-id", "x-kb-org-id", "x-kb-actor-type", "x-kb-operation"} <= sent
    assert "x-kb-config-version" in sent


def _client_header_names() -> set[str]:
    """The header names ``InternalAiClient`` hands the signer, lowercased.

    Read from the source rather than restated: a second literal list here would agree with
    itself forever while the real one drifted, which is the exact failure the signer's own
    docblock warns about.
    """
    client = CLIENT_PHP.read_text(encoding="utf-8")
    block = client[client.index("$headers = [") : client.index("$signature = $this->signer->sign")]
    literal = re.findall(r"^\s*'([^']+)'\s*=>", block, re.MULTILINE)
    assigned = re.findall(r"\$headers\['([^']+)'\]", block)
    return {name.lower() for name in literal + assigned}
