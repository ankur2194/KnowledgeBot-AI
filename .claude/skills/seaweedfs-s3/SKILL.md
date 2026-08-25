---
name: seaweedfs-s3
description: SeaweedFS 4.40 behind its S3 gateway for KnowledgeBot AI — the portable S3 subset we allow ourselves, org-prefixed keys, multipart upload from Laravel and Celery, SHA-256 integrity on every re-read, and verified prefix deletion. Use whenever writing an upload, download, sweep, or backup path touching object storage in services/core-api/ or services/ai-service/, or when a stored file parses as garbage, a deleted prefix keeps billing, or a PutObject starts failing after an SDK bump. No browser ever holds a presigned URL. Pairs with kb-tenancy-isolation (the key layout) and kb-deletion-and-verification (the sweep it must prove).
---

# SeaweedFS via the S3 API

SeaweedFS **4.40** (released 2026-07-20), image `chrislusf/seaweedfs:4.40`, reached through its S3 gateway (`weed s3`, port 8333) with `aws-sdk-php` 3.x in Laravel and `boto3`/`botocore` ≥ 1.36 in the AI service. **Minimum 4.30** — multipart ETag correctness only landed in June 2026 (#9772).
**Authoritative spec:** docs/05-tech-stack.md §9.9, docs/08-ingestion-pipeline.md §13.2 §13.3 §13.5, docs/13-security.md §18.7 §18.10, docs/18-deployment-backup-cicd.md §24.7 §25.1 §25.3 §25.4 §25.5

SeaweedFS has no semver, no LTS, and ships roughly every 5.5 days; 4.16→4.40 is 24 releases in 132 days, and #9700 is a **data-loss** regression inside the 4.x line (0-size chunk after a 4.17→4.25 `filer.sync`). Pin an exact tag, treat every bump as an upgrade with a restore test, never float `:latest`.

## Non-negotiables

1. **Every key starts `org/{org_id}/sources/{source_id}/`** (`kb-tenancy-isolation`), and below it there are exactly **two** tails, which are siblings and not nested: `original/` (source-scoped, irreplaceable) and `versions/{source_version_id}/derived/{parse,ocr,images}/` (version-scoped, rebuildable). ADR-066 — this line used to require the version segment on *every* key, and the control plane structurally cannot obey that: `source_versions` has NOT NULL `ingest_key` and three `*_cfg_version` columns with CHECKs that reject a placeholder, and every one of those values is produced by the data plane *during parsing*, so the request holding the uploaded bytes has no version id and cannot acquire one. A key without the org segment is a cross-tenant leak with no exception, no log line, and no failing test. Never content-address at the root (`objects/{sha256}`): that dedupes one org's price list onto another's and turns a delete into someone else's outage.
2. **Nothing authoritative lives here** (`kb-architecture-map`, §9.9). PostgreSQL is truth. Every object outside `original/` must be recomputable from an original plus `chunks`/`source_versions` — and `original/` holds *both* irreplaceable kinds, the uploaded or pasted body and the crawl snapshot (see the backup section for why the snapshot moved there). If losing a derived artifact changes what the bot answers rather than what it costs to rebuild, the artifact has become authoritative and the ADR is already broken.
3. **Only the portable S3 subset** (§9.9 requires the store be swappable without touching business logic). Application code may call exactly: `PutObject`, `GetObject` (incl. `Range`), `HeadObject`, `CopyObject`, `DeleteObject`, `DeleteObjects`, `ListObjectsV2`, `ListObjectVersions`, and the seven multipart operations. Nothing else — see *Replaceability* below for the concrete ban list.
4. **No browser ever reaches object storage, and no presigned URL leaves the internal network.** The S3 gateway is not a Traefik router (`traefik-routing` publishes four hostnames; this is not one). Resolved in full below.
5. **Integrity is our SHA-256 in PostgreSQL, never the ETag.** §13.2 stage 4 hashes before stage 5 stores, and §13.3 keys idempotency on that hash. Every read that feeds a parser re-verifies it. ETag semantics differ between SeaweedFS, AWS, and MinIO, and differ *within* SeaweedFS by write path.
6. **Deletion is a prefix sweep, verified by listing, never by trusting a 2xx** (`kb-deletion-and-verification`). Batch deletes report per-key failures inside a 200 response body.

## How we use it

### The bucket layout

**One bucket, `kb`, forever.** Tenancy is the key prefix, enforced in our code — not a bucket per organization. A SeaweedFS bucket is a filer collection, so bucket-per-tenant multiplies collections without bound, and it does not port: every managed store caps buckets per account, and bucket creation is a slow, quota-governed control-plane operation everywhere — the wrong thing to put on the signup path. Prefix isolation ports everywhere and is the only form `kb-tenancy-isolation` recognises.

```
org/{org_id}/sources/{source_id}/
    original/{content_hash}                          ← irreplaceable; retention decides its fate
    original/{content_hash}.txt                      ← a pasted-text body; SAME prefix, `.txt` suffix
    versions/{source_version_id}/derived/parse/document.json   ← Docling output, feeds §25.3
    versions/{source_version_id}/derived/ocr/page-{n}.txt
    versions/{source_version_id}/derived/images/{element_id}.png
```

**`original/` is a sibling of `versions/`, not a child of one** (ADR-066), and the two consequences are what a reader is here for:

- **One object serves every version of a source.** Never assume the original you fetched belongs to the version you are processing — it belongs to the *source*, and the version's `content_hash` is what says which bytes those are. And **`purge_retired_version` must never be given `include_original`**: that destroys the bytes the *successor* version was built from, silently, until the next reprocess fails.
- **The two spellings are both real and a reader must compose both.** `ObjectKey::originalUpload()` appends no suffix — an upload's type is a *sniff*, and putting a value derived from attacker-influenced text into a path is how a key stops being addressable — while `ObjectKey::originalText()` appends `.txt`, honestly, because *we* generated those bytes from a validated UTF-8 string. A reader that composes only the first cannot address any pasted-text source, and the symptom is not a mismatch: it is `NoSuchKey`, reported accurately as a missing object, about an object that is present under the other name. **This is not hypothetical — it shipped**, and `docs/22` § Q8 is the record.

This paragraph used to say `original/` and `derived/` are siblings *"so that the phase-2 sweep and its verification are the same prefix string"*. That justification **splits** under ADR-066 and only half of it survives: `derived/` is swept by one version prefix, and `original/` is swept by the *source* prefix once no version references it. The property that actually matters is unchanged — a legitimately retained original is never filtered out of an enumeration, because it has its own prefix.

**Both key spellings and every prefix are composed in exactly one place per runtime** — `App\Support\Kb\ObjectKey` and `services/ai-service/app/storage/objects.py` — and `tests/contract/test_object_key_cross_language.py` runs the PHP class in a container and compares the strings character for character, in both the accept and the refuse direction. Two transcriptions of one layout, each agreeing with its own documentation and disagreeing on bytes, is a failure neither runtime's own suite can see.

### Client configuration and the operations we allow

```python
# services/ai-service/app/storage/objects.py
import hashlib
import boto3
from botocore.config import Config

BUCKET = "kb"

_s3 = boto3.client(
    "s3",
    endpoint_url=settings.s3_endpoint,        # http://seaweedfs-s3:8333 — internal Compose network only
    aws_access_key_id=settings.s3_key,
    aws_secret_access_key=settings.s3_secret,
    region_name="us-east-1",                  # SeaweedFS ignores it; SigV4 signs it. Identical string in Laravel.
    config=Config(
        signature_version="s3v4",
        s3={"addressing_style": "path"},      # virtual-host style would need wildcard DNS we do not have
        # We verify with our own SHA-256, so the SDK's default per-request CRC32 buys nothing
        # and costs a whole interop-breakage class (see Gotchas). Portable: client-side setting.
        request_checksum_calculation="when_required",
        response_checksum_validation="when_required",
        retries={"mode": "standard", "max_attempts": 3},
    ),
)


# Illustrative only. The real ones live in app/storage/objects.py, which is pure string
# algebra with a guard and no client, so deletion can share the algebra without importing a
# reader — and every segment goes through a guard that refuses `/`, `\`, a control character,
# `.` and `..`. Such a segment does not produce a BROKEN key, it produces a WELL-FORMED KEY IN
# THE WRONG PLACE: S3 does not normalize, so `..` is a literal sequence naming a real object,
# potentially outside the org prefix, where nothing at all guards a read.

def source_prefix(org_id: str, source_id: str) -> str:
    """The narrowest prefix a source delete may sweep, and the widest a version delete may not.
    The org segment IS the tenant boundary (kb-tenancy-isolation)."""
    return f"org/{org_id}/sources/{source_id}/"


def original_upload(org_id: str, source_id: str, content_hash: str) -> str:
    """Source-scoped, NOT under versions/ (ADR-066). No suffix — see the layout note."""
    return f"{source_prefix(org_id, source_id)}original/{content_hash}"


def version_prefix(org_id: str, source_id: str, version_id: str) -> str:
    """Everything scoped to one version. NOTE WHAT IS NOT UNDER HERE: `original/`."""
    return f"{source_prefix(org_id, source_id)}versions/{version_id}/"


def put_derived(org_id, source_id, version_id, name: str, body: bytes, content_type: str) -> tuple[str, str]:
    """Hash, then write — in that order, so the digest describes bytes we actually sent."""
    key = f"{version_prefix(org_id, source_id, version_id)}derived/{name}"
    sha = hashlib.sha256(body).hexdigest()
    _s3.put_object(Bucket=BUCKET, Key=key, Body=body, ContentType=content_type,
                   Metadata={"sha256": sha})   # convenience only; PostgreSQL is the record
    return key, sha


def get_verified(key: str, expected_sha256: str) -> bytes:
    """The only read that may feed a parser. `expected_sha256` comes from PostgreSQL
    (`source_items.content_hash` for originals), never from x-amz-meta-sha256 — that
    header was written by the same request that wrote the body, so it cannot detect
    corruption in transit or at rest. Never from the ETag either."""
    data = _s3.get_object(Bucket=BUCKET, Key=key)["Body"].read()
    actual = hashlib.sha256(data).hexdigest()
    if actual != expected_sha256:
        raise ObjectIntegrityError(key, expected_sha256, actual)   # non-retryable; kb-error-taxonomy
    return data


def purge_retired_version(org_id, source_id, version_id) -> None:
    """Phase-2 step 9 of kb-deletion-and-verification. Idempotent; safe to re-run after a kill.

    THERE IS NO `include_original` PARAMETER AND THERE MUST NEVER BE ONE. Under ADR-066 the
    original is source-scoped, so one object serves every version — passing the flag here would
    destroy the bytes the SUCCESSOR version was built from, and it would fail silently until the
    next reprocess. Retiring a version deletes derived output and nothing else; the original's
    fate is decided once, when the SOURCE is deleted, by purge_source_originals() below.
    """
    prefixes = [f"{version_prefix(org_id, source_id, version_id)}derived/"]
    for prefix in prefixes:
        for page in _s3.get_paginator("list_objects_v2").paginate(Bucket=BUCKET, Prefix=prefix):
            keys = [{"Key": o["Key"]} for o in page.get("Contents", [])]   # absent, not [], when empty
            for i in range(0, len(keys), 1000):                            # hard 1000-key cap per request
                r = _s3.delete_objects(Bucket=BUCKET, Delete={"Objects": keys[i:i + 1000], "Quiet": True})
                if r.get("Errors"):
                    raise ObjectDeleteFailed(r["Errors"])   # HTTP 200 with per-key failures in the body


def purge_source_originals(org_id, source_id) -> None:
    """The other half, run ONCE per source and only when retention says the bytes may go.

    Source-scoped because the object is. A *knowledge removed, original retained* disposition
    calls purge_retired_version() for every version and never calls this — and that disposition
    is exactly why `original/` has its own prefix: the retained object is enumerable and
    reportable rather than something a listing has to filter out.
    """
    prefixes = [f"{source_prefix(org_id, source_id)}original/"]
    ...   # same paginate → delete_objects → inspect Errors loop as above


def assert_prefix_empty(prefix: str) -> None:
    """Verification. ListObjectVersions, not ListObjectsV2: if anyone ever enables bucket
    versioning, every DELETE above silently becomes a delete marker and ListObjectsV2 keeps
    reporting clean while the bytes stay. So assert the bucket's posture too."""
    if _s3.get_bucket_versioning(Bucket=BUCKET).get("Status") in ("Enabled", "Suspended"):
        raise BucketVersioningEnabled(BUCKET)
    for page in _s3.get_paginator("list_object_versions").paginate(Bucket=BUCKET, Prefix=prefix):
        if page.get("Versions") or page.get("DeleteMarkers"):
            raise PrefixNotEmpty(prefix)
```

### Presigned URLs — the decision

`kb-security-baseline` (`references/file-upload-safety.md`) offers "a short-lived presigned URL **or** a Laravel proxy route". **We take the proxy route, always. No presigned URL is generated anywhere in this codebase, for upload or download.** Four reasons, in order of weight:

1. **A presigned URL is a bearer credential in a URL** — the exact shape `laravel-sanctum-auth` Non-negotiable 4 bans, for the exact reasons: it lands in access logs, in `Referer` on the next navigation, and in browser history. It is *narrower* than a Sanctum token (one object, one method, one TTL), but it is equally unrevokable: it survives a role change, an org removal, and a source deletion for its whole lifetime, so it cannot participate in the six checks `kb-security-baseline` requires of every protected action.
2. **It would require publishing the S3 gateway.** A browser can only use a presigned URL against a reachable host, so this means a fifth public hostname in front of a service whose auth **fails open** (no identities configured = every operation unauthenticated). `kb-architecture-map` does not route it and `traefik-routing` has no router for it; that is the design, not an omission.
3. **Direct-to-S3 upload puts the bytes in the bucket before anything validated them.** MIME allow-list, size cap, decompression-ratio cap, and the malware scan (`kb-security-baseline`) all run on a stream Laravel is holding. A presigned PUT inverts that into scan-after-store, which is a quarantine problem we have no reason to acquire.
4. Presigned PUT is **broken by default** with current SDKs anyway: with no `Body` at presign time the SDK signs `crc32("") == AAAAAA==` into the URL, and the real upload is rejected (SeaweedFS #10216 — and AWS S3, R2, and MinIO reject it identically, so this is not a compatibility gap to wait out).

What replaces it: uploads are `multipart/form-data` to Laravel, which streams to SeaweedFS; downloads are a Laravel route that runs the six checks and returns a **streamed** response (`response()->stream()`, chunked reads from the SDK's `GuzzleHttp\Psr7\Stream`) with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff` from the separate user-content origin. Two costs, both accepted: egress crosses PHP, and a PHP-FPM worker is held for the length of each transfer — size the pool for concurrent downloads, and never buffer (`Storage::get()` on a 400 MB original is an OOM, not a slow response). Revisit only if the product ever needs multi-GB downloads at concurrency; the change then is a signed, short-TTL **Laravel** route that still proxies, not a presigned S3 URL.

### Large files

- **Threshold 64 MiB, part size 64 MiB.** SeaweedFS implements all seven multipart operations including `UploadPartCopy`.
- **Laravel:** `Aws\S3\ObjectUploader` / `MultipartUploader` over the uploaded file's stream (`$request->file('f')->getRealPath()` → `fopen`). Never `Storage::put($key, file_get_contents(...))` — see Gotchas.
- **Celery:** `_s3.upload_fileobj(fh, BUCKET, key, Config=TransferConfig(multipart_threshold=64<<20, multipart_chunksize=64<<20))`. `upload_fileobj` reads the file object from several threads, so a "tee the `read()` and hash as we go" wrapper produces a **wrong** digest under multipart — hash a separate pass or `use_threads=False`.
- **Aborted uploads leak parts that `ListObjectsV2` cannot see.** AWS's answer is an `AbortIncompleteMultipartUpload` lifecycle rule; ours is the Celery beat entry **`sweep-abandoned-multipart-uploads`** — daily, `crontab(minute=17, hour=4)`, `maintenance` queue (`celery-workers` owns the schedule) — walking `ListMultipartUploads` → `AbortMultipartUpload` for every upload older than **24 h**. 24 h clears the longest legitimate in-flight upload (a 960 s hard time limit plus one 7200 s visibility-timeout redelivery ≈ 2.3 h) by an order of magnitude; a shorter age aborts uploads that are still running and the ingestion job dies on `NoSuchUpload`. A lifecycle rule cannot do this job here — it is a silent no-op (see Gotchas).

### Backup — §25.4, and why it inverts the usual priority

Qdrant is rebuildable (§25.3) and PostgreSQL has PITR (§25.2). **This store is the only one holding bytes that cannot be recomputed from anything else**, and there is exactly one prefix of them: `original/`. Everything under `derived/` is recomputable from an original, so it is *cheap-to-lose*, not *safe-to-lose* — §25.3's "rebuild vectors from PostgreSQL metadata and stored normalized content" is the proof that Qdrant is derived, and it reads `derived/parse/`. Two tiers: tier 1 (`original/`) goes offsite; tier 2 (`derived/`) is replicated locally and regenerated after a loss.

**A crawl snapshot is an `original/`, not a `derived/snapshot/`** — this is the resolution of `docs/22` § Q12, and the contradiction it resolves was in this file. Line 137 used to name *"the `original/` upload **and the crawl `snapshot/`**"* as the two irreplaceable things and put both in tier 1, while the sweep above deleted all of `derived/` unconditionally and `snapshot/` lived under it. So a *knowledge removed, original retained* disposition retained the upload and destroyed the snapshot — the other object this same file said could not be recomputed. A crawled page is not re-fetchable in the sense that matters: the remote page has changed or is gone, which is the entire reason the snapshot exists.

Of the two available shapes — hoist `snapshot/` out, or give the sweep a third disposition — hoisting wins, and it turns out not to need a new prefix at all. **The snapshot *is* the original for a crawled source**: it is the irreplaceable input every derived artifact is computed from, it is content-addressed by the same `content_hash`, and a recrawl that changes nothing produces the same hash and therefore the same object, which is the dedupe a per-version snapshot prefix would have thrown away. So it lands at `original/{content_hash}` like any other, and the disposition set stays two-valued: tier 1 is `original/`, tier 2 is `derived/`, and the sweep needs no third case. A third disposition would have to be honoured by the sweep, the verification, the retention policy and the restore drill independently, and `docs/22` § Q1 is what happens when one of four such places is missed.

Nothing writes a snapshot today — `app/crawl/` is a deliberate stub — so this was recorded and fixed while it was still free. `app/storage/objects.py`'s `derived_prefix()` docstring carries the same rule on the code side.

- `weed filer.backup` runs continuously to a second cluster, **with `-initialSnapshot` on the first run** — without it you get changes from subscription time forward and the existing tree is silently absent. Offsets checkpoint on the source filer every ~3 s, so a restart resumes.
- **There is no consistent online snapshot.** Upstream guidance is to pause the cluster. Our restore procedure is therefore: restore filer metadata, restore volumes, run `volume.fsck` and `fs.verify`, then **reconcile against PostgreSQL** — for every `source_items` row, `HeadObject` its original key. PostgreSQL is the manifest, and the missing-key list is the true restore gap. Document that as the §25.5 object-storage restore procedure.
- `-defaultReplication` is **not** backup. Writes are strongly consistent, but SeaweedFS never re-establishes a lost replica on its own; `volume.fix.replication` is a manual shell command that must be scheduled.
- Do not use **SSE-C**: `filer.backup` and `filer.sync` have no access to customer keys, so SSE-C objects are absent from the DR copy. If at-rest encryption is required, use filer-level `-encryptVolumeData` (per-chunk AES-256-GCM, keys in filer metadata) — an ops-layer choice that no application code observes.

### Replaceability (§9.9), concretely

Forbidden in `services/core-api/` and `services/ai-service/`: the filer HTTP API (`:8888/path/...`), any `weed shell` invocation, volume TTL (`assign?ttl=`), `Seaweed-*` headers, dependence on the `v_<32-hex>` version-id format, dependence on ETag being an MD5, bucket policies or per-bucket credentials as a tenant boundary, and bucket lifecycle rules as a deletion mechanism. **Worth paying for anyway, because they live entirely in `infrastructure/`:** `filer.backup`/`filer.sync`, erasure coding for cold volumes, and the circuit breaker (`/etc/s3/circuit_breaker.json`, per-bucket rate limits returning 429 `SlowDown`). Migrating to a managed store deletes those three files and changes nothing above the storage module. Not worth it under any condition: versioning, object lock, lifecycle, tagging, SSE-KMS — each is a portability tax on a feature PostgreSQL already gives us.

**Not defined here.** Compose services, networks, and volumes for the SeaweedFS tier → `docker-compose-stack`. Edge routing and why this is not routed → `traefik-routing`. MIME/extension allow-lists, size caps, decompression bombs, and malware scanning → `kb-security-baseline` (`references/file-upload-safety.md`). Key layout and org scoping → `kb-tenancy-isolation`. Deletion ordering and the four checks → `kb-deletion-and-verification`.

## Gotchas

- **Every operation succeeds and nothing is authenticated.** With no identities in the `-config` JSON the S3 gateway runs in **Allow-All mode** — it fails *open*, not closed, and a mistyped or unreadable config path produces a wide-open store rather than a startup error. Assert it at boot and in CI: an anonymous `ListBuckets` against the endpoint must return 403.
- **`EndpointConnectionError` / NXDOMAIN for `kb.seaweedfs-s3`.** Virtual-host addressing is the SDK default and needs wildcard DNS that a Compose network does not have. Set path-style on **both** clients (`s3={"addressing_style": "path"}`, `'use_path_style_endpoint' => true`).
- **A stored file parses as garbage and Docling raises a confusing format error; re-running reproduces it forever.** SeaweedFS mistakes HTTP `Transfer-Encoding: chunked` framing for `Content-Encoding: aws-chunked` and stores the chunk-size lines *as file content* — silent corruption, no error (#6583, #6578, both open since Feb 2025). This is why S3 traffic never traverses a reverse proxy: Laravel and the workers speak to `seaweedfs-s3:8333` directly on the internal network. `get_verified()` is what turns this from a mystery parse failure into a named integrity error. <!-- UNVERIFIED: not confirmed whether #6583/#6578 still reproduce on 4.40; both remain open with no closing commit -->
- **`PutObject` starts returning `InternalError` right after a routine SDK bump.** botocore ≥ 1.36 / aws-sdk-php / aws-sdk-js ≥ 3.729 made flexible checksums default-on; SeaweedFS has been patching this surface continuously since Feb 2025 (#6539, #6713, #7246, #9076, #9905, #10064, and FULL_OBJECT CRC only in July 2026). 4.40 handles it, but the code is young. `request_checksum_calculation="when_required"` removes the dependency entirely and costs us nothing because integrity is our own SHA-256.
- **An ETag comparison fails on an object nobody touched.** SeaweedFS returns hex MD5 for a single-part PUT *even when it internally autochunks above 8 MB* — but when `Attributes.Md5` is absent (objects written via FUSE, WebDAV, the filer HTTP API, or a remote mount) it returns `{md5}-N` where **N is the filer chunk count, not an S3 part count**. Multipart returns AWS-compatible `md5(concat(part md5s))-N`, and that only became correct in June 2026 (#9772; Spark/Hadoop-AWS failed with `Constraints of request were unsatisfiable on ETag` before it). Never derive integrity, identity, or change detection from an ETag — that is what `content_hash` is for (§13.3).
- **A deleted prefix keeps growing on disk and verification still reports clean.** Someone enabled bucket versioning; every `DeleteObject` since became a delete marker, and `ListObjectsV2` hides noncurrent versions by design. `assert_prefix_empty()` checks the bucket's versioning status *and* lists versions, so the posture change fails loudly instead of silently defeating the whole deletion contract.
- **`delete_objects` returned HTTP 200 and the objects are still there.** The batch API reports per-key failures in the response body (`Errors`), and `Quiet: True` suppresses only the *successes*. Inspect `Errors` on every call or the phase-2 sweep reports success and the verification job discovers it hours later with no diagnosis.
- **Prefix verification passes against a prefix that is full.** `list_objects_v2` omits `Contents` entirely when nothing matches, so `len(page["Contents"])` raises and `page.get("Contents", [])` is right — but code written as `if "Contents" in page: ...` with no else silently treats "key absent" as "checked and empty". Paginate; never read `KeyCount` off a single unpaginated response (default `MaxKeys` is 1000).
- **Storage grows with no matching objects, in any listing.** Failed or abandoned multipart uploads. `ListObjectsV2` cannot see them, `ListObjectVersions` cannot either, and `assert_prefix_empty()` therefore passes over a prefix that is still consuming volumes — only `ListMultipartUploads` can see them. The one reclaimer is the `sweep-abandoned-multipart-uploads` beat entry above (daily, `maintenance`, 24 h); do not substitute a lifecycle rule (next bullet).
- **Lifecycle rules are configured, the console shows them, and nothing ever expires.** SeaweedFS lifecycle needs the admin server plus a lifecycle worker running; without them "a bucket with rules but no worker silently retains data past its declared expiration", with no error. Default detection interval is 1440 minutes, so even when it works, expiry lags a day. Retention is a PostgreSQL-driven Celery sweep here, never a bucket rule.
- **Legal hold cannot be applied to an existing bucket.** Object Lock requires versioning *and* can only be enabled at bucket creation — there is no retrofit, only a bucket migration. `kb-deletion-and-verification` mandates governance-mode locks for held originals, so if that path is ever built it needs a **separate, pre-created versioned lock-enabled bucket** that held originals are `CopyObject`'d into. Provision it before it is needed, or the first legal hold is a migration under time pressure.
- **PHP dies with `Allowed memory size exhausted` on a file well under `upload_max_filesize`.** `Storage::put($key, file_get_contents($path))` and `Storage::get($key)` both materialise the whole object in PHP memory. Stream in with `MultipartUploader`, stream out with `response()->stream()`.
- **The SDK feature probe succeeds and the feature does nothing.** SeaweedFS returns plausible empty successes rather than 501 for Analytics, Inventory, IntelligentTiering, Metrics, Logging, and Accelerate. Feature detection lies; the supported-API wiki is the only source of truth, and rule 3 above is why we never ask.

## Official docs

- [SeaweedFS — Amazon S3 API](https://github.com/seaweedfs/seaweedfs/wiki/Amazon-S3-API) — the authoritative supported-operations list; read it instead of assuming AWS parity.
- [SeaweedFS — Supported APIs vs MinIO](https://github.com/seaweedfs/seaweedfs/wiki/Supported-APIs-vs-Minio) — which operations are real, which are stubs.
- [SeaweedFS — S3 Configuration](https://github.com/seaweedfs/seaweedfs/wiki/S3-Configuration) and [S3 Credentials](https://github.com/seaweedfs/seaweedfs/wiki/S3-Credentials) — the identities JSON, action verbs, and Allow-All mode.
- [SeaweedFS — S3 Lifecycle vs Volume TTL](https://github.com/seaweedfs/seaweedfs/wiki/S3-Lifecycle-vs-Volume-TTL) — the two unrelated expiry mechanisms and why neither is our retention path.
- [SeaweedFS — Data Backup](https://github.com/seaweedfs/seaweedfs/wiki/Data-Backup), [Async Filer Metadata Backup](https://github.com/seaweedfs/seaweedfs/wiki/Async-Filer-Metadata-Backup), [Replication](https://github.com/seaweedfs/seaweedfs/wiki/Replication) — `filer.backup`, `filer.sync`, and why replication is not backup.
- [SeaweedFS — Weed Shell](https://github.com/seaweedfs/seaweedfs/wiki/Weed-Shell) — `volume.fsck`, `fs.verify`, `volume.fix.replication` for the restore reconciliation.
- [SeaweedFS releases](https://github.com/seaweedfs/seaweedfs/releases) — the cadence, and the tag to pin.
- [boto3 — S3 customization and `TransferConfig`](https://boto3.amazonaws.com/v1/documentation/api/latest/guide/s3.html) — multipart thresholds and `upload_fileobj` threading.
- [AWS SDK — data-integrity protections](https://docs.aws.amazon.com/sdkref/latest/guide/feature-dataintegrity.html) — `request_checksum_calculation` / `response_checksum_validation` and the 2025 default change.

## Definition of done

- [ ] Every key produced in the change set is built by `app/storage/objects.py` or `App\Support\Kb\ObjectKey` — never both spellings hand-written, never one of them — and begins `org/{org_id}/`; `grep -rn "Bucket=\|->putObject(\|Storage::" services/ --include=*.py --include=*.php` shows no hand-built key.
- [ ] If either side gained or renamed a key method, `PAIRS` in `services/ai-service/tests/contract/test_object_key_cross_language.py` gained the row too. That list is the wire: a method missing from it halves the comparison silently, which is the only way this file's guarantee fails.
- [ ] Both clients are configured path-style, SigV4, same `region_name`, and `when_required` checksums.
- [ ] The S3 gateway has identities configured; an anonymous `ListBuckets` returns 403, asserted by a test, not by inspection.
- [ ] No presigned URL is generated anywhere: `grep -rn "presign\|createPresignedRequest\|generate_presigned" services/ apps/` is empty, and CI keeps it empty.
- [ ] Uploads over 64 MiB use `MultipartUploader`/`upload_fileobj`; nothing calls `file_get_contents`, `Storage::get`, or `Storage::put($key, $contents)` on a source object.
- [ ] The `sweep-abandoned-multipart-uploads` beat entry exists in `beat_schedule` (daily, `maintenance`), aborts multipart uploads older than 24 h, and its run is observable (`kb-observability-conventions`).
- [ ] Every read feeding a parser goes through `get_verified()` against `content_hash` from PostgreSQL; a byte-flipped object fails with `ObjectIntegrityError`, not a parser exception.
- [ ] Only the operations listed in Non-negotiable 3 appear in application code; no filer HTTP call, no `weed` invocation, no lifecycle/versioning/tagging/object-lock call.
- [ ] Retiring a version sweeps **only** that version's `derived/` prefix and takes no `include_original` flag; `original/` is swept separately, source-scoped, once retention says so and no surviving version references the bytes. Both inspect `Errors` on every batch and are idempotent across a mid-run kill.
- [ ] Deletion verification calls `assert_prefix_empty()` — bucket versioning asserted disabled, `ListObjectVersions` paginated, `Versions` and `DeleteMarkers` both empty.
- [ ] Cross-tenant test: purging org A's source leaves org B's byte-identical object at its own prefix intact.
- [ ] `filer.backup` runs with `-initialSnapshot` on first start; a restore drill restores tier-1 objects and reconciles every `source_items.content_hash` by `HeadObject`, with the missing-key list recorded as the §25.5 evidence.
- [ ] The SeaweedFS image tag is exact (≥ 4.30), and an upgrade is accompanied by a restore drill.
