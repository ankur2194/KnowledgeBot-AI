import forgotPasswordRules from '@kb/contracts/rules/ForgotPasswordRequest.json';
import loginRules from '@kb/contracts/rules/LoginRequest.json';
import registerRules from '@kb/contracts/rules/RegisterRequest.json';
import resetPasswordRules from '@kb/contracts/rules/ResetPasswordRequest.json';
import inviteRules from '@kb/contracts/rules/StoreInvitationRequest.json';

/**
 * `knownPaths` for each auth form — the argument `applyServerErrors` uses to decide whether a 422 key
 * goes to a FIELD or to `root.serverError`.
 *
 * ── THE SUBTLEST THING IN THIS BATCH ─────────────────────────────────────────────────────────────
 * `knownPaths` is "the paths this form RENDERS", which is a DIFFERENT SET from "the paths the
 * FormRequest validates". `applyServerErrors` routes a known key through `setError(path)`
 * (src/lib/forms/apply-server-errors.ts:50-56) and an unknown one to a single `root.serverError`
 * write (:61-69). A HIDDEN input has no focusable ref, so a key routed to one is written to a field
 * that displays NOWHERE: the user clicks submit, the server rejects, nothing changes on screen, and
 * they click again. `token` — which `resetPasswordSchema` and `registerSchema` both declare, because
 * the drift suite asserts set equality against the manifest — is exactly that field, and an
 * "invalid or expired link" error keyed to it belongs on the banner.
 *
 * Hence `HIDDEN_PATHS`. It is a subtraction from the derived set, never a hand-maintained
 * replacement for it.
 *
 * ── WHERE THE SET COMES FROM ─────────────────────────────────────────────────────────────────────
 * THE COMMITTED MANIFEST, which is the SERVER'S OWN FIELD VOCABULARY — dumped from each
 * FormRequest's `rules()` by `php artisan kb:dump-form-rules`, re-dumped in CI, and gated on
 * `git diff --exit-code` (rhf-zod-forms). The import resolves because
 * packages/contracts/package.json exports `./rules/*.json` and tsconfig.base.json sets
 * `resolveJsonModule`.
 *
 * This file used to derive the set from `loginSchema.shape` instead, because the auth manifests did
 * not exist and an import of an absent JSON file is a TYPECHECK failure that would have made the
 * whole app red. `tests/unit/known-paths.test.ts` carried a SELF-EXPIRING PIN for that, which fired
 * when `rules/LoginRequest.json` landed and has been replaced by the assertion below it: the derived
 * set is compared against the manifest read independently off disk, so a future hand-typed list fails
 * rather than a comment asking nobody in particular to remember.
 *
 * The schema-derived set was never *wrong* — `test/form-drift.test.ts` asserts SET EQUALITY between a
 * schema's paths and its manifest's keys, so the two are provably identical or the contracts suite is
 * red. It was one indirection too many: a form that 422s on a key the schema does not declare is
 * exactly the case `applyServerErrors` partitions, and the manifest is the only side that knows.
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

/** `['email', 'password']` — from `LoginRequest`'s own `rules()`, never typed out. */
export const LOGIN_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  loginRules as FormRulesManifest,
);

/**
 * `['name', 'password', 'password_confirmation']` — from `RegisterRequest`'s own `rules()`.
 *
 * MOVED HERE FROM invitation.ts, where it was declared beside the invitation transport because this
 * file was another unit's during the batch that wrote it. Two consequences of the subtraction are
 * worth keeping next to it:
 *
 *   - `token` is subtracted, so the server's byte-identical "This invitation is no longer valid."
 *     reaches the banner instead of a hidden input that displays nowhere. All five invalid states
 *     answer with that one string on purpose, so a prober holding a guessed token learns nothing.
 *   - `email` is not in this set and CANNOT be: `registerSchema` has no `email` field, because the
 *     invitation pins the address (a submitted one would let an invitee register under someone
 *     else's). So the one deliberate disclosure — an account already exists for the invited address —
 *     also lands in the banner, next to the sign-in link that screen renders.
 */
export const REGISTER_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  registerRules as FormRulesManifest,
);

/**
 * `['email', 'role']` — from `StoreInvitationRequest`'s own `rules()`.
 *
 * THE LAST HAND-TYPED `knownPaths` IN THE AUTH SURFACE, and it was typed out for a real reason rather
 * than an oversight: the invite form shipped with no Zod resolver because
 * `rules/StoreInvitationRequest.json` had not been dumped, so there was nothing to derive from and
 * nothing to compare a local schema to. Both halves have landed — the manifest is committed and
 * `@kb/contracts/forms` exports `inviteMemberSchema` — so this is derived like the other two and
 * `tests/unit/known-paths.test.ts` carried a SELF-EXPIRING PIN that fired on the export appearing.
 *
 * BOTH paths survive the `HIDDEN_PATHS` subtraction, unlike the two above: `email` is a text input and
 * `role` is a `<Select>`. A Radix trigger has no `register` ref, so `applyServerErrors`'
 * `hasFocusableRef` check declines to spend `shouldFocus` on it while `FormMessage` still renders the
 * message — which is the documented reason that check exists, and the reason `role` belongs in this
 * set even though it cannot be focused.
 *
 * What lands on the banner instead: `invitation`, which revoke and resend key their refusals to, and
 * `organization`, which a suspended organization answers with. Neither is a control on this screen.
 */
export const INVITE_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  inviteRules as FormRulesManifest,
);

/**
 * `['email']` — from `ForgotPasswordRequest`'s own `rules()`.
 *
 * ── WHY THIS MOVED HERE FROM THE FORM, WHEN THE OLD DERIVATION WAS ALREADY CORRECT ───────────────
 * `forgot-password-form.tsx` derived its own set as `Object.keys(forgotPasswordFormDefaults())`, and
 * the argument for it was sound as far as it went: `z.strictObject` rejects an extra key on parse, so
 * the defaults' key set IS the schema's path set, and `form-drift.test.ts` asserts SET EQUALITY between
 * that and the manifest. Three asserted links, so the two ends were provably equal.
 *
 * They diverge in exactly one case, and it is the case that actually happens: a server field added as
 * `sometimes|nullable` — the additive, backward-compatible shape the compatibility rules prescribe.
 * Then the mirrored schema path is `.optional()`, `ForgotPasswordIn` permits omitting it, the defaults
 * factory need not list it, `auth-schemas.test.ts`'s `toEqual` on the defaults stays green, and
 * `schemaPaths()` reads `.shape` so `form-drift` stays green too — while `Object.keys(defaults)` is
 * missing a path the manifest has. Login, register and invite would gain it; these two screens would
 * not, and a per-field 422 on the new field would render in the banner instead of under its control,
 * on two screens only, with nothing red anywhere.
 *
 * Reading the manifest directly removes the case rather than documenting it. Exporting it also makes it
 * assertable — the local constants were unexported, so no spec could see them.
 */
export const FORGOT_PASSWORD_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  forgotPasswordRules as FormRulesManifest,
);

/**
 * `['email', 'password', 'password_confirmation']` — from `ResetPasswordRequest`'s own `rules()`.
 *
 * `token` IS declared by that manifest and IS subtracted, which is the one place this set differs from
 * the schema's path list. With `token` absent, `applyServerErrors` treats a 422 keyed on it as an orphan
 * and routes it to the single `root.serverError` slot — which is exactly where "this link is no longer
 * valid" belongs, because the field is a hidden input with no focusable ref and a message written to it
 * would display nowhere.
 *
 * Same reason for reading the manifest rather than the defaults factory as FORGOT_PASSWORD_KNOWN_PATHS
 * above; that note has the divergence case in full.
 */
export const RESET_PASSWORD_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  resetPasswordRules as FormRulesManifest,
);
