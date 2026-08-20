<?php

declare(strict_types=1);

namespace App\Support\Kb;

use InvalidArgumentException;

/**
 * Every object-storage key this service writes or reads, built in exactly one place.
 *
 * ── WHY THIS CLASS EXISTS, AND WHY IT DID NOT UNTIL A DEFECT PRODUCED IT ─────────────────────
 *
 * `seaweedfs-s3`'s Definition of done, line 180, requires that "every key produced in the change
 * set is built by `version_prefix()` (or its Laravel twin) and begins `org/{org_id}/`", and names
 * the grep that is supposed to find the exceptions. THE LARAVEL TWIN DID NOT EXIST. So
 * `SourceService::storeText()` interpolated its own string, and produced a key
 * (`org/{org}/sources/text/{hash}.txt`) with two properties nobody had a place to notice:
 *
 *   1. NOTHING COULD EVER DELETE IT. The phase-2 purge sweeps the prefixes it is given —
 *      `services/ai-service/app/deletion/tasks.py:165-190`, whose own docstring says "never widen a
 *      prefix to make a sweep succeed" — and verification
 *      (`services/ai-service/app/deletion/verification.py:311`) enumerates only under those same
 *      prefixes. A key outside them is not merely missed; it is CERTIFIED CLEAN while it survives,
 *      which is non-negotiable 6 ("deletion uses stable identifiers and is verified") failing in
 *      the one direction that produces a signed proof of a deletion that did not happen.
 *   2. IT DEDUPED ACROSS SOURCES. `{content_hash}.txt` directly under `sources/` is one object for
 *      every source in the organization whose text is byte-identical, with no reference count.
 *      `kb-tenancy-isolation:109` permits dedupe WITHIN an organization, and the one place this
 *      repository actually does it — extracted images — is explicitly refcounted
 *      (`deletion/tasks.py:180`: "dropped only once no surviving element references them"). There
 *      was no refcount here, so deleting either of two identical pastes took the other's body.
 *
 * A hand-built key is reviewed by nobody and compared to nothing. The comparison that catches both
 * defects — "is this key inside a prefix the purge sweeps" — is only possible once one file owns
 * both halves. That is this file, and it is why the fix is a class rather than an edited string.
 *
 * ── THE DEPARTURE FROM `seaweedfs-s3`, STATED PLAINLY BECAUSE IT IS ONE ──────────────────────
 *
 * `seaweedfs-s3` non-negotiable 1 (SKILL.md:15) and its layout diagram (SKILL.md:28-37) fix the key
 * as `org/{org_id}/sources/{source_id}/versions/{source_version_id}/original/{content_hash}`, with
 * `original/` and `derived/` as siblings INSIDE the version prefix. WE HOIST `original/` OUT: it
 * becomes a sibling of `versions/`, one level up, at
 * `org/{org_id}/sources/{source_id}/original/{content_hash}`.
 *
 * THE FIXED LAYOUT CANNOT BE HONOURED BY THE CODE THAT HAS THE BYTES, and the reason is structural
 * rather than a preference. `2026_08_20_002000_create_source_versions_table.php` makes `ingest_key`,
 * `parser_cfg_version`, `ocr_cfg_version`, `chunker_cfg_version` and `embedding_model_version` all
 * NOT NULL, with CHECKs (`^[0-9a-f]{64}$` on the key, `btrim(...) <> ''` on each cfg version) that
 * reject any placeholder. Every one of those values is produced by the DATA PLANE, during and after
 * parsing. So Laravel structurally cannot mint a `source_versions` row at intake — there is no
 * version id at the moment the bytes arrive, and there cannot be one. A key that names a version
 * therefore cannot be built by the request holding the content.
 *
 * Four reasons this shape rather than any other, in the order that decided it:
 *
 *   1. IT IS REACHABLE BY THE PURGE. `_purge_objects(org_id, source_id, version_ids, *,
 *      include_original)` already receives both ids and already treats `original/` as a separate
 *      disposition from `derived/`. The sweep it performs reaches a source-scoped `original/`
 *      without widening anything and without a new argument.
 *   2. DEDUPE IS NOW SCOPED TO ONE SOURCE. Two sources with identical text get two objects at two
 *      keys, so defect 2 above is gone by construction rather than by adding the refcount that a
 *      shared object would have required.
 *   3. THE BYTES GENUINELY ARE VERSION-INDEPENDENT. Reprocessing the same paste under a new parser
 *      configuration produces a NEW VERSION FROM THE SAME ORIGINAL, which is exactly what
 *      `force_nonce` exists to trigger. The spec is already treating the original as outliving any
 *      one version: `deletion/verification.py:325` — "a retained original is not a failure:
 *      `original/` is excluded from the asserted prefix". A path segment that names a version is
 *      claiming a lifetime the bytes do not have.
 *   4. It is the only shape available given the NOT NULL columns above.
 *
 * AN ADR IS OWED FOR THIS, and it is not written yet. `.claude/skills/seaweedfs-s3/SKILL.md` is its
 * owner's file and is deliberately NOT edited from here; the correction is recorded as an
 * obligation against that skill and against `docs/19-repo-structure-adrs.md`, not silently applied.
 * Until it lands, this docblock is the record, and the divergence is exactly one hoisted segment —
 * `derived/` stays inside the version prefix, unchanged, because derived artifacts really do belong
 * to one version and are rebuilt wholesale when a new one is published.
 *
 * ── WHAT THIS CLASS DOES NOT DO ─────────────────────────────────────────────────────────────
 *
 * It does not touch a disk. It builds strings, and it is deliberately free of a `Filesystem` so a
 * key can be asserted in a unit test with no container, no fake and no network — the property that
 * failed here is a property OF THE STRING.
 *
 * It does not name a bucket. There is one bucket, `kb`, forever (`seaweedfs-s3`, "The bucket
 * layout"), it is configured on the `s3` disk, and a key that carried it would be a second place to
 * change when the disk moves.
 */
final class ObjectKey
{
    /**
     * The tenant boundary itself, and the first segment of every key without exception.
     *
     * `source_items_storage_key_is_tenant_scoped` re-derives this same prefix in SQL, from the ROW'S
     * OWN `organization_id`, with a `||` concatenation — so a key this class built for the wrong
     * organization is refused by the INSERT rather than trusted. The two are deliberately
     * independent statements of one rule: this one is early and legible, that one cannot be
     * forgotten.
     *
     * @return non-empty-string always — every segment is guarded by `segment()` below, and PHPStan
     *                          is told so because a caller comparing prefixes needs it
     */
    public static function orgPrefix(string $organizationId): string
    {
        return 'org/'.self::segment($organizationId, 'organization id').'/';
    }

    /**
     * Everything that belongs to one source, and the narrowest prefix a source delete may sweep.
     *
     * This is the prefix the phase-2 purge is walking when it is given `(org_id, source_id)`, and
     * every key below is inside it. If a future caller needs a key that is NOT inside it, that is
     * the moment to stop and re-read the two defects in this class's docblock, because "outside the
     * prefix the purge sweeps" is precisely the shape that survives a verified deletion.
     *
     * @return non-empty-string always — every segment is guarded by `segment()` below, and PHPStan
     *                          is told so because a caller comparing prefixes needs it
     */
    public static function sourcePrefix(string $organizationId, string $sourceId): string
    {
        return self::orgPrefix($organizationId).'sources/'.self::segment($sourceId, 'source id').'/';
    }

    /**
     * The irreplaceable bytes for one source: uploads and pasted text.
     *
     * SOURCE-SCOPED AND NOT VERSION-SCOPED — the departure the class docblock argues. Its own
     * prefix, separate from `versions/`, because the phase-2 sweep deletes `derived/`
     * unconditionally and `original/` only per retention policy: a source can legitimately end as
     * *knowledge removed, original retained*, and that disposition is a different decision made at
     * a different time. Keeping the two apart is what lets the retained original be enumerated and
     * reported rather than filtered out of a listing.
     *
     * @return non-empty-string always — every segment is guarded by `segment()` below, and PHPStan
     *                          is told so because a caller comparing prefixes needs it
     */
    public static function originalPrefix(string $organizationId, string $sourceId): string
    {
        return self::sourcePrefix($organizationId, $sourceId).'original/';
    }

    /**
     * The stored body of a pasted-text source.
     *
     * CONTENT-ADDRESSED WITHIN ONE SOURCE, which is the whole of what `kb-tenancy-isolation:109`
     * permits: "paths start with `org/{org_id}/`; dedupe only within an organization". We are
     * narrower than that line allows, on purpose — one SOURCE, not one organization — because
     * dedupe across sources needs a reference count before a delete is safe, and the correct
     * refcount for a body referenced by an unbounded number of sources is a table nobody has asked
     * for. A duplicate object costs bytes; a shared object costs another source's content.
     *
     * The digest is `sha256` of the RAW body, the same value stored in `source_items.content_hash`,
     * so the object and the row that describes it agree by construction and `get_verified()` on the
     * data-plane side has something to compare against. The `.txt` suffix carries no meaning to any
     * reader — `source_items.mime` is the authority — and is kept because it makes an operator
     * staring at a bucket listing able to guess right.
     *
     * @return non-empty-string always — every segment is guarded by `segment()` below, and PHPStan
     *                          is told so because a caller comparing prefixes needs it
     */
    public static function originalText(string $organizationId, string $sourceId, string $contentHash): string
    {
        return self::originalPrefix($organizationId, $sourceId).self::segment($contentHash, 'content hash').'.txt';
    }

    /**
     * Everything DERIVED from one source version: the parse output, OCR text, extracted images, a
     * crawl snapshot. `version_prefix()` in `services/ai-service/app/storage/objects.py` is the
     * same string, and it is the twin `seaweedfs-s3`:180 asks for.
     *
     * NOTHING IN LARAVEL WRITES UNDER THIS PREFIX TODAY, and that is not an oversight worth
     * stubbing around: the data plane produces every derived artifact, and the version id this
     * needs does not exist until the data plane mints the `source_versions` row (see the class
     * docblock for why it structurally cannot exist earlier). It is expressible HONESTLY all the
     * same, and it is here rather than absent for one reason — Laravel READS this shape even when
     * it never writes it. The deletion report names retained artifacts, the admin console links to
     * a version's parse output, and the first of those callers would otherwise interpolate its own
     * string, which is exactly how this class came to be needed.
     *
     * NOTE WHAT IS NOT UNDER HERE: `original/`. In the skill's fixed layout it would be
     * `{versionPrefix}original/`; here it is one level up. Anything under this prefix is
     * REBUILDABLE from the original plus PostgreSQL, which is what makes the phase-2 sweep able to
     * drop it unconditionally.
     *
     * @return non-empty-string always — every segment is guarded by `segment()` below, and PHPStan
     *                          is told so because a caller comparing prefixes needs it
     */
    public static function versionPrefix(string $organizationId, string $sourceId, string $sourceVersionId): string
    {
        return self::sourcePrefix($organizationId, $sourceId)
            .'versions/'.self::segment($sourceVersionId, 'source version id').'/';
    }

    /**
     * One path segment, or a refusal.
     *
     * EVERY ID THAT REACHES THIS CLASS IS ALREADY A ULID WE MINTED OR A HEX DIGEST WE COMPUTED, so
     * this guard fires for nobody today and is not a validator standing in for one. It is here
     * because of what the failure would be if it were ever wrong: a segment containing `/` or `..`
     * does not produce a broken key, it produces a WELL-FORMED KEY IN THE WRONG PLACE — potentially
     * outside the org prefix, where the database CHECK is the only remaining line and where nothing
     * at all guards a read. Refusing structurally beats sanitizing, because a sanitized segment is
     * a silent rename and this class has just finished paying for one silent divergence.
     *
     * An `InvalidArgumentException` and not a validation refusal: no client input reaches here, so
     * a bad value is a programming error and rendering it as a 422 would tell an admin their
     * perfectly good request was malformed.
     *
     * @return non-empty-string the value unchanged, once it is known to be a single component
     */
    private static function segment(string $value, string $what): string
    {
        if ($value === '' || preg_match('/[\/\\\\]|[[:cntrl:]]/', $value) === 1 || $value === '.' || $value === '..') {
            throw new InvalidArgumentException(
                "An object key segment must be a single non-empty path component; the {$what} given "
                .'is not one, and a key built from it would name a location outside the prefix the '
                .'deletion sweep walks.'
            );
        }

        return $value;
    }
}
