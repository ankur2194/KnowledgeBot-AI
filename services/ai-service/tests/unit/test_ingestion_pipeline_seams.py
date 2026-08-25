"""The ingestion pipeline's seams — every test here guards a failure with no error attached.

The pipeline is the one path in this service that spends a tenant's money, writes to a tenant's
prefix, and produces the index every answer is drawn from. Almost none of its failure modes
raise: a wrong storage key returns *an* object, a resumed run that skips a stage produces zero
of something and verification then compares zero against zero, and a credential envelope with
its tag in the wrong place fails in a way that reads like key corruption.

So the four groups below are, in order: the write allow-list's new runtime guard, the storage
key, the credential envelope, and the run's own arithmetic.
"""

from __future__ import annotations

import hashlib
import shutil
import subprocess
from pathlib import Path
from typing import Any, Final

import pytest

from app.core.credentials import IV_BYTES, TAG_BYTES, CredentialUnavailable, open_credential
from app.core.errors import ErrorClass, KbError
from app.db import objects, writes
from app.ingestion import runner
from app.ingestion.pipeline import VersionContext

ORG: Final = "01JQZ0000000000000000000AA"
SOURCE: Final = "01JQZ0000000000000000000SC"
HASH: Final = "a" * 64


# ── the write allow-list is a check a statement passes, not a list a reviewer reads ──


def test_a_table_off_the_allow_list_is_refused_at_the_statement() -> None:
    """**This is the mechanism `docs/22` § Q5 records as absent.**

    Q5's wording is the thing to keep: the allow-list was "a list a reviewer reads, not a check
    a statement passes", so writing against a name that was never admitted was indistinguishable
    from a green diff. It is a check now — and the *other* Q5 failure, admitting a wrong name to
    the tuple, is unchanged and unmechanisable, because no runtime check can tell a
    correctly-admitted name from a wrongly-admitted one.
    """
    with pytest.raises(writes.TableNotWritable, match="write allow-list"):
        writes.assert_writable("source_versions")


def test_the_guard_returns_the_name_so_it_sits_inside_the_statement() -> None:
    """It returns rather than asserting, so the call site reads
    `f"INSERT INTO {assert_writable('chunks')}"` — the gate is *in the expression that builds
    the statement*. A guard on its own line is one somebody deletes while moving code, and the
    statement still runs."""
    assert writes.assert_writable("chunks") == "chunks"


@pytest.mark.parametrize("table", writes.ALLOWED_TABLES)
def test_every_allow_listed_name_passes_its_own_gate(table: str) -> None:
    """Reads the tuple rather than restating it (ADR-036). A test carrying its own copy of the
    list is a second place the list exists, and the two go out of sync silently."""
    assert writes.assert_writable(table) == table


# ── the storage key is composed, never accepted ───────────────────────────────


@pytest.mark.parametrize(
    "org_id",
    ["../../other-org", "org/with/slash", "", "org id"],
)
def test_a_separator_in_an_identifier_never_reaches_a_storage_key(org_id: str) -> None:
    """An S3 key is not a path the server normalizes. `..` is a literal sequence naming a real
    object and a `/` re-roots the key under another tenant — and neither produces an error from
    the store. Both return an object, at HTTP 200."""
    with pytest.raises(KbError, match="not a bare identifier"):
        objects.legal_original_keys(org_id=org_id, source_id=SOURCE, content_hash=HASH)


def test_the_original_is_a_sibling_of_versions_and_not_a_child() -> None:
    """ADR-066. Two skills still draw `original/` INSIDE the version prefix, and following them
    produces the one shape non-negotiable 6 cannot survive: an object outside every prefix the
    purge sweeps, which verification then certifies clean while it survives."""
    upload, text = objects.legal_original_keys(org_id=ORG, source_id=SOURCE, content_hash=HASH)
    assert upload == f"org/{ORG}/sources/{SOURCE}/original/{HASH}"
    assert "/versions/" not in upload
    assert "/versions/" not in text


def test_a_source_has_exactly_two_legal_original_keys_and_they_differ_by_the_suffix() -> None:
    """THE DEFECT `docs/22` § Q8 PREDICTED, LANDED. This module composed only the first spelling,
    so every pasted-text source resolved to a key Laravel had never written and failed as a
    missing object — an accurate message about the wrong key. `ObjectKey::originalUpload()` has
    no suffix because an upload's type is a sniff and must not enter a path;
    `ObjectKey::originalText()` appends `.txt` because we generated those bytes ourselves."""
    upload, text = objects.legal_original_keys(org_id=ORG, source_id=SOURCE, content_hash=HASH)

    assert text == f"{upload}.txt"
    # One prefix, so one sweep reaches both.
    assert upload.rsplit("/", 1)[0] == text.rsplit("/", 1)[0]


def test_a_stored_key_that_matches_neither_spelling_is_refused_rather_than_read() -> None:
    """The check this module's docstring described and nobody performed.

    A key is a path-traversal parameter. Both candidates are composed from validated identifiers,
    so the row's key can only ever SELECT one — never supply a string.
    """
    store = _Store(b"whatever")

    with pytest.raises(KbError, match="neither of the two keys"):
        objects.fetch_original(
            store,
            bucket="kb",
            org_id=ORG,
            source_id=SOURCE,
            content_hash=HASH,
            storage_key=f"org/another-org/sources/{SOURCE}/original/{HASH}",
        )

    assert store.requested == {}, "the store was never called with a key it was handed"


def test_a_source_with_no_stored_original_says_so_rather_than_guessing() -> None:
    with pytest.raises(KbError, match="no storage_key"):
        objects.fetch_original(
            _Store(b""),
            bucket="kb",
            org_id=ORG,
            source_id=SOURCE,
            content_hash=HASH,
            storage_key=None,
        )


@pytest.mark.parametrize("bad", ["short", "A" * 64, "g" * 64])
def test_a_content_hash_that_is_not_lowercase_hex_is_refused(bad: str) -> None:
    """The hash IS the object's name. An uppercase digest names a different key, and under
    `COLLATE "C"` it also compares unequal to the row — so the read misses and the version
    re-embeds a corpus that had not changed, at a provider's per-token price."""
    with pytest.raises(KbError, match="64 lowercase hex"):
        objects.legal_original_keys(org_id=ORG, source_id=SOURCE, content_hash=bad)


class _Body:
    def __init__(self, data: bytes) -> None:
        self._data = data

    def read(self) -> bytes:
        return self._data


class _Store:
    """Two methods, which is the whole surface `objects` uses — see why the client is `Any`."""

    def __init__(self, data: bytes) -> None:
        self._data = data
        self.requested: dict[str, str] = {}

    def get_object(self, *, Bucket: str, Key: str) -> dict[str, Any]:  # noqa: N803
        self.requested = {"bucket": Bucket, "key": Key}
        return {"Body": _Body(self._data), "ContentLength": len(self._data)}


def test_the_object_is_hashed_and_compared_before_it_is_returned() -> None:
    """Not belt-and-braces. The hash is what the published version is checkable against AND the
    key it is stored under, so a mismatch means the object was replaced under an immutable name
    or the store answered with a different object. Skipping the check indexes a swapped original
    cleanly, and every count downstream agrees about a document nobody uploaded."""
    body = b"the real bytes"
    store = _Store(body)
    digest = hashlib.sha256(body).hexdigest()

    key = f"org/{ORG}/sources/{SOURCE}/original/{digest}"

    assert (
        objects.fetch_original(
            store,
            bucket="kb",
            org_id=ORG,
            source_id=SOURCE,
            content_hash=digest,
            storage_key=key,
        )
        == body
    )

    swapped = _Store(b"different bytes entirely")
    with pytest.raises(KbError, match="built from bytes nobody uploaded"):
        objects.fetch_original(
            swapped,
            bucket="kb",
            org_id=ORG,
            source_id=SOURCE,
            content_hash=digest,
            storage_key=key,
        )


# ── the credential envelope, against PHP's actual output ──────────────────────


def _php_seal(plaintext: bytes, key: bytes) -> bytes:
    """Seal exactly the way `CredentialVault::encrypt` does, using PHP itself.

    **Reimplementing the sealer in Python here would prove nothing**: it would test this
    module against my own reading of the format, which is precisely the reading that could be
    wrong. Shelling out to PHP means the fixture is produced by the code that produces the real
    rows, so a layout disagreement fails here rather than at a tenant's first ingestion.
    """
    script = (
        "$iv=random_bytes(12);$tag='';"
        "$ct=openssl_encrypt(base64_decode($argv[1]),'aes-256-gcm',"
        "base64_decode($argv[2]),OPENSSL_RAW_DATA,$iv,$tag,'',16);"
        "echo base64_encode($iv.$tag.$ct);"
    )
    import base64

    # An ABSOLUTE path, resolved once: a bare "php" is a partial executable path, and the
    # `@php` marker above has already established that the interpreter is on PATH.
    result = subprocess.run(  # noqa: S603 - fixed argv, no shell
        [
            str(shutil.which("php")),
            "-r",
            script,
            "--",
            base64.b64encode(plaintext).decode(),
            base64.b64encode(key).decode(),
        ],
        check=True,
        capture_output=True,
        text=True,
    )
    return base64.b64decode(result.stdout)


php = pytest.mark.skipif(
    shutil.which("php") is None,
    reason="php is not on PATH; this test exists to compare against the real sealer",
)


@php
def test_a_credential_sealed_by_php_opens_here() -> None:
    """The cross-plane round trip, end to end: KEK derivation, both unwraps, both layouts."""
    kek = hashlib.sha256(b"a-test-kek").digest()
    data_key = b"\x11" * 32
    opened = open_credential(
        credential_ciphertext=_php_seal(b"sk-live-not-a-real-key", data_key),
        data_key_ciphertext=_php_seal(data_key, kek),
        kek=kek,
    )
    assert opened.get_secret_value() == "sk-live-not-a-real-key"


@php
def test_the_tag_is_in_the_middle_and_appending_it_does_not_open() -> None:
    """**The trap.** Every reference implementation appends the tag, so the natural port of this
    format authenticates against the wrong bytes — and GCM authenticates, so it does not decrypt
    to garbage, it fails in a way that reads like key corruption."""
    kek = hashlib.sha256(b"a-test-kek").digest()
    envelope = _php_seal(b"payload", kek)
    iv, tag, ciphertext = (
        envelope[:IV_BYTES],
        envelope[IV_BYTES : IV_BYTES + TAG_BYTES],
        envelope[IV_BYTES + TAG_BYTES :],
    )
    appended = iv + ciphertext + tag

    with pytest.raises(CredentialUnavailable, match="did not authenticate"):
        open_credential(
            credential_ciphertext=appended, data_key_ciphertext=_php_seal(b"x" * 32, kek), kek=kek
        )


def test_the_kek_is_derived_from_the_trimmed_file(tmp_path: Path) -> None:
    """`hash('sha256', trim($kek), true)`. A secret file written by an editor almost always ends
    in a newline; deriving from the untrimmed bytes yields a valid-looking 32-byte key that opens
    nothing, and the failure presents as corruption rather than as configuration."""
    from app.core.credentials import read_kek

    path = tmp_path / "kek"
    path.write_bytes(b"  secret-material\n")
    assert read_kek(path) == hashlib.sha256(b"secret-material").digest()


def test_a_missing_kek_is_never_a_reason_to_proceed_unwrapped(tmp_path: Path) -> None:
    from app.core.credentials import read_kek

    with pytest.raises(CredentialUnavailable, match="never a reason to proceed unwrapped"):
        read_kek(tmp_path / "absent")


def test_a_truncated_envelope_names_the_likely_cause() -> None:
    """Phase A's `BinaryCast` defect drained the PDO stream, so the SECOND read of any ciphertext
    column returned empty and the vault reported "truncated" — a message that reads like KEK
    corruption and sends the reader to the wrong system. This message names the right one."""
    with pytest.raises(CredentialUnavailable, match="drained the stream"):
        open_credential(
            credential_ciphertext=b"short", data_key_ciphertext=b"short", kek=b"\x00" * 32
        )


# ── the run's own arithmetic ──────────────────────────────────────────────────


def test_the_token_measure_over_estimates() -> None:
    """There is no local model and therefore no authoritative tokenizer, and the vendor's own is
    a different algorithm over a different vocabulary that diverges most on non-Latin scripts —
    exactly where a silent trim would be least noticed. Under-estimating sends an over-window
    batch; over-estimating sends a smaller one, which costs nothing but a request."""
    text = "the quick brown fox jumps over the lazy dog"
    words = len(text.split())
    assert runner._measure(text) > words
    assert runner._measure("") >= 1


def test_the_embedding_space_is_parsed_from_the_versions_own_recorded_identity() -> None:
    """`embedding_model_version` is the VERSION's row, not this process's configuration. The
    reader must embed its query with the same provider, model and width the passages were
    embedded with, and a service-level setting lets the indexer and the reader disagree with
    nothing raised."""
    ctx = _ctx("emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1")
    space = runner.ctx_space(ctx)
    assert (space.provider, space.model, space.dimensions) == (
        "openai",
        "text-embedding-3-large",
        3072,
    )


@pytest.mark.parametrize(
    "recorded",
    ["", "text-embedding-3-large", "emb/v1:openai:model:3072:digest", "emb/v1:openai:model"],
)
def test_an_unparseable_identity_is_never_defaulted(recorded: str) -> None:
    """The collection name is derived from it, so a default would name a REAL collection and
    index this version into someone else's vector space — which upserts cleanly."""
    with pytest.raises(KbError, match="cannot be defaulted"):
        runner.ctx_space(_ctx(recorded))


def test_backoff_is_capped_and_jittered() -> None:
    """A countdown at or above the visibility timeout re-executes forever — the ETA loop Celery's
    own documentation warns about. Full jitter rather than a fixed ladder, because otherwise
    every worker that failed on one provider brownout retries at the same instant."""
    values = {runner.backoff(attempt=20, cap=600) for _ in range(50)}
    assert max(values) <= 600
    assert len(values) > 1, "a fixed countdown is a thundering herd"


def test_an_unmapped_exception_is_permanent_rather_than_temporary() -> None:
    """Unknown is permanent. A retryable default re-runs a crash that will crash identically,
    and it hides that something raised a class nobody has mapped."""
    verdict = runner.classify(RuntimeError("something nobody classified"))
    assert verdict.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert verdict.retryable is False


@pytest.mark.parametrize(
    ("error_class", "expected"),
    [
        (ErrorClass.PROVIDER_RATE_LIMIT, True),
        (ErrorClass.PROVIDER_TEMPORARY, True),
        (ErrorClass.PROVIDER_AUTH, False),
        (ErrorClass.PROVIDER_BILLING, False),
        (ErrorClass.PROVIDER_PERMANENT_REQUEST, False),
    ],
)
def test_only_two_provider_classes_may_be_retried_on_this_path(
    error_class: ErrorClass, expected: bool
) -> None:
    """An exhausted account and a wrong model id never come right, and retrying them burns the
    delivery cap on a certainty. Fallback to another vendor is unavailable even for the two that
    are retryable: a different vendor is a different vector space."""
    from app.providers.errors import ProviderCallFailed

    verdict = runner.classify(ProviderCallFailed(error_class, "vendor said no"))
    assert verdict.retryable is expected


def _ctx(embedding_model_version: str) -> VersionContext:
    return VersionContext(
        org_id=ORG,
        source_id=SOURCE,
        source_item_id="01JQZ0000000000000000000IT",
        source_version_id="01JQZ0000000000000000000VR",
        content_hash=HASH,
        ingest_key="b" * 64,
        parser_cfg_version="parser/v1",
        ocr_cfg_version="ocr/v1",
        chunker_cfg_version="chunker/v1",
        embedding_model_version=embedding_model_version,
    )
