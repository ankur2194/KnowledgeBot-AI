import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';
// Imported as a VALUE, not just a type: the `sometimes` suite at the bottom builds its own mirror
// schemas, because neither committed manifest carries that rule yet.
import { z } from 'zod';

import {
  forgotPasswordSchema,
  inviteMemberSchema,
  loginSchema,
  registerSchema,
  resetPasswordSchema,
} from '../src/forms/auth.js';
import { botSettingsSchema } from '../src/forms/bot.js';
import { embeddingDesignationSchema } from '../src/forms/embedding-designation.js';
import { OWNERSHIP_KEYS, isOwnershipPath } from '../src/forms/ownership.js';
import { uploadSchema } from '../src/forms/upload.js';

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

/**
 * Builds a value of exactly `size` for one field — the thing a `min:`/`max:` probe needs. Returns
 * `undefined` for "no value of that size can be probed HONESTLY", which suppresses the probe and is
 * recorded by the `size rules with no honest probe` suite below rather than silently dropped.
 *
 * `expectation` is the verdict the probe is about to claim. It matters because the two directions have
 * different burdens: a probe claiming the server ACCEPTS the value must be a value the server really
 * accepts under EVERY rule on the field, while a probe claiming rejection only needs the server to say
 * no — and it does not matter which rule said it.
 */
type Sizer = (size: number, expectation: 'accepted' | 'rejected') => unknown;

interface Mirror {
  readonly schema: z.ZodType;
  /** A value the SERVER accepts, from which every probe is one single-field mutation. */
  readonly baseline: () => Candidate;
  /**
   * Per-path size generators for fields whose FORMAT a generic `'a'.repeat(n)` cannot satisfy — see
   * `sizerFor`. Declaring one is a claim about the server, so it is made HERE, next to the schema
   * whose author verified it, rather than inferred from a rule string in the harness.
   */
  readonly sized?: Readonly<Record<string, Sizer>>;
}

/** A real ULID: 26 Crockford base32 characters, first character <= '7'. */
const ULID = '01JQ8Z3M4N5P6Q7R8S9TVWXYZA';

/**
 * A password the SERVER accepts under the full policy — >=12 characters with a lower-case letter, an
 * upper-case letter and a digit (`min:12|max:255|regex:/\p{Ll}/u|regex:/\p{Lu}/u|regex:/\d/u`). Every
 * probe on a password field is one mutation away from this, so it has to satisfy all five rules or the
 * baseline assertion fails before any probe runs.
 */
const STRONG_PASSWORD = 'Correct-Horse-9';

/** `ResetPasswordRequest.token` is `max:255`; Laravel's own reset tokens are 64 hex characters. */
const RESET_TOKEN = 'a'.repeat(64);

/** `RegisterRequest.token` is `size:64` — a 32-byte invitation capability, hex-encoded. */
const INVITATION_TOKEN = 'b'.repeat(64);

/**
 * A `Sizer` for the two NEW-password fields, and the reason the `sized` hook exists at all.
 *
 * `password` carries `min:12|max:255` AND three `regex:` rules, so the generic `'a'.repeat(n)` is
 * rejected by the SERVER as well as the client — it satisfies no character class. The harness would
 * then report "form blocks input the server accepts" against a schema that is exactly right, and the
 * obvious repair is to delete the regexes from the schema, which ships a password policy the server
 * enforces and the user is never shown. (That is not hypothetical: it is what these four manifests did
 * on the run before this hook landed.)
 *
 * `Aa1` + n-3 filler is length-exact and satisfies all three classes, so `min:12` and `max:255` are
 * probed for real — including the numbers, which is the point. Asserting the policy against a
 * hard-coded 12 (test/auth-schemas.test.ts does) cannot notice the server moving to `min:14`; this can.
 */
const passwordOfLength: Sizer = (size) =>
  size >= 4 ? `Aa1${'b'.repeat(size - 3)}` : undefined;

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

  'App\\Http\\Requests\\LoginRequest': {
    schema: loginSchema,
    baseline: () => ({ email: 'user@example.com', password: 'hunter2' }),
  },

  'App\\Http\\Requests\\ForgotPasswordRequest': {
    schema: forgotPasswordSchema,
    baseline: () => ({ email: 'user@example.com' }),
  },

  'App\\Http\\Requests\\ResetPasswordRequest': {
    schema: resetPasswordSchema,
    baseline: () => ({
      token: RESET_TOKEN,
      email: 'user@example.com',
      password: STRONG_PASSWORD,
      password_confirmation: STRONG_PASSWORD,
    }),
    sized: { password: passwordOfLength },
  },

  'App\\Http\\Requests\\RegisterRequest': {
    schema: registerSchema,
    baseline: () => ({
      token: INVITATION_TOKEN,
      name: 'Ada Lovelace',
      password: STRONG_PASSWORD,
      password_confirmation: STRONG_PASSWORD,
    }),
    sized: { password: passwordOfLength },
  },

  /**
   * MOVED HERE FROM NO_CLIENT_FORM, which recorded it as OWED rather than exempt and spelled out the
   * three-step diff that closed it: `inviteMemberSchema` in src/forms/auth.ts, an `ORG_ROLES` runtime
   * tuple behind the ./forms subpath, and this entry.
   *
   * NO `sized` OVERRIDE, and that is the interesting half. `email`'s `max:254` is probed through the
   * harness's email synthesizer (`sizerFor`), which is measured valid under
   * `egulias/email-validator` at every length 6…254 and invalid at 255 — so this manifest's length
   * rule is read off the SERVER's number rather than trusted. `in:` is probed member by member
   * against `Rule::in(OrgRole::values())`, which is what makes the tuple in src/ honest: a fifth role
   * added server-side now fails HERE instead of being invisible until a select omits it.
   */
  'App\\Http\\Requests\\StoreInvitationRequest': {
    schema: inviteMemberSchema,
    baseline: () => ({ email: 'invitee@example.com', role: 'analyst' }),
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

  // THE ONE ENDPOINT WHOSE SUBJECT *IS* THE OWNERSHIP RELATION, and therefore the one that cannot
  // have a form schema at all. Its only field is `organization_id`, which is the first entry in
  // OWNERSHIP_KEYS (src/forms/ownership.ts) — so a mirroring schema would be a schema whose entire
  // path set is banned by "ownership columns are unrepresentable" (rhf-zod-forms NN1), and the
  // `no schema path intersects OWNERSHIP_KEYS` assertion at the bottom of this file would fail on it
  // by construction. The exemption is not a gap in the rule; it is the rule.
  //
  // What replaces client validation here is server-side membership: the switch re-checks that the
  // caller belongs to the organization named, so a forged id is an authorization failure rather than
  // a validation one. The UI posts an id the user PICKED FROM their own membership list
  // (apps/web/src/features/auth/use-switch-organization.ts) — a select, not a typed field, so there
  // is no per-field error to render and nothing for a resolver to do.
  'App\\Http\\Requests\\SwitchOrganizationRequest':
    'its only field is `organization_id`, banned from every form schema by OWNERSHIP_KEYS; membership is re-checked server-side and the client posts an id picked from the session, never typed',

  // The three single-`token` requests. Each is a capability off a mail link: the user types nothing,
  // there is no field to validate, no error to key to a control, and no resolver to attach. The
  // screens POST the token straight from the query string and render the server's refusal in a
  // banner (apps/web/src/features/auth/{invitation,verify-email-notice}.*), which is why the ONLY
  // client-side check that would be possible here — the `size:64` shape — is deliberately absent:
  // rejecting a wrong-length token locally tells its holder their token is the wrong SHAPE, where
  // the server answers all five invalid states with one byte-identical refusal on purpose.
  'App\\Http\\Requests\\PreviewInvitationRequest':
    'single `token` off a mail link, no user-editable input, so no form and no resolver; the shape is deliberately NOT checked client-side (see the invitation-copy note in apps/web)',
  'App\\Http\\Requests\\VerifyEmailRequest':
    'single `token` off a mail link, no user-editable input, so no form and no resolver',
  'App\\Http\\Requests\\AcceptInvitationRequest':
    'single `token` off a mail link, no user-editable input, so no form and no resolver',

  // `StoreInvitationRequest` USED TO SIT HERE, recorded as OWED rather than exempt, with the exact
  // three-step diff that would close it. It is now a MIRRORS entry above, and the note is kept for
  // one reason: it is the worked example of what this list is FOR. An entry here means "no client
  // schema, and here is why that is correct"; an entry that instead says "…and here is what would
  // fix it" is a to-do the suite is holding, and holding it in the assertion is what got it closed
  // rather than forgotten. rhf-zod-forms NN3 — a schema ships WITH a committed manifest and a drift
  // test or it does not ship — is why the schema could not simply be written earlier.
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
 *
 * THE EXEMPTION IS THE FORMAT VERDICT ONLY. A field in this set still has its `min:`/`max:` probed
 * whenever a value of that size can be synthesized to satisfy the format too — see `sizerFor` and
 * `emailOfLength`. Exempting the length along with the format is what leaves `max:254` unmirrorable
 * and `ForgotPasswordRequest` with no probe at all.
 */
const VALUE_EXEMPT = new Set(['email', 'url', 'active_url', 'timezone', 'image', 'dimensions']);

// ── probes ───────────────────────────────────────────────────────────────────────────────────────

interface Probe {
  readonly label: string;
  readonly apply: (base: Candidate) => Candidate;
  readonly serverAccepts: boolean;
}

const OMITTED = Symbol('omitted');

const setPath = (target: Candidate, path: string, value: unknown): void => {
  const segments = path.split('.');
  const leaf = segments.pop() as string;

  let cursor: Record<string, unknown> = target;
  for (const segment of segments) {
    cursor = cursor[segment] as Record<string, unknown>;
  }

  if (value === OMITTED) delete cursor[leaf];
  else cursor[leaf] = value;
};

/**
 * ONE probe may write MORE THAN ONE path, and the only reason is `confirmed`.
 *
 * Laravel's `confirmed` compares `password` with `password_confirmation`, so a probe that changes only
 * `password` leaves a baseline whose two halves disagree — and the client then rejects EVERY value
 * probe on that field for the mismatch rather than for the value under test. Both directions of the
 * drift assertion are lost: a schema whose `max` is wrong is "caught" by an unrelated mismatch, and a
 * schema whose `max` is MISSING agrees with a server rejection it never actually reproduced. The
 * second one is a false green, and it is invisible.
 *
 * Writing the confirmation alongside is what Laravel's own rule means, so the probe asks the question
 * it claims to ask. Presence probes are unaffected: `confirmed` is CROSS_FIELD, so they are suppressed.
 */
const mutate =
  (paths: readonly string[], value: unknown) =>
  (base: Candidate): Candidate => {
    const next = structuredClone(base);
    for (const path of paths) setPath(next, path, value);

    return next;
  };

const probe = (
  paths: readonly string[],
  label: string,
  value: unknown,
  serverAccepts: boolean,
): Probe => ({
  label,
  apply: mutate(paths, value),
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

/**
 * Rules that constrain a value's FORM rather than its size. A `'a'.repeat(n)` satisfies none of them,
 * so on a field carrying one of these AND a `min:`/`max:`, the generic sizer produces a value the
 * SERVER rejects while the probe claims the server accepts it — see `sizerFor`.
 */
const FORMAT_RULES = new Set([...VALUE_EXEMPT, 'regex']);

/**
 * Egulias measures a non-leading domain label WITH the dot that precedes it, so a 63-character label
 * anywhere but first is reported `LabelTooLong` even though RFC 1035 permits it. Measured against the
 * installed `egulias/email-validator` under `RFCValidation` + `NoRFCWarningsValidation` — the exact
 * pair Laravel's `email:rfc,strict` builds (vendor/.../ValidatesAttributes.php:991-999). 62 is what
 * actually validates; it is an upstream quirk, not a rule of ours, and it is written down because the
 * obvious 63 silently makes every synthesized address invalid.
 */
const DOMAIN_LABEL_MAX = 62;

/** The shortest domain this synthesizes, `a.co`. */
const DOMAIN_MIN = 4;

/** The shortest address this synthesizes, `a@a.co`. */
const EMAIL_MIN = 1 + '@'.length + DOMAIN_MIN;

/** egulias raises `EmailTooLong` above this, and `strict` turns that warning into a rejection. */
const EMAIL_STRICT_MAX = 254;

/** RFC 5321: 64 octets, and egulias warns (therefore, under `strict`, rejects) beyond it. */
const LOCAL_PART_MAX = 64;

const domainOfLength = (size: number): string => {
  const labels: string[] = [];
  let left = size - '.co'.length;

  while (left > DOMAIN_LABEL_MAX) {
    labels.push('a'.repeat(DOMAIN_LABEL_MAX));
    left -= DOMAIN_LABEL_MAX + 1;
  }
  // An exact multiple leaves nothing for the final label, which would emit a trailing dot and an
  // empty label. Borrow one character from the previous label instead.
  if (left === 0) {
    labels[labels.length - 1] = 'a'.repeat(DOMAIN_LABEL_MAX - 1);
    left = 1;
  }
  labels.push('a'.repeat(left));

  return `${labels.join('.')}.co`;
};

/**
 * `email` IS VALUE-EXEMPT AND ITS `max:` IS STILL PROBED, which looks like a contradiction and is not.
 * The exemption is about the FORMAT verdict — `z.email()` is a regex and Laravel's is egulias, and the
 * two will never agree on the edge corpus. The LENGTH verdict is a different question, and dropping it
 * with the format one leaves `max:254` unmirrorable: a schema carrying `.max(999)`, or none at all,
 * would be indistinguishable from the correct one. On `ForgotPasswordRequest`, whose only field is
 * `email`, it would leave the manifest with no size probe at all.
 *
 * So the address is synthesized to be RFC-strict-valid AT AN EXACT LENGTH, which makes
 * `serverAccepts: true` a true claim rather than a convenient one. VERIFIED, not reasoned:
 * `php:8.4-cli` against `services/core-api/vendor` (egulias/email-validator 4.0.4, laravel/framework
 * v13.24.0, whose `ValidatesAttributes::validateEmail` builds exactly this validator pair for
 * `rfc,strict`) reports every length 6…254 valid under `RFCValidation` + `NoRFCWarningsValidation`, and
 * 255+ invalid (`EmailTooLong`), which is the same direction `max:254` points. Both halves matter —
 * the naive
 * `'a'.repeat(n - 12) + '@example.com'` that suggests itself here measures RFC-valid and
 * STRICT-INVALID at n=254 (`LocalTooLong`, local part 242 > 64), so it would assert a server
 * acceptance that does not happen and be green for the wrong reason.
 */
const emailOfLength: Sizer = (size, expectation) => {
  if (size < EMAIL_MIN) return undefined;
  // No address longer than this is accepted, whatever the `max:` says, so a probe claiming acceptance
  // cannot be built from one. A probe claiming REJECTION is fine: over-length is a rejection.
  if (expectation === 'accepted' && size > EMAIL_STRICT_MAX) return undefined;

  const local = Math.min(LOCAL_PART_MAX, size - '@'.length - DOMAIN_MIN);

  return `${'a'.repeat(local)}@${domainOfLength(size - '@'.length - local)}`;
};

/**
 * Which generator answers a `min:`/`max:` probe for this field — the fix for the harness's one real
 * latent bug, which four correct schemas exposed the moment they were mirrored.
 *
 * `probesFor` hardcodes `serverAccepts: true` for a size BOUNDARY, which is right for a field whose
 * only constraint is the size and wrong for a field that also constrains the value's form:
 * `'a'.repeat(254)` is not an `email:rfc,strict` address and `'a'.repeat(12)` satisfies neither
 * `regex:/\p{Lu}/u` nor `regex:/\d/u`, so the server rejects both. The harness reported that as "form
 * blocks input the server accepts" against schemas that were exactly right, and the repair it invites
 * is to delete the rule from the schema.
 *
 * The resolution is per-field rather than blanket suppression, because suppression is not free: the
 * numbers it stops probing (`min:12` on a password, `max:254` on an address) are the ones a schema is
 * most likely to drift on, and `test/auth-schemas.test.ts` asserts them against hard-coded constants
 * that cannot notice the SERVER moving. Order: an explicit `sized` generator from the mirror, then the
 * email synthesizer, then the generic one when nothing constrains the form — and only then
 * suppression, which `size rules with no honest probe` renders visible.
 */
const sizerFor = (path: string, rules: readonly string[], mirror: Mirror): Sizer | undefined => {
  const explicit = mirror.sized?.[path];
  if (explicit) return explicit;

  const kind = kindOf(rules);
  const formats = rules.map(nameOf).filter((name) => FORMAT_RULES.has(name));

  if (formats.length === 0) return (size) => sized(kind, size);
  if (formats.length === 1 && formats[0] === 'email' && kind === 'string') return emailOfLength;

  return undefined;
};

function probesFor(path: string, rules: readonly string[], mirror: Mirror): Probe[] {
  const names = rules.map(nameOf);

  if (names.some((name) => SERVER_ONLY.has(name))) return [];

  const crossField = names.some((name) => CROSS_FIELD.has(name));
  const probes: Probe[] = [];

  // Every VALUE probe writes the `_confirmation` sibling too when the server compares them; see the
  // note on `mutate`. Presence probes never do — `confirmed` is CROSS_FIELD, so they are suppressed.
  const here = [path];
  const value = names.includes('confirmed') ? [path, `${path}_confirmation`] : here;

  const sizer = sizerFor(path, rules, mirror);
  /** A `min:`/`max:` probe, or nothing when no value of that size can be probed honestly. */
  const sizeProbe = (label: string, size: number, serverAccepts: boolean): void => {
    const generated = sizer?.(size, serverAccepts ? 'accepted' : 'rejected');
    if (generated !== undefined) probes.push(probe(value, label, generated, serverAccepts));
  };

  // Presence. Suppressed when a cross-field rule makes "is this field required?" depend on a
  // sibling — the harness cannot answer that from one field's rule list.
  if (!crossField) {
    if (names.includes(SOMETIMES)) {
      // Omission is accepted UNCONDITIONALLY: `sometimes` skips every remaining rule, so a
      // co-declared `required` never runs. See the note on SOMETIMES above.
      probes.push(probe(here, 'omitted (sometimes)', OMITTED, true));
    } else if (names.includes('required') || names.includes('present')) {
      probes.push(probe(here, 'omitted', OMITTED, false));
    } else {
      probes.push(probe(here, 'omitted', OMITTED, true));
    }

    // An explicit null is PRESENT, so `sometimes` does not fire and the verdict is unchanged:
    // accepted only if the server said `nullable`.
    probes.push(probe(here, 'null', null, names.includes('nullable')));
  }

  for (const rule of rules) {
    const name = nameOf(rule);
    if (VALUE_EXEMPT.has(name) || SERVER_ONLY.has(name) || CROSS_FIELD.has(name)) continue;

    switch (name) {
      case 'string':
        probes.push(probe(value, 'a number where a string is required', 1234, false));
        break;

      case 'integer':
        probes.push(probe(value, 'a fractional number where an integer is required', 1.5, false));
        break;

      case 'array':
        probes.push(probe(value, 'a string where an array is required', 'not-an-array', false));
        break;

      case 'boolean':
        probes.push(probe(value, 'a string where a boolean is required', 'yes-ish', false));
        break;

      case 'max': {
        const max = Number(argOf(rule));
        sizeProbe(`max:${max} boundary`, max, true);
        sizeProbe(`max:${max} + 1`, max + 1, false);
        break;
      }

      case 'min': {
        const min = Number(argOf(rule));
        sizeProbe(`min:${min} boundary`, min, true);
        if (min > 0) sizeProbe(`min:${min} - 1`, min - 1, false);
        break;
      }

      case 'in': {
        // Rule::in stringifies as in:"a","b" — quoted, and doubled quotes inside a member.
        for (const member of argOf(rule).split(','))
          probes.push(probe(value, `in: ${member}`, member.replace(/^"|"$/g, ''), true));
        probes.push(probe(value, 'in: a non-member', '__not_a_member__', false));
        break;
      }

      case 'ulid':
        probes.push(probe(value, 'a canonical ULID', ULID, true));
        probes.push(probe(value, 'not a ULID at all', 'definitely-not-a-ulid', false));
        // 26 valid Crockford characters, but the timestamp overflows: Symfony's Ulid::isValid
        // requires the first character to be <= '7', and zod's z.ulid() does not check it.
        probes.push(probe(value, 'a ULID whose timestamp overflows', 'Z'.repeat(26), false));
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
    for (const { label, apply, serverAccepts } of probesFor(path, rules, mirror)) {
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
 * THE TWO GATES THAT KEEP THE PROBE SUPPRESSION HONEST.
 *
 * A harness that can decline to probe a rule is a harness that can be made green by declining, and
 * both declinations here are invisible at the call site: `sizerFor` returning `undefined` drops a
 * `min:`/`max:` pair, and `probesFor`'s `default: break` drops an entire rule NAME it has never heard
 * of. Neither leaves a trace in the output — the suite simply reports fewer probes and passes.
 *
 * So each is asserted against an explicit expected value. Both are `[]` today, and neither can grow
 * without a red build.
 */
describe('what the harness declines to probe', () => {
  /**
   * Rules that deliberately generate NO probe, with the reason. `probesFor`'s `default: break` is
   * what implements this, and without the list below that branch silently absorbs anything new.
   */
  const UNPROBED_RULES: Readonly<Record<string, string>> = {
    bail: 'a control directive, not a constraint: it changes WHICH message comes back first, never which values are accepted',
    regex:
      'no generic generator satisfies an arbitrary pattern. A field carrying one supplies a `sized` generator in its Mirror so its size rules stay probed, and the patterns themselves are asserted in test/auth-schemas.test.ts — the one residual gap is proved and named in `the sizers` below',
    size: 'the only `size:` field is the 64-hex invitation/verification token, and registerSchema mirrors it DELIBERATELY LOOSER (src/forms/auth.ts): a wrong-LENGTH token must reach the server and come back as the byte-identical "no longer valid" refusal rather than being rejected locally by a check that tells its holder the token is the wrong SHAPE. Probing it would report that decision as drift',
  };

  /** Rule names `probesFor` generates a probe from, by switch case or by driving the presence pair. */
  const PROBED_RULES = new Set([
    'string',
    'integer',
    'array',
    'boolean',
    'max',
    'min',
    'in',
    'ulid',
    'required',
    'present',
    'nullable',
    SOMETIMES,
  ]);

  const unknownRuleNames = (rules: readonly string[]): string[] => {
    const known = new Set([
      ...PROBED_RULES,
      ...SERVER_ONLY,
      ...CROSS_FIELD,
      ...VALUE_EXEMPT,
      ...Object.keys(UNPROBED_RULES),
    ]);

    return [...new Set(rules.map(nameOf))].filter((name) => !known.has(name)).sort();
  };

  it('every rule name in every manifest is probed, exempt, or explicitly unprobed', () => {
    // The hole this closes was real and was `size:`: four manifests carry `size:64` and the harness
    // generated nothing for it, in silence, so "the client mirrors this rule" was neither proved nor
    // recorded as unproved. A rule Laravel starts using that nobody teaches this file now fails here
    // by name instead of quietly reducing the probe count.
    const everyRule = manifests.flatMap(([, manifest]) => Object.values(manifest.rules).flat());

    expect(
      unknownRuleNames(everyRule),
      'teach probesFor to generate a probe for these, or add each to UNPROBED_RULES with a reason',
    ).toEqual([]);
  });

  it('…and that check has teeth: an invented rule name is reported', () => {
    expect(unknownRuleNames(['decimal:2', 'string', 'bail'])).toEqual(['decimal']);
  });

  /**
   * Every `min:`/`max:` in every MIRRORED manifest must have produced both of its probes. This is the
   * assertion that made blanket suppression unacceptable: `min:12` on a password and `max:254` on an
   * address are the two numbers a schema is most likely to drift on, and suppressing them to fix the
   * format collision would have removed the only check that reads the SERVER'S number.
   */
  const missingSizeProbes = (): string[] => {
    const missing: string[] = [];

    for (const [, manifest] of manifests) {
      const mirror = MIRRORS[manifest.class];
      if (!mirror) continue;

      for (const [path, rules] of Object.entries(manifest.rules)) {
        const labels = new Set(probesFor(path, rules, mirror).map((generated) => generated.label));
        const want = (label: string): void => {
          if (!labels.has(label)) missing.push(`${manifest.class} · ${path} · ${label}`);
        };

        for (const rule of rules) {
          const size = Number(argOf(rule));

          if (nameOf(rule) === 'max') {
            want(`max:${size} boundary`);
            want(`max:${size} + 1`);
          }
          if (nameOf(rule) === 'min') {
            want(`min:${size} boundary`);
            if (size > 0) want(`min:${size} - 1`);
          }
        }
      }
    }

    return missing;
  };

  it('no size rule in a mirrored manifest lost its probe to a format collision', () => {
    expect(
      missingSizeProbes(),
      'give the field a `sized` generator in its Mirror, or the number stops being checked at all',
    ).toEqual([]);
  });
});

/**
 * The two synthesizers, proved to have teeth rather than merely to be green — because "the probe was
 * suppressed" and "the probe passed" are indistinguishable in the output above.
 */
describe('the sizers', () => {
  it('synthesizes an RFC-strict-valid address at an exact length, and declines above 254', () => {
    const boundary = emailOfLength(254, 'accepted');
    expect(boundary).toHaveLength(254);
    // The claim the probe makes about the CLIENT. The claim about the SERVER was measured against the
    // installed egulias under RFCValidation + NoRFCWarningsValidation — see emailOfLength's docblock.
    expect(z.email().max(254).safeParse(boundary).success).toBe(true);

    // Every label of the domain is short enough for egulias, which is the quirk that makes 62 rather
    // than the RFC's 63 the constant.
    const domain = (boundary as string).split('@')[1] as string;
    for (const label of domain.split('.')) expect(label.length).toBeLessThanOrEqual(62);
    expect((boundary as string).split('@')[0]).toHaveLength(64);

    // No 255-character address is accepted by `email:rfc,strict` whatever the `max:` says, so there is
    // no honest acceptance probe at that size — but the rejection probe is still built, and it is the
    // one that catches a schema whose max is too loose.
    expect(emailOfLength(255, 'accepted')).toBeUndefined();
    expect(emailOfLength(255, 'rejected')).toHaveLength(255);
    expect(emailOfLength(5, 'rejected')).toBeUndefined();
  });

  it('catches an email schema whose max: drifted loose — the probe suppression would have hidden it', () => {
    const loose: Mirror = {
      schema: z.strictObject({ email: z.email().max(999) }),
      baseline: () => ({ email: 'user@example.com' }),
    };
    const manifest = manifests.find(
      ([, candidate]) => candidate.class === 'App\\Http\\Requests\\ForgotPasswordRequest',
    );

    expect(driftFailures((manifest as [string, Manifest])[1], loose)).toEqual([
      'form accepts input the server rejects: email — max:254 + 1',
    ]);
  });

  it('catches a password schema whose min: drifted below the server policy', () => {
    // The `sized` generator is what makes this reachable: with the generic `'a'.repeat(n)` the server
    // rejects every probe on this field (no character class), and an 8-character minimum "agrees".
    const weak: Mirror = {
      schema: z
        .strictObject({
          token: z.string().min(1),
          name: z.string().trim().min(1).max(120),
          password: z
            .string()
            .min(8)
            .max(255)
            .regex(/\p{Ll}/u)
            .regex(/\p{Lu}/u)
            .regex(/\d/u),
          password_confirmation: z.string().min(1),
        })
        .superRefine((value, ctx) => {
          if (value.password !== value.password_confirmation) {
            ctx.addIssue({ code: 'custom', path: ['password_confirmation'], message: 'mismatch' });
          }
        }),
      baseline: () => ({
        token: INVITATION_TOKEN,
        name: 'Ada Lovelace',
        password: STRONG_PASSWORD,
        password_confirmation: STRONG_PASSWORD,
      }),
      sized: { password: passwordOfLength },
    };
    const manifest = manifests.find(
      ([, candidate]) => candidate.class === 'App\\Http\\Requests\\RegisterRequest',
    );

    expect(driftFailures((manifest as [string, Manifest])[1], weak)).toEqual([
      'form accepts input the server rejects: password — min:12 - 1',
    ]);
  });

  it('does NOT catch a password schema that dropped the three regexes — a named residual gap', () => {
    // Every value `passwordOfLength` generates satisfies all three character classes, so a schema
    // missing them answers every probe identically. The gap is real, it is why `regex` is in
    // UNPROBED_RULES, and it is covered by test/auth-schemas.test.ts's four rejection cases — which
    // read the constraints from the schema rather than from the manifest, so they cannot notice the
    // SERVER dropping a class. Recorded rather than papered over: probing an arbitrary pattern needs a
    // generator per pattern, and a generator per pattern is a second copy of the rule.
    const noClasses: Mirror = {
      schema: z
        .strictObject({
          token: z.string().min(1),
          name: z.string().trim().min(1).max(120),
          password: z.string().min(12).max(255),
          password_confirmation: z.string().min(1),
        })
        .superRefine((value, ctx) => {
          if (value.password !== value.password_confirmation) {
            ctx.addIssue({ code: 'custom', path: ['password_confirmation'], message: 'mismatch' });
          }
        }),
      baseline: () => ({
        token: INVITATION_TOKEN,
        name: 'Ada Lovelace',
        password: STRONG_PASSWORD,
        password_confirmation: STRONG_PASSWORD,
      }),
      sized: { password: passwordOfLength },
    };
    const manifest = manifests.find(
      ([, candidate]) => candidate.class === 'App\\Http\\Requests\\RegisterRequest',
    );

    expect(driftFailures((manifest as [string, Manifest])[1], noClasses)).toEqual([]);
  });

  it('mirrors the `_confirmation` sibling, so a value probe tests the value and not the mismatch', () => {
    const probes = probesFor(
      'password',
      ['bail', 'required', 'string', 'min:12', 'max:255', 'confirmed'],
      MIRRORS['App\\Http\\Requests\\RegisterRequest'] as Mirror,
    );
    const boundary = probes.find((candidate) => candidate.label === 'min:12 boundary');
    const applied = (boundary as Probe).apply({
      password: STRONG_PASSWORD,
      password_confirmation: STRONG_PASSWORD,
    });

    expect(applied.password).toBe(applied.password_confirmation);
    expect(applied.password).toHaveLength(12);
  });
});

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
  /**
   * DERIVED FROM `MIRRORS`, NOT A HAND-WRITTEN LIST, and that is the whole repair.
   *
   * This loop used to read `[botSettingsSchema, embeddingDesignationSchema]` — two of the seven schemas
   * in the package — while `src/forms/ownership.ts` told its reader the columns are "ABSENT from EVERY
   * schema… and a test asserts no schema path intersects this set". Five schemas were outside the
   * assertion, including `inviteMemberSchema`, the newest one. All five pass, so nothing was wrong; the
   * docblock was simply making a claim no test made, which is the failure mode that ships.
   *
   * Iterating `MIRRORS` means the next mirrored schema is covered the moment its entry lands, with no
   * second list to remember — the same closure argument the manifest set-equality assertion above makes.
   * The two non-mirrored schemas are named explicitly because they have no manifest yet and therefore
   * cannot be reached through `MIRRORS`; when they get one, they move and these lines go away.
   *
   * `uploadSchema` is a FACTORY over `OrgUploadLimits` (§8.10 makes the size and MIME limits
   * per-organization, so no byte or MIME constant may exist in this package), which is why it is
   * instantiated here rather than referenced. This closes only its OWNERSHIP property — the claim at
   * `src/forms/upload.ts:10` that nothing asserts the file at all is now narrower but still true of the
   * property that matters there: nothing parses one File against two different limit DTOs and expects
   * opposite results. The limits below are therefore arbitrary; only the path set is under test.
   */
  const everySchema = (): readonly (readonly [string, z.ZodType])[] => [
    ...Object.entries(MIRRORS).map(
      ([className, mirror]) => [className, mirror.schema] as readonly [string, z.ZodType],
    ),
    ['botSettingsSchema (no manifest yet)', botSettingsSchema],
    [
      'uploadSchema (no manifest yet; a factory, so instantiated)',
      uploadSchema({ max_bytes: 1, allowed_mime: ['application/pdf'], max_batch: 1 }),
    ],
  ];

  it('no schema path intersects OWNERSHIP_KEYS', () => {
    const checked = everySchema();

    // A positive control on the LOOP, not on the schemas: an empty or truncated list would make every
    // assertion below vacuous, and `toEqual([])` on nothing passes. Eight is the whole package today —
    // the six MIRRORS plus the two schemas with no manifest. It is asserted rather than commented
    // because the number is the only thing standing between this loop and passing on an empty list;
    // when MIRRORS grows, this goes red once and the new count is a one-character edit with a diff that
    // says which schema arrived.
    expect(checked.length, 'every schema in the package must be reached').toBe(8);

    for (const [label, schema] of checked) {
      expect(schemaPaths(schema).filter(isOwnershipPath), label).toEqual([]);
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
