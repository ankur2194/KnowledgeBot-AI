import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';
// Imported as a VALUE, not just a type: the `sometimes` suite at the bottom builds its own mirror
// schemas, because neither committed manifest carries that rule yet.
import { z } from 'zod';

import { botSettingsSchema } from '../src/forms/bot.js';
import { embeddingDesignationSchema } from '../src/forms/embedding-designation.js';
import { OWNERSHIP_KEYS, isOwnershipPath } from '../src/forms/ownership.js';

/**
 * The behavioural drift suite (rhf-zod-forms). For every field in the manifest dumped from
 * Laravel's own `rules()` by `php artisan kb:dump-form-rules`, generate probe values and assert
 * BOTH sides answer the same yes/no question — separately, because the two failures are different
 * bugs. A form looser than the server produces a visible 422; a form STRICTER than the server
 * silently removes functionality nobody reports.
 *
 * Matching `z.string().max(200)` against the STRING "max:200" would need a rule interpreter that
 * drifts on its own, so nothing here parses a schema. Probes are values; both sides parse them.
 */

const rulesDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'rules');

interface Manifest {
  readonly class: string;
  readonly rules: Readonly<Record<string, readonly string[]>>;
}

const manifests: ReadonlyArray<readonly [string, Manifest]> = readdirSync(rulesDir)
  .filter((file) => file.endsWith('.json'))
  .sort()
  .map((file) => [file, JSON.parse(readFileSync(join(rulesDir, file), 'utf8')) as Manifest]);

type Candidate = Record<string, unknown>;

interface Mirror {
  readonly schema: z.ZodType;
  /** A value the SERVER accepts, from which every probe is one single-field mutation. */
  readonly baseline: () => Candidate;
}

/** A real ULID: 26 Crockford base32 characters, first character <= '7'. */
const ULID = '01JQ8Z3M4N5P6Q7R8S9TVWXYZA';

/**
 * Manifest class → the schema in src/forms/ that mirrors it. Every dumped manifest must appear
 * here or in NO_CLIENT_FORM below, so the FormRequest nobody mirrored fails this suite by name
 * instead of being a form that 422s in production on a rule it was never told about.
 */
const MIRRORS: Readonly<Record<string, Mirror>> = {
  'App\\Http\\Requests\\DesignateEmbeddingConnectionRequest': {
    schema: embeddingDesignationSchema,
    baseline: () => ({ connection_id: ULID, model: 'text-embedding-3-large' }),
  },
};

/**
 * Manifests with no client schema, and why. This is an ALLOW-LIST, not a skip: adding an entry is
 * a decision with a reason, and CI diffs the file that produced it.
 */
const NO_CLIENT_FORM: Readonly<Record<string, string>> = {
  // POST /api/v1/organizations/{organization}/provider-connections carries `credential` — the
  // PLAINTEXT provider key. A shared, importable Zod schema naming that field is one
  // `providerConnectionDefaults(resource)` away from seeding `masked_key` into form state and
  // posting "…4a91" back as the new key (rhf-zod-forms NN6 and its gotcha). The admin form that
  // eventually collects it keeps its credential field local to apps/web, write-only, absent from
  // defaultValues, and optional-means-unchanged — pending an explicit decision recorded with the
  // form, not a schema exported to apps/mobile and apps/widget by default.
  'App\\Http\\Requests\\StoreProviderConnectionRequest':
    'credential field — see the comment above; no shared schema until the form lands in apps/web',
};

// ── rule classification ──────────────────────────────────────────────────────────────────────────

const nameOf = (rule: string): string => rule.split(':')[0] ?? rule;
const argOf = (rule: string): string => rule.slice(rule.indexOf(':') + 1);

/** No client can evaluate these: they need a database or a request context. Present, value-exempt. */
const SERVER_ONLY = new Set(['exists', 'unique', '@server-only']);

/**
 * `sometimes` short-circuits EVERY other rule for the field when the KEY IS ABSENT, `required` and
 * `present` included. It is how a PATCH FormRequest says "validate only what was sent", so the
 * first one that lands will carry `sometimes|required|...` on every field — and without this the
 * harness answers the presence question backwards on both sides:
 *
 *   - FALSE RED on the correct schema. `sometimes|required` reads as required, the harness expects
 *     the server to reject an omission, an `.optional()` field accepts it, and the suite reports
 *     "form accepts input the server rejects". The obvious fix is to drop `.optional()`, which is
 *     the bug.
 *   - FALSE GREEN on the wrong one. With the field made mandatory, both sides now "agree" that the
 *     omission is rejected, the suite goes green, and the form has quietly lost the ability to
 *     PATCH one field without re-sending the rest. That is precisely the asymmetric failure this
 *     file exists for: a form STRICTER than the server removes functionality nobody reports.
 *
 * The presence pair below stays a PAIR for the same reason. `sometimes` says nothing about an
 * EXPLICIT null — the key is present, so every other rule applies and the verdict is still
 * `nullable` alone. A schema widened to `.nullish()` to make the omitted probe pass would then
 * accept a null the server rejects, and only the second probe can see it.
 */
const SOMETIMES = 'sometimes';

/**
 * Rules whose verdict depends on ANOTHER field. The baseline supplies the sibling, so value probes
 * stay meaningful, but presence probes (omit / null) would be asking the wrong question — those
 * cases are asserted by hand below, where the intended semantics can be written down.
 */
const CROSS_FIELD = new Set([
  'required_with',
  'required_with_all',
  'required_without',
  'required_without_all',
  'required_if',
  'required_unless',
  'prohibited_if',
  'prohibited_unless',
  'missing_with',
  'same',
  'different',
  'confirmed',
  'gt',
  'gte',
  'lt',
  'lte',
  'after',
  'after_or_equal',
  'before',
  'before_or_equal',
]);

/**
 * Value-exempt by construction: Laravel's `email` is egulias/RFC validation and `z.email()` is a
 * regex; they will never agree on the edge corpus, and pretending otherwise produces a red suite
 * nobody can fix.
 */
const VALUE_EXEMPT = new Set(['email', 'url', 'active_url', 'timezone', 'image', 'dimensions']);

// ── probes ───────────────────────────────────────────────────────────────────────────────────────

interface Probe {
  readonly label: string;
  readonly apply: (base: Candidate) => Candidate;
  readonly serverAccepts: boolean;
}

const OMITTED = Symbol('omitted');

const mutate =
  (path: string, value: unknown) =>
  (base: Candidate): Candidate => {
    const next = structuredClone(base);
    const segments = path.split('.');
    const leaf = segments.pop() as string;

    let cursor: Record<string, unknown> = next;
    for (const segment of segments) {
      cursor = cursor[segment] as Record<string, unknown>;
    }

    if (value === OMITTED) delete cursor[leaf];
    else cursor[leaf] = value;

    return next;
  };

const probe = (path: string, label: string, value: unknown, serverAccepts: boolean): Probe => ({
  label,
  apply: mutate(path, value),
  serverAccepts,
});

/** `max:`/`min:` mean length, count or magnitude depending on the field's declared type. */
type Kind = 'string' | 'array' | 'number' | 'unknown';

const kindOf = (rules: readonly string[]): Kind => {
  const names = new Set(rules.map(nameOf));
  if (names.has('array')) return 'array';
  if (names.has('integer') || names.has('numeric')) return 'number';
  if (names.has('string')) return 'string';
  return 'unknown';
};

const sized = (kind: Kind, size: number): unknown => {
  if (kind === 'string') return 'a'.repeat(size);
  if (kind === 'array') return Array.from({ length: size }, () => 'a');
  return size;
};

function probesFor(path: string, rules: readonly string[]): Probe[] {
  const names = rules.map(nameOf);

  if (names.some((name) => SERVER_ONLY.has(name))) return [];

  const kind = kindOf(rules);
  const crossField = names.some((name) => CROSS_FIELD.has(name));
  const probes: Probe[] = [];

  // Presence. Suppressed when a cross-field rule makes "is this field required?" depend on a
  // sibling — the harness cannot answer that from one field's rule list.
  if (!crossField) {
    if (names.includes(SOMETIMES)) {
      // Omission is accepted UNCONDITIONALLY: `sometimes` skips every remaining rule, so a
      // co-declared `required` never runs. See the note on SOMETIMES above.
      probes.push(probe(path, 'omitted (sometimes)', OMITTED, true));
    } else if (names.includes('required') || names.includes('present')) {
      probes.push(probe(path, 'omitted', OMITTED, false));
    } else {
      probes.push(probe(path, 'omitted', OMITTED, true));
    }

    // An explicit null is PRESENT, so `sometimes` does not fire and the verdict is unchanged:
    // accepted only if the server said `nullable`.
    probes.push(probe(path, 'null', null, names.includes('nullable')));
  }

  for (const rule of rules) {
    const name = nameOf(rule);
    if (VALUE_EXEMPT.has(name) || SERVER_ONLY.has(name) || CROSS_FIELD.has(name)) continue;

    switch (name) {
      case 'string':
        probes.push(probe(path, 'a number where a string is required', 1234, false));
        break;

      case 'integer':
        probes.push(probe(path, 'a fractional number where an integer is required', 1.5, false));
        break;

      case 'array':
        probes.push(probe(path, 'a string where an array is required', 'not-an-array', false));
        break;

      case 'boolean':
        probes.push(probe(path, 'a string where a boolean is required', 'yes-ish', false));
        break;

      case 'max': {
        const max = Number(argOf(rule));
        probes.push(probe(path, `max:${max} boundary`, sized(kind, max), true));
        probes.push(probe(path, `max:${max} + 1`, sized(kind, max + 1), false));
        break;
      }

      case 'min': {
        const min = Number(argOf(rule));
        probes.push(probe(path, `min:${min} boundary`, sized(kind, min), true));
        if (min > 0) probes.push(probe(path, `min:${min} - 1`, sized(kind, min - 1), false));
        break;
      }

      case 'in': {
        // Rule::in stringifies as in:"a","b" — quoted, and doubled quotes inside a member.
        for (const member of argOf(rule).split(','))
          probes.push(probe(path, `in: ${member}`, member.replace(/^"|"$/g, ''), true));
        probes.push(probe(path, 'in: a non-member', '__not_a_member__', false));
        break;
      }

      case 'ulid':
        probes.push(probe(path, 'a canonical ULID', ULID, true));
        probes.push(probe(path, 'not a ULID at all', 'definitely-not-a-ulid', false));
        // 26 valid Crockford characters, but the timestamp overflows: Symfony's Ulid::isValid
        // requires the first character to be <= '7', and zod's z.ulid() does not check it.
        probes.push(probe(path, 'a ULID whose timestamp overflows', 'Z'.repeat(26), false));
        break;

      default:
        break;
    }
  }

  return probes;
}

/**
 * Returns one message per disagreement. A function rather than inline expectations so the meta-test
 * below can prove the harness has teeth by feeding it a manifest that is deliberately one character
 * off — a suite that cannot fail is the failure this file exists to prevent.
 */
function driftFailures(manifest: Manifest, mirror: Mirror): string[] {
  const failures: string[] = [];

  for (const [path, rules] of Object.entries(manifest.rules)) {
    for (const { label, apply, serverAccepts } of probesFor(path, rules)) {
      const clientAccepts = mirror.schema.safeParse(apply(mirror.baseline())).success;

      if (serverAccepts && !clientAccepts) {
        failures.push(`form blocks input the server accepts: ${path} — ${label}`);
      } else if (!serverAccepts && clientAccepts) {
        failures.push(`form accepts input the server rejects: ${path} — ${label}`);
      }
    }
  }

  return failures;
}

/** Every leaf path a schema declares, dotted, so it can be set-compared with the manifest's keys. */
function schemaPaths(schema: z.ZodType, prefix = ''): string[] {
  const shape = (schema as unknown as { shape?: Record<string, z.ZodType> }).shape;
  if (!shape) return prefix === '' ? [] : [prefix];

  return Object.entries(shape).flatMap(([key, child]) =>
    schemaPaths(child, prefix === '' ? key : `${prefix}.${key}`),
  );
}

// ── the suite ────────────────────────────────────────────────────────────────────────────────────

describe('form rules dumped from services/core-api', () => {
  // NOT `describe.skipIf(manifests.length === 0)`. rules/ is populated now, and a suite that
  // silently disappears when the directory empties out is a green build that asserted nothing.
  it('rules/ is non-empty — the dump ran', () => {
    expect(manifests.length).toBeGreaterThan(0);
  });

  it.each(manifests)(
    '%s is either mirrored by a schema or explicitly exempt',
    (_file, manifest) => {
      const mirrored = manifest.class in MIRRORS;
      const exempt = manifest.class in NO_CLIENT_FORM;

      expect(
        mirrored !== exempt,
        `${manifest.class} must appear in exactly one of MIRRORS or NO_CLIENT_FORM`,
      ).toBe(true);
    },
  );

  it('the manifest set matches what this package knows about', () => {
    expect(manifests.map(([, manifest]) => manifest.class).sort()).toEqual(
      [...Object.keys(MIRRORS), ...Object.keys(NO_CLIENT_FORM)].sort(),
    );
  });
});

describe.each(manifests.filter(([, manifest]) => manifest.class in MIRRORS))(
  'behavioural drift: %s',
  (_file, manifest) => {
    const mirror = MIRRORS[manifest.class] as Mirror;

    it('the baseline is a value both sides accept', () => {
      expect(mirror.schema.safeParse(mirror.baseline()).success).toBe(true);
    });

    it('the schema declares exactly the fields the FormRequest validates', () => {
      expect(new Set(schemaPaths(mirror.schema))).toEqual(new Set(Object.keys(manifest.rules)));
    });

    it('client and server answer every probe the same way', () => {
      expect(driftFailures(manifest, mirror)).toEqual([]);
    });

    it('the probe harness fails on a deliberate one-character rule change', () => {
      const rules = Object.fromEntries(
        Object.entries(manifest.rules).map(([path, list]) => [
          path,
          list.map((rule) => (rule.startsWith('max:') ? `max:${Number(argOf(rule)) - 1}` : rule)),
        ]),
      );

      const tampered = driftFailures({ class: manifest.class, rules }, mirror);
      expect(
        tampered.length,
        'a manifest one character off must produce a failure',
      ).toBeGreaterThan(0);
    });
  },
);

/**
 * The `sometimes` branch of the classifier, proved against a manifest fixture.
 *
 * NEITHER COMMITTED MANIFEST CARRIES `sometimes`, so this gap is latent and the first PATCH
 * FormRequest that lands trips it — which is exactly the shape of hole that gets closed with a
 * comment and no test. The fixture below is a manifest, not a file in `rules/`: writing it to disk
 * would make the "every manifest is mirrored or exempt" suite above assert against a FormRequest
 * that does not exist.
 *
 * All three specs run through `driftFailures`, the same function the real manifests use, so what is
 * being proved is the harness's behaviour and not a re-implementation of it.
 */
describe('the `sometimes` branch of the rule classifier', () => {
  const PATCH_MANIFEST: Manifest = {
    class: 'App\\Http\\Requests\\Fixture\\UpdateBotRequest',
    rules: {
      // The canonical PATCH shape. `sometimes|required` means "if you sent it, it must not be
      // empty" — NOT "you must send it".
      name: ['sometimes', 'required', 'string', 'max:120'],
      // `sometimes` and `nullable` together: absent means "leave it alone", null means "clear it".
      // Two different intentions that the two presence probes have to keep apart.
      welcome_message: ['sometimes', 'nullable', 'string', 'max:500'],
    },
  };

  const baseline = (): Candidate => ({ name: 'Support bot', welcome_message: 'Hi' });

  /** What a faithful mirror of that FormRequest looks like. */
  const faithful: Mirror = {
    schema: z.strictObject({
      name: z.string().trim().min(1).max(120).optional(),
      welcome_message: z.string().trim().max(500).nullable().optional(),
    }),
    baseline,
  };

  it('accepts the faithful mirror — an omitted `sometimes|required` field is not a divergence', () => {
    // Before the branch this produced "form accepts input the server rejects: name — omitted",
    // a FALSE RED whose obvious fix is to drop `.optional()` and ship the bug below.
    expect(driftFailures(PATCH_MANIFEST, faithful)).toEqual([]);
  });

  it('catches the mirror that made a `sometimes` field mandatory', () => {
    const mandatory: Mirror = {
      schema: z.strictObject({
        // The "fix" the false red invites. The form now demands `name` on every PATCH, so editing
        // only the welcome message is impossible — functionality removed, nothing reported.
        name: z.string().trim().min(1).max(120),
        welcome_message: z.string().trim().max(500).nullable().optional(),
      }),
      baseline,
    };

    // Before the branch this was a FALSE GREEN: the harness also thought the server rejected the
    // omission, so the two agreed and the suite said nothing.
    expect(driftFailures(PATCH_MANIFEST, mandatory)).toEqual([
      'form blocks input the server accepts: name — omitted (sometimes)',
    ]);
  });

  it('still catches a mirror widened to nullish, which the omitted probe alone cannot see', () => {
    const nullish: Mirror = {
      schema: z.strictObject({
        // `.nullish()` also satisfies the omitted probe, so a branch that only generated THAT probe
        // would call this correct. The server said `sometimes`, not `nullable`: sending an explicit
        // null clears a field that has no null state.
        name: z.string().trim().min(1).max(120).nullish(),
        welcome_message: z.string().trim().max(500).nullable().optional(),
      }),
      baseline,
    };

    expect(driftFailures(PATCH_MANIFEST, nullish)).toEqual([
      'form accepts input the server rejects: name — null',
    ]);
  });

  it('answers omission and explicit null separately and in opposite directions', () => {
    const bothWays: Mirror = {
      schema: z.strictObject({
        // Rejects `undefined`, accepts `null` — wrong on both presence probes at once, and wrong in
        // opposite directions. One assertion that the pair is a pair.
        name: z.string().trim().min(1).max(120).nullable(),
        welcome_message: z.string().trim().max(500).nullable().optional(),
      }),
      baseline,
    };

    expect(driftFailures(PATCH_MANIFEST, bothWays).sort()).toEqual([
      'form accepts input the server rejects: name — null',
      'form blocks input the server accepts: name — omitted (sometimes)',
    ]);
  });

  it('leaves the value probes alone — `sometimes` is a presence rule, not a value-exemption', () => {
    const looseMax: Mirror = {
      schema: z.strictObject({
        name: z.string().trim().min(1).max(999).optional(),
        welcome_message: z.string().trim().max(500).nullable().optional(),
      }),
      baseline,
    };

    // A field carrying `sometimes` must still have every one of its other rules probed. Skipping
    // the field outright would have been the cheap way to make the false red go away.
    expect(driftFailures(PATCH_MANIFEST, looseMax)).toEqual([
      'form accepts input the server rejects: name — max:120 + 1',
    ]);
  });
});

/**
 * The cases `probesFor` declares itself unable to generate: `present` and `required_with` are about
 * the SHAPE of the payload and the relationship between two fields, not about one field's value.
 * Written by hand so the intended semantics are legible rather than inferred.
 */
describe('embedding designation: the rules a single-field probe cannot express', () => {
  const parse = (value: unknown) => embeddingDesignationSchema.safeParse(value).success;

  it('requires both keys to be present, because the server rule is present|nullable', () => {
    expect(parse({ connection_id: ULID })).toBe(false);
    expect(parse({ model: 'text-embedding-3-large' })).toBe(false);
    expect(parse({})).toBe(false);
  });

  it('accepts the cleared designation — both null is how an org unsets it', () => {
    expect(parse({ connection_id: null, model: null })).toBe(true);
  });

  it('rejects half a designation in both directions (required_with, both ways)', () => {
    expect(parse({ connection_id: ULID, model: null })).toBe(false);
    expect(parse({ connection_id: null, model: 'text-embedding-3-large' })).toBe(false);
  });

  it('treats a cleared select ("") as null, exactly as ConvertEmptyStringsToNull does', () => {
    const cleared = embeddingDesignationSchema.safeParse({ connection_id: '', model: '' });
    expect(cleared.success).toBe(true);
    expect(cleared.success && cleared.data).toEqual({ connection_id: null, model: null });

    // …and therefore a half-cleared pair is rejected rather than posted as an empty string.
    expect(parse({ connection_id: ULID, model: '' })).toBe(false);
  });

  it('carries no credential field — the designation is a connection reference', () => {
    const paths = schemaPaths(embeddingDesignationSchema);
    expect(paths).toEqual(['connection_id', 'model']);
    expect(paths.some((path) => /credential|api_key|secret|token|password/i.test(path))).toBe(
      false,
    );
  });
});

describe('ownership columns are unrepresentable', () => {
  it('no schema path intersects OWNERSHIP_KEYS', () => {
    for (const schema of [botSettingsSchema, embeddingDesignationSchema]) {
      expect(schemaPaths(schema).filter(isOwnershipPath)).toEqual([]);
    }
  });

  it('a strictObject rejects an ownership key instead of silently stripping it', () => {
    const result = botSettingsSchema.safeParse({
      name: 'Support bot',
      status: 'draft',
      welcome_message: 'Hi',
      starter_questions: [],
      retrieval: { top_k: 5 },
      organization_id: '01JSOMEONEELSE',
    });
    // z.object() would strip it and hide the escalation attempt until something bypasses the parse.
    expect(result.success).toBe(false);

    expect(
      embeddingDesignationSchema.safeParse({
        connection_id: null,
        model: null,
        organization_id: '01JSOMEONEELSE',
      }).success,
    ).toBe(false);
  });

  it('lists the five columns the server guards', () => {
    expect([...OWNERSHIP_KEYS]).toEqual([
      'organization_id',
      'org_id',
      'user_id',
      'created_by',
      'id',
    ]);
  });
});
