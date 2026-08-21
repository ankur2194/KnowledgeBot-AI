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
import {
  BOT_ACCESS_MODES,
  BOT_ANSWER_MODES,
  BOT_STATUSES,
  botCreateDefaults,
  botCreateSchema,
  botFormDefaults,
  botSettingsSchema,
  botStatusTransitionDefaults,
  botStatusTransitionSchema,
  EVIDENCE_THRESHOLD_SCALES,
  THEME_RADII,
} from '../src/forms/bot.js';
import {
  BOT_DOMAIN_STATUSES,
  botDomainCreateDefaults,
  botDomainCreateSchema,
  botDomainStatusDefaults,
  botDomainStatusSchema,
} from '../src/forms/bot-domain.js';
import {
  starterQuestionCreateDefaults,
  starterQuestionCreateSchema,
  starterQuestionUpdateDefaults,
  starterQuestionUpdateSchema,
} from '../src/forms/bot-starter-question.js';
import { embeddingDesignationSchema } from '../src/forms/embedding-designation.js';
import { OWNERSHIP_KEYS, isOwnershipPath } from '../src/forms/ownership.js';
import {
  PROVIDER_CONNECTION_STATUSES,
  providerConnectionEditDefaults,
  providerConnectionEditSchema,
} from '../src/forms/provider-connection.js';
import {
  providerModelCreateDefaults,
  providerModelCreateSchema,
  providerModelEditDefaults,
  providerModelEditSchema,
} from '../src/forms/provider-model.js';
import { uploadDefaults, uploadSchema } from '../src/forms/upload.js';

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
 * A `Sizer` for `supported.*` on both model-catalogue manifests, and the second worked example of why
 * this hook exists.
 *
 * The element rule grew a `regex:/^[a-z][a-z0-9_]*$/` — the server closed a channel that let a tenant
 * write an arbitrary attacker-chosen string into the model row's audit detail. `regex` is a
 * `FORMAT_RULES` member, so from that moment `sizerFor` returns `undefined` for this path and BOTH
 * `max:64` probes disappear: not reported, not failed, simply absent. `missingSizeProbes()` is what
 * turns that into a red build instead of a quieter suite, and this generator is the repair it asks for.
 *
 * `'a'.repeat(n)` is length-exact AND matches the pattern — a run of lower-case letters starting with
 * one — so `serverAccepts: true` at 64 is a true claim about the server rather than a convenient one,
 * which is the whole burden a `sized` entry takes on. It is a claim made HERE, beside the mirror whose
 * author verified it against the dumped rule, rather than inferred in the harness from a rule string.
 */
const capabilityFlagOfLength: Sizer = (size) => 'a'.repeat(size);

/**
 * A `Sizer` for `slug` on both bot manifests, and the third worked example of the hook.
 *
 * The rule is `max:64|regex:/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/` — `bots_slug_shape` verbatim —
 * and `regex` is a `FORMAT_RULES` member, so without this both `max:64` probes vanish: not reported,
 * not failed, simply absent. `missingSizeProbes()` is what turns that into a red build.
 *
 * `'a'.repeat(n)` is length-exact AND matches the pattern for every n in 1…64 (one leading
 * alphanumeric, up to 62 middle characters, one trailing alphanumeric), so `serverAccepts: true` at
 * the boundary is a true claim about the server rather than a convenient one. At 65 it matches
 * nothing, which is fine: the rejection probe only needs the server to say no, and it says no twice.
 */
const slugOfLength: Sizer = (size) => 'a'.repeat(size);

/**
 * A `Sizer` for `theme.primary` and `theme.accent`, and the only one in this file whose claim about
 * the server was MEASURED AGAINST THE SERVER rather than reasoned about.
 *
 * Both paths carry `max:64` beside `App\Rules\ReadableThemeColor`, which demands a well-formed
 * `oklch()` triple that can be given readable text. The generic `'a'.repeat(64)` satisfies neither
 * half, so the boundary probe would claim an acceptance that does not happen — the exact false red
 * `passwordOfLength` exists for, and the repair it invites is to delete the length bound from the
 * schema.
 *
 * The padding goes BETWEEN the components, because that is the only axis with room: the grammar caps
 * each component at three integer digits and six decimals, so all three plus an alpha reach nowhere
 * near 64, while `\s+` between them matches a run of any length. `oklch(0.525 … 0.235 264)` is
 * therefore length-exact and legal.
 *
 * VERIFIED, NOT REASONED: `php` against `services/core-api/vendor` ran `App\Rules\ReadableThemeColor`
 * over the generated values and reported ACCEPT at 22, at 64 and at 65 — 65 is rejected by `max:64`
 * alone, which is what the rejection probe needs — and REJECT for `'a'.repeat(64)`, which is the
 * false red this generator removes. The same run confirmed the contrast half is live:
 * `oklch(0.58 0.2 264)` is a legal colour and is refused, because L in [0.538, 0.634] is the band
 * where neither platform foreground clears 4.5:1.
 *
 * L = 0.525 is deliberately just BELOW that band: it is the example the server's own error message
 * gives, so a probe built on it is asserting against the value the rule's author had in mind.
 */
const THEME_COLOR_HEAD = 'oklch(0.525 0.235 264)';

const themeColorOfLength: Sizer = (size) =>
  size < THEME_COLOR_HEAD.length
    ? undefined
    : `oklch(0.525${' '.repeat(size - THEME_COLOR_HEAD.length + 1)}0.235 264)`;

/** `https://` — the shortest scheme prefix `ExactOrigin` admits is `http://`, but every origin this
 *  generator emits uses the longer one so one arithmetic serves both ends. */
const ORIGIN_HEAD = 'https://';

/** RFC 1035, and `ExactOrigin::HOST` carries it verbatim: `[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?` is a
 *  label of at most 63 characters. Unlike the email synthesizer's 62 this is NOT an upstream quirk —
 *  the pattern is the server's own, so the RFC number is the real one here. */
const HOST_LABEL_MAX = 63;

/**
 * A `Sizer` for `origin` on `StoreBotDomainRequest`, and the fourth worked example of why the hook
 * exists — this time on a rule whose refusals are a SECURITY CONTROL rather than a format.
 *
 * `App\Rules\ExactWidgetOrigin` is a `FORMAT_RULES` member by declaration (see that set), so
 * without this generator `sizerFor` returns `undefined` and both `max:255` probes vanish: not
 * reported, not failed, simply absent. `missingSizeProbes()` is what turns that into a red build.
 *
 * The generic `'a'.repeat(255)` is worse than absent, which is the other half of the argument: the
 * rule refuses it outright ("An origin starts with `http://` or `https://`"), so the boundary probe
 * would claim an acceptance that does not happen and the repair it invites is to delete the length
 * bound from the schema.
 *
 * The padding goes into the HOST, which is the only axis with room, and it is split into labels of
 * at most 63 characters because that is what `ExactOrigin::HOST` admits. A run of `a`s satisfies the
 * label grammar at every length in 1…63 — one leading alphanumeric, up to 61 middle characters, one
 * trailing alphanumeric — and the dots between labels are free, so the result is length-exact and
 * legal. An exact multiple would leave nothing for the final label and emit a trailing dot, which
 * the host pattern refuses, so one character is borrowed from the previous label exactly as
 * `domainOfLength` does.
 *
 * VERIFIED, NOT REASONED, like `themeColorOfLength`'s and `emailOfLength`'s: `php` against
 * `services/core-api/vendor` ran `App\Support\Web\ExactOrigin::parse()` over the generated values
 * and reported ACCEPT at 9, 22, 254 and 255, and REJECT at 256 — 256 is refused by `max:255` AND by
 * the rule's own `MAX_LENGTH`, which is what the rejection probe needs — and REJECT for
 * `'a'.repeat(255)`, which is the false red this generator removes. The same run confirmed the three
 * normalisations are live: `HTTPS://EXAMPLE.COM:443` is ACCEPTED and stored as `https://example.com`,
 * which is why nothing in this package echoes a submitted origin back to the operator.
 */
const originOfLength: Sizer = (size) => {
  let left = size - ORIGIN_HEAD.length;
  if (left < 1) return undefined;

  const labels: string[] = [];
  while (left > HOST_LABEL_MAX) {
    labels.push('a'.repeat(HOST_LABEL_MAX));
    left -= HOST_LABEL_MAX + 1;
  }
  if (left === 0) {
    labels[labels.length - 1] = 'a'.repeat(HOST_LABEL_MAX - 1);
    left = 1;
  }
  labels.push('a'.repeat(left));

  return `${ORIGIN_HEAD}${labels.join('.')}`;
};

/**
 * The bot body both requests share, and a FUNCTION rather than a constant because `mutate` clones it
 * per probe and `theme` is a nested object — a shared literal would let one probe's `structuredClone`
 * source be a previous probe's mutation if anything ever wrote through.
 *
 * Every value here is one the SERVER accepts: the retrieval depths sit inside docs/07 §12.7-12.12's
 * bands (note `rerank_candidates` starts at 20 and `rerank_retain` at 6, so a plausible-looking 5
 * would fail the baseline before a single probe ran), the theme colours are legal `oklch()` triples
 * outside the unreadable band, and the evidence pair is the one argued for in the MIRRORS note below.
 *
 * `consent_text` IS SET WHILE `collect_end_user_data` IS FALSE, deliberately: that is a legal row —
 * an operator who wrote the disclosure before switching collection on — and it keeps the baseline
 * clear of the one pairing rule that is NOT in either manifest. `BotService` owns that check against
 * the resulting row on both paths, and neither schema mirrors it; see the note on
 * `collect_end_user_data` in src/forms/bot.ts.
 */
const botBaseline = (): Candidate => ({
  name: 'Support desk',
  slug: 'support-desk',
  description: 'Answers questions about the employee handbook.',
  welcome_message: 'Hi — ask me anything about the handbook.',
  placeholder_text: 'Ask a question',
  system_instruction: 'Answer only from the handbook, and say so when it does not cover something.',
  answer_style_instruction: 'Be concise and use the reader’s own vocabulary.',
  access_mode: 'private',
  provider_connection_id: ULID,
  provider_model_id: ULID,
  answer_mode: 'strict',
  dense_top_k: 20,
  sparse_top_k: 20,
  rerank_candidates: 20,
  rerank_retain: 6,
  evidence_threshold: 0.5,
  evidence_threshold_scale: 'logit',
  allow_general_answers: false,
  theme: {
    primary: 'oklch(0.525 0.235 264)',
    accent: 'oklch(0.97 0.005 264)',
    radius: '0.625rem',
  },
  rate_limit_per_minute: 60,
  rate_limit_per_day: 5000,
  retention_days: 90,
  collect_end_user_data: false,
  consent_text: 'We keep your email so we can follow up on this conversation.',
});

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

  /**
   * THE ONE PROVIDER FORM WITH A SHARED SCHEMA, and the other two are the reason this entry needs a
   * note. `StoreProviderConnectionRequest` and `RotateProviderCredentialRequest` both carry the
   * plaintext `credential` and are NO_CLIENT_FORM below; this one carries `label` and `status` and
   * CANNOT acquire a credential field (the FormRequest declares none, `ProviderConnectionEdit` has no
   * member for one, and the service never reaches the vault). So the argument that exempts the other
   * two does not reach this one, and what is left is exactly the kind of rule that drifts in silence:
   * a fourth lifecycle status added server-side becomes a `<Select>` that cannot express a value the
   * API returns, with nothing red anywhere.
   *
   * NO `sized` OVERRIDE. Neither field carries a format rule, so the generic `'a'.repeat(n)` sizer
   * answers `max:120` honestly.
   *
   * BOTH FIELDS ARE `required_without` THE OTHER, which is CROSS_FIELD — so the harness suppresses
   * the presence probes for both and the schema's `superRefine` (the "names neither" case) is
   * asserted by hand in the cross-field section below rather than by a generated probe.
   */
  'App\\Http\\Requests\\UpdateProviderConnectionRequest': {
    schema: providerConnectionEditSchema,
    baseline: () => ({ label: 'Primary OpenAI key', status: 'active' }),
  },

  /**
   * THE TWO MODEL-CATALOG REQUESTS, MOVED HERE FROM NO_CLIENT_FORM. That entry recorded them as OWED
   * rather than exempt — neither carries a credential, both are ordinary field forms — and named the
   * three-step diff that closes it: the schemas in src/forms/provider-model.ts, these entries, and the
   * form that renders them (apps/web/src/features/models). rhf-zod-forms NN3 is why the schema could
   * not simply be written earlier: a schema ships WITH the form that renders it, and one written ahead
   * of its form is an unread declaration whose drift nobody would notice.
   *
   * ONE `sized` OVERRIDE ON EACH, and it is `supported.*` — see `capabilityFlagOfLength`. It used to
   * be none: the element rule was `string|max:64` and the generic sizer answered it. The server then
   * added `regex:/^[a-z][a-z0-9_]*$/`, which put the path into `FORMAT_RULES` territory and would have
   * made `sizerFor` return `undefined`, dropping both `max:64` probes in silence. `missingSizeProbes()`
   * caught it; the override is the repair.
   *
   * Each of the other size rules is probed by a different generator for a different reason.
   * `display_name`/`model` carry no format rule, so `'a'.repeat(n)` answers `max:200` honestly.
   * `supported` is `kind: 'array'`, so the sizer builds an n-element array against `max:20` — its
   * filler element is `'a'`, which satisfies the new element pattern, so that probe stayed honest
   * without an override. `supported.*` is `kind: 'string'` against `max:64` — reachable only because
   * `schemaPaths`/`setPath` learned the `.*` segment below. The two prices are `kind: 'number'`, and
   * their `decimal:0,6` probes pass real JS NUMBERS, which is why the schema's price field is a union
   * over string AND number rather than the string-only shape the form actually produces: Laravel's
   * `numeric` accepts both, and a string-only mirror would report a disagreement of this package's own
   * invention.
   *
   * `price_currency` IS `required_with` BOTH PRICES, which is CROSS_FIELD — so the harness suppresses
   * every presence probe on that field and the schema's `superRefine` is asserted by hand in the
   * cross-field section below. Its `size:3` stays unprobed for the reason UNPROBED_RULES gives.
   *
   * THE BASELINES CARRY PRICES WITH SIX PLACES AND A CURRENCY. Six because that is the scale the
   * server round-trips (`'0.02'` comes back `'0.020000'`), and a currency because a baseline with a
   * price and no currency fails the `superRefine` before a single probe runs.
   */
  'App\\Http\\Requests\\StoreProviderModelRequest': {
    schema: providerModelCreateSchema,
    baseline: () => ({
      model: 'gpt-5.6-sol',
      display_name: 'GPT-5.6 Sol',
      supported: ['text', 'tool_use'],
      context_window: 400_000,
      max_output_tokens: 128_000,
      enabled: true,
      input_price_per_million: '1.250000',
      output_price_per_million: '10.000000',
      price_currency: 'USD',
    }),
    sized: { 'supported.*': capabilityFlagOfLength },
  },

  /**
   * THE TWO BOT REQUESTS, and the longest note in this map because three separate things about them
   * are load-bearing and none is visible at the call site.
   *
   * ── THE BASELINE'S EVIDENCE PAIR IS CHOSEN, NOT ARBITRARY ──────────────────────────────────────
   * `{evidence_threshold: 0.5, evidence_threshold_scale: 'logit'}` is the ONE combination that keeps
   * both probe sets honest, and every other plausible pair makes one of them a false claim about the
   * server:
   *
   *   the probes on `evidence_threshold` mutate the number and keep the baseline's SCALE. `min:-100`
   *   and `max:100` claim the server ACCEPTS -100 and 100 — true on `logit`, which is unbounded, and
   *   FALSE on either bounded scale, where `App\Rules\EvidenceThresholdWithinScale` refuses anything
   *   outside [0, 1]. A `sigmoid` baseline reports the correct schema as blocking input the server
   *   accepts, and the repair it invites is to delete the range mirror.
   *
   *   the probes on `evidence_threshold_scale` mutate the scale and keep the baseline's NUMBER. The
   *   `in:` case claims the server accepts each of the three members in turn, which is true only for
   *   a number inside [0, 1] — so a logit-shaped baseline like `-3.0` would assert an acceptance that
   *   does not happen on two of the three members.
   *
   * 0.5 is inside [0, 1] and `logit` is unbounded, so both claims hold. This is the same burden a
   * `sized` entry takes on, made here beside the mirror rather than inferred in the harness.
   *
   * VERIFIED, NOT REASONED, like `emailOfLength`'s: `php` against `services/core-api/vendor` ran
   * `App\Rules\EvidenceThresholdWithinScale` over the whole probe matrix and reported ACCEPT for 0.5
   * on all three scales, ACCEPT for -100 and 100 on `logit`, and REJECT for 1.7 on `sigmoid` and
   * -0.1 on `unit_interval` — which is every claim this baseline makes about the server, in both
   * directions. The same run reported ACCEPT from `App\Rules\ReadableThemeColor` for both baseline
   * theme colours.
   *
   * ── TWO `sized` OVERRIDES EACH, FOR TWO DIFFERENT FORMAT COLLISIONS ────────────────────────────
   * `slug` carries a `regex:`, which is a `FORMAT_RULES` member; `theme.primary` and `theme.accent`
   * carry a rule OBJECT that is one by declaration (see FORMAT_RULES). Without the overrides the
   * first pair of probes disappears and the second pair lies. `missingSizeProbes()` catches the
   * disappearance; only a reader catches the lie, which is why `themeColorOfLength`'s claim was
   * measured against the installed PHP rather than argued.
   *
   * ── THE BASELINE CARRIES EVERY KEY THE MANIFEST DECLARES ───────────────────────────────────────
   * Including the ones a real form would omit. An `omitted` probe deletes a key, so a baseline
   * missing that key makes the probe a no-op that passes while asserting nothing — the quiet
   * variant of the suppression this file's two gates exist to prevent.
   *
   * ── ONE DIFFERENCE BETWEEN THE TWO BASELINES, AND IT IS THE WHOLE DIFFERENCE BETWEEN THE
   *    REQUESTS ────────────────────────────────────────────────────────────────────────────────────
   * This note used to read "`status` is on the PATCH and not on the POST: a bot is created `draft`,
   * always", and BOTH BASELINES ARE NOW IDENTICAL. `status` is `["missing"]` on the PATCH — a
   * lifecycle move is `PUT …/bots/{bot}/status` and nothing else — so a PATCH baseline that still
   * carried one would fail `the baseline is a value both sides accept` against a server that answers
   * 422. What is left as the whole difference between the two requests is `sometimes`, which is what
   * the note on `UpdateBotRequest` below is about.
   *
   * The `missing` PROBES are what keep the new arrangement from being a claim nobody checks:
   * `probesFor` generates an omission ACCEPTED and both a value and an explicit null REJECTED for the
   * path, so a `botSettingsSchema` that re-declared `status` fails by name — as does the set
   * comparison in `the schema declares exactly the fields the FormRequest validates`, which subtracts
   * those paths from the manifest side for exactly this reason.
   */
  'App\\Http\\Requests\\StoreBotRequest': {
    schema: botCreateSchema,
    baseline: () => botBaseline(),
    sized: {
      slug: slugOfLength,
      'theme.primary': themeColorOfLength,
      'theme.accent': themeColorOfLength,
    },
  },

  /**
   * THE FIRST REAL `sometimes|required` MANIFEST IN THIS REPO, and the reason the fixture block near
   * the bottom of this file was written before one existed. Twelve of its fields carry that pair, and
   * reading it as `required` would make the whole settings form a replace: the schema would demand
   * `name`, `slug`, `status` and nine more on every save, so editing one welcome message would be
   * impossible — functionality removed, nothing reported, every probe green because both sides would
   * "agree" the omission is a rejection.
   *
   * The fixture suite proves the harness answers that correctly; this entry is the first place it
   * answers it about a schema that ships.
   */
  'App\\Http\\Requests\\UpdateBotRequest': {
    schema: botSettingsSchema,
    baseline: () => botBaseline(),
    sized: {
      slug: slugOfLength,
      'theme.primary': themeColorOfLength,
      'theme.accent': themeColorOfLength,
    },
  },


  /**
   * THE STATUS TRANSITION, AND IT IS THE ONLY PLACE `BOT_STATUSES` IS STILL COMPARED TO THE SERVER.
   *
   * The tuple used to be pinned through `botSettingsSchema`'s `status` field. That field is gone —
   * `UpdateBotRequest` rules it `["missing"]` — and if this manifest had been exempted instead of
   * mirrored, the five-member tuple every status pill and transition menu iterates would have become
   * a list nothing in this repo compares to anything. The `in:` probes below are that comparison: a
   * sixth lifecycle value added server-side fails HERE rather than being invisible until a `<Select>`
   * omits it.
   *
   * NO `sized` OVERRIDE and no size rule at all: the field is `bail|required|string|in:…`. That is
   * also why this manifest is the reason the teeth test learned a SECOND tampering, below — a
   * manifest with no `max:` cannot be knocked one character off in the only way that test used to
   * know.
   *
   * THE BASELINE IS `published` RATHER THAN `draft`, and it is arbitrary in a way the bot baselines
   * are not: every probe on this field replaces the value outright and the object has no sibling for
   * a verdict to depend on, so any member is as honest as any other. `published` is the one the
   * console's most consequential transition targets.
   */
  'App\\Http\\Requests\\UpdateBotStatusRequest': {
    schema: botStatusTransitionSchema,
    baseline: () => ({ status: 'published' }),
  },

  /**
   * THE WIDGET ORIGIN ALLOW-LIST, and the one mirror in this map whose unmirrored half is a SECURITY
   * CONTROL rather than a format.
   *
   * `App\Rules\ExactWidgetOrigin` refuses a wildcard, a path, a query, a fragment, userinfo, an IPv6
   * literal, a non-ASCII host, `:0` and `:00443`, each with its own sentence, and it NORMALISES what
   * it accepts. `botDomainCreateSchema` mirrors the length, the type and the two-scheme prefix and
   * nothing else; the module docblock in src/forms/bot-domain.ts argues that boundary and
   * `UNPROBED_RULES` records the suppression.
   *
   * ONE `sized` OVERRIDE, and it is the same failure mode `slug`'s and `theme.primary`'s are: the
   * rule is a `FORMAT_RULES` member, so without `originOfLength` both `max:255` probes disappear in
   * silence and `missingSizeProbes()` is what turns that into a red build. Unlike `slug`'s, this
   * generator's acceptance claim was MEASURED against the installed PHP rather than read off a
   * pattern — see its docblock — because the rule is 200 lines of prose-carrying refusals rather
   * than a regex anyone can check by eye.
   */
  'App\\Http\\Requests\\StoreBotDomainRequest': {
    schema: botDomainCreateSchema,
    baseline: () => ({ origin: 'https://example.com' }),
    sized: { origin: originOfLength },
  },

  /**
   * THE ALLOW-LIST ENTRY'S LIFECYCLE, mirrored for the reason `UpdateProviderConnectionRequest` is
   * and not because a one-field enum body needs a resolver: a fourth value added server-side becomes
   * a `<Select>` that cannot express a value the API returns, with nothing red anywhere. The `in:`
   * probes are what pin `BOT_DOMAIN_STATUSES`.
   *
   * THE BASELINE IS `active`, WHICH IS THE ONE VALUE THAT GRANTS AN EMBED — chosen so the fixture in
   * this file is never mistaken for a safe default. `pending` grants nothing and `disabled` is a
   * withdrawn row; only `active` permits a widget to boot, and that is the transition this form
   * exists to make deliberate.
   *
   * NO `origin` KEY, and `strictObject` is what makes that a parse failure rather than a silent
   * strip: the origin is IMMUTABLE, the FormRequest declares no rule for it, and a body carrying one
   * would change what a live grant points at while every audit row naming it still read the old
   * string.
   */
  'App\\Http\\Requests\\UpdateBotDomainRequest': {
    schema: botDomainStatusSchema,
    baseline: () => ({ status: 'active' }),
  },

  /**
   * THE STARTER-QUESTION CHIPS. Two manifests, and the PATCH is the interesting one.
   *
   * `StoreBotStarterQuestionRequest` is one `required|string|max:200` field and declares NO
   * `sort_order`: a new question is appended by the server, which is the only position that cannot
   * collide with an existing one. `strictObject` refuses a form that tried to choose one.
   */
  'App\\Http\\Requests\\StoreBotStarterQuestionRequest': {
    schema: starterQuestionCreateSchema,
    baseline: () => ({ question: 'How do I reset my password?' }),
  },

  /**
   * THE PATCH, AND IT DELIBERATELY DOES NOT CARRY `sometimes` — which is the opposite of what every
   * other PATCH manifest in this map does, so mirroring what looks symmetrical is exactly the
   * mistake to avoid.
   *
   * `sometimes` short-circuits every remaining rule for an ABSENT key, `required_without` included.
   * With it on both fields an empty PATCH body satisfied everything and returned 200 having changed
   * nothing — which is what the server found and removed. So both fields are `required_without` the
   * other with no `sometimes`, and the schema spells the same thing: both optional, plus a
   * refinement for the body that names neither.
   *
   * BOTH FIELDS ARE CROSS_FIELD, so the harness suppresses every presence probe on both and the
   * refinement is asserted BY HAND in the starter-question section below. What the probes do reach
   * is `max:200` on the text and the `min:0`/`max:5` pair on the position — and `min:0` is the one
   * worth naming: the positions are ZERO-BASED, so 0 is the first chip rather than an unset value,
   * and `probesFor` generates the boundary but not the `-1` case for exactly that reason.
   *
   * NO `sized` OVERRIDE. Neither field carries a format rule, so the generic sizer answers both
   * honestly — `'a'.repeat(n)` for the text and the number itself for the position.
   */
  'App\\Http\\Requests\\UpdateBotStarterQuestionRequest': {
    schema: starterQuestionUpdateSchema,
    baseline: () => ({ question: 'How do I reset my password?', sort_order: 2 }),
  },

  'App\\Http\\Requests\\UpdateProviderModelRequest': {
    schema: providerModelEditSchema,
    // NO `model` KEY, and `strictObject` is what makes that a parse failure rather than a silent
    // strip: the identifier is immutable, the FormRequest declares no rule for it, and a body
    // carrying one would be a rename of the vector space everything under this row was embedded into.
    baseline: () => ({
      display_name: 'GPT-5.6 Sol',
      supported: ['text', 'tool_use'],
      context_window: 400_000,
      max_output_tokens: 128_000,
      enabled: true,
      input_price_per_million: '1.250000',
      output_price_per_million: '10.000000',
      price_currency: 'USD',
    }),
    sized: { 'supported.*': capabilityFlagOfLength },
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
    'credential field — see the comment above; the create form in apps/web/src/features/providers keeps it local, write-only and out of defaultValues',

  // ROTATION CARRIES THE SAME FIELD AND THE SAME DECISION, and the argument above applies to it
  // VERBATIM: `credential` is the plaintext provider key, and a shared importable schema naming it is
  // one `providerConnectionDefaults(resource)` away from posting `…4a91` back as the new key.
  //
  // Rotation is in fact the WORSE of the two to export, for two reasons the create endpoint does not
  // have. First, the seeding bug is unreachable on create — there is no resource to seed FROM — while
  // rotate is by definition a form opened against an existing connection whose `masked_key` is on
  // screen beside the input. The server has a `not_regex:/^\x{2026}/u` rule precisely because that is
  // the obvious way to build this screen, and a rule that exists to catch a client mistake is not a
  // licence to make it. Second, the body's other field is `current_password`: the §18.3
  // re-authentication. A schema exported to apps/mobile and apps/widget that names both a provider key
  // and the actor's password in one object is a shape nothing else in this package has, and the only
  // thing it would buy is mirroring `min:8|max:512`, which the input's own `minLength`/`maxLength`
  // carry.
  //
  // `current_password:web` is SERVER_ONLY besides — it needs the session and the stored hash — so the
  // only mirrorable rules on this request are two length bounds and a regex the client must never
  // rely on. The dialog in apps/web/src/features/providers/rotate-credential-dialog.tsx therefore
  // ships with no resolver, exactly as the invite form did before its manifest existed, and maps the
  // server's 422 onto `credential` and `current_password` by name.
  'App\\Http\\Requests\\RotateProviderCredentialRequest':
    'plaintext `credential` plus the §18.3 re-authentication password — see the comment above; no shared schema, and the dialog keeps both fields local, write-only and absent from defaultValues',

  // THE TWO MODEL-CATALOG REQUESTS ARE GONE FROM THIS LIST and are MIRRORS entries above. Their
  // note read "no form renders it yet … OWED rather than exempt", and it carried the hint that
  // closed it: exact decimal STRINGS out and `numeric|decimal:0,6` in, `price_currency`
  // `required_with` both prices, and `supported` `present|array` so the form posts `[]` rather than
  // dropping the key. Every one of those is now a probe or a hand-written cross-field assertion.
  //
  // What that episode is worth keeping: the harness gained `numeric` and `decimal` probes BEFORE
  // those schemas existed, deliberately, so a schema with no scale check could not "agree" with the
  // server by being unprobed. It could not have gained them afterwards without somebody noticing
  // they were missing, which nobody would have.

  // THE ONE MANIFEST THAT IS NOT A FORM AT ALL: it validates a QUERY STRING, and the client's
  // correct behaviour on every one of its five fields is the OPPOSITE of what a mirroring schema
  // would do.
  //
  // `page`, `per_page`, `sort`, `dir` and `filter` are read out of the URL by
  // `apps/web/src/lib/table/params.ts`, which is the table's state and the request in one value. A
  // query string is user input that somebody may simply have typed or bookmarked from a previous
  // release, and it reaches two places that must not take unbounded values: the request Laravel
  // validates, and the TanStack Query cache key. So that module CLAMPS — a `sort` outside the
  // endpoint's sortable set degrades to the default, a `per_page` outside the declared page sizes
  // degrades to the default, and a filter longer than `MAX_FILTER_LENGTH` is truncated.
  //
  // A Zod mirror of these rules would have to REJECT each of those, and rejecting is the wrong
  // answer twice over: there is no field, no control and no per-field error to key a message to, and
  // the visible result would be an error screen where the correct one is the default view. The drift
  // harness cannot express "degrades to the default" — its whole vocabulary is accept/reject — so a
  // mirror would either report the clamping module as drift or force it to start 422ing its own
  // users.
  //
  // WHAT REPLACES IT: the server clamps too (`ListQuery::fromValidated()`), and `params.ts` mirrors
  // `MAX_PER_PAGE` and `MAX_FILTER_LENGTH` as NUMBERS with the server named as the authority — a
  // config whose `pageSizes` exceed the cap fail loudly at the call site instead of producing a
  // response whose applied page size differs from the one the pager is doing arithmetic with. The
  // one thing genuinely owed here is the SORTABLE SET (`id`, `name`, `slug`, `status`), which each
  // table declares locally today; if a third list endpoint arrives, that is the piece worth lifting
  // into this package — as a tuple, not as a schema.
  'App\\Http\\Requests\\IndexBotsRequest':
    'a query-string manifest, not a form: no control, no resolver and no per-field error, and the client CLAMPS every one of these five values to a declared set (apps/web/src/lib/table/params.ts) where a mirroring schema would have to reject — see the comment above',

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

  // ── THE FIVE SOURCE-LIFECYCLE MANIFESTS, AND NOT ONE OF THEM IS MIRRORED ─────────────────────
  //
  // Two are exempt ON THE MERITS and three are OWED, and every entry says which it is in its first
  // clause — because a list where a decision and a to-do read alike is a list whose to-dos are never
  // done. The response side of the same feature went the OTHER way in the same change:
  // `SourceResource` and `SourceCollectionResource` are MIRRORED in test/resource-drift.test.ts. The
  // asymmetry is `rhf-zod-forms` NN3 and is deliberate — a RESPONSE type is correct the moment the
  // server publishes it and is wrong the moment a client re-declares it locally, while a REQUEST
  // schema is only correct beside the form that renders it, and one written ahead of its form is an
  // unread declaration whose drift nobody would notice.

  // EXEMPT ON THE MERITS. THE SECOND QUERY-STRING MANIFEST, and `IndexBotsRequest`'s argument above
  // applies to all five of its fields verbatim: they are read out of the URL by
  // `apps/web/src/lib/table/params.ts`, which CLAMPS — a `sort` outside the endpoint's sortable set
  // degrades to the default, a `per_page` outside the declared sizes degrades to the default, a
  // filter past `MAX_FILTER_LENGTH` is truncated — where a Zod mirror would have to REJECT, with no
  // field, no control and no per-field error to key a message to. The drift harness has no vocabulary
  // for "degrades to the default"; it can say accept or reject and nothing else.
  //
  // WHAT THE SECOND ONE MEASURES THAT THE FIRST COULD ONLY PREDICT. That note ended "if a third list
  // endpoint arrives, that is the piece worth lifting into this package — as a tuple, not as a
  // schema", meaning the SORTABLE SET. This is the second, and the two sets already disagree:
  // `id,name,slug,status` for bots against `id,name,type,status` here. So the thing that looked
  // liftable is per-endpoint after all — a shared tuple would be one endpoint's vocabulary pretending
  // to be every endpoint's, which is the exact reason `ListMetaResource.sort` is typed `string`
  // rather than a union (test/resource-drift.test.ts). What IS shared is the ENVELOPE, and that is
  // already imported rather than re-declared.
  'App\\Http\\Requests\\IndexSourcesRequest':
    'EXEMPT: a query-string manifest, not a form — no control, no resolver and no per-field error, and the client CLAMPS every one of these five values (apps/web/src/lib/table/params.ts) where a mirroring schema would have to reject. Its sortable set differs from IndexBotsRequest\'s in one member, which is the measurement that says the set stays per-endpoint — see the comment above',

  // EXEMPT ON THE MERITS, AND THE ONLY MANIFEST IN THIS FILE NO CLIENT COULD SUBMIT IF IT WANTED TO.
  // `POST /internal/ingestion/callback` is the FastAPI data plane reporting a stage transition back
  // to Laravel over the signed internal transport (`kb-internal-api-contracts`): the caller proves
  // itself with an HMAC signature, not a session, and there is no browser, widget or mobile client on
  // either end of it. `kb-architecture-map` NN3 is the same fact from the other side — clients never
  // reach FastAPI — so a "client form" for this body is a contradiction rather than a gap.
  //
  // AND EXPORTING IT WOULD BE ACTIVELY WRONG, not merely useless. This barrel ships to apps/web,
  // apps/widget and apps/mobile by default, and the body is the internal wire shape: `job_id`,
  // `sequence`, `ingest_key`, `content_hash`, and the four `*_cfg_version` strings that decide when a
  // corpus must be re-embedded. Publishing that to a browser bundle tells a reader which fields make
  // the control plane accept a stage transition — the same argument that keeps `credential` out of a
  // shared schema, applied to a shape whose whole security model is that only one caller has it.
  //
  // ONE FIELD HERE IS GENUINELY SHARED, AND IT IS ALREADY PINNED ELSEWHERE: `error_class` is the
  // eighteen-member taxonomy, which this package holds as `ERROR_CLASSES` and
  // test/error-taxonomy-parity.test.ts compares directly against services/ai-service/app/core/errors.py
  // — Python at the apex, not this manifest. So the exemption costs no vocabulary.
  'App\\Http\\Requests\\IngestionCallbackRequest':
    'EXEMPT: the inbound frame from the FastAPI data plane on a signature-authenticated internal route. No browser, widget or mobile client ever submits it, and a shared schema would publish the internal wire shape — job_id, sequence, ingest_key, content_hash, the cfg versions — into a package that ships to browsers. Its one shared vocabulary, `error_class`, is pinned against errors.py by test/error-taxonomy-parity.test.ts',

  // OWED, NOT EXEMPT — AND THE INTERESTING PART IS THAT A SCHEMA FOR HALF OF IT ALREADY EXISTS.
  // `uploadSchema` (src/forms/upload.ts) has shipped since before this manifest was dumped, and the
  // question this entry answers is whether it MIRRORS this request. It does not, and the reasons were
  // measured against the real manifest and the real schema rather than argued:
  //
  //   1. THE MANIFEST IS A THREE-ARM BODY AND THE SCHEMA IS ONE ARM. `POST /sources` accepts `file`,
  //      `url` and `text`, discriminated by `type` and enforced by paired `required_if` /
  //      `prohibited_unless` rules on `files`, `origin_url` and `content`. It validates eleven paths;
  //      `schemaPaths(uploadSchema(…))` is exactly `['files', 'files.*']`, so nine are missing and
  //      `the schema declares exactly the fields the FormRequest validates` fails by name on every one
  //      of them. Both repairs that failure invites are wrong: subtracting the nine stops probing
  //      them, and growing `uploadSchema` into the whole body makes the FILE PICKER's schema demand
  //      `name`, `type` and a discriminant it does not render.
  //
  //   2. THE TWO SIZE PROBES ON THE FILE HALF WOULD BOTH BE FALSE REDS. `files`' `max:10` is generated
  //      by the generic array sizer as ten `'a'` STRINGS, which `z.file()` refuses — "form blocks
  //      input the server accepts", against a schema that is exactly right. `files.*`'s `max:25600` is
  //      worse: `kindOf(['bail','file','max:25600'])` is `unknown`, so the sizer emits the NUMBER
  //      25600. Both measured, not reasoned. A `sized` generator could synthesize real `File`s — and
  //      then it would be comparing the wrong quantity, which is (3).
  //
  //   3. THE TWO NUMBERS ARE NOT THE SAME NUMBER. `max:25600` is KILOBYTES, because that is the unit
  //      Laravel's `max:` speaks for an uploaded file, and it is a CONSTANT on the FormRequest
  //      (`StoreSourceRequest::MAX_FILE_KILOBYTES`). `uploadSchema` caps `max_bytes`, in BYTES, from
  //      a per-organization `OrgUploadLimits` the bootstrap config returns — §8.10 makes the cap
  //      configurable, which is why no byte constant may exist in this package at all. A probe would
  //      have to pick one organization's limits and assert the server's platform constant against
  //      them, and the two agree only by coincidence. The server's own docblock names the closure:
  //      the limits endpoint "belongs with the intake" and "must publish THESE constants rather than
  //      a second copy of the numbers". Until it does, there is nothing here to compare.
  //
  // WHAT CLOSES IT, in the order it has to happen: the upload intake and its `OrgUploadLimits`
  // endpoint land; the console gets a create form covering all three arms (or the manifest is split);
  // the schema declares the eleven paths with the discriminated union spelled as a `superRefine`, like
  // every other cross-field rule in this file; and a `sized` generator for `files.*` synthesizes real
  // `File`s at an exact byte length against limits derived from the server's own constants. Then this
  // entry becomes a MIRRORS entry and the hand-written cases in `the upload form: the limits are the
  // organization's, not this package's` keep only what a probe cannot express.
  //
  // A FALSE MIRROR CLAIM WOULD BE WORSE THAN THIS DEFERRAL, which is the whole reason the register has
  // an OWED shape at all: `StoreInvitationRequest` and the two model-catalogue requests all sat here
  // as OWED and all graduated, because holding a to-do inside the assertion is what got them closed
  // rather than forgotten.
  'App\\Http\\Requests\\StoreSourceRequest':
    'OWED, not exempt: `uploadSchema` covers the FILE arm of a three-arm body and declares 2 of the 11 validated paths, and its per-organization byte cap is not comparable to the FormRequest\'s platform constant in kilobytes — measured, see the comment above. It graduates with the upload intake, the OrgUploadLimits endpoint and a create form that renders all three arms',

  // OWED, NOT EXEMPT. This is the bots precedent's mirror image and the difference is the SCREEN:
  // `UpdateBotRequest` is a MIRRORS entry because apps/web renders the bot settings form, and
  // `/sources` is a 45-line placeholder page with no detail screen behind it. `rhf-zod-forms` NN3 is
  // the rule — a schema ships WITH the form that renders it — and this is the case it is about.
  //
  // THE SHAPE IS READY WHEN THE SCREEN IS. It is the same `sometimes` PATCH the bot settings form
  // mirrors: `name`, `description`, `tags`, `tags.*` and the two window fields, plus four paths ruled
  // `missing` (`type`, `status`, `origin_url`, `content`) whose correct client-side spelling is the
  // ABSENCE of a path from a `strictObject` — so the mirror can be written mechanically from the
  // manifest the day the form exists, and the four `missing` paths are exactly the fields a metadata
  // form is most likely to grow by accident. `distinct` on `tags.*` is probed as of this change, so
  // the schema will have to refuse a repeated tag rather than agree with the server by being unprobed.
  'App\\Http\\Requests\\UpdateSourceRequest':
    'OWED, not exempt: the metadata PATCH is an ordinary `sometimes` field form with no credential and no security grammar, and nothing about it resists mirroring — there is simply no form yet. The sources detail screen (apps/web/src/features/sources) is the next batch\'s; the schema ships with it, per rhf-zod-forms NN3',

  // OWED, NOT EXEMPT, and it is the entry where the bots precedent most nearly forces the other
  // answer — so the difference is worth stating rather than assuming.
  //
  // `UpdateBotStatusRequest` is MIRRORED because after `status` left `botSettingsSchema` its `in:`
  // probes became THE ONLY comparison between `BOT_STATUSES` and the server: a five-member tuple that
  // every status pill iterates, pinned nowhere else. That argument does not reach here, because this
  // package declares no source tuple at all. `SourceStatus` is a UNION in src/resources/sources.ts and
  // it is pinned — member for member, against the document's inlined enum, in
  // test/resource-drift.test.ts — so nothing about the vocabulary is unwatched by this exemption.
  //
  // AND THE TWO SETS ARE NOT THE SAME SET, which is why mirroring the wrong one would be worse than
  // waiting: the resource publishes FIFTEEN lifecycle states and this request accepts TWO
  // (`disabled`, `ready`). Every other move belongs to the ingestion pipeline, and a `<Select>` built
  // over the union rather than over the transition set would offer to publish a version nothing
  // verified. That relationship is asserted today — the source-types suite in resource-drift reads
  // this manifest's `in:` members and requires each to be a member of the union — so the transition
  // vocabulary is not unwatched either; it simply has no client spelling to drift against yet.
  'App\\Http\\Requests\\UpdateSourceStatusRequest':
    'OWED, not exempt: the enable/disable control on the sources detail screen (apps/web/src/features/sources) is the next batch\'s and the schema ships with it. Unlike UpdateBotStatusRequest this exemption pins nothing loose — `SourceStatus` is a union pinned against the document in test/resource-drift.test.ts, and this manifest\'s two-member transition set is read from the manifest there rather than restated in TypeScript',

  // ── THE TWO BOT↔SOURCE ASSIGNMENT MANIFESTS: ONE EXEMPT, ONE OWED ───────────────────────────
  //
  // Same split as the source-lifecycle block above and for the same reasons, which is the point of
  // repeating the shape rather than a sign nobody thought about it: the query-string manifest is
  // exempt on the merits and the body manifest is a to-do this suite is holding. The RESPONSE side
  // went the other way in the same change — `BotSourceAssignmentResource` and its collection are
  // MIRRORED in test/resource-drift.test.ts — which is `rhf-zod-forms` NN3 again.

  // EXEMPT ON THE MERITS. THE THIRD QUERY-STRING MANIFEST, and `IndexBotsRequest`'s argument reaches
  // all five of its fields verbatim: they are read out of the URL by `apps/web/src/lib/table/params.ts`,
  // which CLAMPS — a `sort` outside the endpoint's sortable set degrades to the default, a `per_page`
  // outside the declared sizes degrades to the default, a filter past `MAX_FILTER_LENGTH` is truncated
  // — where a Zod mirror would have to REJECT, with no field, no control and no per-field error to key
  // a message to. This harness can say accept or reject and has no vocabulary for "degrades to the
  // default", so a mirror would either report the clamping module as drift or force it to 422 its own
  // users.
  //
  // WHAT THE THIRD ONE SETTLES. `IndexBotsRequest`'s note offered the SORTABLE SET as the one piece
  // worth lifting into this package if a third list endpoint arrived; `IndexSourcesRequest` then
  // measured a second set that differed in one member and concluded the set is per-endpoint after
  // all. This is the third, and it is not a near-miss like the second: `id,priority,enabled` shares
  // exactly ONE member with `id,name,slug,status` and one with `id,name,type,status`, and it is the
  // first that sorts on a BOOLEAN. So the question is closed rather than open — there is no shared
  // vocabulary here to lift, which is the same fact `ListMetaResource.sort` records by being typed
  // `string` rather than a union.
  //
  // AND THE EXEMPTION NOW PINS LESS THAN THE FIRST TWO DID, which is the one thing that changed:
  // test/resource-drift.test.ts reads this manifest's `in:` members and requires each to be a
  // property `BotSourceAssignmentResource` publishes. That is the same move the source-status suite
  // makes on `UpdateSourceStatusRequest`, and it means a server that renamed `priority` while
  // updating its own whitelist fails a suite instead of leaving every table sorting by a column
  // nothing publishes, with a 422 as the only symptom.
  'App\\Http\\Requests\\IndexBotSourceAssignmentsRequest':
    'EXEMPT: the third query-string manifest, not a form — no control, no resolver and no per-field error, and the client CLAMPS every one of these five values (apps/web/src/lib/table/params.ts) where a mirroring schema would have to reject. Its sortable set shares one member with each of the other two and sorts on a boolean, which closes the "lift the sortable set" question the first two left open; the set is compared against the published resource in test/resource-drift.test.ts rather than going unwatched',

  // OWED, NOT EXEMPT — and it is the entry in this list with the LEAST to say against mirroring,
  // which is exactly why the reason has to be stated rather than assumed. There is no credential
  // here, no security grammar, no `@server-only` rule, no cross-field pairing and no rule name this
  // harness cannot probe: `source_id` is `required|string|ulid`, `priority` is
  // `sometimes|integer|min:0|max:9999` and `enabled` is `sometimes|boolean`, and every one of those
  // seven names is in PROBED_RULES today. Nothing about the shape resists a mirror. There is simply
  // no form yet.
  //
  // `rhf-zod-forms` NN3 is the rule — a schema ships WITH the form that renders it — and the
  // alternative was measured rather than waved away: a schema written now would be an unread
  // declaration, and an unread declaration is one whose drift nobody notices, because the only thing
  // that reads a form schema is a resolver. `UpdateSourceRequest` and `UpdateSourceStatusRequest`
  // above are the same call made twice this phase, and both times the form arrived later than the
  // batch that would have written the schema.
  //
  // THE ONE ARGUMENT FOR EXEMPTING IT INSTEAD, CONSIDERED AND REJECTED. `source_id` is PICKED from a
  // list rather than typed, which is the shape `SwitchOrganizationRequest`'s exemption rests on — an
  // id the user chose from data the server sent, with no per-field error to render. But that
  // exemption's actual load-bearing clause is that `organization_id` is banned from every schema in
  // this package by OWNERSHIP_KEYS, and `source_id` is not: it names a sibling row, not the owner of
  // one. And the other two fields are ordinary editable controls — a number input and a switch —
  // whose `min:0|max:9999` and boolean coercion are exactly what a resolver is for. So the analogy
  // reaches one field of three and the exemption does not follow from it.
  //
  // WHAT CLOSES IT: the assignment screen under a bot (`apps/web/src/features/bots`, the next task's)
  // renders the picker, the priority input and the switch; the schema arrives with it as a
  // `z.strictObject` of three paths — `source_id` a ULID string, `priority` the usual `intField`
  // preprocess so a cleared input reads "required" rather than "must be at least 0", `enabled` a
  // boolean — and this entry becomes a MIRRORS entry with a baseline. It can be written mechanically
  // from the manifest the day the form exists, and none of its probes are suppressed in the
  // meantime, so it cannot graduate into agreeing with the server by being unprobed.
  'App\\Http\\Requests\\StoreBotSourceAssignmentRequest':
    'OWED, not exempt: three fields, no credential, no security grammar and no unprobed rule name — nothing about it resists mirroring, there is simply no form yet. The bot\'s source-assignment screen (apps/web/src/features/bots) is the next task\'s and the schema ships with it, per rhf-zod-forms NN3. The SwitchOrganizationRequest analogy for `source_id` was considered and does not reach: that exemption rests on OWNERSHIP_KEYS, and `priority`/`enabled` are ordinary controls with per-field errors',

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

/**
 * No client can evaluate these: they need a database or a request context. Present, value-exempt.
 *
 * `current_password:web` is the third member and the least obvious one. It resolves the named guard,
 * pulls the authenticated user and compares a hash — three things a browser has none of — so no probe
 * against it could be anything but a guess. It is the §18.3 re-authentication on
 * `RotateProviderCredentialRequest`, and the client's whole job is to RENDER the field and key the
 * server's 422 to it; a schema that "validated" the actor's password would be validating that the
 * string is non-empty and implying more.
 */
const SERVER_ONLY = new Set(['exists', 'unique', '@server-only', 'current_password']);

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
 * `missing` is the rule that says "this key may not appear in the body at all", and the client-side
 * mirror of it is not a field rule either — it is the ABSENCE of a path from a `strictObject`. So it
 * drives the presence probes the way `sometimes` does rather than generating a value probe, and it
 * changes two things about how a manifest is read.
 *
 * ── IT USED TO BE `prohibited`, AND THE RENAME IS THE WHOLE REASON THIS NOTE MOVED ──────────────
 * `Illuminate\Validation\Concerns\ValidatesAttributes::validateProhibited` is literally
 * `! validateRequired`, so it means "missing OR EMPTY": measured against the installed factory,
 * `{status: null}`, `{status: ""}` and `{status: []}` all PASS, and `validated()` KEEPS the key.
 * That reached a `NOT NULL` column and turned an accompanying rename into a lost edit behind a 500.
 * `validateMissing` is `! Arr::has($this->data, $attribute)` — the key itself, present or not — so
 * the same four probes now read: omitted PASSES, and null, `""` and `[]` all FAIL. The server rules
 * `'status' => ['missing']` and this harness follows the rule NAME rather than the shape it used to
 * have.
 *
 * ── WHAT IT IS FOR, IN THE ONE CASE THIS REPO HAS ───────────────────────────────────────────────
 * `UpdateBotRequest.status` is `["missing"]` because a lifecycle move became `PUT
 * …/bots/{bot}/status`. The rule is there rather than the field simply being deleted from `rules()`,
 * and the difference is the whole point: an ABSENT rule makes `validated()` discard the key in
 * silence, so a console would publish a bot, get a 200, and find it still in draft. `missing` turns
 * that into a 422.
 *
 * ── THE THREE PROBES IT GENERATES, AND WHAT EACH ONE ACTUALLY CATCHES ───────────────────────────
 * An omission is ACCEPTED; a non-empty value is REJECTED; an explicit null is REJECTED. All three
 * are true of a schema that does not declare the path.
 *
 * THE NULL PROBE IS NEW WITH THE RENAME AND IS NOT DECORATION. Under `prohibited` it had to be
 * SUPPRESSED — the server accepted null there, so the probe would have claimed the faithful mirror
 * "blocks input the server accepts" and invited the re-declaration this rule exists to prevent.
 * Under `missing` the server rejects it, so the probe is honest, and it reaches a re-declaration the
 * value probe cannot: `z.enum(BOT_STATUSES).nullable().optional()` accepts null and is caught here.
 *
 * THE VALUE PROBE IS THE WEAKEST OF THE THREE AND THE LIMIT WAS MEASURED, NOT ASSUMED. `MISSING_VALUE`
 * is a string this harness invents, so it catches a re-declaration typed loosely enough to accept one
 * (`z.string().optional()`, `z.unknown()`) and NOT a re-declaration typed as the real vocabulary —
 * `z.enum(BOT_STATUSES).optional()` refuses `'__missing__'` too, so both sides "agree" and the probe
 * is silent. Reaching that case would mean the harness knowing the field's legal values, which live
 * in a DIFFERENT manifest (`UpdateBotStatusRequest`), and a cross-manifest probe generator is a
 * second harness.
 *
 * What closes it is not a probe at all: `the schema declares exactly the fields the FormRequest
 * validates` subtracts these paths from the manifest side, so ANY declaration of the path — of any
 * type — is a superset and fails by name. The omitted probe closes the other repair that failure
 * invites, which is to declare the path as REQUIRED. All five cases are proved against fixtures in
 * `the `missing` branch of the rule classifier` below, because "the probe was silent" and "the probe
 * passed" are indistinguishable in the output.
 *
 * ── AND THE IMPLICIT-RULE DIFFERENCE, WHICH CHANGES WHY THE OMITTED PROBE PASSES ────────────────
 * `Missing` is in `Validator::$implicitRules` and `Prohibited` was not. Under the old rule an
 * omission passed because a non-implicit rule is SKIPPED entirely for an absent key; under this one
 * it passes because the rule RAN and returned true. The verdict is the same and the reason is not,
 * which matters the day someone writes `sometimes|missing`: `sometimes` short-circuits an absent
 * key before any rule runs, so it would make the whole declaration inert. `UpdateBotRequest` rules
 * this field `["missing"]` alone, and it must stay alone.
 */
const MISSING = 'missing';

/**
 * A value `missing` really does refuse. Any value would do — `validateMissing` looks at the KEY and
 * never at what it holds, so `null` and `""` are rejected by it exactly as this string is.
 *
 * A STRING even on a field the manifest gives no type for. `missing` short-circuits before any type
 * rule, so the server's verdict is the same for every value — and the client's is too, since a
 * `strictObject` rejects an undeclared KEY whatever it holds.
 */
const MISSING_VALUE = '__missing__';

/** The paths a manifest forbids the caller from sending at all. */
const missingPaths = (manifest: Manifest): ReadonlySet<string> =>
  new Set(
    Object.entries(manifest.rules)
      .filter(([, rules]) => rules.map(nameOf).includes(MISSING))
      .map(([path]) => path),
  );

/**
 * The paths a manifest VALIDATES, which is its key set minus the ones it rules `missing` — and
 * therefore the set a mirroring schema must declare exactly.
 *
 * A function rather than an inline filter so the fixture block below can prove the subtraction has
 * teeth against a manifest of its own, the same way `driftFailures` is a function so the tampering
 * test can prove the probes do.
 */
const validatedPaths = (manifest: Manifest): readonly string[] => {
  const forbidden = missingPaths(manifest);

  return Object.keys(manifest.rules).filter((path) => !forbidden.has(path));
};

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

  /**
   * `supported.*` — Laravel's per-ELEMENT rules, and the first manifest key in this repo that does
   * not name a property.
   *
   * Writing `cursor['*'] = value` is what the generic branch below would do, and it is silently
   * wrong in the direction that produces a FALSE FAILURE: setting a `'*'` property on an ARRAY leaves
   * every element untouched, `z.array(...)` ignores it entirely, and the `null` probe — which claims
   * the server rejects a null element — would report "form accepts input the server rejects" against
   * a schema that is exactly right. The obvious repair is to loosen the element schema.
   *
   * So the probe REPLACES THE ARRAY with a one-element array holding the value under test, which is
   * what "this element rule sees this value" means. `OMITTED` becomes the EMPTY array: `supported`
   * carries no `min:`, so a list with no elements runs no element rule at all and the server accepts
   * it — which is the honest reading of "omit this element".
   */
  if (leaf === '*') {
    const elements = cursor as unknown as unknown[];
    elements.length = 0;
    if (value !== OMITTED) elements.push(value);
    return;
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

/** Reads a dotted path out of a candidate body. The inverse of `setPath`, minus the `.*` segment. */
const readPath = (source: Candidate, path: string): unknown =>
  path
    .split('.')
    .reduce<unknown>(
      (cursor, segment) => (cursor as Record<string, unknown> | undefined)?.[segment],
      source,
    );

/**
 * THE ONE PROBE IN THIS FILE THAT DOES NOT MUTATE A SINGLE VALUE, and `distinct` is the reason.
 *
 * Every other probe here is one path set to one value, because every other rule decides on the value
 * in front of it. `distinct` decides on the RELATIONSHIP BETWEEN TWO ELEMENTS of the same array, and
 * `setPath`'s `.*` branch — which replaces the array with a ONE-element list holding the value under
 * test — can never express it: a single element is distinct by construction, so the generic
 * vocabulary would emit a probe that asserts nothing, which is the silent variant of not probing at
 * all.
 *
 * So this writes the ARRAY: the same element twice.
 *
 * THE ELEMENT COMES FROM THE MIRROR'S BASELINE, and that is what makes the rejection claim honest
 * rather than merely plausible. The baseline is asserted to be a body the SERVER accepts before any
 * probe runs, so its first tag/flag/whatever satisfies every element rule on the field — `min:1`,
 * `max:64`, a regex, all of them. The ONLY thing that changes between the baseline and this probe is
 * that the element appears twice, so the only rule that can produce the rejection is `distinct`. A
 * synthesized element (`'a'`, or a sizer value) would risk a probe that "passes" because the value
 * violated something else entirely, which is a green with no meaning.
 *
 * IT DECLINES rather than guessing when the baseline's array is empty or absent — there is no
 * element to duplicate, and `[undefined, undefined]` is a different claim about a different rule.
 * That declination is not silent in the way `sizerFor`'s is: `distinct` only ever appears on a `.*`
 * path, whose parent array a baseline must populate anyway for the element rules to be probed at all,
 * so an empty one already fails `no size rule in a mirrored manifest lost its probe`.
 */
const duplicateElementProbe = (path: string, mirror: Mirror): Probe | undefined => {
  if (!path.endsWith('.*')) return undefined;

  const arrayPath = path.slice(0, -'.*'.length);
  const baseline = readPath(mirror.baseline(), arrayPath);
  if (!Array.isArray(baseline) || baseline.length === 0) return undefined;

  const element: unknown = baseline[0];

  return {
    label: 'distinct: the same element twice',
    apply: (base) => {
      const next = structuredClone(base);
      setPath(next, arrayPath, [element, element]);

      return next;
    },
    serverAccepts: false,
  };
};

/** `max:`/`min:` mean length, count or magnitude depending on the field's declared type. */
type Kind = 'string' | 'array' | 'number' | 'unknown';

const kindOf = (rules: readonly string[]): Kind => {
  const names = new Set(rules.map(nameOf));
  if (names.has('array')) return 'array';
  if (names.has('integer') || names.has('numeric')) return 'number';
  if (names.has('string')) return 'string';
  return 'unknown';
};

/**
 * THE ARRAY FILLER IS UNIQUE PER ELEMENT, and it was `'a'` repeated until `distinct` arrived.
 *
 * An `n`-element list of identical values is not a value the server accepts when the field's ELEMENT
 * rule says `distinct` — `tags: max:50` with `tags.*: …|distinct` is the real shape — so the `max:`
 * boundary probe, which claims acceptance, was making a claim the server refuses. The failure it
 * produces is a FALSE RED on a correct schema ("form blocks input the server accepts: tags"), and the
 * repair it invites is to delete the uniqueness refinement from the mirror, which is the rule.
 *
 * Fixing it HERE rather than with a per-field `sized` override is deliberate. `probesFor` is handed
 * one path's rule list and cannot see `tags.*` while probing `tags`, so a harness-level "is the
 * element distinct?" branch would be the sibling-aware generator this file does not have; and an
 * override would have to be remembered by every future mirror of an array whose elements are
 * distinct, with a false red as the only reminder. A unique filler is honest for BOTH cases: no rule
 * in Laravel demands that an array's elements REPEAT, so uniqueness can never be the reason a server
 * rejects a probe.
 *
 * `a${index}` rather than `'a'.repeat(index)`, because element rules are usually `max:`-bounded and a
 * filler whose length grows with the index would blow one at element 65. The only element pattern in
 * the tree today is `^[a-z][a-z0-9_]*$` (`supported.*`), which `a0`…`a19` satisfies.
 */
const sized = (kind: Kind, size: number): unknown => {
  if (kind === 'string') return 'a'.repeat(size);
  if (kind === 'array') return Array.from({ length: size }, (_, index) => `a${index}`);
  return size;
};

/**
 * Rules that constrain a value's FORM rather than its size. A `'a'.repeat(n)` satisfies none of them,
 * so on a field carrying one of these AND a `min:`/`max:`, the generic sizer produces a value the
 * SERVER rejects while the probe claims the server accepts it — see `sizerFor`.
 */
const FORMAT_RULES = new Set([
  ...VALUE_EXEMPT,
  'regex',
  /**
   * `file` IS A FORMAT RULE WHOSE FORM IS AN OBJECT, and its co-declared `max:` is the worst
   * collision in this set because `kindOf` cannot even see it: `['bail','file','max:25600']` names no
   * type rule, so the kind is `unknown` and the generic sizer emits the NUMBER 25600 as the probe
   * value for a field that must hold a `File`. The probe would then claim the server accepts a bare
   * integer where it accepts a 25 MB upload — a false red on a correct schema, and the harder failure
   * to diagnose.
   *
   * There is no field carrying it in any MIRRORED manifest today: `StoreSourceRequest` is
   * NO_CLIENT_FORM, so `sizerFor` never runs on it. This entry is for the day it graduates — with it
   * the probe is SUPPRESSED and `missingSizeProbes()` reports `files.* · max:25600 boundary` by name,
   * which forces the `sized` generator that is the only honest answer: real `File` objects at an
   * exact byte length, built from limits derived from the server's own constants rather than from a
   * number this file picked. See the OWED entry in NO_CLIENT_FORM, and `UNPROBED_RULES.file` for why
   * the format verdict itself cannot be probed at all.
   */
  'file',
  /**
   * A RULE OBJECT, listed by the class name `kb:dump-form-rules` records it under, and the reason it
   * belongs in this set is that it is a format rule wearing a different spelling: it demands a
   * well-formed `oklch()` triple that can be given readable text, and `'a'.repeat(64)` is neither.
   *
   * Both fields carrying it already declare a `sized` generator in their Mirror, so `sizerFor`
   * returns that first and this membership changes nothing today. It is here for the NEXT field: a
   * manifest that grows this rule without an override would otherwise get the generic sizer, whose
   * boundary probe claims an acceptance the server does not give — a false red on a correct schema,
   * which is the harder failure to diagnose. With this entry the probe is suppressed instead and
   * `missingSizeProbes()` reports it by name.
   *
   * `App\Rules\EvidenceThresholdWithinScale` is deliberately NOT here. It constrains a number's
   * RANGE against a sibling, not its form, and the generic numeric sizer answers its co-declared
   * `min:-100`/`max:100` honestly for a `logit` baseline — see the MIRRORS note.
   */
  'App\\Rules\\ReadableThemeColor',
  /**
   * The second rule OBJECT in this set, and it belongs here for the same reason with a sharper edge:
   * it demands a full RFC 6454 origin — scheme, host, optional port, no path, no wildcard, no
   * userinfo — and `'a'.repeat(255)` is refused by its very first check. Measured, not assumed:
   * `ExactOrigin::parse('a'.repeat(255))` returns "An origin starts with `http://` or `https://`".
   *
   * The one field carrying it declares `originOfLength` in its Mirror, so `sizerFor` returns that
   * first and this membership changes nothing today. It is here for the next field to grow the rule:
   * without it the generic sizer would claim a `max:` acceptance the server does not give, which is
   * a false red on a correct schema and the harder failure to diagnose. With it the probe is
   * suppressed instead and `missingSizeProbes()` reports it by name.
   */
  'App\\Rules\\ExactWidgetOrigin',
]);

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
    if (names.includes(MISSING)) {
      // All three presence probes, INCLUDING the null one, which this branch generates itself rather
      // than leaving to the generic line below — see the note on MISSING. The generic one reads
      // `nullable`, and a `missing|nullable` co-declaration (nonsense, but expressible) would flip it
      // into claiming an acceptance the server does not make; here the verdict comes from the rule
      // that actually decides, which looks at the KEY and never at the value.
      //
      // `here` rather than `value`: `confirmed` never co-occurs with this rule, and a field the
      // caller may not send has no `_confirmation` sibling to keep in step.
      probes.push(probe(here, 'omitted (missing)', OMITTED, true));
      probes.push(probe(here, 'missing: a key the caller may not send', MISSING_VALUE, false));
      probes.push(probe(here, 'missing: an explicit null is still a present key', null, false));
    } else if (names.includes(SOMETIMES)) {
      // Omission is accepted UNCONDITIONALLY: `sometimes` skips every remaining rule, so a
      // co-declared `required` never runs. See the note on SOMETIMES above.
      probes.push(probe(here, 'omitted (sometimes)', OMITTED, true));
    } else if (names.includes('required') || names.includes('present')) {
      probes.push(probe(here, 'omitted', OMITTED, false));
    } else {
      probes.push(probe(here, 'omitted', OMITTED, true));
    }

    // An explicit null is PRESENT, so `sometimes` does not fire and the verdict is unchanged:
    // accepted only if the server said `nullable`. A `missing` field already generated its own null
    // probe above, where the verdict is the rule's rather than `nullable`'s.
    if (!names.includes(MISSING)) {
      probes.push(probe(here, 'null', null, names.includes('nullable')));
    }
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

      case 'numeric':
        // Laravel's `numeric` accepts numeric STRINGS ("1.5" passes), so the rejection probe has to be
        // a string that is not a number rather than a string at all.
        probes.push(probe(value, 'a non-numeric string where a number is required', 'not-a-number', false));
        break;

      case 'decimal': {
        // `decimal:2` means EXACTLY two places; `decimal:0,6` means between none and six. Both forms
        // appear in Laravel and only the second is in this repo today, so the parse handles both and
        // the probes are built off the upper bound either way.
        const [first, second] = argOf(rule).split(',');
        const max = Number(second ?? first);
        if (Number.isFinite(max)) {
          // A magnitude of 1 keeps every co-declared `min:`/`max:` satisfied, so the only thing under
          // test is the SCALE. JSON.stringify of a JS number prints its shortest round-trip form, which
          // is what Laravel then counts the places of.
          probes.push(probe(value, `decimal:${max} places`, Number(`1.${'1'.repeat(max)}`), true));
          probes.push(
            probe(value, `decimal:${max} + 1 places`, Number(`1.${'1'.repeat(max + 1)}`), false),
          );
        }
        break;
      }

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

      /**
       * TAUGHT RATHER THAN EXEMPTED, and taught in ONE DIRECTION ONLY — which is a first for this
       * switch and is the honest shape rather than a half-measure.
       *
       * THE REJECTION PROBES ARE UNCONDITIONAL AND THEY ARE THE POINT. `validateDate` is
       * `strtotime() !== false` followed by `checkdate()` on the parsed parts, so both values below
       * are refused whatever else the field declares, and they catch the two schemas a date field
       * actually drifts into: a bare `z.string()`, which takes `'not-a-date'`, and a hand-rolled
       * `/^\d{4}-\d{2}-\d{2}$/`, which takes the thirtieth of February. Nothing else in this harness
       * would notice either — the field's other rules are `sometimes` and `nullable`, whose probes a
       * plain string field passes.
       *
       * THE ACCEPTANCE DIRECTION IS DELIBERATELY LEFT TO THE MIRROR'S BASELINE, because there is no
       * canonical value to claim. Laravel's `date` is `strtotime`, which accepts `'tomorrow'`,
       * `'+1 week'` and `'1 January 2026'`; no client schema will ever take those and none should, so
       * a `serverAccepts: true` probe would have to pick ONE ISO spelling — date-only or a full
       * offset datetime — and assert it against a mirror whose picker emits the other. That is a
       * false red on a correct schema, produced by this file's own choice rather than by any
       * disagreement with the server. The baseline covers the direction properly: it is asserted to
       * parse before any probe runs, and it carries whatever format that form really submits.
       *
       * That leaves `date`'s acceptance edge — a value the server takes and the client refuses —
       * uncovered on purpose, and it is the tolerable direction here for the same reason
       * `ReadableThemeColor`'s residual is: the consequence is a 422 keyed to the field, rendering the
       * server's own sentence, on a value nobody types into a date picker.
       */
      case 'date':
        probes.push(probe(value, 'a string strtotime cannot parse', 'not-a-date', false));
        // `strtotime` PARSES this one — it is `checkdate` that refuses it — which is why a mirror
        // built from a shape regex agrees with the first probe and fails this one.
        probes.push(probe(value, 'a well-shaped date that does not exist', '2026-02-30', false));
        break;

      /**
       * TAUGHT RATHER THAN EXEMPTED, and it needed a probe generator of a different shape to be
       * taught at all — see `duplicateElementProbe`, which writes the ARRAY rather than one element
       * because a one-element list is distinct by construction.
       *
       * The alternative was an UNPROBED_RULES entry, and the reason it lost is the one that map's own
       * `numeric`/`decimal` note gives: an entry there is silent forever, and the field carrying this
       * rule (`tags.*`) belongs to a request that is OWED rather than exempt. A suppressed `distinct`
       * would let the tag input that ships next batch "agree" with the server by being unprobed, and
       * the symptom — a duplicate tag accepted locally and 422'd on arrival, keyed `tags.3` — is
       * precisely what this file exists to catch before it is written.
       *
       * It generates NOTHING today, because no MIRRORED manifest carries the rule. Proved rather than
       * asserted: `the `date` and `distinct` branches of the rule classifier` below runs it against a
       * fixture manifest in both directions, since "the probe was silent" and "the probe passed" are
       * indistinguishable in the output.
       */
      case 'distinct': {
        const duplicate = duplicateElementProbe(path, mirror);
        if (duplicate !== undefined) probes.push(duplicate);
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

/**
 * Every leaf path a schema declares, dotted, so it can be set-compared with the manifest's keys.
 *
 * ── THE ARRAY BRANCH, AND WHY IT IS NOT OPTIONAL ────────────────────────────────────────────────
 * Laravel keys per-element rules with a `.*` segment (`supported.*: string|max:64`), so a manifest
 * for a request with an array field has ONE MORE KEY than the schema has properties. Without this
 * branch the `schema declares exactly the fields the FormRequest validates` assertion fails on a
 * correct schema — and the two repairs it invites are both wrong: subtract `.*` keys from the
 * manifest side (which silently drops the element rules from `driftFailures` too, so `max:64` stops
 * being checked at all), or add a `'supported.*'` property to the schema (which is not a schema).
 *
 * An array contributes BOTH paths, exactly as the manifest does: `supported` carries the count rules
 * and `supported.*` carries the element rules, and they are different questions.
 *
 * `.element` rather than a `def` walk because that is ZodArray's public accessor and it survives
 * `.max()` (which returns a new ZodArray carrying the same element).
 *
 * ── THE UNWRAPPING LOOP, AND THE DAY THIS FILE SAID IT WOULD BE NEEDED ──────────────────────────
 * This docblock used to end "`ZodOptional`/`ZodNullable` wrappers expose neither `.shape` nor
 * `.element`, so a wrapped array reads as a leaf — no manifest in this repo has one, and the day one
 * does, this assertion goes red naming the field rather than passing with the element rules
 * unprobed." That day is the bot manifests: `theme` is `sometimes`, so its mirror is an OPTIONAL
 * object, and read as a leaf it contributes one path where the manifest declares four.
 *
 * The red was the design working; the repair is unwrapping rather than either of the two the failure
 * invites. Subtracting the `theme.*` keys from the manifest side would stop probing the colour rules
 * altogether, and making `theme` mandatory to keep it unwrapped would be a form that cannot save a
 * bot without re-sending its appearance.
 *
 * ONLY `ZodOptional` AND `ZodNullable`. `ZodDefault` also has `.unwrap()` and is deliberately absent:
 * nothing in this package uses `.default()`, and if something starts to, this assertion goes red
 * naming the field — which is the same property the paragraph above is a record of.
 *
 * ── A NESTED OBJECT CONTRIBUTES ITS OWN PATH TOO ────────────────────────────────────────────────
 * Exactly as an array does, and for the same reason: Laravel dumps `theme` (carrying
 * `array:primary,accent,radius`) and `theme.primary` as separate keys with different rules, so a
 * schema that produced only the leaves would be missing one. The ROOT object is the exception —
 * `prefix === ''` names nothing — which is why the branch is conditional rather than unconditional.
 */
function schemaPaths(schema: z.ZodType, prefix = ''): string[] {
  let node: z.ZodType = schema;
  while (node instanceof z.ZodOptional || node instanceof z.ZodNullable) {
    node = node.unwrap() as z.ZodType;
  }

  const shape = (node as unknown as { shape?: Record<string, z.ZodType> }).shape;
  if (shape) {
    const children = Object.entries(shape).flatMap(([key, child]) =>
      schemaPaths(child, prefix === '' ? key : `${prefix}.${key}`),
    );

    return prefix === '' ? children : [prefix, ...children];
  }

  const element = (node as unknown as { element?: z.ZodType }).element;
  if (element !== undefined && prefix !== '') {
    return [prefix, ...schemaPaths(element, `${prefix}.*`)];
  }

  return prefix === '' ? [] : [prefix];
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
      /**
       * `missing` PATHS ARE SUBTRACTED FROM THE MANIFEST SIDE, and that is a strengthening rather
       * than an exemption.
       *
       * `missing` is the one rule whose correct mirror is the ABSENCE of a path: the server says
       * "you may not send this key", and a `strictObject` says the same thing by not declaring it.
       * Compared against the raw key set, a correct schema fails here and the repair the failure
       * invites is to declare the field — which is precisely the body the server now answers 422 to.
       *
       * Subtracting does not weaken the comparison, because the set stays CLOSED IN BOTH DIRECTIONS:
       * a schema that declares one of these paths is now a SUPERSET and fails here. That is not a
       * duplicate of the `missing` probes — it is the check that catches the case they cannot,
       * because a path re-declared with its real vocabulary (`z.enum(BOT_STATUSES).optional()`)
       * refuses the harness's invented probe value and both sides silently agree. See the note on
       * MISSING, and the fixture block that proves the boundary between the two.
       */
      const forbidden = missingPaths(manifest);

      expect(new Set(schemaPaths(mirror.schema))).toEqual(new Set(validatedPaths(manifest)));

      // Named separately from the comparison above, because "the schema grew a field" and "the
      // schema declares a field the server forbids" are the same red with very different repairs.
      expect(
        schemaPaths(mirror.schema).filter((path) => forbidden.has(path)),
        `${manifest.class}: a path ruled \`missing\` may not be declared by any schema`,
      ).toEqual([]);
    });

    it('client and server answer every probe the same way', () => {
      expect(driftFailures(manifest, mirror)).toEqual([]);
    });

    /**
     * TWO TAMPERINGS, NOT ONE, AND THE SECOND ARRIVED WITH A MANIFEST THAT HAS NO `max:` AT ALL.
     *
     * This test used to knock every `max:` down by one, which is a one-character change that must
     * produce a failure on any mirror worth having. `UpdateBotStatusRequest` and
     * `UpdateBotDomainRequest` are `bail|required|string|in:…` and carry no size rule, so on those
     * two the "tampered" manifest was byte-identical to the real one and the assertion would have
     * failed for the right reason with entirely the wrong message: not "this mirror has no teeth"
     * but "this test cannot bite this shape".
     *
     * The second tampering ADDS one character to the last `in:` member. Adding rather than removing
     * is the direction that works: `probesFor` generates one probe per member the manifest DECLARES,
     * so dropping a member deletes its probe and produces no failure at all, while a bogus member
     * generates a probe claiming the server accepts a value the mirror's `z.enum` refuses.
     *
     * Both are applied to every manifest. A mirror with both kinds of rule simply fails twice, which
     * costs nothing; a manifest with NEITHER still fails this assertion by producing no failures at
     * all, which is the honest report for a mirror nothing in this file can hold to account.
     */
    it('the probe harness fails on a deliberately altered rule', () => {
      const rules = Object.fromEntries(
        Object.entries(manifest.rules).map(([path, list]) => [
          path,
          list.map((rule) => {
            if (rule.startsWith('max:')) return `max:${Number(argOf(rule)) - 1}`;
            if (!rule.startsWith('in:')) return rule;

            // `in:"a","b"` — quoted members. Re-quote the mutated one so the probe's own
            // quote-stripping sees the shape it expects rather than a mangled string that would
            // "fail" for a reason nobody chose.
            const members = argOf(rule).split(',');
            const last = (members.pop() as string).replace(/^"|"$/g, '');

            return `in:${[...members, `"${last}x"`].join(',')}`;
          }),
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
    not_regex:
      'the negative form of `regex`, and unprobeable for the same reason plus one: a REJECTION probe would have to synthesize a value that MATCHES an arbitrary pattern. The only `not_regex` in the tree guards the masked display string (`^…`) on the two credential requests, neither of which has a client schema at all — and the client-side property that matters there is structural rather than validated: no type in this package puts `masked_key` and `credential` in one shape, so there is nothing to seed the input from. See NO_CLIENT_FORM',
    /**
     * ── THE TWO RULE OBJECTS, WHICH ARE THE FIRST NON-STRING RULES THIS FILE HAS SEEN ────────────
     *
     * `kb:dump-form-rules` records a rule OBJECT by class name and a closure as the bare string
     * `Closure`, and both server classes say in their own docblocks that they are objects rather than
     * closures FOR THIS FILE — a class name is something a generated client and this harness can key
     * on, where a closure is "a rule no client can be generated from". So an entry here is the answer
     * they were written to make possible, and it has to be a real answer.
     *
     * Both entries are narrower than they look: neither says "cannot be checked", both say "cannot be
     * PROBED GENERICALLY", and each names where the check actually lives instead.
     */
    'App\\Rules\\EvidenceThresholdWithinScale':
      'a DataAwareRule: the bound is [0, 1] on a bounded scale and unbounded on `logit`, so the verdict depends on the VALUE of `evidence_threshold_scale` rather than on this field alone. `probesFor` builds every probe from one field\'s rule list and has no vocabulary for "accepted with this sibling, rejected with that one" — the CROSS_FIELD set is the nearest thing and it only SUPPRESSES presence probes, which are already suppressed here by the mutual `required_with`. Teaching a generic probe would mean synthesizing a second field per rule, which is the sibling-aware generator this harness deliberately does not have. IT IS MIRRORED ANYWAY (`thresholdWithinScale` in src/forms/bot.ts) and asserted BY HAND in the bot cross-field section below, in both directions and on both schemas — an entry here suppresses the PROBE, not the check',
    'App\\Rules\\ReadableThemeColor':
      'two rules in one object, and neither can be probed. The GRAMMAR half needs a generator for an arbitrary pattern, which is `regex`\'s reason one line above. The CONTRAST half needs a value that is a legal `oklch()` triple AND lands in the band where neither platform foreground clears 4.5:1 — synthesizing one means implementing CSS Color 4 §13.2 gamut mapping and WCAG relative luminance inside this file, which is a second copy of `App\\Support\\Theme\\OklchColor` and would be asserting its own arithmetic. THE SCHEMA DOES NOT MIRROR THIS RULE EITHER, which is the residual and is stated in src/forms/bot.ts: the grammar already exists twice on purpose (apps/web/src/lib/color.ts at render time, OklchColor at write time, held together by tests/Contract/ThemeGrammarParityTest.php), a third spelling here would be the one that parity test does not read, and the console composes its field check from the copy that IS watched. The consequence is bounded and is the tolerable direction: an unreadable-but-legal colour submits and comes back a 422 keyed to `theme.primary`. What IS probed is the co-declared `max:64`, through the `themeColorOfLength` generator, whose acceptance claim was measured against the installed PHP rule rather than reasoned about',

    'App\\Rules\\ExactWidgetOrigin':
      'the widget origin grammar, and the entry that comes closest to the line this map draws — because a probe for it COULD be written and would be a second implementation of a security control. `App\\Support\\Web\\ExactOrigin` refuses a wildcard, a path, a query, a fragment, userinfo, an IPv6 literal, a non-ASCII host, `:0` and `:00443`, each with its own sentence, and NORMALISES what it accepts (case folded, default port dropped, one trailing slash dropped). Generating a rejection probe means picking one of those refusals and asserting the client reproduces it; the client deliberately reproduces NONE of them, for the reason src/forms/bot-domain.ts argues at length — a third spelling of a control whose refusals are its content is the copy nothing compares to the other two, and it fails in the bad direction, refusing an origin the operator really can embed on with no 422 to explain it. So the residual is exactly the theme-colour one: an origin that is malformed, wildcarded or pathed submits and comes back a 422 keyed `origin` carrying the server’s own sentence, which `ExactOrigin::parse()` returns precisely so a form can render it. WHAT IS PROBED ANYWAY is the co-declared `max:255`, through `originOfLength`, whose acceptance at 255 and rejection at 256 were MEASURED against the installed PHP rather than reasoned about — and the boundary of the decision is asserted by hand in the widget-origin section below, so the gap stays the gap that was argued for rather than widening into "the client checks nothing about an origin"',

    /**
     * THE ONE RULE IN THIS MAP THAT IS UNPROBEABLE BECAUSE OF THE TRANSPORT rather than because of a
     * pattern, a sibling or a second implementation — and the only one where "teach it instead" is
     * not a choice this file gets to make.
     *
     * `validateFile` requires an `Illuminate\Http\UploadedFile`, which exists only because PHP's
     * multipart machinery wrote a temporary file and `is_uploaded_file` agreed. There is no JSON
     * value of any shape that satisfies it: the request is `multipart/form-data`, and every probe in
     * this harness is a value inside a candidate BODY that both sides parse. Synthesizing one would
     * mean building a multipart request and a PHP interpreter to receive it, which is not a probe —
     * it is the endpoint's Feature test, and `services/core-api` has it.
     *
     * WHAT THE EXEMPTION DOES NOT COVER, said out loud because an entry here is silent forever. The
     * client's half of "is this a file" is `z.file()`, which is `instanceof File` — a check with no
     * server counterpart it could disagree with, since the server's question is about a transport
     * artifact and the client's is about a JavaScript object. The rules that DO have two comparable
     * spellings are the size cap and the MIME list, and both are asserted by hand in `the upload
     * form: the limits are the organization's, not this package's`, holding one `File` fixed and
     * varying the limits DTO. The co-declared `max:` is separately protected by `file`'s membership
     * in FORMAT_RULES, which suppresses the generic sizer's dishonest probe and makes
     * `missingSizeProbes()` name the field the day the request is mirrored.
     */
    file: 'an uploaded-file rule, and the only one here that no JSON probe can reach: `validateFile` demands an `UploadedFile` that PHP\'s multipart machinery produced and `is_uploaded_file` vouched for, while every probe in this harness is a value inside a body both sides parse. The client\'s `z.file()` is an `instanceof File` check with no comparable server spelling; the two rules that DO have one — the size cap and the MIME allow-list — are asserted by hand in the upload-form suite below, and the co-declared `max:` is suppressed rather than faked by `file`\'s membership in FORMAT_RULES',

    size: 'the `size:` fields are the 64-hex invitation/verification token and `price_currency`\'s `size:3`, and NEITHER can be probed generically. The token: registerSchema mirrors it DELIBERATELY LOOSER (src/forms/auth.ts), because a wrong-LENGTH token must reach the server and come back as the byte-identical "no longer valid" refusal rather than being rejected locally by a check that tells its holder the token is the wrong SHAPE — probing it would report that decision as drift. `price_currency` NOW HAS A MIRROR (providerModelCreateSchema/providerModelEditSchema) and is still unprobed, which is a narrower claim than the one that used to stand here: `size:3` is co-declared with `regex:/^[A-Z]{3}$/`, so the only honest acceptance value at length 3 is a three-letter UPPER-CASE code and the only honest rejection is a value of another length that also matches nothing — teaching `probesFor` a `size` case to reach it would apply that case to the four token manifests too, where the deliberate looseness above would then read as drift. The schema mirrors both halves as one regex and the cross-field section asserts it by hand',
  };

  /** Rule names `probesFor` generates a probe from, by switch case or by driving the presence pair. */
  const PROBED_RULES = new Set([
    'string',
    'integer',
    // Both arrived with the model-catalogue manifests, whose requests are NO_CLIENT_FORM — so
    // `probesFor` never runs on them today. They are TAUGHT rather than listed as UNPROBED anyway,
    // because an entry in UNPROBED_RULES is silent forever: the day A4a mirrors those requests, a
    // suppressed `decimal:0,6` would let a schema with no scale check "agree" with the server, which is
    // the false green this file exists to prevent.
    'numeric',
    'decimal',
    'array',
    'boolean',
    'max',
    'min',
    'in',
    /**
     * BOTH ARRIVED WITH THE SOURCE MANIFESTS, whose requests are all NO_CLIENT_FORM — so `probesFor`
     * never runs on them today, exactly as `numeric` and `decimal` did not when they were taught.
     * They are taught for the same reason and it is sharper here: three of those five manifests are
     * OWED rather than exempt, so the forms that carry these rules are a batch away rather than
     * hypothetical, and a rule suppressed now is a rule nobody re-examines when the schema lands.
     *
     * `date` is taught in ONE DIRECTION and `distinct` needed a probe generator of a different shape;
     * both are argued at their `case` in `probesFor` and proved against fixtures below.
     */
    'date',
    'distinct',
    'ulid',
    'required',
    'present',
    'nullable',
    SOMETIMES,
    /**
     * TAUGHT RATHER THAN EXEMPTED, and the choice was a real one: an entry in UNPROBED_RULES is
     * silent forever, and `missing` is the rule that says "a client sending this key gets a 422" —
     * precisely the thing a drift suite exists to catch a schema forgetting. It drives all three
     * presence probes (see the note on MISSING), so a schema that re-declares one of these paths
     * fails on a value probe as well as on the path-set comparison.
     *
     * It replaced `prohibited` here, and the replacement was not a rename in this file alone: the
     * old rule accepted a null the new one refuses, so the branch it drives generates a probe it
     * used to suppress.
     */
    MISSING,
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

  it('…and that check has teeth: a rule name nobody taught this file is reported', () => {
    // THIS PROBE USED TO BE `decimal:2`, AND IT HAD TO CHANGE, which is the check working rather than
    // being weakened: `decimal` is a real Laravel rule that arrived in the model-catalogue manifests and
    // is now in PROBED_RULES, so asserting it reads as unknown would have been asserting the opposite of
    // what this file now knows. Any name that is genuinely untaught does the job; `hex_color` is a real
    // Laravel rule this repo does not use, so the probe still asks "what happens when Laravel gains a
    // rule nobody told the harness about" rather than "what happens to a typo".
    expect(unknownRuleNames(['hex_color', 'string', 'bail'])).toEqual(['hex_color']);
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
 * The `missing` branch of the rule classifier, proved against a manifest fixture — and unlike the
 * `sometimes` block above, this one exists to write down where the probes STOP.
 *
 * `UpdateBotRequest.status` is the real instance, and a fixture is used here for the same reason the
 * `sometimes` block uses one: writing it to `rules/` would make the "every manifest is mirrored or
 * exempt" suite assert against a FormRequest that does not exist. All five specs run through
 * `driftFailures` and `validatedPaths`, the same two functions the real manifests use.
 *
 * IT WAS THE `prohibited` BRANCH UNTIL THE SERVER CHANGED THE RULE, and the fifth spec is the one
 * that moved rather than being renamed: `prohibited` ACCEPTED an explicit null and this branch had
 * to suppress the null probe to stay honest, while `missing` refuses one — so the spec that recorded
 * a suppression now records a probe, and the fourth spec below is the tooth that suppression cost.
 */
describe('the `missing` branch of the rule classifier', () => {
  const MISSING_MANIFEST: Manifest = {
    class: 'App\\Http\\Requests\\Fixture\\UpdateBotRequest',
    rules: {
      name: ['sometimes', 'required', 'string', 'max:120'],
      // The real shape: a field the server used to accept and now refuses, because the write moved
      // to its own endpoint. The rule is present rather than the field being deleted from `rules()`,
      // and that difference is the reason this branch exists at all — an absent rule makes
      // `validated()` discard the key in silence.
      //
      // ALONE, with no `sometimes` beside it. `missing` is an implicit rule and `sometimes` would
      // short-circuit it for exactly the body it is meant to judge.
      status: ['missing'],
    },
  };

  const baseline = (): Candidate => ({ name: 'Support bot' });

  /** A faithful mirror does not declare the path at all. */
  const faithful: Mirror = {
    schema: z.strictObject({ name: z.string().trim().min(1).max(120).optional() }),
    baseline,
  };

  it('accepts the faithful mirror — the client-side spelling of `missing` is an absent path', () => {
    expect(driftFailures(MISSING_MANIFEST, faithful)).toEqual([]);
    // …and the path-set comparison agrees with it, which is the assertion the real suite makes.
    expect(validatedPaths(MISSING_MANIFEST)).toEqual(['name']);
    expect(new Set(schemaPaths(faithful.schema))).toEqual(new Set(validatedPaths(MISSING_MANIFEST)));
  });

  it('catches a re-declaration loose enough to accept the probe value', () => {
    const loose: Mirror = {
      schema: z.strictObject({
        name: z.string().trim().min(1).max(120).optional(),
        status: z.string().optional(),
      }),
      baseline,
    };

    expect(driftFailures(MISSING_MANIFEST, loose)).toEqual([
      'form accepts input the server rejects: status — missing: a key the caller may not send',
    ]);
  });

  it('catches a re-declaration that is nullable, which the old `prohibited` probes could not', () => {
    // THE TOOTH THE RENAME BOUGHT. `z.enum(BOT_STATUSES).nullable().optional()` refuses the invented
    // probe value exactly as the server does, so the value probe is silent on it — and under
    // `prohibited` the null probe was suppressed, so this schema passed every probe and was caught
    // only by the path-set comparison. `missing` rejects a present null, so the null probe now names
    // it directly. It is the schema shape a form gets when somebody mirrors "the server sends null
    // here" into the resolver.
    const nullableTyped: Mirror = {
      schema: z.strictObject({
        name: z.string().trim().min(1).max(120).optional(),
        status: z.enum(BOT_STATUSES).nullable().optional(),
      }),
      baseline,
    };

    expect(driftFailures(MISSING_MANIFEST, nullableTyped)).toEqual([
      'form accepts input the server rejects: status — missing: an explicit null is still a present key',
    ]);
  });

  it('is SILENT on a re-declaration typed as the real vocabulary — and the path set is not', () => {
    // THE MEASURED LIMIT, and the reason the path-set subtraction is not a duplicate of these probes.
    // `z.enum(BOT_STATUSES)` refuses `'__missing__'` exactly as the server does, and `.optional()`
    // without `.nullable()` refuses the null too, so both sides agree and every probe passes — which
    // is the shape this schema would actually have if somebody simply left the old field in place
    // after the server moved the write.
    const typed: Mirror = {
      schema: z.strictObject({
        name: z.string().trim().min(1).max(120).optional(),
        status: z.enum(BOT_STATUSES).optional(),
      }),
      baseline,
    };

    expect(driftFailures(MISSING_MANIFEST, typed)).toEqual([]);

    // …and this is what fails instead, by name, in `the schema declares exactly the fields the
    // FormRequest validates`.
    expect(new Set(schemaPaths(typed.schema))).not.toEqual(
      new Set(validatedPaths(MISSING_MANIFEST)),
    );
    expect(schemaPaths(typed.schema).filter((path) => missingPaths(MISSING_MANIFEST).has(path))).toEqual(
      ['status'],
    );
  });

  it('catches the repair that failure invites, which is to make the path REQUIRED', () => {
    // The obvious reading of "the server refuses my body" is "I must be sending the wrong shape", and
    // the obvious fix is to stop making the field optional. The omitted probe is what says no: the
    // server ACCEPTS a body with no `status`, because that is the only body it accepts.
    const mandatory: Mirror = {
      schema: z.strictObject({
        name: z.string().trim().min(1).max(120).optional(),
        status: z.string(),
      }),
      baseline,
    };

    // `toContain` rather than a whole-array comparison, and the reason is worth a line: a REQUIRED
    // path the server forbids makes the BASELINE itself unparseable, so every probe on every other
    // field fails too. That cascade is noise — it names `name` for a mistake that is entirely about
    // `status` — and the two assertions below are the ones that identify the cause.
    const failures = driftFailures(MISSING_MANIFEST, mandatory);

    expect(failures).toContain('form blocks input the server accepts: status — omitted (missing)');
    expect(failures).toContain(
      'form accepts input the server rejects: status — missing: a key the caller may not send',
    );
  });

  it('generates exactly three presence probes, and the null one is REJECTED by both sides', () => {
    // MEASURED against the installed `Illuminate\Validation\Factory` rather than read off the rule
    // name: under `['missing']`, `{}` passes and `{status: null}`, `{status: ''}`, `{status: []}` and
    // `{status: 'published'}` all fail. Under `['prohibited']` the first FOUR of those passed and
    // `validated()` kept the key, which is the data-loss shape the server moved off.
    //
    // Asserted as an exact list, because the count is the thing: the branch generates these three and
    // must NOT also pick up the generic `null` probe below it, whose verdict comes from `nullable`
    // rather than from the rule that actually decides.
    const labels = probesFor('status', ['missing'], faithful).map((generated) => generated.label);

    expect(labels).toEqual([
      'omitted (missing)',
      'missing: a key the caller may not send',
      'missing: an explicit null is still a present key',
    ]);

    // The verdicts, not merely the labels: the null probe is only worth generating if it claims a
    // REJECTION. A probe claiming the server accepts null would report the faithful mirror above as
    // blocking input the server accepts, and invite exactly the re-declaration this block is about —
    // which is what the old rule forced and this one does not.
    expect(
      probesFor('status', ['missing'], faithful).map((generated) => generated.serverAccepts),
    ).toEqual([true, false, false]);
  });
});

/**
 * The `date` and `distinct` branches, proved against a manifest fixture — and this block exists for a
 * reason the two above do not have: BOTH BRANCHES GENERATE NOTHING IN THE REAL SUITE TODAY.
 *
 * The rules arrived on `StoreSourceRequest` and `UpdateSourceRequest`, both NO_CLIENT_FORM, so
 * `probesFor` never runs on either. That is exactly the state in which teaching a rule and exempting
 * it look identical from the outside — the suite passes either way, reports the same counts, and
 * nobody finds out which happened until a schema lands beside a branch that was never executed. So
 * the branches are executed here, in both directions, the same way `emailOfLength`'s teeth are proved
 * rather than assumed.
 *
 * THE FIXTURE IS SHAPED ON `UpdateSourceRequest`, which is the manifest that will carry both first —
 * it is recorded as OWED rather than exempt, with the sources detail screen named. Writing it to
 * `rules/` instead would make the "every manifest is mirrored or exempt" suite assert against a
 * FormRequest that does not exist.
 */
describe('the `date` and `distinct` branches of the rule classifier', () => {
  const SOURCE_FIXTURE: Manifest = {
    class: 'App\\Http\\Requests\\Fixture\\UpdateSourceRequest',
    rules: {
      tags: ['bail', 'sometimes', 'array', 'max:50'],
      'tags.*': ['bail', 'string', 'min:1', 'max:64', 'distinct'],
      effective_at: ['bail', 'sometimes', 'nullable', 'date'],
    },
  };

  /** TWO TAGS, NOT ONE: `duplicateElementProbe` reads element 0, and a one-element baseline would
   *  prove the probe fires without proving it left the rest of the list alone. */
  const baseline = (): Candidate => ({ tags: ['handbook', '2026'], effective_at: '2026-08-20' });

  /**
   * The mirror a tag input and a date picker would really ship. `z.iso.date()` because that is what
   * an `<input type="date">` submits; the server takes far more (`strtotime` accepts `'tomorrow'`),
   * which is the asymmetry the `date` case declines to probe in the acceptance direction.
   */
  const faithful: Mirror = {
    schema: z.strictObject({
      tags: z
        .array(z.string().trim().min(1).max(64))
        .max(50)
        .refine((values) => new Set(values).size === values.length, {
          error: 'Tags must be unique',
        })
        .optional(),
      effective_at: z.iso.date().nullable().optional(),
    }),
    baseline,
  };

  it('accepts the faithful mirror, and the path set agrees with the manifest', () => {
    expect(faithful.schema.safeParse(baseline()).success).toBe(true);
    expect(driftFailures(SOURCE_FIXTURE, faithful)).toEqual([]);
    // `.refine()` on an array returns an array, so `.element` survives it and `tags.*` is still
    // reachable — the property `schemaPaths`' docblock depends on, restated where a refinement is in
    // the way for the first time.
    expect(new Set(schemaPaths(faithful.schema))).toEqual(new Set(validatedPaths(SOURCE_FIXTURE)));
  });

  it('catches a tag list that permits the duplicate the server refuses', () => {
    // THE SCHEMA SOMEBODY WRITES FIRST. Every element rule mirrored, `max:50` mirrored, and the one
    // rule that is about the LIST rather than an element simply absent — which is invisible in review
    // and produces a 422 keyed `tags.3` on a chip input that looked fine.
    const noRefinement: Mirror = {
      schema: z.strictObject({
        tags: z.array(z.string().trim().min(1).max(64)).max(50).optional(),
        effective_at: z.iso.date().nullable().optional(),
      }),
      baseline,
    };

    expect(driftFailures(SOURCE_FIXTURE, noRefinement)).toEqual([
      'form accepts input the server rejects: tags.* — distinct: the same element twice',
    ]);
  });

  it('builds the duplicate out of the BASELINE element, so only `distinct` can explain the refusal', () => {
    const [generated] = probesFor('tags.*', SOURCE_FIXTURE.rules['tags.*'] as string[], faithful)
      .filter((candidate) => candidate.label.startsWith('distinct'));

    expect(generated?.serverAccepts).toBe(false);
    expect(generated?.apply(baseline())).toEqual({
      tags: ['handbook', 'handbook'],
      effective_at: '2026-08-20',
    });

    // …and it declines rather than guessing when there is no element to duplicate. `[undefined,
    // undefined]` would be a probe about `string` wearing `distinct`'s label.
    expect(
      duplicateElementProbe('tags.*', { schema: faithful.schema, baseline: () => ({ tags: [] }) }),
    ).toBeUndefined();
    // A `distinct` on a non-element path is not a thing Laravel emits, and the generator says so
    // rather than mutating the array wholesale.
    expect(duplicateElementProbe('tags', faithful)).toBeUndefined();
  });

  it('catches a date field typed as a bare string', () => {
    const looseDate: Mirror = {
      schema: z.strictObject({
        tags: z
          .array(z.string().trim().min(1).max(64))
          .max(50)
          .refine((values) => new Set(values).size === values.length)
          .optional(),
        effective_at: z.string().nullable().optional(),
      }),
      baseline,
    };

    // BOTH rejection probes fire, which is the shape of the bug: a `z.string()` date field takes
    // anything at all.
    expect(driftFailures(SOURCE_FIXTURE, looseDate)).toEqual([
      'form accepts input the server rejects: effective_at — a string strtotime cannot parse',
      'form accepts input the server rejects: effective_at — a well-shaped date that does not exist',
    ]);
  });

  it('catches a date field mirrored as a SHAPE regex, which the first probe cannot', () => {
    // THE SECOND PROBE'S WHOLE REASON. `/^\d{4}-\d{2}-\d{2}$/` refuses `'not-a-date'` exactly as the
    // server does, so probe one is silent on it and the field looks mirrored — and it takes the
    // thirtieth of February, which `checkdate` refuses and a calendar does not have. It is the schema
    // a reviewer waves through, because it looks like a date check.
    const shapeOnly: Mirror = {
      schema: z.strictObject({
        tags: z
          .array(z.string().trim().min(1).max(64))
          .max(50)
          .refine((values) => new Set(values).size === values.length)
          .optional(),
        effective_at: z
          .string()
          .regex(/^\d{4}-\d{2}-\d{2}$/)
          .nullable()
          .optional(),
      }),
      baseline,
    };

    expect(driftFailures(SOURCE_FIXTURE, shapeOnly)).toEqual([
      'form accepts input the server rejects: effective_at — a well-shaped date that does not exist',
    ]);
  });

  it('generates NO acceptance probe for `date`, which is the deliberate half', () => {
    // Written down as an assertion rather than only as a comment, because "no probe" is the thing a
    // future reader is most likely to mistake for an oversight. The two verdicts are both `false`;
    // the acceptance direction is carried by `the baseline is a value both sides accept`, which each
    // mirror answers in whatever format its own form submits.
    //
    // FILTERED TO THE `date` CASE'S OWN PROBES rather than read off a bare `['date']` rule list: a
    // field with no `sometimes` and no `required` still gets the presence pair, whose omitted probe is
    // an acceptance — and it belongs to the presence branch, not to this one.
    const emitted = new Set([
      'a string strtotime cannot parse',
      'a well-shaped date that does not exist',
    ]);
    const dateProbes = probesFor(
      'effective_at',
      SOURCE_FIXTURE.rules['effective_at'] as string[],
      faithful,
    ).filter((generated) => emitted.has(generated.label));
    expect(dateProbes).toHaveLength(2);
    expect(dateProbes.map((generated) => generated.serverAccepts)).toEqual([false, false]);

    // The presence pair is separate and unaffected: `sometimes|nullable` still generates its two.
    expect(
      probesFor('effective_at', SOURCE_FIXTURE.rules['effective_at'] as string[], faithful).map(
        (generated) => generated.label,
      ),
    ).toEqual([
      'omitted (sometimes)',
      'null',
      'a string strtotime cannot parse',
      'a well-shaped date that does not exist',
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

/**
 * The same treatment for the other cross-field pair in the package. `required_without` in BOTH
 * directions is how `UpdateProviderConnectionRequest` says "either field alone is a legitimate PATCH
 * body, but a body carrying neither is not" — and `probesFor` suppresses every presence probe on a
 * CROSS_FIELD rule, because it cannot answer "is this field required?" from one field's rule list. So
 * the four presence combinations are asserted here, where the intent can be written down.
 *
 * WHY THE SERVER SPELLS IT THIS WAY AT ALL: the alternative is a `withValidator`/`after` closure,
 * which works server-side and is INVISIBLE to `kb:dump-form-rules` — the manifest is dumped from
 * executing `rules()`, so a constraint expressed in a hook is a constraint this file could never see
 * and no client would ever be told about.
 */
describe('provider connection edit: the rules a single-field probe cannot express', () => {
  const parse = (value: unknown) => providerConnectionEditSchema.safeParse(value).success;

  it('accepts either field alone — it is a PATCH, not a replace', () => {
    expect(parse({ label: 'Primary OpenAI key' })).toBe(true);
    expect(parse({ status: 'revoked' })).toBe(true);
  });

  it('accepts both together, and each `required_without` is satisfied by the other', () => {
    expect(parse({ label: 'Primary OpenAI key', status: 'active' })).toBe(true);
  });

  it('rejects a body that names neither, which would audit an edit that did not happen', () => {
    expect(parse({})).toBe(false);
  });

  /**
   * THE LOOSENESS THIS PACKAGE REPORTED, NOW CLOSED ON BOTH SIDES — and it is asserted by hand for the
   * same reason the cases above are: `min:1` is probed generically, but the case that MATTERS is
   * `{label: "", status: "active"}`, and the probe that generates `""` cannot also supply the sibling
   * that made the old behaviour surprising.
   *
   * The old shape was `required_without:status|string|max:120` with no lower bound, so `""` satisfied
   * `required_without` (via `status`), `string` and `max:` — a 200 that blanked the label. That is not
   * cosmetic: the update writes the new label into its own audit row and into every later one, so one
   * empty PATCH erased the only human-readable identifier a reviewer had for that connection,
   * retroactively. The server added `min:1` and this schema mirrors it.
   */
  it('rejects an empty label even when `status` satisfies the `required_without`', () => {
    expect(parse({ label: '', status: 'active' })).toBe(false);
    expect(parse({ label: '' })).toBe(false);
    expect(parse({ label: 'a', status: 'active' })).toBe(true);
  });

  it('rejects an unknown key rather than silently stripping it', () => {
    // `strictObject`. The key that matters is `credential`: this endpoint may never accept one, and a
    // form whose extra field is dropped in silence is a form that looks like it worked.
    expect(parse({ label: 'Primary', credential: 'sk-live-not-a-real-key' })).toBe(false);
    expect(parse({ label: 'Primary', masked_key: '…4a91' })).toBe(false);
  });

  it('carries no credential field, and neither does the defaults factory', () => {
    const paths = schemaPaths(providerConnectionEditSchema);
    expect(paths.sort()).toEqual(['label', 'status']);
    expect(
      paths.some((path) => /credential|api_key|secret|masked|token|password/i.test(path)),
    ).toBe(false);

    // The ONLY path from server data into this form's state, and it reaches exactly two fields. A
    // `reset({...connection})` would keep `masked_key` and submit it; this cannot.
    expect(
      providerConnectionEditDefaults({ label: 'Primary OpenAI key', status: 'invalid' }),
    ).toEqual({ label: 'Primary OpenAI key', status: 'invalid' });
  });

  it('the status tuple is exactly the enum the schema accepts', () => {
    // The tuple is what the `<Select>` iterates and the enum is what the resolver checks; two
    // spellings of one list is how an option that cannot be submitted gets rendered.
    for (const status of PROVIDER_CONNECTION_STATUSES) {
      expect(parse({ status })).toBe(true);
    }
    expect(PROVIDER_CONNECTION_STATUSES).toHaveLength(3);
    expect(parse({ status: 'suspended' })).toBe(false);
  });
});

/**
 * The model catalog's own unprobeable rules, and there are four kinds of them here — more than any
 * other manifest in this package, which is why this block is the longest.
 *
 *   1. `required_with` in ONE direction (a price needs a currency; a currency needs no price). The
 *      harness suppresses every presence probe on a CROSS_FIELD field, so the whole rule is here.
 *   2. `present` vs omitted on three fields, where the two requests DISAGREE — the difference
 *      between a create form that may skip pricing and a PUT that refuses a partial body.
 *   3. The decimal SCALE as a STRING question. The generated probes pass JS numbers, which is the
 *      right test of the rule and the wrong test of the thing that actually breaks: `'0.020000'`
 *      surviving a round-trip byte for byte.
 *   4. `size:3` on the currency, which UNPROBED_RULES declines for a reason it states.
 */
describe('the model catalog: the rules a single-field probe cannot express', () => {
  const create = (value: unknown) => providerModelCreateSchema.safeParse(value);
  const edit = (value: unknown) => providerModelEditSchema.safeParse(value);

  const CREATE_BASE = {
    model: 'gpt-5.6-sol',
    display_name: 'GPT-5.6 Sol',
    supported: ['text'],
    context_window: 400_000,
    max_output_tokens: 128_000,
    enabled: true,
    input_price_per_million: '1.250000',
    output_price_per_million: '10.000000',
    price_currency: 'USD',
  };

  const EDIT_BASE = {
    display_name: 'GPT-5.6 Sol',
    supported: ['text'],
    context_window: 400_000,
    max_output_tokens: 128_000,
    enabled: true,
    input_price_per_million: '1.250000',
    output_price_per_million: '10.000000',
    price_currency: 'USD',
  };

  it('refuses a price with no currency, in both price fields independently', () => {
    // The database says the same thing one layer down (`provider_models_price_needs_currency`), and
    // the reason is arithmetic rather than tidiness: two organizations billed in different
    // currencies would both store `15.00`, and a spend estimate would add them.
    expect(create({ ...CREATE_BASE, price_currency: null }).success).toBe(false);
    expect(
      create({ ...CREATE_BASE, output_price_per_million: null, price_currency: null }).success,
    ).toBe(false);
    expect(
      create({ ...CREATE_BASE, input_price_per_million: null, price_currency: null }).success,
    ).toBe(false);
    expect(edit({ ...EDIT_BASE, price_currency: null }).success).toBe(false);
  });

  it('keys that refusal to `price_currency`, because the typed prices are not the mistake', () => {
    const refused = create({ ...CREATE_BASE, price_currency: null });
    expect(refused.success).toBe(false);
    expect(refused.success === false && refused.error.issues[0]?.path).toEqual(['price_currency']);
  });

  it('accepts a currency with NO prices — the order a human fills the form in', () => {
    const partial = {
      ...CREATE_BASE,
      input_price_per_million: null,
      output_price_per_million: null,
      price_currency: 'EUR',
    };
    expect(create(partial).success).toBe(true);
    // …and the fully unpriced row, which is what "no price recorded" looks like. It is NOT free.
    expect(create({ ...partial, price_currency: null }).success).toBe(true);
  });

  it('treats a cleared price input ("") as null, exactly as ConvertEmptyStringsToNull does', () => {
    const cleared = create({
      ...CREATE_BASE,
      input_price_per_million: '',
      output_price_per_million: '   ',
      price_currency: '',
    });
    expect(cleared.success).toBe(true);
    expect(cleared.success && cleared.data.input_price_per_million).toBeNull();
    expect(cleared.success && cleared.data.output_price_per_million).toBeNull();
    expect(cleared.success && cleared.data.price_currency).toBeNull();

    // …and therefore a price with a CLEARED currency is refused rather than posted as "".
    expect(create({ ...CREATE_BASE, price_currency: '' }).success).toBe(false);
  });

  /**
   * THE ASSERTION THIS WHOLE FILE EXISTS FOR ON THIS SURFACE. The generated `decimal:0,6` probes pass
   * JS NUMBERS — that is the honest test of Laravel's rule — and they cannot see the failure that
   * actually happens: a schema that parses the price into a `number` agrees with every one of those
   * probes and re-serializes `'0.020000'` as `'0.02'`. Same amount, different string, in an audit row
   * and in a diff, and nothing anywhere reports it.
   */
  it('round-trips an exact decimal string BYTE FOR BYTE, trailing zeros included', () => {
    const parsed = create({ ...CREATE_BASE, input_price_per_million: '0.020000' });
    expect(parsed.success && parsed.data.input_price_per_million).toBe('0.020000');

    // A JSON number is accepted (the server's `numeric` does) and stringified rather than kept as a
    // number, so what leaves this schema is always the exact-decimal representation.
    const fromNumber = create({ ...CREATE_BASE, input_price_per_million: 0.02 });
    expect(fromNumber.success && fromNumber.data.input_price_per_million).toBe('0.02');
    expect(typeof (fromNumber.success && fromNumber.data.input_price_per_million)).toBe('string');
  });

  it('mirrors the SCALE and the CEILING, and the ceiling is the form’s and not the column’s', () => {
    expect(create({ ...CREATE_BASE, input_price_per_million: '0.123456' }).success).toBe(true);
    expect(create({ ...CREATE_BASE, input_price_per_million: '0.1234567' }).success).toBe(false);
    expect(create({ ...CREATE_BASE, input_price_per_million: '1000000' }).success).toBe(true);
    expect(create({ ...CREATE_BASE, input_price_per_million: '1000000.000001' }).success).toBe(false);
    expect(create({ ...CREATE_BASE, input_price_per_million: '-1' }).success).toBe(false);
    // `numeric(14, 6)` holds up to 99,999,999.999999 and BOTH FormRequests stop at 1,000,000. The gap
    // is deliberate: flush bounds would let the boundary value pass validation and then raise
    // SQLSTATE 22003 from the driver as a 500 — a bug report about the server for a value the form
    // said was fine. Mirroring the column's number instead of the form's would reproduce that.
    expect(create({ ...CREATE_BASE, input_price_per_million: '99999999.999999' }).success).toBe(false);
  });

  it('refuses the exponent form, which `numeric` accepts and `decimal` does not', () => {
    // PHP's `is_numeric('1e3')` is true, and Laravel's own decimal pattern has no exponent branch —
    // so the server refuses it on the second rule. A client checking only `Number.isFinite` would
    // accept a value the server rejects, which is the visible-422 direction and still drift.
    expect(create({ ...CREATE_BASE, input_price_per_million: '1e3' }).success).toBe(false);
    expect(create({ ...CREATE_BASE, input_price_per_million: 'not-a-price' }).success).toBe(false);
  });

  it('enforces the currency SHAPE without folding case, because the server does not fold either', () => {
    expect(create({ ...CREATE_BASE, price_currency: 'usd' }).success).toBe(false);
    expect(create({ ...CREATE_BASE, price_currency: 'US' }).success).toBe(false);
    expect(create({ ...CREATE_BASE, price_currency: 'USDX' }).success).toBe(false);
    expect(create({ ...CREATE_BASE, price_currency: 'USD' }).success).toBe(true);
    // The SHAPE is enforced and membership of the real ISO 4217 list is not — that list changes, and
    // pinning it here would make a new currency a release of this package.
    expect(create({ ...CREATE_BASE, price_currency: 'ZZZ' }).success).toBe(true);
  });

  it('is a PUT: the edit schema refuses a body missing any of the three `present` fields', () => {
    // The server expresses "a body that changes nothing is refused" by demanding the FULL attribute
    // set, because the readable alternative — an `after()` closure — is invisible to
    // `kb:dump-form-rules` and no client would ever be told the constraint exists. A form that
    // omitted a field here would 422 on a rule it was never shown.
    // `Object.entries().filter()` rather than `delete partial[field]`: indexing an object by a loop
    // variable is the object-injection sink eslint-plugin-security reports, and a warning nobody can
    // act on is a warning everybody stops reading.
    for (const field of [
      'input_price_per_million',
      'output_price_per_million',
      'price_currency',
      'enabled',
    ]) {
      const partial = Object.fromEntries(
        Object.entries(EDIT_BASE).filter(([key]) => key !== field),
      );
      expect(edit(partial).success, `${field} is present/required on the PUT`).toBe(false);
    }
  });

  it('…while the create schema permits omitting all four, because that request says `sometimes`', () => {
    expect(
      create({
        model: 'text-embedding-3-large',
        display_name: 'Embedding 3 Large',
        supported: ['embedding'],
        context_window: 8191,
        max_output_tokens: 0,
      }).success,
    ).toBe(true);
  });

  it('carries no `model` field on the edit schema, and refuses one rather than stripping it', () => {
    expect(schemaPaths(providerModelEditSchema)).not.toContain('model');
    // `strictObject`. `edit({...row})` is the tempting call and it is a parse FAILURE — the
    // identifier is half of the vector-space identity for everything already embedded through the
    // row, and `organizations.embedding_model` references it as a bare string with no foreign key.
    expect(edit({ ...EDIT_BASE, model: 'gpt-5.6-sol' }).success).toBe(false);
    expect(edit({ ...EDIT_BASE, id: ULID }).success).toBe(false);
    expect(edit({ ...EDIT_BASE, connection_id: ULID }).success).toBe(false);
  });

  it('declares `supported` and its element rules as two paths, matching the manifest', () => {
    expect(schemaPaths(providerModelCreateSchema).sort()).toEqual([
      'context_window',
      'display_name',
      'enabled',
      'input_price_per_million',
      'max_output_tokens',
      'model',
      'output_price_per_million',
      'price_currency',
      'supported',
      'supported.*',
    ]);
  });

  it('leaves the capability vocabulary OPEN, because the closed list is the data plane’s', () => {
    // A `z.enum(...)` here would reject a flag the data plane added last week — functionality
    // removed with nothing reported — and Laravel validates the members as
    // `string|max:64|regex:/^[a-z][a-z0-9_]*$/`, which is a claim about SPELLING and not about
    // membership, and publishes no enum on the resource. The admin console's closed checkbox list is
    // a UI affordance declared beside the form that renders it, and it PRESERVES an unrecognised flag
    // rather than dropping it.
    expect(create({ ...CREATE_BASE, supported: ['a_flag_nobody_here_has_heard_of'] }).success).toBe(
      true,
    );
    expect(create({ ...CREATE_BASE, supported: ['a'.repeat(65)] }).success).toBe(false);
    // Open on the MEMBERS, closed on the COUNT: `max:20` is a server rule and is mirrored.
    expect(
      create({ ...CREATE_BASE, supported: Array.from({ length: 21 }, () => 'text') }).success,
    ).toBe(false);
    expect(
      create({ ...CREATE_BASE, supported: Array.from({ length: 20 }, () => 'text') }).success,
    ).toBe(true);
  });

  /**
   * THE ELEMENT PATTERN IS ASSERTED HERE OR NOWHERE. `regex` is an `UNPROBED_RULES` entry — no generic
   * generator satisfies an arbitrary pattern — so the drift harness proves the element's `max:64` and
   * says nothing at all about its shape. A schema that dropped `.regex(CAPABILITY_FLAG)` would stay
   * green above and be a form accepting input the server rejects, which is the direction that produces
   * a 422 nobody predicted rather than the silent one, but is still drift.
   *
   * The rule exists because `supported` is echoed verbatim into the model row's audit detail, so an
   * unconstrained element let a tenant write an arbitrary attacker-chosen string — a key-shaped one,
   * a sentence, a URL — into a field operators read as trustworthy. Every rejection below is a member
   * of that class; the last is the one the server rule was written for.
   */
  it('constrains each flag to a lower-snake identifier, which is what the server now checks', () => {
    const rejects = (flag: string): boolean =>
      create({ ...CREATE_BASE, supported: [flag] }).success === false;

    expect(rejects('Text'), 'upper case').toBe(true);
    expect(rejects('1text'), 'leading digit').toBe(true);
    expect(rejects('_text'), 'leading underscore').toBe(true);
    expect(rejects('tool-use'), 'hyphen').toBe(true);
    expect(rejects('tool use'), 'space').toBe(true);
    expect(rejects(''), 'empty').toBe(true);
    expect(rejects('sk-live-0000000000000000'), 'a key-shaped string').toBe(true);

    // …and the shapes the data plane's own `Capability` members actually have still pass, which is
    // what keeps this a spelling rule rather than a vocabulary.
    for (const flag of ['text', 'tool_use', 'stream_usage', 'embedding', 'rerank', 'json_mode2']) {
      expect(create({ ...CREATE_BASE, supported: [flag] }).success, flag).toBe(true);
    }

    // The edit schema shares the field, and sharing it is the assertion: a second literal here is how
    // the two spellings start to disagree.
    expect(edit({ ...EDIT_BASE, supported: ['Text'] }).success).toBe(false);
    expect(edit({ ...EDIT_BASE, supported: ['tool_use'] }).success).toBe(true);
  });

  it('the defaults factory reaches exactly the eight mutable fields, and no identifier', () => {
    // The ONLY path from server data into this form's state. A `reset({...row})` would keep `id`,
    // `connection_id`, `created_at` AND `model`; this cannot, because its parameter type has eight
    // members and its RETURN type is the schema's output — which is also what makes it a complete
    // PUT body for the inline `enabled` toggle.
    const seeded = providerModelEditDefaults({
      display_name: 'GPT-5.6 Sol',
      supported: ['text'],
      context_window: 400_000,
      max_output_tokens: 128_000,
      enabled: false,
      input_price_per_million: '0.020000',
      output_price_per_million: null,
      price_currency: 'USD',
    });

    expect(Object.keys(seeded).sort()).toEqual([
      'context_window',
      'display_name',
      'enabled',
      'input_price_per_million',
      'max_output_tokens',
      'output_price_per_million',
      'price_currency',
      'supported',
    ]);
    // It is a value the schema itself accepts — the property the inline toggle depends on.
    expect(edit(seeded).success).toBe(true);
    expect(edit({ ...seeded, enabled: true }).success).toBe(true);
    // And the price survived, unscaled.
    expect(seeded.input_price_per_million).toBe('0.020000');
  });

  it('the create defaults are a value the schema accepts once the two identifiers are typed', () => {
    const empty = providerModelCreateDefaults();
    // NOT accepted as-is: `model` and `display_name` are `required`, and an empty create form is not
    // a submittable body. That is the point of rendering it.
    expect(create(empty).success).toBe(false);
    expect(
      create({ ...empty, model: 'gpt-5.6-sol', display_name: 'GPT-5.6 Sol' }).success,
    ).toBe(true);
    // The empty price inputs become nulls rather than zeros: "no price recorded" is not "free".
    const filled = create({ ...empty, model: 'm', display_name: 'M' });
    expect(filled.success && filled.data.input_price_per_million).toBeNull();
    expect(filled.success && filled.data.price_currency).toBeNull();
  });
});

/**
 * The bot requests' own unprobeable rules, and there are FIVE kinds here — one more than the model
 * catalog, which is why this is now the longest block in the file.
 *
 *   1. `required_with` in BOTH directions on the evidence pair. Every presence probe on a CROSS_FIELD
 *      field is suppressed, so the whole rule is here.
 *   2. `required_with` in ONE direction from `provider_model_id` to `provider_connection_id`. Same
 *      suppression, opposite asymmetry to the model catalog's currency rule. ON THE POST ONLY:
 *      `UpdateBotRequest` dropped it, because on a PATCH the rule sees only the keys the caller
 *      sent and the pair that matters is the RESULTING one, which `BotService` decides against the
 *      stored row. So every assertion below is `create(...)`, and `botSettingsSchema` must NOT
 *      mirror it — the probe harness reports that as "form blocks input the server accepts".
 *   3. `App\Rules\EvidenceThresholdWithinScale` — a sibling-dependent RANGE, which `probesFor` has no
 *      vocabulary for at all. `UNPROBED_RULES` records the suppression; this is the check.
 *   4. `App\Rules\ReadableThemeColor` — the one rule in this file that is unprobed AND unmirrored.
 *      What is asserted here is the boundary of that decision, so the residual is a test rather than
 *      a paragraph.
 *   5. The `sometimes|required` READING, on a schema that ships. The fixture suite above proves the
 *      harness answers it correctly; these prove the settings form actually is a PATCH.
 */
describe('the bot requests: the rules a single-field probe cannot express', () => {
  const create = (value: unknown) => botCreateSchema.safeParse(value);
  const settings = (value: unknown) => botSettingsSchema.safeParse(value);

  const CREATE_BASE = { name: 'Support desk', slug: 'support-desk' };

  it('is a PATCH: the settings schema accepts a body carrying ONE field', () => {
    // The assertion the whole `sometimes` block exists for, on a real schema. Twelve fields carry
    // `sometimes|required`, and reading that as `required` would make every one of these a 422 that
    // the drift harness would call agreement.
    expect(settings({ welcome_message: 'Hi there' }).success).toBe(true);
    expect(settings({ access_mode: 'public' }).success).toBe(true);
    expect(settings({ name: 'Support desk' }).success).toBe(true);
    // …and the empty body, which is what a form submitted with nothing changed produces. The server
    // accepts it (every field is `sometimes`) and answers 200 with no change; refusing it here would
    // be this package inventing a rule.
    expect(settings({}).success).toBe(true);
  });

  it('…but `sometimes|required` still refuses the value it calls empty', () => {
    // The other half of the pair, and the one a schema that merely made everything `.optional()`
    // would lose: sending `name` means sending a name. `TrimStrings` runs before `min:1`, so a field
    // of spaces is empty server-side and must be empty here.
    expect(settings({ name: '' }).success).toBe(false);
    expect(settings({ name: '   ' }).success).toBe(false);
    expect(settings({ slug: '' }).success).toBe(false);
    // An explicit null is PRESENT, so `sometimes` does not fire and `nullable` was never declared.
    expect(settings({ name: null }).success).toBe(false);
  });

  it('the create schema demands `name` and `slug` and nothing else', () => {
    expect(create(CREATE_BASE).success).toBe(true);
    expect(create({ name: 'Support desk' }).success).toBe(false);
    expect(create({ slug: 'support-desk' }).success).toBe(false);
    expect(create({}).success).toBe(false);
  });

  it('carries no `status` on EITHER schema, and refuses one rather than stripping it', () => {
    // ── THE CREATE HALF IS UNCHANGED ──────────────────────────────────────────────────────────
    // A bot is created `draft`, always: creating one directly into `published` would run the publish
    // guard against a source assignment that cannot exist yet. `StoreBotRequest` declares no rule for
    // the field, so `strictObject` is what turns `create({...settingsValues})` into a parse failure
    // instead of a body whose extra key is dropped in silence.
    expect(create({ ...CREATE_BASE, status: 'draft' }).success).toBe(false);

    // ── THE PATCH HALF IS NEW, AND IT IS THE ASSERTION THAT USED TO SAY THE OPPOSITE ──────────
    // This line read `expect(settings({status:'draft'}).success).toBe(true)` while `status` was a
    // PATCH field. `UpdateBotRequest` now rules it `["missing"]` — a lifecycle move is
    // `PUT …/bots/{bot}/status` and nothing else — and the rule is there rather than the field being
    // deleted from `rules()` because an ABSENT rule makes `validated()` discard the key in silence:
    // a console would publish a bot, get a 200, and find it still in draft.
    for (const status of BOT_STATUSES) {
      expect(settings({ status }).success, status).toBe(false);
    }
    // …including alongside fields the schema does declare, which is the shape a form would actually
    // post if `status` were still in its panel's field tuple.
    expect(settings({ name: 'Support desk', status: 'published' }).success).toBe(false);
  });

  it('refuses every server-owned identifier on both schemas', () => {
    // `public_bot_id` is the one that would not be harmless: it is the token every live embed on the
    // customer's own site carries, server-minted once, and a form that round-tripped it could break
    // all of them with a 200.
    for (const key of [
      'id',
      'public_bot_id',
      'retrieval_configuration_version',
      'created_at',
      'updated_at',
    ]) {
      expect(create({ ...CREATE_BASE, [key]: 'x' }).success, `create ${key}`).toBe(false);
      expect(settings({ [key]: 'x' }).success, `settings ${key}`).toBe(false);
    }
  });

  it('refuses half an evidence pair in both directions, and accepts both or neither', () => {
    // `bots_evidence_threshold_paired` CHECKs `num_nonnulls(threshold, scale) <> 1` one layer down.
    // The pair is not tidiness: the same float is an unbounded logit on one provider and a bounded
    // relevance score on another, so half a pair is a number in no units at all.
    expect(create({ ...CREATE_BASE, evidence_threshold: 0.5 }).success).toBe(false);
    expect(create({ ...CREATE_BASE, evidence_threshold_scale: 'logit' }).success).toBe(false);
    expect(
      create({ ...CREATE_BASE, evidence_threshold: 0.5, evidence_threshold_scale: 'sigmoid' })
        .success,
    ).toBe(true);
    // Neither is the unset state, and clearing BOTH is how an operator gets back to it on a PATCH.
    expect(create(CREATE_BASE).success).toBe(true);
    expect(
      settings({ evidence_threshold: null, evidence_threshold_scale: null }).success,
    ).toBe(true);
    // A cleared number input posts "" and `ConvertEmptyStringsToNull` makes it null before any rule
    // runs, so a half-cleared pair must be refused rather than posted as an empty string.
    expect(settings({ evidence_threshold: '', evidence_threshold_scale: 'logit' }).success).toBe(
      false,
    );
  });

  it('keys that refusal to the field the operator still has to fill in', () => {
    const refused = create({ ...CREATE_BASE, evidence_threshold: 0.5 });
    expect(refused.success).toBe(false);
    expect(refused.success === false && refused.error.issues[0]?.path).toEqual([
      'evidence_threshold_scale',
    ]);
  });

  /**
   * `App\Rules\EvidenceThresholdWithinScale`, which `UNPROBED_RULES` declines to probe and this
   * mirrors. Both directions matter and they are different failures: `1.7` on a bounded scale is the
   * catchable half, and `1.7` on `logit` is a perfectly ordinary threshold that a schema which
   * clamped everything to [0, 1] would refuse — functionality removed, nothing reported.
   */
  it('bounds the threshold by its own scale, and only when that scale is bounded', () => {
    const withScale = (evidence_threshold: number, evidence_threshold_scale: string) =>
      create({ ...CREATE_BASE, evidence_threshold, evidence_threshold_scale }).success;

    for (const scale of ['sigmoid', 'unit_interval']) {
      expect(withScale(0, scale), `${scale} lower bound`).toBe(true);
      expect(withScale(1, scale), `${scale} upper bound`).toBe(true);
      expect(withScale(1.7, scale), `${scale} above`).toBe(false);
      expect(withScale(-0.1, scale), `${scale} below`).toBe(false);
    }

    // `logit` is unbounded and signed — NVIDIA's ranking endpoint returns one — so the only bounds
    // are the absurdity pair the manifest declares, which the generated probes already cover.
    expect(withScale(1.7, 'logit')).toBe(true);
    expect(withScale(-3.2, 'logit')).toBe(true);
    expect(withScale(-100, 'logit')).toBe(true);
    expect(withScale(-101, 'logit')).toBe(false);

    // The same rule on the PATCH, because the refinement is shared and a second copy is how the two
    // schemas start to disagree.
    expect(
      settings({ evidence_threshold: 1.7, evidence_threshold_scale: 'unit_interval' }).success,
    ).toBe(false);
  });

  it('the scale tuple is exactly the enum both schemas accept', () => {
    // The tuple is what a `<Select>` iterates and the enum is what the resolver checks; two
    // spellings of one list is how an option that cannot be submitted gets rendered. There is
    // deliberately no `uncalibrated` member: that would be the statement that no characterization
    // exists, which makes a stored threshold a contradiction rather than a value.
    for (const scale of EVIDENCE_THRESHOLD_SCALES) {
      expect(create({ ...CREATE_BASE, evidence_threshold: 0.5, evidence_threshold_scale: scale })
        .success).toBe(true);
    }
    expect(EVIDENCE_THRESHOLD_SCALES).toHaveLength(3);
    expect(EVIDENCE_THRESHOLD_SCALES).not.toContain('uncalibrated');
  });

  it('refuses a model with no connection, and permits a connection with no model', () => {
    // ONE DIRECTION ONLY, and the asymmetry is the rule. A connection with no model is a real and
    // common state — "I have chosen the vendor, not the model yet" — and both columns are nullable
    // with MATCH SIMPLE foreign keys precisely so a half-configured draft is expressible. A MODEL
    // with no connection is not a state at all: a catalog row names a credential only through its
    // parent, so the pair would name no credential.
    expect(create({ ...CREATE_BASE, provider_model_id: ULID }).success).toBe(false);
    expect(create({ ...CREATE_BASE, provider_connection_id: ULID }).success).toBe(true);
    expect(
      create({ ...CREATE_BASE, provider_connection_id: ULID, provider_model_id: ULID }).success,
    ).toBe(true);

    const refused = create({ ...CREATE_BASE, provider_model_id: ULID });
    // Keyed to the connection, because the model the operator picked is not the mistake.
    expect(refused.success === false && refused.error.issues[0]?.path).toEqual([
      'provider_connection_id',
    ]);
  });

  it('treats a cleared model select ("") as null, exactly as ConvertEmptyStringsToNull does', () => {
    const cleared = create({
      ...CREATE_BASE,
      provider_connection_id: '',
      provider_model_id: '',
    });
    expect(cleared.success).toBe(true);
    expect(cleared.success && cleared.data.provider_connection_id).toBeNull();
    // …and therefore clearing the connection while a model is selected is refused rather than posted
    // as an empty string, which the server would read as null and refuse anyway.
    expect(create({ ...CREATE_BASE, provider_connection_id: '', provider_model_id: ULID }).success)
      .toBe(false);
  });

  it('closes the theme key set and rejects an explicit null theme', () => {
    // `array:primary,accent,radius` closes the key set, which is what makes an unknown key a 422
    // instead of a value stored forever and rendered nowhere. Every other custom property the
    // renderer writes — the whole `-foreground` and accent-ramp family — is DERIVED at render time
    // and is never form-settable, because contrast is derived and never chosen.
    expect(create({ ...CREATE_BASE, theme: {} }).success).toBe(true);
    expect(create({ ...CREATE_BASE, theme: { radius: '0rem' } }).success).toBe(true);
    expect(create({ ...CREATE_BASE, theme: { foreground: 'oklch(1 0 0)' } }).success).toBe(false);
    expect(create({ ...CREATE_BASE, theme: { primary_foreground: 'x' } }).success).toBe(false);
    // Neither request declares `nullable` on this path: absent leaves the stored theme alone, `{}` is
    // the unthemed state, and null is neither.
    expect(create({ ...CREATE_BASE, theme: null }).success).toBe(false);
    // A cleared colour input posts "", which the server reads as null and then refuses with the
    // `string` rule — so the lower bound here is mirroring a behaviour rather than a `min:` rule.
    expect(create({ ...CREATE_BASE, theme: { primary: '' } }).success).toBe(false);
  });

  it('the radius tuple is exactly the six values the design tokens publish', () => {
    for (const radius of THEME_RADII) {
      expect(create({ ...CREATE_BASE, theme: { radius } }).success, radius).toBe(true);
    }
    expect(THEME_RADII).toHaveLength(6);
    // The renderer matches this string EXACTLY against that set and drops anything else, so an
    // arbitrary CSS length is refused on write rather than silently ignored on render.
    expect(create({ ...CREATE_BASE, theme: { radius: '0.5em' } }).success).toBe(false);
    expect(create({ ...CREATE_BASE, theme: { radius: '8px' } }).success).toBe(false);
  });

  /**
   * THE RESIDUAL, AS A TEST RATHER THAN A PARAGRAPH. `App\Rules\ReadableThemeColor` is the one rule
   * this package neither probes nor mirrors, and the reason is written out in `UNPROBED_RULES` and in
   * src/forms/bot.ts. What can be asserted is the SHAPE of the gap, so it stays the gap that was
   * argued for rather than quietly widening into "the client checks nothing about a theme".
   */
  it('does NOT mirror the oklch grammar or the contrast floor — the named residual', () => {
    // Measured against the installed `App\Rules\ReadableThemeColor`: the first is refused for its
    // grammar, the second is a legal colour refused for landing in the band where neither platform
    // foreground clears 4.5:1. Both are accepted here, and both are a visible 422 keyed to the field.
    expect(create({ ...CREATE_BASE, theme: { primary: 'rebeccapurple' } }).success).toBe(true);
    expect(create({ ...CREATE_BASE, theme: { primary: 'oklch(0.58 0.2 264)' } }).success).toBe(true);
    // What IS mirrored is the length, which is the half a generated probe can keep honest.
    expect(create({ ...CREATE_BASE, theme: { primary: 'a'.repeat(65) } }).success).toBe(false);
    expect(create({ ...CREATE_BASE, theme: { primary: 'a'.repeat(64) } }).success).toBe(true);
  });

  it('does NOT mirror the consent pairing, because neither manifest declares it', () => {
    // The server's own note on `consent_text`: the rule would be correct on the POST and WRONG on the
    // PATCH, where enabling collection on a bot that already carries a disclosure would be refused
    // for a field the caller had no reason to resend. So the whole check lives in `BotService`,
    // evaluated against the RESULTING row, with `bots_consent_text_present_when_collecting` as the
    // database's copy — and a client-side version would block a body the server accepts.
    expect(create({ ...CREATE_BASE, collect_end_user_data: true }).success).toBe(true);
    expect(settings({ collect_end_user_data: true }).success).toBe(true);
    // The other direction is a legal row and not a special case: an operator who wrote the disclosure
    // before switching collection on.
    expect(
      create({ ...CREATE_BASE, collect_end_user_data: false, consent_text: 'We keep your email.' })
        .success,
    ).toBe(true);
  });

  it('the mode tuples are exactly the enums the settings schema accepts', () => {
    // `BOT_STATUSES` USED TO BE ASSERTED HERE and has moved to the transition section below, with
    // the field. The tuple did not move out of the package with it, deliberately: every status pill
    // and transition menu iterates it, and a tuple pinned to nothing is how a sixth lifecycle value
    // becomes a `<Select>` option that cannot be submitted.
    for (const access_mode of BOT_ACCESS_MODES) {
      expect(settings({ access_mode }).success, access_mode).toBe(true);
    }
    for (const answer_mode of BOT_ANSWER_MODES) {
      expect(settings({ answer_mode }).success, answer_mode).toBe(true);
    }
    expect(BOT_ACCESS_MODES).toHaveLength(2);
    expect(BOT_ANSWER_MODES).toHaveLength(2);
  });

  it('clears a nullable number rather than coercing the empty input to zero', () => {
    // `Number('')` is 0, and a rate limit of ZERO is not a limit — it is a bot that answers nobody,
    // and it is a plausible typo for "no limit", which is spelled null. `min:1` here and
    // `bots_rate_limits_positive` in the database both refuse the zero; this asserts the CLEARED
    // input does not become one on the way past.
    const cleared = settings({ rate_limit_per_minute: '', retention_days: '' });
    expect(cleared.success).toBe(true);
    expect(cleared.success && cleared.data.rate_limit_per_minute).toBeNull();
    expect(cleared.success && cleared.data.retention_days).toBeNull();
    expect(settings({ rate_limit_per_minute: 0 }).success).toBe(false);
    expect(settings({ rate_limit_per_minute: null }).success).toBe(true);
  });

  it('clears a nullable text field to null rather than to the blank string', () => {
    // `TrimStrings` then `ConvertEmptyStringsToNull` run before every rule, so a cleared textarea is
    // null server-side. A schema that kept `''` would round-trip a value `bots_text_not_blank`
    // refuses for every writer that is not an HTTP request.
    const cleared = settings({ welcome_message: '   ', description: '' });
    expect(cleared.success).toBe(true);
    expect(cleared.success && cleared.data.welcome_message).toBeNull();
    expect(cleared.success && cleared.data.description).toBeNull();
  });

  /**
   * A `BotResource`-shaped row, at describe scope so the two `botFormDefaults` specs below read the
   * SAME row and differ only in the projection flag.
   *
   * It carries `status` deliberately — a real resource has one — so the pick is asserted to DROP it
   * rather than asserted against a fixture that could not have leaked it. Both instruction fields
   * carry text for the same reason: the withheld spec overrides them to `null`, which is what the
   * wire actually does, and a fixture that was already null could not tell the two states apart.
   */
  const SEEDABLE_ROW = {
    name: 'Support desk',
    slug: 'support-desk',
    status: 'published',
    description: null,
    welcome_message: 'Hi',
    placeholder_text: null,
    system_instruction: 'You are a support agent.',
    answer_style_instruction: 'Two short paragraphs.',
    // The server's own statement that the two values above are this bot's rather than the
    // projection's. The withheld case is the spec below.
    instructions_visible: true,
    access_mode: 'public',
    answer_mode: 'rag_first',
    allow_general_answers: true,
    provider_connection_id: ULID,
    provider_model_id: ULID,
    dense_top_k: 30,
    sparse_top_k: 25,
    rerank_candidates: 24,
    rerank_retain: 8,
    evidence_threshold: 0.42,
    evidence_threshold_scale: 'sigmoid',
    theme: { primary: 'oklch(0.525 0.235 264)', radius: '1rem' },
    rate_limit_per_minute: 60,
    rate_limit_per_day: null,
    retention_days: 30,
    collect_end_user_data: true,
    consent_text: 'We keep your email.',
  } as const;

  it('the defaults factory reaches exactly the 24 mutable fields, and no identifier', () => {
    // The ONLY path from server data into this form's state. A `reset({...bot})` would keep `id`,
    // `public_bot_id`, `retrieval_configuration_version`, `created_at` and `updated_at`, and the
    // second of those is the one that matters: it is the token every live embed carries.
    //
    // TWENTY-FOUR AND NOT TWENTY-FIVE: `status` left with the PATCH field.
    const source = SEEDABLE_ROW;
    const seeded = botFormDefaults(source);

    expect(Object.keys(seeded)).toHaveLength(24);
    for (const banned of [
      // `status` is FIRST because it is the newest and the least obvious: it is not server-owned in
      // the way the five below are — an operator moves it — but it is not this form's to send, and a
      // seeded `status` would ride a rename back into a PATCH that answers 422.
      'status',
      // The flag itself is a STATEMENT ABOUT the body, not a field of the bot: `UpdateBotRequest`
      // rules no such key, so a seeded one would be an unknown key `strictObject` rejects.
      'instructions_visible',
      'id',
      'public_bot_id',
      'retrieval_configuration_version',
      'created_at',
      'updated_at',
      'organization_id',
    ]) {
      expect(Object.keys(seeded), banned).not.toContain(banned);
    }

    // It is a value the schema itself accepts — the property a settings form depends on, and the one
    // a pick that dropped a required-shaped field would break.
    expect(settings(seeded).success).toBe(true);

    // THE THEME IS COPIED, not passed through: the resource's object is shared with the query cache,
    // and handing it to a form that then edits a colour would mutate the cached row in place —
    // TanStack Query would then compare the "new" data against a value that had already changed.
    expect(seeded.theme).toEqual(source.theme);
    expect(seeded.theme).not.toBe(source.theme);
  });

  it('OMITS both instruction fields on a withheld row, rather than seeding the projected null', () => {
    // THE DATA-LOSS PATH, ASSERTED AT ITS SOURCE. A body with `instructions_visible: false` carries
    // `null` for both fields whatever is stored — the projection, not the bot's state. Seeding those
    // nulls makes them form values, and `UpdateBotRequest` rules both `sometimes|nullable|string`:
    // an OMITTED key is left alone, a PRESENT null CLEARS the column and returns 200. So saving an
    // unrelated rename would wipe two operator-authored prompts.
    //
    // The assertion is about the KEYS and not the values, because that is the whole distinction: a
    // `system_instruction: null` in this object would be indistinguishable from the withheld row in
    // the PATCH body, which is exactly the bug.
    const withheld = botFormDefaults({
      ...SEEDABLE_ROW,
      instructions_visible: false,
      system_instruction: null,
      answer_style_instruction: null,
    });

    expect(Object.keys(withheld)).not.toContain('system_instruction');
    expect(Object.keys(withheld)).not.toContain('answer_style_instruction');
    // Both or neither: a partial refusal would leave one prompt writable from a value nobody read.
    expect(Object.keys(withheld)).toHaveLength(22);

    // Everything else is seeded exactly as before — the refusal is two keys wide, not a degraded
    // form. `name` is the field whose save is the one that used to destroy the prompts.
    expect(withheld.name).toBe(SEEDABLE_ROW.name);
    expect(withheld.consent_text).toBe(SEEDABLE_ROW.consent_text);

    // And the narrowed object is still a body the schema accepts: `sometimes` on every field is what
    // makes a subset a legitimate PATCH, and `strictObject` is what would have caught a stray key.
    expect(settings(withheld).success).toBe(true);
  });

  it('the create defaults are a value the schema accepts once the two identifiers are typed', () => {
    const empty = botCreateDefaults();
    // NOT accepted as-is: `name` and `slug` are `required`, and an empty create form is not a
    // submittable body. That is the point of rendering it.
    expect(create(empty).success).toBe(false);
    expect(create({ ...empty, ...CREATE_BASE }).success).toBe(true);

    // The blank text inputs become nulls rather than empty strings: "not set", not "set to blank".
    const filled = create({ ...empty, ...CREATE_BASE });
    expect(filled.success && filled.data.description).toBeNull();
    expect(filled.success && filled.data.consent_text).toBeNull();
    // The four retrieval depths are seeded with the server's own defaults rather than left empty,
    // because `rerank_retain`'s band starts at 6 — a blank control there is outside the range, not
    // merely unhelpful.
    expect(empty.rerank_retain).toBe(6);
    expect(empty.rerank_candidates).toBe(20);
    // The unthemed state is `{}`, which renders the platform theme and is the normal one.
    expect(empty.theme).toEqual({});
    // No `status`: a bot is created `draft` and the field is not in the create body at all.
    expect(Object.keys(empty)).not.toContain('status');
  });
});

/**
 * THE LIFECYCLE TRANSITION, WHICH IS A WHOLE ENDPOINT AND A ONE-FIELD SCHEMA.
 *
 * Everything interesting about it is unprobeable, because the server judges the MOVE against the row
 * as it stands and a schema only ever sees the submitted value. What can be asserted here is the
 * value set and the shape — and the value set is the load-bearing half, because this is now the only
 * place `BOT_STATUSES` is compared against the server at all.
 */
describe('the bot status transition: what a one-field schema can and cannot mirror', () => {
  const transition = (value: unknown) => botStatusTransitionSchema.safeParse(value);

  it('accepts exactly the five members the FormRequest lists, and the tuple is the same five', () => {
    // The tuple is what a `<Select>` iterates and the enum is what the resolver checks; two
    // spellings of one list is how an option that cannot be submitted gets rendered. The `in:` probes
    // pin the ENUM to the server; this pins the TUPLE to the enum, and the pair is what makes the
    // transition menu honest.
    for (const status of BOT_STATUSES) expect(transition({ status }).success, status).toBe(true);
    expect(BOT_STATUSES).toHaveLength(5);
    expect(transition({ status: 'deleted' }).success).toBe(false);
    expect(transition({ status: 'unpublished' }).success).toBe(false);
  });

  it('requires the field, because this request says `required` and not `sometimes`', () => {
    // Unlike every other PATCH-shaped body in this package. A transition with no target is not a
    // partial update, it is not a request.
    expect(transition({}).success).toBe(false);
    expect(transition({ status: null }).success).toBe(false);
    expect(transition({ status: '' }).success).toBe(false);
  });

  it('refuses a settings field rather than stripping it, which is the whole point of the split', () => {
    // The body a form would post if somebody wired this control into the settings form by habit.
    // `strictObject` makes it a parse failure here rather than a request that renames the bot as a
    // side effect of publishing it.
    expect(transition({ status: 'published', name: 'Support desk' }).success).toBe(false);
    expect(transition({ status: 'published', organization_id: '01JSOMEONEELSE' }).success).toBe(
      false,
    );
  });

  it('does NOT mirror the transition rules, and cannot — the named residual', () => {
    // Three of the server's four refusals are properties of the STORED ROW: `archived` is terminal,
    // the publish guard refuses `published` for a bot with no provider connection and model or with
    // `rag_first` and `allow_general_answers` still false (both 409), and re-asserting the status a
    // bot already holds is a 422 because a no-op would write an audit row describing a change that
    // did not happen.
    //
    // All four are ACCEPTED here, which is the tolerable direction: a refused move is a visible 409
    // or 422 carrying Laravel's own sentence, keyed to the control. The alternative — a client that
    // greys out the options it believes are unreachable — is a console computing the publish guard
    // from a cached row, and it fails by hiding a move the server would have allowed.
    expect(transition({ status: 'draft' }).success).toBe(true);
    expect(transition({ status: 'published' }).success).toBe(true);
  });

  it('opens on the status the bot is IN, which is deliberately a value the server refuses', () => {
    // Submitting it unchanged is the no-op 422, and that is correct rather than awkward: the control
    // shows where the bot stands, and "save without choosing anything" is not a transition. Seeding
    // some other member instead would put a lifecycle move one mis-click away AND would misreport
    // the current state while it sat there.
    const seeded = botStatusTransitionDefaults({ status: 'testing' });
    expect(seeded).toEqual({ status: 'testing' });
    expect(transition(seeded).success).toBe(true);
    // A narrow pick, not a spread: a `BotResource` handed to `reset()` would round-trip
    // `public_bot_id`, and `strictObject` is what turns the shortcut into a parse failure.
    expect(Object.keys(seeded)).toEqual(['status']);
  });
});

/**
 * THE WIDGET ORIGIN ALLOW-LIST, and the block is mostly a NEGATIVE one: it asserts the SHAPE of a
 * deliberate gap, so the gap stays the one that was argued for rather than quietly widening into
 * "the client checks nothing about an origin".
 *
 * Same treatment as `ReadableThemeColor`'s residual test one section up, and for a stronger reason:
 * this rule is a security control. A third spelling of it here would be the copy nothing compares to
 * `App\Support\Web\ExactOrigin` or to `bot_domains_origin_exact`, and its failure direction is the
 * bad one — one case stricter than the server refuses an origin the operator really can embed on,
 * with no 422 to explain it.
 */
describe('the widget origin: what is mirrored, and the security control that is not', () => {
  const domain = (value: unknown) => botDomainCreateSchema.safeParse(value);
  const lifecycle = (value: unknown) => botDomainStatusSchema.safeParse(value);

  it('mirrors the length, the type and the two-scheme prefix — and nothing else', () => {
    expect(domain({ origin: 'https://example.com' }).success).toBe(true);
    expect(domain({ origin: 'http://localhost:3000' }).success).toBe(true);
    // `max:255`, which the generated probes also reach through `originOfLength`.
    expect(domain({ origin: `https://${'a'.repeat(60)}.example.com` }).success).toBe(true);
    expect(domain({ origin: `https://${'a'.repeat(300)}.example` }).success).toBe(false);
    // The prefix, which is the mistake operators actually make.
    expect(domain({ origin: 'example.com' }).success).toBe(false);
    expect(domain({ origin: 'ftp://example.com' }).success).toBe(false);
    expect(domain({ origin: 1234 }).success).toBe(false);
  });

  it('accepts the scheme in any case, because the server matches case-insensitively', () => {
    // `preg_match('#^(https?)://(.*)$#i', …)`. A case-SENSITIVE mirror would refuse a value the
    // server accepts and stores, which is the failure direction this whole file is organised around.
    expect(domain({ origin: 'HTTPS://Example.COM' }).success).toBe(true);
    expect(domain({ origin: 'Http://localhost:3000' }).success).toBe(true);
  });

  it('does NOT mirror the wildcard, path, userinfo, IPv6 or port refusals — the named residual', () => {
    // Measured against the installed `App\Support\Web\ExactOrigin`: every one of these is REFUSED
    // server-side, each with its own sentence, and every one of them parses here. That is the
    // tolerable direction — a visible 422 keyed `origin`, carrying the message the rule returns
    // precisely so a form can render it — and it is the whole reason `ExactWidgetOrigin` sits in
    // UNPROBED_RULES rather than being reproduced in Zod.
    for (const origin of [
      'https://*.example.com', // a wildcard: there is no wildcard grammar anywhere in this system
      'https://example.com/widget', // a path, REFUSED rather than trimmed: trimming widens the grant
      'https://example.com?utm=1', // a query
      'https://trusted.example@evil.test', // userinfo, the classic host-confusion primitive
      'http://[::1]:3000', // a legal origin, deliberately not storable
      'https://example.com:0', // not a port a browser emits
      'https://example.com:00443', // a second spelling of 443, a row that never matches
      'https://bücher.example', // an IDN; the browser sends the punycode form
    ]) {
      expect(domain({ origin }).success, origin).toBe(true);
    }
  });

  it('does NOT normalise, which is why nothing may render the submitted value', () => {
    // The server lower-cases the scheme and host, drops a single trailing slash, and drops a default
    // port — `HTTPS://Example.COM:443/` is STORED as `https://example.com`. This schema reproduces
    // none of that and must not: a client-side normaliser is a fourth spelling of a serialisation
    // that has to be byte-equal to what the browser sends.
    //
    // The consequence is the rule every caller has to obey: render `origin` from the POST's 201
    // body, never from the value that was submitted.
    const parsed = domain({ origin: 'HTTPS://Example.COM:443/' });
    expect(parsed.success).toBe(true);
    expect(parsed.success && parsed.data.origin).toBe('HTTPS://Example.COM:443/');
  });

  it('treats a cleared input as empty rather than as a blank origin', () => {
    // `TrimStrings` then `ConvertEmptyStringsToNull` run before any rule, so a cleared input is null
    // server-side and `required` refuses it. `.min(1)` after `.trim()` is where that becomes visible
    // on this side instead of after a round trip.
    expect(domain({ origin: '' }).success).toBe(false);
    expect(domain({ origin: '   ' }).success).toBe(false);
    expect(domain({}).success).toBe(false);
    expect(domain({ origin: null }).success).toBe(false);
    // …and the create form opens on exactly that state.
    expect(botDomainCreateDefaults()).toEqual({ origin: '' });
    expect(domain(botDomainCreateDefaults()).success).toBe(false);
  });

  it('the lifecycle tuple is exactly the enum the status schema accepts', () => {
    for (const status of BOT_DOMAIN_STATUSES) expect(lifecycle({ status }).success, status).toBe(true);
    expect(BOT_DOMAIN_STATUSES).toHaveLength(3);
    expect(lifecycle({ status: 'revoked' }).success).toBe(false);
    expect(lifecycle({}).success).toBe(false);
  });

  it('refuses an origin or a derived flag on the status body rather than stripping either', () => {
    // The origin is IMMUTABLE — this request declares no rule for it — so an edit that tried to
    // correct a typo would change what a live grant points at while every audit row naming it still
    // read the old string. `permits_embedding` is DERIVED from `status`, so round-tripping it posts a
    // second, stale spelling of the field being changed.
    expect(lifecycle({ status: 'active', origin: 'https://example.com' }).success).toBe(false);
    expect(lifecycle({ status: 'active', permits_embedding: true }).success).toBe(false);
    expect(lifecycle({ status: 'active', id: '01JDOMAINAAAAAAAAAAAAAAAAA' }).success).toBe(false);
  });

  it('opens the status control on the row it is editing', () => {
    const seeded = botDomainStatusDefaults({ status: 'pending' });
    expect(seeded).toEqual({ status: 'pending' });
    expect(Object.keys(seeded)).toEqual(['status']);
    expect(lifecycle(seeded).success).toBe(true);
  });
});

/**
 * THE STARTER-QUESTION CHIPS, and the PATCH here is the mirror image of every other PATCH in this
 * package: it deliberately does NOT carry `sometimes`, so the assertions that look symmetrical are
 * the wrong ones.
 *
 * `probesFor` suppresses every presence probe on a CROSS_FIELD field, and both fields are
 * `required_without` the other, so the entire presence question is asserted here by hand.
 */
describe('the starter questions: the rules a single-field probe cannot express', () => {
  const create = (value: unknown) => starterQuestionCreateSchema.safeParse(value);
  const update = (value: unknown) => starterQuestionUpdateSchema.safeParse(value);

  it('accepts either field alone — a rename and a move are both legitimate bodies', () => {
    expect(update({ question: 'How do I reset my password?' }).success).toBe(true);
    expect(update({ sort_order: 0 }).success).toBe(true);
    expect(update({ question: 'How do I reset my password?', sort_order: 3 }).success).toBe(true);
  });

  it('rejects the body that names NEITHER, which is the bug `sometimes` used to hide', () => {
    // The server found this one: with `sometimes` on both fields an empty PATCH satisfied everything
    // — `sometimes` short-circuits every remaining rule for an absent key, `required_without`
    // included — and returned 200 having changed nothing. Mirroring what looked symmetrical is what
    // this assertion exists to prevent a second time.
    expect(update({}).success).toBe(false);

    const refused = update({});
    // Keyed to the text, because that is the control an operator is looking at when they submit an
    // edit that names nothing.
    expect(refused.success === false && refused.error.issues[0]?.path).toEqual(['question']);
  });

  it('refuses a blank chip label on both schemas', () => {
    // `TrimStrings` then `ConvertEmptyStringsToNull` make a whitespace-only label null server-side,
    // where `required_without` or `string` refuses it, and
    // `bot_starter_questions_question_not_blank` refuses it again for every writer that is not an
    // HTTP request. A chip with no label is a control an end user can see, can click, and cannot
    // read.
    expect(create({ question: '' }).success).toBe(false);
    expect(create({ question: '   ' }).success).toBe(false);
    expect(update({ question: '', sort_order: 1 }).success).toBe(false);
    expect(create({ question: 'a'.repeat(200) }).success).toBe(true);
    expect(create({ question: 'a'.repeat(201) }).success).toBe(false);
  });

  it('keeps position ZERO expressible, which is the whole reason the field is preprocessed', () => {
    // `min:0` is zero-BASED, so 0 is the first chip rather than an unset value. `Number('')` is 0,
    // so a bare coercion would read a cleared input as "move this to the front" — a real and
    // destructive position rather than a missing one.
    expect(update({ sort_order: 0 }).success).toBe(true);
    expect(update({ sort_order: '' }).success).toBe(false);
    expect(update({ sort_order: null }).success).toBe(false);
    expect(update({ sort_order: -1 }).success).toBe(false);
    expect(update({ sort_order: 1.5 }).success).toBe(false);
    // `max:5` is a CEILING ON THE LIST wearing a bound on one row: positions are 0..n-1 and the
    // collection publishes `maxItems: 6`, which is the number of chips kb-ai-chat-ux renders.
    expect(update({ sort_order: 5 }).success).toBe(true);
    expect(update({ sort_order: 6 }).success).toBe(false);
  });

  it('carries no `sort_order` on create, and refuses one rather than stripping it', () => {
    // A new question is APPENDED by the server, which is the only position that cannot collide with
    // an existing one. The POST declares no rule for the field.
    expect(create({ question: 'Where are my invoices?', sort_order: 0 }).success).toBe(false);
    expect(starterQuestionCreateDefaults()).toEqual({ question: '' });
  });

  it('refuses a server-owned field on either schema', () => {
    // `reset(resource)` is the shortcut this makes impossible: `BotStarterQuestionResource` carries
    // `id`, `created_at` and `updated_at`, RHF keeps every key it is handed, and submit posts them
    // back — a 200, an audit row and no change.
    for (const key of ['id', 'created_at', 'updated_at', 'organization_id']) {
      expect(create({ question: 'Where are my invoices?', [key]: 'x' }).success, key).toBe(false);
      expect(update({ question: 'Where are my invoices?', [key]: 'x' }).success, key).toBe(false);
    }
  });

  it('seeds an edit from both stored fields, and the seed is a body the schema accepts', () => {
    const seeded = starterQuestionUpdateDefaults({ question: 'Where are my invoices?', sort_order: 4 });
    expect(seeded).toEqual({ question: 'Where are my invoices?', sort_order: 4 });
    expect(update(seeded).success).toBe(true);
  });
});

/**
 * ── THE ONE CLAIM `src/forms/upload.ts` SAID NOTHING ASSERTED, NOW ASSERTED ─────────────────────
 *
 * That module's docblock stated the property and named the test that would close it: *"a reviewer
 * check until the drift suite gains an upload case that parses the same File against two different
 * `OrgUploadLimits` and expects opposite results."* This is that case.
 *
 * WHY IT IS WORTH A BLOCK OF ITS OWN. §8.10 makes the maximum file size and the accepted MIME list
 * PER-ORGANIZATION, so `uploadSchema` is a FACTORY and there is deliberately no byte constant and
 * no MIME constant anywhere in this package or in apps/web. A refactor that "simplified" the
 * factory into a fixed schema — hoisting a default cap, defaulting the allow-list, memoising the
 * result and handing every organization the first one built — would keep every other test in this
 * file green: the form would still accept files, still refuse rubbish, and still round-trip. It
 * would simply enforce SOMEBODY ELSE'S limits, and the symptom is an upload rejected server-side
 * with no client-side hint, or accepted client-side and rejected on arrival. The only way to see it
 * is to hold the file fixed and vary the DTO.
 *
 * THIS IS STILL NOT A SECURITY CLAIM, and the direction matters. `.mime()` reads `File.type`, which
 * the browser derives from the extension and any caller can forge; it filters the picker and
 * produces a fast message. The server's extension allow-list, its libmagic sniffing independent of
 * filename, its compression-ratio caps and its malware hook are the control. Everything below is
 * about the form agreeing with the ORGANIZATION it is rendering for — not about the form being
 * trusted.
 *
 * THE MANIFEST FOR THIS ENDPOINT HAS LANDED AND THIS SCHEMA STILL MIRRORS NOTHING, which is the
 * opposite of what this paragraph used to predict. It read "when `StoreSourceRequest.json` lands it
 * becomes a `MIRRORS` entry like every other schema" — and when it landed it described a THREE-ARM
 * body of which `uploadSchema` covers one arm: two of eleven validated paths, a per-file cap the
 * server states in KILOBYTES as a platform constant against a per-organization cap this factory takes
 * in BYTES, and a `file` rule no JSON probe can reach. So the request sits in `NO_CLIENT_FORM` as
 * OWED, with the measurement, and every case below is still the only thing standing between this
 * schema and the server.
 *
 * WHAT THAT CHANGES ABOUT THIS BLOCK: nothing to remove, and one thing to stop expecting. These cases
 * were written as the residue a probe cannot express, and they are currently the WHOLE check rather
 * than the residue. When the request graduates they go back to being what they were built as — the
 * harness has no generator that varies the LIMITS a schema was built from, and it never will, because
 * that is a property of the factory rather than of any one instantiation.
 */
describe('the upload form: the limits are the organization’s, not this package’s', () => {
  /** Two organizations that disagree on every axis. Neither set of numbers means anything on its
   *  own — the assertions are all about the same file landing differently under each. */
  const GENEROUS = { max_bytes: 32, allowed_mime: ['application/pdf', 'text/csv'], max_batch: 3 };
  const STRICT = { max_bytes: 8, allowed_mime: ['text/csv'], max_batch: 1 };

  /** `new File(...)` rather than a stub: `z.file()` checks `instanceof File`, and `File` has been a
   *  Node global since 20 — which is also why the schema uses it instead of `z.instanceof(FileList)`,
   *  a DOM type absent from Node that would crash this file at module load. */
  const file = (bytes: number, type: string, name = 'report.pdf'): File =>
    new File([new Uint8Array(bytes)], name, { type });

  const accepts = (limits: typeof GENEROUS, files: readonly File[]): boolean =>
    uploadSchema(limits).safeParse({ files: [...files] }).success;

  it('accepts and refuses the SAME file on size, decided only by which DTO built the schema', () => {
    const sixteen = file(16, 'application/pdf');
    // POSITIVE CONTROL FIRST. Without it, "the strict org refuses it" also passes on a schema that
    // refuses everything — which is what a broken factory would produce.
    expect(accepts(GENEROUS, [sixteen]), 'max_bytes 32 must accept 16 bytes').toBe(true);
    expect(accepts(STRICT, [sixteen]), 'max_bytes 8 must refuse 16 bytes').toBe(false);
  });

  it('puts the size boundary exactly where the DTO puts it', () => {
    // `.max()` on a file is INCLUSIVE, like Laravel's `max:`. A schema one byte out in either
    // direction is the drift nobody reports: it refuses a file the server would have taken.
    expect(accepts(STRICT, [file(8, 'text/csv', 'rows.csv')])).toBe(true);
    expect(accepts(STRICT, [file(9, 'text/csv', 'rows.csv')])).toBe(false);
  });

  it('accepts and refuses the SAME file on MIME, decided only by which DTO built the schema', () => {
    const pdf = file(4, 'application/pdf');
    expect(accepts(GENEROUS, [pdf]), 'a PDF is on the generous list').toBe(true);
    // The strict organization allows CSV only. Same bytes, same name, same object.
    expect(accepts(STRICT, [pdf]), 'a PDF is not on the strict list').toBe(false);
  });

  it('caps the BATCH from the DTO, and the cap is a count rather than a total size', () => {
    const one = file(1, 'text/csv', 'a.csv');
    const two = file(1, 'text/csv', 'b.csv');
    expect(accepts(GENEROUS, [one, two])).toBe(true);
    expect(accepts(STRICT, [one, two]), 'max_batch 1 must refuse two files').toBe(false);
    // ...and one file well under the strict cap still passes, so the refusal above is the COUNT and
    // not something else the strict DTO changed.
    expect(accepts(STRICT, [one])).toBe(true);
  });

  it('refuses an empty batch under every DTO, which is why the defaults do not parse', () => {
    for (const limits of [GENEROUS, STRICT]) {
      expect(accepts(limits, [])).toBe(false);
    }
  });

  it('seeds an empty form, and the seed is deliberately NOT a body the schema accepts', () => {
    // The only defaults factory in the package whose output fails its own schema, and it is correct:
    // `.min(1)` is what keeps the submit disabled until the user has chosen something. A default
    // that parsed would mean an empty batch is postable.
    expect(uploadDefaults()).toEqual({ files: [] });
    expect(uploadSchema(GENEROUS).safeParse(uploadDefaults()).success).toBe(false);
  });

  it('hands out a FRESH array each call, so two mounted forms are not one file list', () => {
    const first = uploadDefaults();
    const second = uploadDefaults();
    expect(first.files).not.toBe(second.files);
    // react-hook-form takes ownership of `defaultValues`; a shared module-level `[]` would make the
    // second form's picker append to the first form's rows.
    first.files.push(new File([], 'leaked.csv', { type: 'text/csv' }));
    expect(second.files).toEqual([]);
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
   * The one non-mirrored schema is named explicitly because it cannot be reached through `MIRRORS`;
   * when it becomes an entry, it moves and these lines go away.
   *
   * "BECAUSE IT HAS NO MANIFEST YET" IS WHAT THAT SENTENCE USED TO SAY, and it is no longer the
   * reason: `StoreSourceRequest.json` exists and `uploadSchema` mirrors two of its eleven validated
   * paths, so the request is `NO_CLIENT_FORM` as OWED and this schema stays hand-listed. The
   * distinction matters for this loop specifically — a reader who believed the only obstacle was a
   * missing dump would close it by adding a `MIRRORS` entry, which is the false mirror claim that
   * register exists to refuse.
   *
   * `botSettingsSchema` USED TO BE THE SECOND NAME HERE, carrying the label "(no manifest yet)". It
   * has two now — `StoreBotRequest` and `UpdateBotRequest` — so it is reached through `MIRRORS` like
   * every other real schema, and it arrived with a sibling (`botCreateSchema`) that this list would
   * have had no reason to know about. That is the closure argument working: the hand-written half
   * shrinks as the mechanical half grows.
   *
   * `uploadSchema` is a FACTORY over `OrgUploadLimits` (§8.10 makes the size and MIME limits
   * per-organization, so no byte or MIME constant may exist in this package), which is why it is
   * instantiated here rather than referenced. This closes only its OWNERSHIP property — the claim in
   * `src/forms/upload.ts` (the paragraph headed *THIS IS ASSERTED NOW*) that nothing asserts the file
   * at all is now narrower but still true of the property that matters there: nothing parses one File
   * against two different limit DTOs and expects opposite results. The limits below are therefore
   * arbitrary; only the path set is under test.
   *
   * THE CITATION USED TO CARRY A LINE NUMBER and it went stale the moment that file gained an import,
   * which is the whole argument against a `file:line` anchor in a comment nothing recomputes — the
   * paragraph heading above is greppable and does not drift. `OrgUploadLimits` also stopped being
   * declared in that file in the same change: it is a published component now
   * (`OrgUploadLimitsResource`), so its one definition moved to `src/resources/sources.ts` and
   * `src/forms/upload.ts` re-exports it. The annotation on the entry below still reads correctly —
   * `StoreSourceRequest` remains NO_CLIENT_FORM as OWED, because what it is owed is a create form
   * covering all three arms, not the limits endpoint that has now landed.
   */
  const everySchema = (): readonly (readonly [string, z.ZodType])[] => [
    ...Object.entries(MIRRORS).map(
      ([className, mirror]) => [className, mirror.schema] as readonly [string, z.ZodType],
    ),
    [
      'uploadSchema (mirrors no manifest — StoreSourceRequest is NO_CLIENT_FORM; a factory, so instantiated)',
      uploadSchema({ max_bytes: 1, allowed_mime: ['application/pdf'], max_batch: 1 }),
    ],
  ];

  it('no schema path intersects OWNERSHIP_KEYS', () => {
    const checked = everySchema();

    // A positive control on the LOOP, not on the schemas: an empty or truncated list would make every
    // assertion below vacuous, and `toEqual([])` on nothing passes. Twelve is the whole package today
    // — the eleven MIRRORS plus `uploadSchema`, which has no manifest. It is asserted rather than
    // commented because the number is the only thing standing between this loop and passing on an
    // empty list; when MIRRORS grows, this goes red once and the new count is a one-character edit
    // with a diff that says which schema arrived. It just did, twice: the two model-catalog schemas
    // took it from 9 to 11, and the two bot schemas took it to 12 while REMOVING a hand-written line
    // — `botSettingsSchema` stopped being named here and became a MIRRORS entry, so the net is +1.
    // Then FIVE arrived at once with the bot editor's child collections and the status transition
    // (12 -> 17), which is the closure argument paying off a second time: none of the five needed a
    // line here, and all five are now inside the ownership assertion the moment their MIRRORS entry
    // landed.
    expect(checked.length, 'every schema in the package must be reached').toBe(17);

    for (const [label, schema] of checked) {
      expect(schemaPaths(schema).filter(isOwnershipPath), label).toEqual([]);
    }
  });

  it('a strictObject rejects an ownership key instead of silently stripping it', () => {
    // THE BODY WITHOUT THE OWNERSHIP KEY MUST PARSE, and that half is new. The fixture this replaced
    // carried `starter_questions` and `retrieval.top_k` — two fields the shipped FormRequests do not
    // have — so after the schema was expanded it would have failed for the unknown keys rather than
    // for `organization_id`, and asserted nothing about ownership at all. A negative control needs a
    // positive one beside it or it is only a claim that SOMETHING was wrong.
    // NO `status`: `UpdateBotRequest` rules it `missing`, so a positive control carrying one would
    // fail
    // for that rather than proving anything about ownership.
    const legitimate = { name: 'Support bot', access_mode: 'public', welcome_message: 'Hi' };
    expect(botSettingsSchema.safeParse(legitimate).success).toBe(true);

    const result = botSettingsSchema.safeParse({
      ...legitimate,
      organization_id: '01JSOMEONEELSE',
    });
    // z.object() would strip it and hide the escalation attempt until something bypasses the parse.
    expect(result.success).toBe(false);

    // The create schema is a separate object and gets the same treatment: `strictObject` is a
    // property of each schema, not of the package.
    expect(
      botCreateSchema.safeParse({ name: 'Support bot', slug: 'support-bot' }).success,
    ).toBe(true);
    expect(
      botCreateSchema.safeParse({
        name: 'Support bot',
        slug: 'support-bot',
        organization_id: '01JSOMEONEELSE',
      }).success,
    ).toBe(false);

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
