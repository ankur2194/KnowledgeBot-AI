"""Every object-storage key this service composes, built in exactly one place.

**The twin of ``App\\Support\\Kb\\ObjectKey``** (`services/core-api/app/Support/Kb/ObjectKey.php`).
That class's ``versionPrefix()`` docblock names this file by path, ``seaweedfs-s3``'s
client-configuration block is headed with it, and its Definition of done line 180 requires that
"every key produced in the change set is built by ``version_prefix()`` (or its Laravel twin)".

**IT DID NOT EXIST**, and ``docs/22`` § Q8 recorded the absence rather than the consequence,
because the consequence had not been looked for. It was there: ``app/db/objects.py`` composed the
UPLOAD spelling of the original key for every source, while ``SourceService::storeText()`` writes
a pasted body at the TEXT spelling — the same prefix with a ``.txt`` suffix. So every text source
resolved to a key nothing had written, ``fetch_original`` raised ``ObjectNotFound``, and the
version failed as `storage` with a message saying the object was missing. It was not missing. The
two planes disagreed about its name, which is precisely the "two implementations of one layout"
cost ADR-066 names and Q8 predicted would land here first.

═══ WHAT THIS MODULE IS AND IS NOT ═════════════════════════════════════════════════════════════

It is **pure string algebra with a guard**. No client, no I/O, no settings. ``app/db/objects.py``
is the one module that makes object-storage *calls*; this is the one module that decides what a
key *is*. Splitting them is what lets deletion — which needs prefixes and issues no read — share
the algebra without importing a reader.

Every function here mirrors a static method on ``ObjectKey``, name for name, and
``tests/contract/test_object_key_cross_language.py`` runs the PHP class in a container and
compares the strings. That test is the whole point of writing this file rather than another
format string: two transcriptions of a layout agreeing with their own documentation and
disagreeing on bytes is a failure neither runtime's suite can see.

═══ THE LAYOUT, AND THE DEPARTURE FROM `seaweedfs-s3` ══════════════════════════════════════════

::

    org/{org_id}/                                                    orgPrefix
    org/{org_id}/sources/{source_id}/                                sourcePrefix
    org/{org_id}/sources/{source_id}/original/                       originalPrefix
    org/{org_id}/sources/{source_id}/original/{sha256}               originalUpload
    org/{org_id}/sources/{source_id}/original/{sha256}.txt           originalText
    org/{org_id}/sources/{source_id}/versions/{version_id}/          versionPrefix
    org/{org_id}/sources/{source_id}/versions/{version_id}/derived/  derivedPrefix

``original/`` is a **sibling of** ``versions/``, never a child of one (ADR-066). Laravel cannot
mint a ``source_versions`` row at intake — ``ingest_key`` and the three ``*_cfg_version`` columns
are NOT NULL with CHECKs that reject a placeholder, and every one of those values is produced by
this service during parsing — so the request holding the bytes has no version id to put in a key
and cannot acquire one.

The consequence that reaches every reader: **one object serves every version of a source.** Never
assume the original you fetched belongs to the version being processed; it belongs to the
*source*, and the version's ``content_hash`` is what says which bytes those are. And
``purge_retired_version`` must never pass ``include_original=True`` — that destroys the bytes the
successor version was built from, silently, until the next reprocess.
"""

from __future__ import annotations

import re
from typing import Final

from app.core.errors import ErrorClass, KbError

__all__ = [
    "derived_prefix",
    "org_prefix",
    "original_prefix",
    "original_text",
    "original_upload",
    "segment",
    "source_prefix",
    "version_prefix",
]

#: A separator, a backslash or a control character in a segment. Matches PHP's
#: ``/[\/\\\\]|[[:cntrl:]]/`` exactly, because the two guards have to refuse the same strings —
#: a value one side accepts and the other rejects is a key one plane can write and the other
#: cannot address, which is the defect this module was written after.
_ILLEGAL: Final[re.Pattern[str]] = re.compile(r"[/\\]|[\x00-\x1f\x7f]")


def segment(value: str, what: str) -> str:
    """One path segment, or a refusal. Returns the value unchanged.

    EVERY ID THAT REACHES THIS MODULE IS A ULID OR A HEX DIGEST, so this fires for nobody today
    and is not a validator standing in for one. It is here because of what the failure would be:
    a segment containing ``/`` or ``..`` does not produce a broken key, it produces a WELL-FORMED
    KEY IN THE WRONG PLACE — potentially outside the org prefix, where nothing at all guards a
    read. S3 does not normalize keys; ``..`` is a literal character sequence naming a real object.

    Refusing structurally beats sanitizing: a sanitized segment is a silent rename, and this
    layout has already paid for one silent divergence.

    ``ErrorClass.VALIDATION`` and non-retryable, where the PHP twin raises
    ``InvalidArgumentException``. The classes differ because the taxonomies do — every exception
    crossing a Celery task boundary here is classified, and an unclassified one is reported as
    `internal_dependency` and RETRIED, which is the wrong answer for a programming error.
    """
    if value == "" or value in {".", ".."} or _ILLEGAL.search(value):
        raise KbError(
            ErrorClass.VALIDATION,
            f"an object key segment must be a single non-empty path component; the {what} given "
            "is not one, and a key built from it would name a location outside the prefix the "
            "deletion sweep walks",
            retryable=False,
        )
    return value


def org_prefix(org_id: str) -> str:
    """Everything one organization owns. **The widest prefix any sweep may ever name.**

    Non-negotiable 1: every storage path is scoped to an organization. One segment too few here
    is every tenant's data.
    """
    return f"org/{segment(org_id, 'organization id')}/"


def source_prefix(org_id: str, source_id: str) -> str:
    """Everything one source owns, and the narrowest prefix a source delete may sweep.

    Every key below is inside it. A key that is NOT inside it is the shape that survives a
    *verified* deletion: the purge walks the prefixes it is given and verification enumerates
    those same prefixes, so an object outside them is not merely missed — it is certified clean
    while it survives.
    """
    return f"{org_prefix(org_id)}sources/{segment(source_id, 'source id')}/"


def original_prefix(org_id: str, source_id: str) -> str:
    """The irreplaceable bytes for one source: uploads and pasted text.

    Source-scoped, and its own prefix separate from ``versions/``, because the phase-2 sweep
    deletes ``derived/`` unconditionally and ``original/`` only per retention policy. A source can
    legitimately end as *knowledge removed, original retained*, and keeping the two apart is what
    lets the retained object be enumerated and reported rather than filtered out of a listing.
    """
    return f"{source_prefix(org_id, source_id)}original/"


def original_upload(org_id: str, source_id: str, content_hash: str) -> str:
    """The stored bytes of one uploaded file, exactly as they arrived. **No suffix.**

    An upload's type is a SNIFF, and appending an extension would put a value derived from
    attacker-influenced text into a path. ``source_items.mime`` is the authority for every reader.
    """
    return f"{original_prefix(org_id, source_id)}{segment(content_hash, 'content hash')}"


def original_text(org_id: str, source_id: str, content_hash: str) -> str:
    """The stored body of a pasted-text source. **The same prefix, plus ``.txt``.**

    The suffix is honest here because *we* generated those bytes from a validated UTF-8 string,
    so it restates something already known and helps an operator reading a bucket listing.

    THE SUFFIX IS THE ENTIRE DIFFERENCE FROM ``original_upload`` AND IT IS WHY BOTH EXIST HERE.
    A reader that composes only one spelling silently cannot address half the sources in the
    system — which is what was happening. A source is either pasted or uploaded and never both,
    so within one source the two can never collide.
    """
    return f"{original_upload(org_id, source_id, content_hash)}.txt"


def version_prefix(org_id: str, source_id: str, source_version_id: str) -> str:
    """Everything scoped to one source version. The twin ``seaweedfs-s3``:180 asks for.

    NOTE WHAT IS NOT UNDER HERE: ``original/``. In the skill's fixed layout it would be
    ``{version_prefix}original/``; here it is one level up (ADR-066).
    """
    return (
        f"{source_prefix(org_id, source_id)}versions/"
        f"{segment(source_version_id, 'source version id')}/"
    )


def derived_prefix(org_id: str, source_id: str, source_version_id: str) -> str:
    """Everything DERIVED from one version: parse output, OCR text, extracted images, a snapshot.

    Swept unconditionally by the phase-2 purge, and that is safe for exactly one reason:
    everything under it is rebuildable from the original plus PostgreSQL. Anything that is not
    rebuildable does not belong here — see ``docs/22`` § Q12, which is that rule being broken by
    ``snapshot/`` and is the reason a crawl snapshot must not land under this prefix.
    """
    return f"{version_prefix(org_id, source_id, source_version_id)}derived/"
