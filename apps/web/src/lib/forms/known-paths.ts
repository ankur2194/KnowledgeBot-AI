/**
 * The manifest-to-`knownPaths` derivation, and NOTHING ELSE.
 *
 * ── THIS FILE IS A MOVE, AND THE INSTRUCTION TO MAKE IT WAS LEFT IN THE FILE IT CAME FROM ───────
 * `features/auth/known-paths.ts` held these three declarations while auth was the only surface that
 * needed them. It grew a second block for the members invite and a third for the provider screens,
 * and its own comment recorded the rule that follows: *"the next feature that needs one should move
 * the helper to `@/lib/forms/` rather than add a fourth block here."* The model catalogue is that
 * feature, so this is the move rather than a fourth block.
 *
 * The auth file keeps every one of its per-form CONSTANTS — those are that feature's, and they are
 * closed over by `tests/unit/known-paths.test.ts`, which asserts the exact set of `*_KNOWN_PATHS` it
 * exports so a sixth added without a spec fails by name. What left is the shared derivation, which
 * belonged to nobody in particular and was therefore importable across a feature boundary as a side
 * effect of where it happened to sit.
 *
 * NO RE-EXPORT SHIM WAS LEFT BEHIND, deliberately: a single home is the whole point of the move, and
 * two import paths for one function is how the two spellings start. Only the auth module and its
 * spec ever imported these, and both now name this file.
 *
 * ── WHAT `knownPaths` ACTUALLY MEANS, BECAUSE IT IS NOT WHAT IT LOOKS LIKE ──────────────────────
 * It is "the paths this form RENDERS", which is a DIFFERENT SET from "the paths the FormRequest
 * validates". `applyServerErrors` routes a known key through `setError(path)` and an unknown one to a
 * single `root.serverError` write. A key routed to a control that displays NOWHERE — a hidden input,
 * a sub-tree the form does not render — is a submit where the server rejects, nothing changes on
 * screen, and the user clicks again.
 *
 * So a caller may SUBTRACT from the derived set (see `HIDDEN_PATHS`, and the `models.*` subtraction
 * on the provider create form), and may never hand-type a replacement for it.
 */

/** The shape `php artisan kb:dump-form-rules` writes (see packages/contracts/rules/*.json). */
export interface FormRulesManifest {
  /** The FormRequest FQCN, e.g. `App\\Http\\Requests\\LoginRequest`. */
  readonly class: string;
  readonly rules: Readonly<Record<string, readonly string[]>>;
}

/**
 * Paths that a FormRequest validates and no form RENDERS as a focusable control.
 *
 * `token` only, and it is deliberately not per-form: every auth screen that carries a token carries
 * it hidden, and a hidden token is never the field to focus. If a form ever renders a token as a
 * visible, typed input (a 6-digit code, say), that form takes its own subtraction set rather than
 * this one growing an exception.
 */
export const HIDDEN_PATHS: ReadonlySet<string> = new Set(['token']);

/** The manifest's field vocabulary, minus what the form does not render. */
export const knownPathsFromRules = (manifest: FormRulesManifest): readonly string[] =>
  Object.keys(manifest.rules).filter((path) => !HIDDEN_PATHS.has(path));
