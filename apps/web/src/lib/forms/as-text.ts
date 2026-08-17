/**
 * `unknown` → a controlled input value, without `as string` anywhere.
 *
 * ── WHY EVERY EMAIL FIELD IN THIS APP NEEDS IT ───────────────────────────────────────────────────
 * `emailField` in `@kb/contracts/forms` is a `z.preprocess` — it trims BEFORE the check, because
 * Laravel's `TrimStrings` runs before `max:254` server-side and a form that checks length first would
 * reject an address the server accepts. The cost of that ordering is that the schema's INPUT type for
 * the field is `unknown` (a preprocessor may be handed anything) while its output is a string. So
 * `field.value` is `unknown` at every `<Input>` that renders one, and `<Input value={unknown}>` does
 * not typecheck.
 *
 * The `''` fallback is not defensive padding: it is what keeps the input CONTROLLED across a
 * `form.reset()`. React treats `value={undefined}` as an uncontrolled input and warns that a component
 * is switching between the two, after which the field stops tracking form state — visible as a box
 * that will not clear when the invite form resets after a successful send.
 *
 * ── EXTRACTED FROM FOUR IDENTICAL COPIES ─────────────────────────────────────────────────────────
 * `login-form`, `register-form`, `reset-password-form` and `forgot-password-form` each declared this
 * function privately, byte for byte, because each was written by a different agent in a different
 * batch and none could see the others. `invite-form` needed a fifth. Three lines duplicated five ways
 * is not a correctness problem today, but the reason it is one file now is that the `''` fallback above
 * is a DECISION with a reason, and a decision recorded in five places is a decision that will be
 * changed in one.
 */
export function asText(value: unknown): string {
  return typeof value === 'string' ? value : '';
}
