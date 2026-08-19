import { z } from 'zod';

/**
 * The starter-question chips — mirrors `App\Http\Requests\StoreBotStarterQuestionRequest` (POST
 * `…/bots/{bot}/starter-questions`) and `App\Http\Requests\UpdateBotStarterQuestionRequest` (PATCH
 * `…/bots/{bot}/starter-questions/{starterQuestion}`).
 *
 * They MIRROR the FormRequests; they do not enforce them (rhf-zod-forms NN2).
 * `test/form-drift.test.ts` probes both against the dumped manifests.
 *
 * ── THE PATCH IS TWO FIELDS AND EITHER ONE ALONE IS A LEGITIMATE BODY ──────────────────────────
 *
 * `question` and `sort_order` are each `required_without` the other: a rename sends the text, a
 * reorder sends the position, and a body carrying NEITHER is refused rather than answered 200.
 *
 * THE REQUEST DELIBERATELY DOES NOT CARRY `sometimes`, and mirroring what looks symmetrical instead
 * of what the manifest says is how the bug it fixed came back: `sometimes` short-circuits every
 * remaining rule for an ABSENT key, `required_without` included, so with it on both fields an empty
 * PATCH body satisfied everything and returned 200 having changed nothing. The schema below spells
 * the same thing — both fields optional, plus a refinement for the body that names neither — and
 * `probesFor` suppresses every presence probe on a CROSS_FIELD rule, so that refinement is asserted
 * by hand in test/form-drift.test.ts rather than by a generated probe.
 *
 * ── RE-READ THE COLLECTION AFTER A MOVE OR A DELETE ────────────────────────────────────────────
 *
 * Every write RE-SEQUENCES the whole list inside one transaction: positions are always 0..n-1 with
 * no gaps, so moving one question rewrites the `sort_order` of every row it passed and deleting one
 * closes the gap. A client-side splice therefore desynchronises against a list the server has
 * already renumbered, and the symptom is a chip order that is right until the next reload. Invalidate
 * the collection key; do not patch it in place, and do not make either write optimistic.
 */

/**
 * `max:200` on the text, and `max:5` on the position — which is a CEILING ON THE LIST expressed as a
 * bound on one row: positions are zero-based and the collection publishes `maxItems: 6`, so the last
 * legal index is 5. Named rather than inlined because both numbers are the server's and a literal
 * repeated at two call sites is how two spellings start to disagree.
 *
 * Six is the ceiling the chat surface renders (kb-ai-chat-ux: three to six chips), so what is stored
 * is what is shown and the console cannot promise a seventh chip no client draws.
 */
const QUESTION_MAX = 200;
const SORT_ORDER_MIN = 0;
const SORT_ORDER_MAX = 5;

/**
 * `bail|required|string|max:200`.
 *
 * `.min(1)` MIRRORS A BEHAVIOUR RATHER THAN A RULE, exactly as `theme.primary`'s does: `TrimStrings`
 * then `ConvertEmptyStringsToNull` run before any rule, so a whitespace-only chip label arrives as
 * null and `required` refuses it — and `bot_starter_questions_question_not_blank` refuses it again
 * for every writer that is not an HTTP request. A chip with no label is a control an end user can
 * see, can click, and cannot read.
 */
const question = z
  .string()
  .trim()
  .min(1, { error: 'A starter question needs the text an end user will see on the chip.' })
  .max(QUESTION_MAX);

export const starterQuestionCreateSchema = z.strictObject({ question });

export type StarterQuestionCreateIn = z.input<typeof starterQuestionCreateSchema>;
export type StarterQuestionCreateOut = z.output<typeof starterQuestionCreateSchema>;

/**
 * An empty add-question form.
 *
 * NO `sort_order`: the POST declares no rule for it, because a new question is appended to the end
 * of the list by the server, which is the only position that cannot collide with an existing one.
 * `strictObject` makes a form that tried to choose one a parse failure rather than a silent strip.
 */
export const starterQuestionCreateDefaults = (): StarterQuestionCreateIn => ({ question: '' });

/**
 * `question`: `bail|required_without:sort_order|string|max:200`
 * `sort_order`: `bail|required_without:question|integer|min:0|max:5`
 *
 * ONE SCHEMA FOR TWO DIFFERENT GESTURES — a rename and a move — because the server accepts them
 * through one request and each is the other's `required_without`. A form that renames sends
 * `{question}`; a drag that reorders sends `{sort_order}`; a body with both is legal and is what an
 * inline editor that lets an operator do both at once would post.
 *
 * `.optional()` ON BOTH AND A REFINEMENT FOR NEITHER. Making either mandatory would be the mirror of
 * `required` rather than of `required_without`, and it would remove the ability to reorder without
 * re-sending the text — functionality gone, nothing reported, and every probe green because
 * `probesFor` suppresses presence probes on a CROSS_FIELD field and would never ask.
 */
export const starterQuestionUpdateSchema = z
  .strictObject({
    question: question.optional(),
    /**
     * The NON-nullable spelling of `intField` (src/forms/bot.ts), and `min:0` is why it has to be:
     * the positions are ZERO-BASED, so 0 is the first chip rather than an unset value. `Number('')`
     * is 0, so a bare `z.coerce.number()` would read a cleared input as "move this to the front" —
     * a real, destructive position rather than a missing one. The preprocess maps `''` and `null` to
     * `undefined` before the coercion sees either, which is also the server's reading: neither
     * request declares `nullable`, and `ConvertEmptyStringsToNull` turns `''` into a null the
     * `integer` rule then refuses.
     */
    sort_order: z
      .preprocess(
        (v) => (v === '' || v === null ? undefined : v),
        z.coerce.number().int().min(SORT_ORDER_MIN).max(SORT_ORDER_MAX),
      )
      .optional(),
  })
  .superRefine((value, ctx) => {
    if (value.question !== undefined || value.sort_order !== undefined) return;

    ctx.addIssue({
      code: 'custom',
      path: ['question'],
      message:
        'A change names either the question’s text or its position. A body carrying neither is a ' +
        'write that changes nothing and still records an edit, which is why the server refuses it ' +
        'rather than answering 200.',
    });
  });

export type StarterQuestionUpdateIn = z.input<typeof starterQuestionUpdateSchema>;
export type StarterQuestionUpdateOut = z.output<typeof starterQuestionUpdateSchema>;

/**
 * The narrow shape `starterQuestionUpdateDefaults` reads — structural on purpose, and not
 * `BotStarterQuestionResource` itself, so this pick keeps compiling without importing the three
 * server-owned fields that type carries.
 */
export interface StarterQuestionSource {
  readonly question: string;
  readonly sort_order: number;
}

/**
 * The ONE path from a stored question into form state.
 *
 * NEVER `reset(resource)`: `BotStarterQuestionResource` carries `id`, `created_at` and `updated_at`,
 * RHF keeps every key it is handed, and submit posts them back — a 200, an audit row and no change.
 */
export const starterQuestionUpdateDefaults = (
  starterQuestion: StarterQuestionSource,
): StarterQuestionUpdateIn => ({
  question: starterQuestion.question,
  sort_order: starterQuestion.sort_order,
});
