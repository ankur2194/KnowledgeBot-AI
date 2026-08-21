<?php

declare(strict_types=1);

use App\Support\Kb\ObjectKey;

/*
|--------------------------------------------------------------------------
| The one place a Laravel-side object key is built
|--------------------------------------------------------------------------
|
| `seaweedfs-s3`:180 requires every key to come from `version_prefix()` "or its Laravel twin". The
| twin did not exist, `SourceService::storeText()` interpolated its own string, and the two defects
| that produced are what these assertions exist to make impossible to reintroduce:
|
|   1. The key lived OUTSIDE the prefix the phase-2 purge sweeps, so a deleted source's pasted body
|      survived and the verification job certified the prefix clean over it — non-negotiable 6
|      failing in the one direction that produces a signed proof of a deletion that did not happen.
|   2. It deduped across SOURCES within the organization with no reference count, so deleting either
|      of two byte-identical pastes took the other's body.
|
| A UNIT TEST BECAUSE THE BROKEN PROPERTY IS A PROPERTY OF THE STRING. No container, no database, no
| disk, no fake. The Feature suite proves the service actually calls this; this file proves what it
| gets when it does. Both are needed: a Feature test alone cannot state the relationship between
| two keys, and the cross-source dedupe defect IS a relationship between two keys.
*/

/**
 * Values shaped like the real ones — lowercase ULIDs and a hex digest — so nothing here passes only
 * because the inputs were tidy.
 */
const OBJ_ORG = '01k2wm5r4t0000000000000001';
const OBJ_SOURCE = '01k2wm5r4t0000000000000002';
const OBJ_VERSION = '01k2wm5r4t0000000000000003';
const OBJ_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

it('puts every key inside the organization prefix the database CHECK re-derives', function (): void {
    // `source_items_storage_key_is_tenant_scoped` is `storage_key LIKE 'org/' || organization_id ||
    // '/%'` — a `||` concatenation against the ROW'S OWN tenant column, so this is not a redundant
    // assertion of the same rule but the early, legible half of a rule the database also states.
    $keys = [
        ObjectKey::orgPrefix(OBJ_ORG),
        ObjectKey::sourcePrefix(OBJ_ORG, OBJ_SOURCE),
        ObjectKey::originalPrefix(OBJ_ORG, OBJ_SOURCE),
        ObjectKey::originalText(OBJ_ORG, OBJ_SOURCE, OBJ_HASH),
        ObjectKey::originalUpload(OBJ_ORG, OBJ_SOURCE, OBJ_HASH),
        ObjectKey::versionPrefix(OBJ_ORG, OBJ_SOURCE, OBJ_VERSION),
    ];

    foreach ($keys as $key) {
        expect($key)->toStartWith('org/'.OBJ_ORG.'/');
    }
});

it('scopes the pasted-text original to its own source, inside the prefix the purge sweeps', function (): void {
    $key = ObjectKey::originalText(OBJ_ORG, OBJ_SOURCE, OBJ_HASH);

    expect($key)->toBe('org/'.OBJ_ORG.'/sources/'.OBJ_SOURCE.'/original/'.OBJ_HASH.'.txt');

    // THE ASSERTION THAT WOULD HAVE CAUGHT THE DEFECT. `_purge_objects(org_id, source_id, ...)`
    // sweeps under the source, and `object_versions_remaining()` enumerates under the same string.
    // A key outside `sourcePrefix()` is unreachable by both, and unreachable-by-verification is
    // worse than unreachable-by-delete: it is certified gone while it is still there.
    expect($key)->toStartWith(ObjectKey::sourcePrefix(OBJ_ORG, OBJ_SOURCE));
});

it('scopes an uploaded original to its own source, under the same prefix as a paste and with no suffix', function (): void {
    $key = ObjectKey::originalUpload(OBJ_ORG, OBJ_SOURCE, OBJ_HASH);

    expect($key)->toBe('org/'.OBJ_ORG.'/sources/'.OBJ_SOURCE.'/original/'.OBJ_HASH);

    // NO EXTENSION, AND THAT IS THE ONE DIFFERENCE FROM `originalText()`. A paste's `.txt` restates
    // something we established by generating the bytes; an upload's type is a SNIFF, and appending
    // an extension here would put a value derived from attacker-influenced text into a PATH for no
    // benefit — `source_items.mime` is the authority for every reader.
    expect($key)->not->toContain('.');

    // THE SAME `original/` PREFIX AS A PASTE, which is what lets the phase-2 sweep and its
    // verification say the string once. A source is either pasted or uploaded, never both, so the
    // two can never collide inside one source.
    expect($key)->toStartWith(ObjectKey::originalPrefix(OBJ_ORG, OBJ_SOURCE));
    expect($key)->toStartWith(ObjectKey::sourcePrefix(OBJ_ORG, OBJ_SOURCE));
    expect($key)->not->toContain('/versions/');
});

it('gives two sources with byte-identical content two different keys', function (): void {
    $otherSource = '01k2wm5r4t0000000000000009';

    // ONE ORGANIZATION, TWO SOURCES, ONE DIGEST — the exact case the old key collapsed into a
    // single shared object with no reference count. `kb-tenancy-isolation`:109 permits dedupe
    // within an organization; the one place this repository actually does it (extracted images,
    // `deletion/tasks.py`:180) refcounts it explicitly. Nothing refcounts a pasted body, so the
    // dedupe boundary is the source and not the tenant.
    expect(ObjectKey::originalText(OBJ_ORG, OBJ_SOURCE, OBJ_HASH))
        ->not->toBe(ObjectKey::originalText(OBJ_ORG, $otherSource, OBJ_HASH));

    // AND THE SAME FOR AN UPLOAD, which is the case the intake gate's `duplicate` refusal is the
    // other half of: two IDENTICAL files inside ONE source would collide at one key with no
    // reference count, so the gate refuses the second rather than letting this method pretend the
    // collision is fine.
    expect(ObjectKey::originalUpload(OBJ_ORG, OBJ_SOURCE, OBJ_HASH))
        ->not->toBe(ObjectKey::originalUpload(OBJ_ORG, $otherSource, OBJ_HASH));
});

it('keeps the original outside any version prefix, and derived artifacts inside one', function (): void {
    // The recorded departure from `seaweedfs-s3`'s fixed layout, asserted so it is a decision with
    // a test behind it rather than a drift. Reprocessing the same paste under a new parser
    // configuration produces a NEW VERSION FROM THE SAME ORIGINAL, and
    // `deletion/verification.py`:325 already excludes `original/` from the asserted version prefix
    // when retention keeps it — so the bytes outlive any one version by design.
    $original = ObjectKey::originalText(OBJ_ORG, OBJ_SOURCE, OBJ_HASH);
    $version = ObjectKey::versionPrefix(OBJ_ORG, OBJ_SOURCE, OBJ_VERSION);

    expect($original)->not->toContain('/versions/');
    expect($version)->toBe('org/'.OBJ_ORG.'/sources/'.OBJ_SOURCE.'/versions/'.OBJ_VERSION.'/');

    // Both are still inside the one prefix a source delete walks, which is what makes the
    // divergence safe rather than merely convenient.
    expect($version)->toStartWith(ObjectKey::sourcePrefix(OBJ_ORG, OBJ_SOURCE));
});

it('refuses a segment that would name a location outside the prefix it was given', function (string $segment): void {
    // Every id reaching this class is a ULID we minted or a digest we computed, so this fires for
    // nobody today. It is asserted because of what the failure WOULD be: a segment carrying a
    // separator does not produce a broken key, it produces a well-formed key somewhere else — and
    // "somewhere else" is the property that just cost a deletion guarantee.
    expect(fn (): string => ObjectKey::sourcePrefix(OBJ_ORG, $segment))
        ->toThrow(\InvalidArgumentException::class);

    expect(fn (): string => ObjectKey::originalText(OBJ_ORG, OBJ_SOURCE, $segment))
        ->toThrow(\InvalidArgumentException::class);

    expect(fn (): string => ObjectKey::originalUpload(OBJ_ORG, OBJ_SOURCE, $segment))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'empty' => '',
    'traversal' => '..',
    'current directory' => '.',
    'embedded separator' => 'a/../../b',
    'backslash' => 'a\\b',
    'control character' => "a\0b",
]);
