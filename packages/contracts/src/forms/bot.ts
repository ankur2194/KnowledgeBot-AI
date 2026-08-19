import { z } from 'zod';

import { ULID_PATTERN } from './laravel-rules.js';

/**
 * Mirrors `App\Http\Requests\StoreBotRequest`, `App\Http\Requests\UpdateBotRequest` and
 * `App\Http\Requests\UpdateBotStatusRequest` (POST and PATCH
 * `/api/v1/organizations/{organization}/bots`, and PUT `…/bots/{bot}/status`).
 *
 * They MIRROR the FormRequests; they do not enforce them. Client validation is a UX affordance and
 * the FormRequest is the authority (rhf-zod-forms NN2). `test/form-drift.test.ts` probes all three
 * schemas against `rules/StoreBotRequest.json`, `rules/UpdateBotRequest.json` and
 * `rules/UpdateBotStatusRequest.json` — dumped from Laravel's own `rules()` by
 * `php artisan kb:dump-form-rules` — which is what keeps that claim honest rather than aspirational.
 *
 * ── TWO SCHEMAS FOR THE BOT BODY, NOT ONE WITH `.partial()`, AND THEY DIFFER IN EXACTLY ONE PLACE ─
 *
 * `botCreateSchema` mirrors the POST and `botSettingsSchema` mirrors the PATCH. Field for field they
 * are now the same key set, and the one difference between them is a difference the server declares:
 * `name` and `slug` are `required` on create and `sometimes|required` on update. Those are not the
 * same rule, and reading the second as the first is the mistake `form-drift.test.ts` devotes a whole
 * `describe` block to: `sometimes|required` means "if you sent it, it must not be empty", NOT "you
 * must send it". A schema that made them mandatory would remove the ability to PATCH one field
 * without re-sending the rest — functionality gone, nothing reported. Every other field is
 * optional-or-nullable on both requests, so the shared declarations below are shared rather than
 * duplicated.
 *
 * ── `status` IS ON NEITHER OF THEM, AND THAT IS THE INTERESTING HALF ────────────────────────────
 *
 * A lifecycle move is `PUT …/bots/{bot}/status` and nothing else. `UpdateBotRequest` now rules the
 * field `["missing"]` — a rule rather than a deletion, and the difference is the whole point: an
 * ABSENT rule makes `validated()` discard the key in silence, so a console would publish a bot, get
 * a 200, and find it still in draft. `missing` turns that into a 422 keyed `status`.
 *
 * IT WAS `["prohibited"]` AND THAT RULE DID NOT MEAN WHAT ITS NAME SAID. `validateProhibited` is
 * `! validateRequired`, so it passed for `null`, `""` and `[]` and `validated()` kept the key —
 * which reached a `NOT NULL` column and turned an accompanying rename into a lost edit behind a 500.
 * `validateMissing` asks whether the key is present at all, which is the rule this paragraph always
 * described.
 *
 * The mirror of "the server refuses this key" is "no schema declares this path", which is what
 * `strictObject` turns into a parse failure. So `status` appears in exactly one schema here —
 * `botStatusTransitionSchema` at the bottom of this file, against the transition endpoint's own
 * FormRequest — and `botSettingsSchema` cannot express a lifecycle move at all. `botFormDefaults`
 * therefore does not read `bot.status` either: a settings form that seeded it would send it back.
 *
 * WHY THE SERVER SPLIT IT. A transition is judged against the row as it stands — `archived` is
 * TERMINAL, and the publish guard refuses a move to `published` for a bot with no provider
 * connection and model, or with `rag_first` and `allow_general_answers` still false — and none of
 * that is a property of the submitted value, which is all a field rule can see.
 *
 * ── THE FIELDS THAT ARE UNREPRESENTABLE, AND WHY EACH ONE IS ────────────────────────────────────
 *
 * `organization_id` is an OWNERSHIP_KEY (src/forms/ownership.ts) and could not appear here even if
 * somebody wanted it to: the server takes the organization from the authenticated context and a
 * client that posts one is attempting escalation for a silent 200.
 *
 * `id`, `public_bot_id`, `retrieval_configuration_version`, `created_at` and `updated_at` are on
 * `BotResource` and are absent from both schemas. `public_bot_id` is the one worth naming twice: it
 * is server-minted once and is the token every live embed on the customer's own site carries, so a
 * form that round-tripped it is a form that can break every one of them with a 200. That is the
 * whole argument for `botFormDefaults`' narrow pick over `reset(resource)` — see its docblock.
 *
 * ── WHAT THIS MIRRORS OF THE TWO CUSTOM RULE OBJECTS, AND WHAT IT DELIBERATELY DOES NOT ─────────
 *
 * `App\Rules\EvidenceThresholdWithinScale` IS mirrored (`thresholdWithinScale` below): it is three
 * lines of comparison against a sibling field, and leaving it out would let the console submit `1.7`
 * on a bounded scale — a value the server refuses and the database refuses again.
 *
 * `App\Rules\ReadableThemeColor` IS NOT, and that is a decision rather than an omission. Its first
 * half is an `oklch()` grammar that already exists TWICE on purpose (`apps/web/src/lib/color.ts` at
 * render time, `App\Support\Theme\OklchColor` at write time) with
 * `tests/Contract/ThemeGrammarParityTest.php` holding the pair together by re-reading the TypeScript
 * source; its second half is a WCAG contrast ratio over a gamut-mapped sRGB triple. A copy here
 * would be a third spelling of the grammar that that parity test does not look at, plus a second
 * implementation of CSS Color 4 §13.2 gamut mapping in a package that is zero-dependency by
 * contract. So this schema carries the LENGTH and the TYPE, the console composes the grammar check
 * from the copy the parity test does watch, and a colour in the unreachable contrast band is a
 * visible 422 keyed to `theme.primary`. `UNPROBED_RULES` in test/form-drift.test.ts records that
 * residual out loud rather than leaving it to be discovered.
 */

/**
 * Every bound below is a SERVER number this file is mirroring. Named rather than inlined because
 * each one appears in two schemas here, and a literal repeated at two call sites is how the two
 * spellings start to disagree.
 *
 * The text lengths are absurdity bounds with no column equivalent (`text` is unbounded in
 * PostgreSQL) and exist so one request cannot put a megabyte of prose in a row that is read whole on
 * every chat turn. The four retrieval depths are docs/07 §12.7-12.12's own bands, which the
 * `bots_dense_top_k_range` family of CHECK constraints also carries — a value outside them is not a
 * tuning choice, it is a typo that changes what the §21.5 regression gate is comparing.
 */
const NAME_MAX = 120;
const SLUG_MAX = 64;
const DESCRIPTION_MAX = 2000;
const WELCOME_MESSAGE_MAX = 2000;
const PLACEHOLDER_TEXT_MAX = 200;
const SYSTEM_INSTRUCTION_MAX = 8000;
const ANSWER_STYLE_INSTRUCTION_MAX = 4000;
const CONSENT_TEXT_MAX = 2000;
const THEME_COLOR_MAX = 64;

const DENSE_TOP_K_MIN = 1;
const DENSE_TOP_K_MAX = 200;
const SPARSE_TOP_K_MIN = 1;
const SPARSE_TOP_K_MAX = 200;
const RERANK_CANDIDATES_MIN = 20;
const RERANK_CANDIDATES_MAX = 30;
const RERANK_RETAIN_MIN = 6;
const RERANK_RETAIN_MAX = 10;

/**
 * `min:-100`/`max:100` is an ABSURDITY bound and not a column bound: the column is
 * `double precision`, a logit is roughly ±10 in practice and a bounded score is 0-1, so anything
 * outside this is a typo or a unit confusion rather than a calibration. The SCALE-DEPENDENT bound —
 * [0, 1] on a bounded scale — is `thresholdWithinScale` below, because no declarative Laravel rule
 * can express "between 0 and 1 when a sibling equals X".
 */
const EVIDENCE_THRESHOLD_MIN = -100;
const EVIDENCE_THRESHOLD_MAX = 100;

/**
 * A limit of ZERO is not a limit, it is a bot that answers nobody, and it is a plausible typo for
 * "no limit" — which is spelled `null`. `min:1` here and `bots_rate_limits_positive` in the database
 * both refuse it.
 */
const RATE_LIMIT_PER_MINUTE_MAX = 100_000;
const RATE_LIMIT_PER_DAY_MAX = 100_000_000;
/** Ten years. Retention beyond it is indistinguishable from "keep forever", which is spelled null. */
const RETENTION_DAYS_MAX = 3650;

/**
 * The four closed vocabularies, as runtime tuples.
 *
 * THEY ARE HERE AND NOT IN `src/resources/bots.ts` for the reason `ORG_ROLES` is here and `Role` is
 * a union there: every module under `src/resources/` is re-exported from the ROOT entry, which is
 * budgeted at <=1 kB brotli inside apps/widget's app shell and must emit no runtime value at all
 * (test/resource-drift.test.ts asserts the built entry's export list). A `<Select>` needs a list it
 * can iterate; a resource type needs a union it can narrow. The two spellings are pinned to the
 * server independently — these by the `in:` probes in test/form-drift.test.ts, the unions by the
 * enum comparison in test/resource-drift.test.ts — so neither can drift without a red suite.
 *
 * `BOT_STATUSES` IS STILL EXPORTED AND ITS PIN MOVED RATHER THAN DISAPPEARING. It left
 * `botSettingsSchema` with the field, and if it had left the package with it the tuple would have
 * become a list nothing compares to the server — every status pill and every transition menu reads
 * it. `botStatusTransitionSchema` is now the one schema the `in:` probes reach it through, which is
 * exactly the vocabulary `UpdateBotStatusRequest` declares.
 */
export const BOT_STATUSES = ['draft', 'testing', 'published', 'paused', 'archived'] as const;
export const BOT_ACCESS_MODES = ['public', 'private'] as const;
export const BOT_ANSWER_MODES = ['strict', 'rag_first'] as const;
export const EVIDENCE_THRESHOLD_SCALES = ['logit', 'sigmoid', 'unit_interval'] as const;

/**
 * The six radii, matched EXACTLY against the values `packages/design-tokens` publishes. The renderer
 * compares the stored string against that set and drops anything else, so an arbitrary CSS length is
 * refused on write rather than silently ignored on render — which is the same argument
 * `ReadableThemeColor` makes about a colour, one layer down.
 */
export const THEME_RADII = [
  '0rem',
  '0.25rem',
  '0.5rem',
  '0.625rem',
  '0.75rem',
  '1rem',
] as const;

/**
 * The two scales that are BOUNDED, and the reason the third is not in the list.
 *
 * `logit` is unbounded and signed — NVIDIA's ranking endpoint returns one — while `sigmoid` and
 * `unit_interval` are 0-1. They are NOT interchangeable: only the bounds transfer, and the bounds
 * are not the calibration. Applying `0.30` to a logit passes almost everything and applying a logit
 * threshold to a bounded score refuses almost everything, and NEITHER RAISES: only the refusal rate
 * moves, only in aggregate. `1.7` is the half of that a range check can actually catch.
 */
const BOUNDED_SCALES: ReadonlySet<string> = new Set(['sigmoid', 'unit_interval']);

/**
 * Empty number inputs post "". `Number("")` is 0, so a bare `z.coerce.number().min(1)` says
 * "must be at least 1" on a CLEARED field instead of "required".
 *
 * `null` becomes `undefined` rather than passing through, which is what makes this the NON-nullable
 * spelling: every field using it is a server field with no `nullable` rule, so an explicit null must
 * be a client rejection too. `nullableIntField` is the other spelling, and the difference between
 * them is exactly the presence pair `probesFor` generates.
 */
const intField = (min: number, max: number) =>
  z.preprocess(
    (v) => (v === '' || v === null ? undefined : v),
    z.coerce.number().int().min(min).max(max),
  );

/**
 * The same field where the server said `nullable`, and the ORDER OF THE WRAPPERS is the whole
 * content of this helper.
 *
 * `.nullable()` is INSIDE the preprocess pipe and `.optional()` is outside it. Both short-circuit on
 * their own sentinel before delegating, so `null` never reaches `z.coerce.number()` — which would
 * turn it into 0 and then report "must be at least 1" on a field the operator deliberately cleared.
 * Writing it the other way round (`z.preprocess(...).nullable()`) puts the null check on the OUTSIDE
 * of the preprocess, so a cleared input's `''` is mapped to null AFTER the only thing that would have
 * honoured it, and lands on the coercion as a zero.
 *
 * A cleared input is `null` and not `undefined` because those are two different intentions on a
 * PATCH: absent means "leave it alone", null means "clear it back to the platform default". The two
 * presence probes in the drift harness keep them apart, and so must this.
 */
const nullableIntField = (min: number, max: number) =>
  z
    .preprocess(
      (v) => (v === '' ? null : v),
      z.coerce.number().int().min(min).max(max).nullable(),
    )
    .optional();

/**
 * A `nullable|string|max:N` text field.
 *
 * BLANK BECOMES NULL, not an empty string and not a validation error, because that is what the
 * server sees: `TrimStrings` runs first, then `ConvertEmptyStringsToNull` turns `""` into null, both
 * BEFORE any rule in the manifest. So a cleared textarea posts `""`, the server stores null, and a
 * schema that kept `""` would round-trip a value the database refuses for every writer that is not
 * an HTTP request (`bots_text_not_blank`).
 *
 * `.trim()` before `.max()` for the same reason in the other direction: two spaces plus 1,999
 * characters is 1,999 server-side and 2,001 in the browser, and only the browser would refuse it.
 */
const clearableText = (max: number) =>
  z
    .preprocess(
      (v) => (typeof v === 'string' && v.trim() === '' ? null : v),
      z.string().trim().max(max).nullable(),
    )
    .optional();

/** `required|string|min:1|max:120` — `required` refuses the empty string, which `.min(1)` mirrors. */
const name = z
  .string()
  .trim()
  .min(1, { error: 'Give this bot a name your team will recognise.' })
  .max(NAME_MAX);

/**
 * `bots_slug_shape` verbatim: lower-case alphanumerics and internal hyphens, 1-64 characters, never
 * starting or ending with one. Two independent statements of one grammar (the CHECK constraint and
 * the FormRequest's `regex:`), and this is the third — mirrored rather than left to the server
 * because the message is the only thing that tells an operator what a slug IS.
 *
 * UNIQUENESS IS NOT MIRRORED AND CANNOT BE. It is per ORGANIZATION, and an unscoped `unique:` check
 * would be an existence oracle over the whole platform rendered as a validation error on a form; the
 * server does it through an org-scoped repository method and answers 422. There is no `unique:` rule
 * in either manifest for the same reason, so the drift harness never asks about it.
 *
 * THE PATTERN COSTS THE DRIFT HARNESS ITS GENERIC SIZER — `regex` is a `FORMAT_RULES` member, so
 * `sizerFor` returns `undefined` and both `max:64` probes disappear in silence. Both Mirrors in
 * test/form-drift.test.ts therefore declare a `sized` generator for this path, and
 * `missingSizeProbes()` is what turns a forgotten one into a red build.
 */
/**
 * `security/detect-unsafe-regex` flags the middle group: `[a-z0-9-]{0,62}` and the `[a-z0-9]` after
 * it overlap, so a backtracking engine can retry the split. The disable is deliberate and the
 * alternatives are both worse. The repetition is BOUNDED at 62 by the pattern itself — there is no
 * unbounded quantifier and therefore no exponential blowup, only a 63-step retry on a string this
 * schema has already capped at 64 characters. And the unambiguous rewrite
 * (`[a-z0-9](?:[a-z0-9-]*[a-z0-9])?`) is a DIFFERENT grammar: it drops the length bound the server's
 * pattern carries, which would make this a third spelling agreeing with neither `bots_slug_shape`
 * nor the FormRequest. Transcribed verbatim is the property worth having.
 */
// eslint-disable-next-line security/detect-unsafe-regex
const SLUG = /^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/;

const slug = z
  .string()
  .trim()
  .min(1, { error: 'Give this bot a handle.' })
  .max(SLUG_MAX)
  .regex(SLUG, {
    error:
      'A handle is lower-case letters, digits and internal hyphens — `support-desk`, not ' +
      '`Support Desk` — and it may not start or end with a hyphen. It is unique within this ' +
      'organization only: another organization using the same handle is not a conflict.',
  });

/**
 * A tenant-supplied theme colour: `sometimes|string|max:64|ReadableThemeColor`.
 *
 * `.min(1)` MIRRORS A RULE THAT IS NOT IN THE MANIFEST, and it is not this package being stricter
 * than the server. `ConvertEmptyStringsToNull` turns `""` into null before any rule runs, `theme.primary`
 * is NOT `nullable`, and `string` then refuses the null — so the server rejects `theme: {primary: ""}`
 * with a message about a string. The lower bound is where that behaviour becomes visible on this
 * side; without it a cleared colour input submits and comes back a 422 nobody predicted.
 *
 * THE GRAMMAR AND THE CONTRAST FLOOR ARE NOT HERE. See the module docblock: three copies of an
 * `oklch()` pattern with a parity test watching two of them is worse than two copies and a stated
 * gap.
 */
const themeColor = z
  .string()
  .trim()
  .min(1, { error: 'Enter an `oklch()` colour, or leave the field out to use the platform theme.' })
  .max(THEME_COLOR_MAX)
  .optional();

/**
 * `sometimes|array:primary,accent,radius`, which CLOSES the key set — an unknown key is a 422 rather
 * than a value stored forever and rendered nowhere. `strictObject` is that rule on this side.
 *
 * EVERY MEMBER IS OPTIONAL AND THE OBJECT ITSELF IS OPTIONAL, which are two different facts: an
 * absent `theme` leaves the stored one alone, and `{}` is the unthemed state. Neither is null —
 * neither request declares `nullable` on this path, so an explicit null is a rejection on both
 * sides, and the harness's presence pair asks about exactly that.
 *
 * Every other custom property the renderer writes — the whole `-foreground` and accent-ramp family —
 * is DERIVED from these three at render time and is never settable, because contrast is derived and
 * never chosen (kb-design-language).
 */
const theme = z
  .strictObject({
    primary: themeColor,
    accent: themeColor,
    radius: z.enum(THEME_RADII).optional(),
  })
  .optional();

/**
 * `nullable|numeric|min:-100|max:100`, plus `required_with:evidence_threshold_scale` and the custom
 * rule — the last two are cross-field and live in `evidencePair`/`thresholdWithinScale` below.
 *
 * COERCED, unlike `providerModelCreateSchema`'s prices, and the two are answering different
 * questions. A price is an exact decimal STRING that must round-trip byte for byte through an audit
 * row; a threshold is published on `BotResource` as a JSON `number`, so there is no representation to
 * preserve and the only hazard is a `<input type="number">` handing over a string. Laravel's
 * `numeric` accepts both spellings, and coercing here mirrors that rather than being stricter.
 */
const evidenceThreshold = z
  .preprocess(
    (v) => (v === '' ? null : v),
    z.coerce
      .number()
      .min(EVIDENCE_THRESHOLD_MIN)
      .max(EVIDENCE_THRESHOLD_MAX)
      .nullable(),
  )
  .optional();

const evidenceThresholdScale = z
  .preprocess((v) => (v === '' ? null : v), z.enum(EVIDENCE_THRESHOLD_SCALES).nullable())
  .optional();

/** `nullable|string|ulid` — a REFERENCE to a stored credential, never key material. */
const connectionReference = z
  .preprocess(
    (v) => (v === '' ? null : v),
    z.string().trim().regex(ULID_PATTERN).nullable(),
  )
  .optional();

/**
 * The shape the three `superRefine`s below read. Written out rather than inferred because the two
 * schemas have different key sets and a refinement shared between them may only depend on the
 * fields they have in common.
 */
interface EvidenceAndModelSelection {
  readonly evidence_threshold?: number | null;
  readonly evidence_threshold_scale?: 'logit' | 'sigmoid' | 'unit_interval' | null;
  readonly provider_connection_id?: string | null;
  readonly provider_model_id?: string | null;
}

/** Present AND non-null. Absent and explicitly-null are different intentions and the same verdict here. */
const isSet = (value: unknown): boolean => value !== undefined && value !== null;

/**
 * `evidence_threshold` and `evidence_threshold_scale` are `required_with` EACH OTHER, and this
 * mirrors both directions.
 *
 * `bots_evidence_threshold_paired` CHECKs `num_nonnulls(threshold, scale) <> 1` one layer down: a
 * number with no scale, or a scale with no number, is uninterpretable and the table will not hold
 * it. The pair is not a tidiness rule — the same float is an unbounded logit on one provider and a
 * bounded relevance score on another, so half a pair is a number in no units at all.
 *
 * WHY THIS IS A `superRefine` AND NOT A FIELD RULE: the verdict depends on a sibling, the drift
 * harness classifies `required_with` as CROSS_FIELD, and it SUPPRESSES every presence probe on a
 * field carrying one — it cannot answer "is this field required?" from one field's rule list. So
 * this refinement is asserted by hand in that file's cross-field section rather than by a generated
 * probe, which is also why the message is written for a person.
 *
 * IT IS NOT THE WHOLE RULE ON THE PATCH, and the server says so itself: a body that clears only the
 * scale passes every declarative rule and still violates the CHECK against the STORED threshold, so
 * `BotService` re-checks the resulting pair on both paths and is the authority. This mirrors what
 * `rules()` declares, which is all a generated client can be told about.
 */
const evidencePair = (value: EvidenceAndModelSelection, ctx: z.RefinementCtx): void => {
  const threshold = isSet(value.evidence_threshold);
  const scale = isSet(value.evidence_threshold_scale);

  if (threshold === scale) return;

  ctx.addIssue({
    code: 'custom',
    path: [threshold ? 'evidence_threshold_scale' : 'evidence_threshold'],
    message:
      'An evidence threshold and its scale are meaningless apart: the same float is an unbounded ' +
      'logit on one provider and a bounded relevance score on another, and applying one scale’s ' +
      'number to the other moves only the refusal rate, only in aggregate, and raises nothing ' +
      'anywhere. Set both, or clear both.',
  });
};

/**
 * `App\Rules\EvidenceThresholdWithinScale`, mirrored.
 *
 * The bound depends on the VALUE of a sibling, which Laravel's declarative rules cannot express —
 * they can say "required when a sibling equals X" and not "between 0 and 1 when a sibling equals X"
 * — so the server states it as a `DataAwareRule` OBJECT rather than an `after()` closure, precisely
 * so `kb:dump-form-rules` records it by class name and a client can be told the constraint exists
 * (docs/22 finding 19). This is the client being told.
 *
 * IT DOES NOT RE-CHECK THE PAIRING. A request that failed `evidencePair` should not also collect a
 * range error about a scale it never sent, which is the same order the server's own rule observes.
 *
 * THE HARNESS CANNOT PROBE THIS and `UNPROBED_RULES` records why, so this refinement's correctness
 * rests on the hand-written assertions in that file's cross-field section rather than on a generated
 * probe. What the harness DOES depend on is the baseline: every generated probe on either field is
 * one mutation away from `{evidence_threshold: 0.5, evidence_threshold_scale: 'logit'}`, and that
 * pair is chosen so both directions stay honest — 0.5 is inside [0, 1] so the three `in:` probes on
 * the scale all really are server-accepted, and `logit` is unbounded so the `min:-100`/`max:100`
 * probes on the threshold really are too. Any other baseline turns one of those probe sets into a
 * claim about the server that is false.
 */
const thresholdWithinScale = (value: EvidenceAndModelSelection, ctx: z.RefinementCtx): void => {
  const threshold = value.evidence_threshold;
  const scale = value.evidence_threshold_scale;

  if (typeof threshold !== 'number' || typeof scale !== 'string') return;
  if (!BOUNDED_SCALES.has(scale)) return;
  if (threshold >= 0 && threshold <= 1) return;

  ctx.addIssue({
    code: 'custom',
    path: ['evidence_threshold'],
    message:
      `A threshold on the \`${scale}\` scale is between 0 and 1: that scale is bounded, and a ` +
      'value outside its own range would refuse every answer without raising anything anywhere. ' +
      'Use the `logit` scale for a provider that returns unbounded signed scores.',
  });
};

/**
 * `provider_connection_id` is `required_with:provider_model_id` — ONE DIRECTION ONLY, and the
 * asymmetry is the content of the rule.
 *
 * A connection with no model is a real and common state: "I have chosen the vendor, not the model
 * yet". Both columns are nullable with `MATCH SIMPLE` foreign keys precisely so a half-configured
 * draft is expressible. A MODEL with no connection is not a state at all — a `provider_models` row
 * names a credential only through its parent — so the pair would name no credential. Nothing in the
 * database refuses that, which is why `BotService::assertModelSelection()` does and why the form
 * should not offer it.
 *
 * The path is `provider_connection_id` because that is the control the operator has to change: the
 * model they picked is not the mistake.
 */
const modelNeedsConnection = (value: EvidenceAndModelSelection, ctx: z.RefinementCtx): void => {
  if (!isSet(value.provider_model_id) || isSet(value.provider_connection_id)) return;

  ctx.addIssue({
    code: 'custom',
    path: ['provider_connection_id'],
    message:
      'A model needs the connection it is registered under: a catalog row names a credential only ' +
      'through its parent connection, so a bot naming a model with no connection names no ' +
      'credential. A connection with no model is fine — that is a bot whose vendor is chosen and ' +
      'whose model is not.',
  });
};

const crossField = (value: EvidenceAndModelSelection, ctx: z.RefinementCtx): void => {
  evidencePair(value, ctx);
  thresholdWithinScale(value, ctx);
  modelNeedsConnection(value, ctx);
};

/**
 * The fields both requests declare identically. Spread into both schemas rather than expressed as
 * `botCreateSchema.partial()`, because `.partial()` would loosen `name` and `slug` — the two fields
 * that genuinely differ — and would silently add `status` to the create body.
 *
 * `strictObject`, not `object`: an unknown key means the defaults builder leaked a server field into
 * form state. `z.object()` strips it silently and hides the bug until something bypasses the parse —
 * FormData uploads do. Ownership keys are unrepresentable here by construction.
 */
const sharedBotFields = {
  description: clearableText(DESCRIPTION_MAX),
  welcome_message: clearableText(WELCOME_MESSAGE_MAX),
  placeholder_text: clearableText(PLACEHOLDER_TEXT_MAX),
  system_instruction: clearableText(SYSTEM_INSTRUCTION_MAX),
  answer_style_instruction: clearableText(ANSWER_STYLE_INSTRUCTION_MAX),

  access_mode: z.enum(BOT_ACCESS_MODES).optional(),
  answer_mode: z.enum(BOT_ANSWER_MODES).optional(),
  /**
   * The one field an operator must set on purpose before a bot may answer from anything but its
   * sources. Separate from `answer_mode` so "RAG-first but not yet cleared to publish" stays
   * expressible.
   */
  allow_general_answers: z.boolean().optional(),

  provider_connection_id: connectionReference,
  provider_model_id: connectionReference,

  dense_top_k: intField(DENSE_TOP_K_MIN, DENSE_TOP_K_MAX).optional(),
  sparse_top_k: intField(SPARSE_TOP_K_MIN, SPARSE_TOP_K_MAX).optional(),
  rerank_candidates: intField(RERANK_CANDIDATES_MIN, RERANK_CANDIDATES_MAX).optional(),
  rerank_retain: intField(RERANK_RETAIN_MIN, RERANK_RETAIN_MAX).optional(),

  evidence_threshold: evidenceThreshold,
  evidence_threshold_scale: evidenceThresholdScale,

  theme,

  rate_limit_per_minute: nullableIntField(1, RATE_LIMIT_PER_MINUTE_MAX),
  rate_limit_per_day: nullableIntField(1, RATE_LIMIT_PER_DAY_MAX),
  retention_days: nullableIntField(1, RETENTION_DAYS_MAX),

  /**
   * `collect_end_user_data` true is only STORABLE together with `consent_text`, and that pairing is
   * deliberately NOT mirrored here, because it is deliberately not in `rules()` either. The server's
   * own note: the rule would be correct on the POST and wrong on the PATCH, where enabling
   * collection on a bot that already carries a disclosure would be refused for a field the caller
   * had no reason to resend. The whole check lives in `BotService`, evaluated against the RESULTING
   * row on both paths, with `bots_consent_text_present_when_collecting` as the database's copy.
   *
   * A client-side copy would therefore be a rule this package invented for the PATCH: it would block
   * `{collect_end_user_data: true}` on a bot whose disclosure is already stored, which is a body the
   * server accepts. That is the direction that removes functionality with nothing reported, so the
   * console renders the two controls together and lets the server's 422 key to `consent_text`.
   */
  collect_end_user_data: z.boolean().optional(),
  consent_text: clearableText(CONSENT_TEXT_MAX),
};

/**
 * POST — create one bot. `name` and `slug` are the only required fields; everything else falls to
 * `NewBot`'s own defaults rather than being written as null, which is what keeps "the caller said
 * nothing" and "the caller cleared it" apart on the one endpoint where they happen to coincide.
 */
export const botCreateSchema = z
  .strictObject({
    name,
    slug,
    ...sharedBotFields,
  })
  .superRefine(crossField);

export type BotCreateIn = z.input<typeof botCreateSchema>;
export type BotCreateOut = z.output<typeof botCreateSchema>;

/**
 * PATCH — the bot settings form. EVERY FIELD IS OPTIONAL, and that is the request's shape rather
 * than this schema's convenience: `sometimes|required` on `name` means "if you sent it, it must not
 * be empty", so a body carrying one field is a legitimate PATCH. Making them mandatory here would
 * make a single-field save impossible while every test stayed green — see the `sometimes` block in
 * test/form-drift.test.ts, which exists because that is the fix a false red invites.
 */
export const botSettingsSchema = z
  .strictObject({
    name: name.optional(),
    slug: slug.optional(),
    /**
     * NO `status`, AND ITS ABSENCE IS THE MIRROR OF A RULE RATHER THAN AN OMISSION.
     * `UpdateBotRequest` rules it `["missing"]`, so a body carrying one is a 422 keyed `status` —
     * and the client-side spelling of "this key may not be sent" is a `strictObject` that does not
     * declare the path. See the module docblock; the transition lives in
     * `botStatusTransitionSchema`.
     */
    ...sharedBotFields,
  })
  .superRefine(crossField);

export type BotSettingsIn = z.input<typeof botSettingsSchema>;
export type BotSettingsOut = z.output<typeof botSettingsSchema>;

/**
 * The narrow shape `botFormDefaults` reads. STRUCTURAL ON PURPOSE and not `BotResource` itself: this
 * pick must keep compiling against that type (src/resources/bots.ts) without importing the five
 * server-owned fields it carries, so the day one of them is renamed this file does not have to move.
 *
 * `theme.radius` is typed as the six-member union rather than `string`, which makes
 * `botFormDefaults(bot)` a TYPECHECK failure if `BotThemeRadius` and `THEME_RADII` ever stop
 * agreeing — the one link between the resource union and the form tuple that neither drift suite
 * covers, since each pins its own spelling to the server independently.
 */
export interface BotFormSource {
  readonly name: string;
  readonly slug: string;
  /**
   * NO `status`. `UpdateBotRequest` rules it `missing`, `botSettingsSchema` therefore does not
   * declare it,
   * and a source shape that still carried it would be a shape whose only reader was a pick that must
   * not make one — the two would then disagree silently rather than at the typecheck. A screen
   * rendering the current lifecycle state reads it off `BotResource` directly; only a form's state
   * is narrowed here.
   */
  readonly description: string | null;
  readonly welcome_message: string | null;
  readonly placeholder_text: string | null;
  readonly system_instruction: string | null;
  readonly answer_style_instruction: string | null;
  /**
   * REQUIRED, AND IT MAY NOT GAIN A DEFAULT. It is the server's own statement of whether the two
   * fields above carry their stored values in the body this source came from, and `botFormDefaults`
   * reads it to decide whether either may be seeded at all — see that function.
   *
   * A default here would let a call site inherit a decision it never made, which is the same reason
   * `BotResource::__construct`'s `$withInstructions` is a required argument on the server. A
   * structural shape whose one boolean is optional is a shape where forgetting it means "visible".
   */
  readonly instructions_visible: boolean;
  readonly access_mode: (typeof BOT_ACCESS_MODES)[number];
  readonly answer_mode: (typeof BOT_ANSWER_MODES)[number];
  readonly allow_general_answers: boolean;
  readonly provider_connection_id: string | null;
  readonly provider_model_id: string | null;
  readonly dense_top_k: number;
  readonly sparse_top_k: number;
  readonly rerank_candidates: number;
  readonly rerank_retain: number;
  readonly evidence_threshold: number | null;
  readonly evidence_threshold_scale: (typeof EVIDENCE_THRESHOLD_SCALES)[number] | null;
  readonly theme: {
    readonly primary?: string;
    readonly accent?: string;
    readonly radius?: (typeof THEME_RADII)[number];
  };
  readonly rate_limit_per_minute: number | null;
  readonly rate_limit_per_day: number | null;
  readonly retention_days: number | null;
  readonly collect_end_user_data: boolean;
  readonly consent_text: string | null;
}

/**
 * THE TWO INSTRUCTION FIELDS, OR NEITHER — the whole of the management-only projection, kept in one
 * place so the two can never be seeded one at a time.
 *
 * ── OMISSION, NOT `null`, AND THE DIFFERENCE IS THE WHOLE FIX ───────────────────────────────────
 * `UpdateBotRequest` rules both `sometimes|nullable|string`. `sometimes` means an OMITTED key is
 * left alone; a PRESENT `null` is a legitimate "clear it" that returns 200. So a form seeded with
 * the projected `null` — which is what a body with `instructions_visible: false` carries whatever is
 * stored — writes `null` over an operator-authored system prompt the next time anybody saves a
 * rename. Narrow window, worst possible payload: it needs only a role promoted mid-session, a cached
 * detail row, or any refetch skew between the row a form was seeded from and the role the client
 * believes it has.
 *
 * Returning `{}` here means the keys never enter form state, so `handleSubmit`'s output — which is
 * the PATCH body verbatim — cannot carry them. `botSettingsSchema` is a `strictObject` of optional
 * fields, so a subset parses and the request is exactly those keys.
 *
 * ── THE FLAG IS THE SERVER'S, AND NOTHING HERE MAY RE-DERIVE IT ────────────────────────────────
 * `bots.manage` is resolved per record against that record's own organization. A client predicate
 * over a session role answers a different question, against a row that may have been fetched under a
 * different membership — which is precisely how a `true` lands on a body whose instructions were
 * withheld. `instructions_visible` is set from the same flag that decided the projection, so the two
 * cannot disagree.
 */
const instructionDefaults = (
  bot: BotFormSource,
): Pick<BotSettingsIn, 'system_instruction' | 'answer_style_instruction'> =>
  bot.instructions_visible
    ? {
        system_instruction: bot.system_instruction,
        answer_style_instruction: bot.answer_style_instruction,
      }
    : {};

/**
 * NEVER `reset(resource)`. The API Resource carries `id`, `public_bot_id`,
 * `retrieval_configuration_version`, `created_at` and `updated_at`; `reset()` replaces form state
 * with exactly what it is handed, `getValues()` returns those keys, and submit posts them back — a
 * 200, an audit row, and no change. This pick is the ONLY path from server data into form state, and
 * `strictObject` is what turns the spread that skips it into a parse failure rather than a silent
 * strip.
 *
 * `public_bot_id` IS THE ONE THAT WOULD NOT BE HARMLESS. It is server-minted once and is the token
 * every widget snippet, hosted-chat URL and theme stylesheet request on the customer's own site
 * carries; a form that round-tripped it is one careless rule away from a 200 that breaks all of them.
 *
 * THE THEME IS COPIED rather than passed through, for the reason `providerModelEditDefaults` copies
 * `supported`: the resource's object is shared with the query cache, and handing it to a form that
 * then edits a colour would mutate the cached row in place — TanStack Query would compare the "new"
 * data against a value that had already changed.
 *
 * ── THE KEY SET IS NOT FIXED, AND THE ONE THING THAT VARIES IS THE PROJECTION ───────────────────
 * `system_instruction` and `answer_style_instruction` are OMITTED — not nulled — on a source whose
 * `instructions_visible` is false, because a withheld value must not be able to be written back.
 * Every other key is always present. `instructionDefaults` above carries the argument; a caller that
 * needs "the fields this form may send" must read the returned object's keys rather than assume the
 * schema's.
 */
export const botFormDefaults = (bot: BotFormSource): BotSettingsIn => ({
  name: bot.name,
  slug: bot.slug,
  description: bot.description,
  welcome_message: bot.welcome_message,
  placeholder_text: bot.placeholder_text,
  // Both keys, or NEITHER. A withheld field must not reach form state at all — see
  // `instructionDefaults`, which is where the reason is written down.
  ...instructionDefaults(bot),
  access_mode: bot.access_mode,
  answer_mode: bot.answer_mode,
  allow_general_answers: bot.allow_general_answers,
  provider_connection_id: bot.provider_connection_id,
  provider_model_id: bot.provider_model_id,
  dense_top_k: bot.dense_top_k,
  sparse_top_k: bot.sparse_top_k,
  rerank_candidates: bot.rerank_candidates,
  rerank_retain: bot.rerank_retain,
  evidence_threshold: bot.evidence_threshold,
  evidence_threshold_scale: bot.evidence_threshold_scale,
  theme: { ...bot.theme },
  rate_limit_per_minute: bot.rate_limit_per_minute,
  rate_limit_per_day: bot.rate_limit_per_day,
  retention_days: bot.retention_days,
  collect_end_user_data: bot.collect_end_user_data,
  consent_text: bot.consent_text,
});

/**
 * What an empty create form holds.
 *
 * THE FOUR RETRIEVAL DEPTHS ARE SEEDED WITH `NewBot`'s OWN DEFAULTS rather than left empty, and the
 * numbers are copied from there deliberately: an empty numeric input registered with `valueAsNumber`
 * is `NaN`, which fails validation on a field the operator has not reached yet, and `rerank_retain`'s
 * band starts at 6 rather than at 1 — so a blank control is not merely unhelpful, it is outside the
 * accepted range. Re-sending a value the server would have defaulted to is free: the server moves
 * `retrieval_configuration_version` only when a knob's VALUE changes, so a console that submits its
 * whole form on every save does not mint a new configuration identity for a configuration that did
 * not move.
 *
 * `theme` starts as `{}` — the unthemed state, which renders the platform theme and is the normal
 * one. The three nullable text fields start as `''`, which `clearableText` turns into null: "not
 * set" rather than "set to blank".
 *
 * NO `status`: a bot is created `draft` and the field is not in the create body at all.
 */
export const botCreateDefaults = (): BotCreateIn => ({
  name: '',
  slug: '',
  description: '',
  welcome_message: '',
  placeholder_text: '',
  system_instruction: '',
  answer_style_instruction: '',
  access_mode: 'private',
  answer_mode: 'strict',
  allow_general_answers: false,
  provider_connection_id: null,
  provider_model_id: null,
  dense_top_k: 20,
  sparse_top_k: 20,
  rerank_candidates: 20,
  rerank_retain: 6,
  evidence_threshold: null,
  evidence_threshold_scale: null,
  theme: {},
  rate_limit_per_minute: null,
  rate_limit_per_day: null,
  retention_days: null,
  collect_end_user_data: false,
  consent_text: '',
});

/**
 * PUT `…/bots/{bot}/status` — mirrors `App\Http\Requests\UpdateBotStatusRequest`.
 *
 * ── ONE FIELD, AND IT IS THE ONLY SCHEMA IN THIS PACKAGE THAT MAY NAME IT ──────────────────────
 *
 * `status` is ruled `missing` on the PATCH, so this is where the vocabulary is submitted from and the
 * only place `BOT_STATUSES` is compared against the server: `test/form-drift.test.ts` probes
 * `in:"draft","testing","published","paused","archived"` member by member against this schema, which
 * is what keeps the tuple every status pill and transition menu iterates honest.
 *
 * ── IT MIRRORS THE VALUE SET AND CANNOT MIRROR THE TRANSITION, WHICH IS MOST OF THE RULE ───────
 *
 * The server judges the move against the row as it stands, and three of its four refusals are
 * invisible to any schema:
 *
 *   `archived` is TERMINAL — an archived bot is read-only, including its status, so there is no
 *   un-archive transition and no value this enum could add to express one.
 *
 *   the PUBLISH GUARD refuses a move to `published` for a bot with no provider connection and model,
 *   or with `answer_mode: 'rag_first'` and `allow_general_answers` still false. Both are 409.
 *
 *   a NO-OP is a 422: this is a transition rather than a state assertion, and re-asserting the
 *   status a bot already holds would write an audit row describing a change that did not happen.
 *
 * So a refused move arrives as a 409 or a 422 keyed `status` carrying Laravel's own translated
 * sentence, and the control renders it under itself rather than pre-judging the move. A client that
 * greyed out the options it believed were unreachable would be computing the publish guard from a
 * cached row.
 */
export const botStatusTransitionSchema = z.strictObject({
  status: z.enum(BOT_STATUSES),
});

export type BotStatusTransitionIn = z.input<typeof botStatusTransitionSchema>;
export type BotStatusTransitionOut = z.output<typeof botStatusTransitionSchema>;

/**
 * What the transition control opens on: the status the bot is IN.
 *
 * DELIBERATELY A VALUE THE SERVER REFUSES. Submitting it unchanged is the no-op 422 above, which is
 * correct rather than awkward — the control is a `<Select>` showing where the bot stands, and "save
 * without choosing anything" is not a transition. Seeding it with some other member instead would
 * put a lifecycle move one mis-click away and would misreport the current state while it sat there.
 *
 * A NARROW PICK LIKE EVERY OTHER DEFAULTS FACTORY HERE, not `reset(bot)`: `BotResource` carries
 * `public_bot_id` and four more server-owned fields, and a `strictObject` of one key is what turns
 * the spread that skips this into a parse failure rather than a silent strip.
 */
export const botStatusTransitionDefaults = (bot: {
  readonly status: (typeof BOT_STATUSES)[number];
}): BotStatusTransitionIn => ({ status: bot.status });
