// TYPE-ONLY, including the two tuples: this module reads them as `typeof` to name their member
// unions and never iterates them, so nothing here adds a runtime import of the forms barrel.
import type {
  BOT_ANSWER_MODES,
  BotSettingsIn,
  BotSettingsOut,
  EVIDENCE_THRESHOLD_SCALES,
} from '@kb/contracts/forms';
import updateBotRules from '@kb/contracts/rules/UpdateBotRequest.json';
import type { UseFormReturn } from 'react-hook-form';

import type { FormRulesManifest } from '@/lib/forms/known-paths';

/**
 * WHAT THE "Model & retrieval" TAB IS ALLOWED TO SAY, AND WHERE EVERY NUMBER IN IT CAME FROM.
 *
 * Private to `bot-model-panel.tsx` and its two sibling cards. It is a `.ts` and carries no JSX, for
 * the reason `bot-editor-context.ts` records — the `unit` Vitest project runs in `node` with no react
 * plugin and `jsx: "preserve"`, so a module a `tests/unit/**` spec reaches transitively must stay
 * JSX-free, and this is the module most likely to be reached that way if the depths are ever probed
 * from a unit spec.
 *
 * ── NOT ONE BOUND IS TYPED OUT HERE ─────────────────────────────────────────────────────────────
 * `min`/`max` come out of `packages/contracts/rules/UpdateBotRequest.json`, which
 * `php artisan kb:dump-form-rules` writes from `UpdateBotRequest::rules()`. That file is what the
 * SERVER enforces; `botSettingsSchema` mirrors it and `test/form-drift.test.ts` probes the mirror
 * against the same manifest. Reading the manifest directly means the number on a spinner's `max` and
 * the number in Laravel's `max:` rule cannot disagree — a hand-typed `200` here would be a fourth
 * spelling (CHECK constraint, `rules()`, schema, control) and the only one nothing compares.
 *
 * `tests/components/bot-model-panel.test.tsx` additionally asserts each rendered bound against
 * `botSettingsSchema`'s own accept/reject behaviour at the boundary, in both directions, so a
 * manifest that stopped agreeing with the schema fails by name rather than shipping a control that
 * offers a value the resolver refuses.
 */

/** The one form type the three modules of this tab pass around. `BotSettingsIn` is the INPUT side —
 *  several fields are `z.preprocess`, so `field.value` is `unknown` at the control. */
export type BotSettingsForm = UseFormReturn<BotSettingsIn, unknown, BotSettingsOut>;

export interface NumericBound {
  readonly min: number;
  readonly max: number;
}

/** `min:20` -> 20. `null` when the rule is absent or unparseable, which the caller renders as "no
 *  bound stated" rather than as a guessed one. */
const numericRule = (rules: readonly string[], prefix: string): number | null => {
  const rule = rules.find((entry) => entry.startsWith(prefix));
  if (rule === undefined) return null;
  const value = Number(rule.slice(prefix.length));
  return Number.isFinite(value) ? value : null;
};

/**
 * Built once, by iterating `Object.entries` rather than indexing `rules[path]` — indexing an object
 * by a variable is `security/detect-object-injection`'s sink and reports as a warning nobody can act
 * on. `features/bots/api.ts` makes the same move for the same reason.
 */
const readBounds = (): ReadonlyMap<string, NumericBound> => {
  const bounds = new Map<string, NumericBound>();

  for (const [path, rules] of Object.entries((updateBotRules as FormRulesManifest).rules)) {
    const min = numericRule(rules, 'min:');
    const max = numericRule(rules, 'max:');
    if (min !== null && max !== null) bounds.set(path, { min, max });
  }

  return bounds;
};

const BOUNDS = readBounds();

/** The server's own applied bounds for one field, or `null` when it declares none. */
export const boundFor = (field: string): NumericBound | null => BOUNDS.get(field) ?? null;

/**
 * THE FOUR RETRIEVAL DEPTHS, WITH THE SHIPPED DEFAULT BESIDE THE BOUND.
 *
 * The DEFAULTS are `kb-rag-query-contract`'s table (§12.7–12.12): dense 20, sparse 20, rerank
 * candidates 20–30, retain 6–10. The BOUNDS are the manifest's, read above. They are two different
 * facts and the card renders both, because "20 is the shipped starting point" and "1–200 is what the
 * column will hold" answer different questions and an operator comparing two bots needs the first.
 *
 * `note` carries that skill's standing instruction verbatim in substance: every one of these moves
 * only through an evaluation run with an immutable config snapshot, never by intuition. Changing one
 * moves `retrieval_configuration_version`, which is the term every trace is replayed against.
 */
export interface DepthField {
  readonly field: 'dense_top_k' | 'sparse_top_k' | 'rerank_candidates' | 'rerank_retain';
  readonly label: string;
  readonly shipped: string;
  readonly description: string;
}

export const RETRIEVAL_DEPTHS: readonly DepthField[] = [
  {
    field: 'dense_top_k',
    label: 'Dense candidates',
    shipped: '20',
    description:
      'How many candidates the vector arm returns before fusion. The shipped starting point is 20.',
  },
  {
    field: 'sparse_top_k',
    label: 'Sparse candidates',
    shipped: '20',
    description:
      'How many candidates the BM25 arm returns. The two arms are fused by RRF, which discards ' +
      'score magnitude by construction — which is why nothing is ever thresholded on a fused ' +
      'score. The shipped starting point is 20.',
  },
  {
    field: 'rerank_candidates',
    label: 'Reranked candidates',
    shipped: '20 to 30',
    description:
      'How many fused candidates are sent to the reranker. Ranking APIs cap the passages accepted ' +
      'per request, so depth is round trips rather than arithmetic.',
  },
  {
    field: 'rerank_retain',
    label: 'Retained after reranking',
    shipped: '6 to 10',
    description:
      'How many survive into the packed context. More is not better: accuracy is U-shaped in the ' +
      'packed order and worst in the middle, so this stays small on purpose.',
  },
];

/** kb-rag-query-contract's standing instruction, said once, on the card that renders the four. */
export const DEPTHS_MOVE_BY_EVALUATION =
  'Every one of these four is a starting point, not a finding. It moves through an evaluation run ' +
  'with an immutable configuration snapshot and never by intuition — changing a value moves this ' +
  'bot’s retrieval configuration version, and a trace can only be replayed against the version it ' +
  'ran under.';

/**
 * Reranking is CAPABILITY-GATED and its absence is silent (ADR-030, `bge-reranker`). Said beside the
 * two rerank controls rather than in a tooltip, because the failure it describes is one nothing on
 * this screen can detect: the save succeeds, the numbers store, and the stage simply never runs.
 */
export const RERANK_IS_CAPABILITY_GATED =
  'Reranking is capability-gated rather than guaranteed. A provider that publishes no ranking route ' +
  '— or one whose score scale this platform cannot threshold — produces a traced skip and not an ' +
  'error: the answer is served from the fused order and these two numbers do nothing. Nothing on ' +
  'this screen can tell you in advance; the skip and its reason are on the retrieval trace.';

type AnswerMode = (typeof BOT_ANSWER_MODES)[number];
type EvidenceScale = (typeof EVIDENCE_THRESHOLD_SCALES)[number];

/**
 * The two answer modes.
 *
 * The parameter is `string` and an unrecognised member renders VERBATIM, for the reason
 * `botStatusDisplay` gives in `./api`: the vocabulary is the server's, a build that has not heard of
 * a new member should show what the row claims rather than nothing at all.
 */
export const answerModeLabel = (mode: string): string => {
  switch (mode as AnswerMode) {
    case 'strict':
      return 'Strict — the active sources only';
    case 'rag_first':
      return 'RAG-first — general knowledge allowed, with disclosure';
    default:
      return mode;
  }
};

export const answerModeDescription = (mode: string): string => {
  switch (mode as AnswerMode) {
    case 'strict':
      return 'When nothing clears the evidence threshold the bot says the answer is not in its sources. It never falls through to what the model already knows.';
    case 'rag_first':
      return 'The bot may answer from the model’s own knowledge when its sources do not cover the question, and discloses in the answer when it has left them.';
    default:
      return '';
  }
};

/**
 * The three scales a STORED threshold may live on, and the sentence that says what each one is.
 *
 * Copied in substance from `App\Enums\EvidenceThresholdScale`, which is itself a deliberate SUBSET of
 * the data plane's `RerankScale`: `uncalibrated` is absent from all three, because it is not a scale
 * — it is the statement that no characterization exists for a `(provider, model)` pair, which is
 * exactly the state in which a calibration REFUSES construction rather than defaulting.
 */
export const evidenceScaleLabel = (scale: string): string => {
  switch (scale as EvidenceScale) {
    case 'logit':
      return 'Logit — unbounded and signed';
    case 'sigmoid':
      return 'Sigmoid — bounded, 0 to 1';
    case 'unit_interval':
      return 'Unit interval — bounded, 0 to 1';
    default:
      return scale;
  }
};

export const evidenceScaleNote = (scale: string): string => {
  switch (scale as EvidenceScale) {
    case 'logit':
      return 'Unbounded, signed, roughly ±10 and centred near zero. NVIDIA’s ranking models report on this scale.';
    case 'sigmoid':
      return 'A logistic transform of a logit, bounded 0 to 1. What the retired local cross-encoder produced.';
    case 'unit_interval':
      return 'Bounded 0 to 1 but not a sigmoid of a logit: a vendor-defined relevance score.';
    default:
      return '';
  }
};

/**
 * Whether a threshold on this scale must lie in [0, 1].
 *
 * `$this !== self::Logit` in `EvidenceThresholdScale::isBounded()`, and the same set spelled as
 * `BOUNDED_SCALES` inside `botSettingsSchema`. It is written as the NEGATION of the one unbounded
 * member rather than as a list of the two bounded ones, so a fourth scale added to the vocabulary
 * defaults to "bounded" — which is the direction that produces a visible refusal instead of an
 * accepted number nobody can interpret.
 *
 * The component spec asserts this against `botSettingsSchema`'s own verdict on `1.7`, on every
 * member of `EVIDENCE_THRESHOLD_SCALES`, so the two spellings cannot drift silently.
 */
export const isBoundedScale = (scale: string): boolean => scale !== 'logit';

/** `unknown` -> a controlled input's string, for a field whose schema INPUT type is `unknown`
 *  because the field is a `z.preprocess`. `asText` covers the string case only; a seeded number
 *  (every depth, and a stored threshold) arrives as a number. */
export const asFieldText = (value: unknown): string => {
  if (typeof value === 'number') return String(value);
  return typeof value === 'string' ? value : '';
};

/**
 * A typed number input's raw string, converted back to something the resolver reads the same way the
 * SERVER would — and the `empty` argument is the whole content of this helper.
 *
 * A cleared input is `''`. `botSettingsSchema` maps that to `undefined` on a non-nullable depth
 * ("you sent nothing") and to `null` on the nullable threshold ("clear it"), and those are two
 * different intentions on a PATCH. Handing the raw `''` through instead would work for the resolver
 * and would break `formState.isDirty`: `'' !== null`, so a cleared threshold on a bot that never had
 * one would read as an unsaved edit and the shell would ask before letting the operator leave.
 *
 * A parseable number is stored as a NUMBER for the same reason — `'20' !== 20`, so typing the seeded
 * value back in would otherwise leave the panel permanently dirty. An unparseable fragment (`-`,
 * `1e`) is kept verbatim so the operator can keep typing; `mode: 'onTouched'` judges it on blur.
 */
export const numericFieldValue = (raw: string, empty: null | undefined): unknown => {
  if (raw.trim() === '') return empty;
  const parsed = Number(raw);
  return Number.isFinite(parsed) ? parsed : raw;
};

/**
 * The same job as `numericFieldValue`, for a `clearableText` field — and it exists for the same
 * reason: a control that writes the raw DOM string leaves form state holding a value the schema
 * would never produce, and `formState.isDirty` compares against `defaultValues` BEFORE the resolver
 * runs.
 *
 * `clearableText` (`packages/contracts/src/forms/bot.ts`) preprocesses with
 * `(v) => (typeof v === 'string' && v.trim() === '' ? null : v)`. This is that expression and
 * nothing else, deliberately:
 *
 *   - blank OR whitespace-only becomes `null`, because `TrimStrings` then
 *     `ConvertEmptyStringsToNull` run server-side before any rule, so `'   '` is stored as null;
 *   - a non-blank string is passed through VERBATIM and is NOT trimmed here, because the preprocess
 *     does not trim either — `z.string().trim()` inside it does, at parse time. Trimming on the way
 *     into form state would move the caret and delete a space the operator is still typing after.
 *
 * The consequence that matters: a stored `null` that an operator types into and then clears returns
 * to `null` rather than sticking at `''`, so the tab guard disarms. Without it `'' !== null` and the
 * shell asks "Leave without saving?" for a form holding exactly the stored row.
 */
export const clearableFieldValue = (raw: string): string | null => (raw.trim() === '' ? null : raw);

/** Present AND non-null — the same reading `botSettingsSchema`'s `isSet` uses, because absent and
 *  explicitly-null are different intentions and the same verdict for "is this configured". */
export const isConfigured = (value: unknown): boolean => value !== undefined && value !== null;
