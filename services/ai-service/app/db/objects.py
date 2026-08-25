"""Reading tenant objects out of SeaweedFS. The data plane's only object-storage entry point.

It sits beside ``writes.py`` rather than under ``ingestion/`` for the same reason that module
does: **one place issues the calls**, so the rules that make them tenant-safe are stated once
and are checkable by reading a single file.

WHAT THIS MODULE IS ALLOWED TO DO
---------------------------------
Read, and nothing else. There is no ``put`` and no ``delete`` here, deliberately:

* **Writing originals is Laravel's.** The upload intake runs there — six checks in order,
  ending in a content hash the version is checkable against — and a second writer would put
  bytes under a tenant prefix with none of that in front of them. ``SourceObjectWriter`` is
  the one writer.
* **Deleting is the purge path's**, and it is two-phase and verified (non-negotiable 6). A
  convenience ``delete`` here is how an object leaves without the verification step that
  proves it left.

Derived artifacts — page images, extracted text — are written under the version prefix by the
ingestion tasks, which is a separate concern from reading an original and is not yet wired.

THE KEY SHAPE, AND THE ONE THAT COSTS AN UNDELETABLE OBJECT
------------------------------------------------------------
``original/`` is **source-scoped and a sibling of** ``versions/`` (ADR-066)::

    org/{org_id}/sources/{source_id}/original/{content_hash}
    org/{org_id}/sources/{source_id}/versions/{source_version_id}/derived/...

Two skills still draw ``original/`` *inside* the version prefix — ``seaweedfs-s3`` NN1 and
``kb-tenancy-isolation``:97 — and following them produces the one shape non-negotiable 6
cannot survive: an object outside every prefix the purge sweeps, which verification then
certifies clean while it survives. ``docs/19`` ADR-066 is what the shipped code follows.

The consequence that reaches this module: **one object serves every version of a source.** A
reader must never assume the original it fetched belongs to the version it is processing —
it belongs to the *source*, and the version's ``content_hash`` is what says which bytes those
are.

WHY THE KEY IS COMPOSED HERE AND THE ROW'S KEY IS ONLY EVER *COMPARED*
-----------------------------------------------------------------------
``fetch_original`` takes ``org_id`` and ``source_id`` and composes the key itself. It never uses
the key it is handed. That is the whole tenancy control on this path: a key parameter is a
path-traversal parameter, and ``..`` in an S3 key is not normalized by anything — it is a
literal key that reads whatever object is actually there. The row's ``storage_key`` is checked
against the composed set rather than used, so a row that names another tenant's object fails
loudly instead of reading it.

**THAT PARAGRAPH USED TO DESCRIBE A CHECK NOBODY PERFORMED**, and the omission cost more than
the check would have. Nothing passed ``storage_key`` in, so there was nothing to compare — and
because there was nothing to compare, the module composed ONE spelling of the original key and
nobody noticed there are two. ``ObjectKey::originalUpload()`` has no suffix;
``ObjectKey::originalText()`` appends ``.txt``. Every pasted-text source therefore resolved to a
key Laravel had never written, and ``fetch_original`` reported it as a missing object — an
accurate message about the wrong key. See ``app/storage/objects.py``, which is now the single
builder for both, and ``docs/22`` § Q8.
"""

from __future__ import annotations

import hashlib
from typing import Any, Final

from app.core.errors import ErrorClass, KbError
from app.storage import objects as keys

__all__ = [
    "ObjectNotFound",
    "fetch_original",
    "legal_original_keys",
]

#: Read in one call rather than streamed, and the ceiling is why that is safe. Laravel's upload
#: intake caps the object long before it reaches here, so a body above this is not a large
#: upload — it is an object that is not what the row says it is, and reading it into a Celery
#: worker's memory is how one document takes a worker down.
#:
#: <!-- UNVERIFIED: not reconciled against `OrgUploadLimits`' configured maximum. It is a
#: backstop against a corrupt row rather than a restatement of the policy limit, which is the
#: control plane's and must not be duplicated here (ADR-036). -->
MAX_OBJECT_BYTES: Final[int] = 512 * 1024 * 1024


class ObjectNotFound(KbError):
    """The original is not where the row says it is.

    ``storage`` rather than ``internal_dependency``, and retryable, because the common cause is
    a read racing the write that put it there rather than a missing object. The *uncommon*
    cause is finding Q1's orphan in reverse — a row whose object was never written — and that
    one exhausts the retries and fails the version, which is the correct outcome: the version
    cannot be built and a person has to look.
    """


def legal_original_keys(*, org_id: str, source_id: str, content_hash: str) -> tuple[str, str]:
    """The two — and only two — keys a source's original bytes may legally be at.

    ``(upload, text)``. A source is either pasted or uploaded and never both, so exactly one of
    these exists for any real source; returning both is what lets a caller *identify* which
    without ever trusting a key it was handed.

    Every component is validated before it reaches a format string, by ``app/storage/objects.py``.
    An S3 key is not a path the server normalizes: ``..`` is a literal character sequence that
    names a real object, and a ``/`` inside what should be an identifier re-roots the key under
    another tenant. Neither produces an error from the store — both return an object.
    """
    # NARROWER THAN `storage.objects.segment`, DELIBERATELY, AND THE TWO ARE NOT REDUNDANT.
    # `segment()` is the guard the Laravel twin has, character for character, because a guard that
    # refuses what the other plane accepts turns a legal key into an unreadable one. This check is
    # an extra floor on the values THIS path is known to receive: `organization_id` and `source_id`
    # are ULIDs constrained to `char(26)` by their migrations, so "bare identifier" cannot reject a
    # real value and does reject a class of nonsense a path-shaped guard would let through.
    for name, value in (("org_id", org_id), ("source_id", source_id)):
        if not value or not value.isalnum():
            raise KbError(
                ErrorClass.VALIDATION,
                f"{name}={value!r} is not a bare identifier, so it cannot go into a storage "
                "key. Nothing normalizes an S3 key: a separator here re-roots the read under "
                "another tenant's prefix and returns that tenant's object, at HTTP 200",
            )
    if len(content_hash) != 64 or not all(c in "0123456789abcdef" for c in content_hash):
        raise KbError(
            ErrorClass.VALIDATION,
            "content_hash must be 64 lowercase hex characters; it is the object's name and "
            "the only thing that says which bytes a version was built from",
        )
    return (
        keys.original_upload(org_id, source_id, content_hash),
        keys.original_text(org_id, source_id, content_hash),
    )


def fetch_original(
    client: Any,
    *,
    bucket: str,
    org_id: str,
    source_id: str,
    content_hash: str,
    storage_key: str | None,
) -> bytes:
    """The original bytes for one source, verified against the hash that names them.

    **THE HASH IS RE-COMPUTED AND COMPARED, AND THAT IS NOT BELT-AND-BRACES.** The content hash
    is what the published version is checkable against and it is the key the object is stored
    under, so a mismatch means one of two things and both are worth failing on: the object was
    replaced under a key that should be immutable, or the store returned a different object
    than the one addressed. Skipping the check makes a corrupted or swapped original index
    cleanly, and every count downstream agrees about a document nobody uploaded.

    Reading before checking is unavoidable — the bytes have to be in hand to hash — which is
    what ``MAX_OBJECT_BYTES`` bounds.
    """
    upload, text = legal_original_keys(
        org_id=org_id, source_id=source_id, content_hash=content_hash
    )

    # THE ROW'S KEY SELECTS THE SPELLING; IT NEVER SUPPLIES THE STRING. Both candidates were
    # composed from validated identifiers above, so whichever one matches is a key this service
    # built. A row naming anything else — another tenant's prefix, another source, a traversal —
    # matches neither and is refused here rather than read.
    if storage_key == upload:
        key = upload
    elif storage_key == text:
        key = text
    elif storage_key is None:
        raise KbError(
            ErrorClass.VALIDATION,
            f"source {source_id} has no storage_key, so there are no original bytes to fetch. "
            "A crawled source stores no original; a stored one cannot have a null key, because "
            "`source_items_stored_object_is_complete` refuses the row",
            retryable=False,
        )
    else:
        raise KbError(
            ErrorClass.VALIDATION,
            f"the stored key for source {source_id} is neither of the two keys this layout "
            "permits for its content hash. It was not used to read anything: the key is always "
            "composed from the row's identifiers, and a key that does not match one we would "
            "have built names an object outside the prefix the purge sweeps",
            retryable=False,
        )

    try:
        response = client.get_object(Bucket=bucket, Key=key)
    # Broad, because boto3 builds its exception classes off a per-client factory: there is no
    # importable `NoSuchKey` to catch and the name is the only stable handle.
    except Exception as exc:
        if type(exc).__name__ in {"NoSuchKey", "ClientError", "NoSuchBucket"}:
            raise ObjectNotFound(
                ErrorClass.STORAGE,
                f"no object at the composed key for source {source_id}. The key is derived "
                "from the row, never taken from the request, so this is a missing object "
                "rather than a wrong path",
                retryable=True,
            ) from None
        raise

    length = response.get("ContentLength")
    if isinstance(length, int) and length > MAX_OBJECT_BYTES:
        raise KbError(
            ErrorClass.STORAGE,
            f"the object at the composed key is {length} bytes, above the {MAX_OBJECT_BYTES} "
            "ceiling. The upload intake caps originals far below this, so an object this size "
            "is not a large upload — it is not the object the row describes",
        )

    body: bytes = response["Body"].read()

    actual = hashlib.sha256(body).hexdigest()
    if actual != content_hash:
        raise KbError(
            ErrorClass.STORAGE,
            f"the object stored for source {source_id} hashes to {actual[:12]}… but the row "
            f"names {content_hash[:12]}…. The hash IS the key, so the two cannot disagree "
            "unless the object was replaced under an immutable name or the store answered "
            "with a different object; indexing it would publish a version built from bytes "
            "nobody uploaded",
        )

    return body
