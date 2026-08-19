import forgotPasswordRules from '@kb/contracts/rules/ForgotPasswordRequest.json';
import loginRules from '@kb/contracts/rules/LoginRequest.json';
import registerRules from '@kb/contracts/rules/RegisterRequest.json';
import resetPasswordRules from '@kb/contracts/rules/ResetPasswordRequest.json';
import rotateProviderCredentialRules from '@kb/contracts/rules/RotateProviderCredentialRequest.json';
import inviteRules from '@kb/contracts/rules/StoreInvitationRequest.json';
import storeProviderConnectionRules from '@kb/contracts/rules/StoreProviderConnectionRequest.json';
import updateProviderConnectionRules from '@kb/contracts/rules/UpdateProviderConnectionRequest.json';

import { knownPathsFromRules, type FormRulesManifest } from '@/lib/forms/known-paths';

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

/**
 * ── THE DERIVATION MOVED TO `@/lib/forms/known-paths`, AS THIS FILE'S OWN NOTE ASKED ────────────
 * `FormRulesManifest`, `HIDDEN_PATHS` and `knownPathsFromRules` used to be declared here. The note
 * below the provider block said the next feature to need one should move them rather than add a
 * fourth block, and the model catalogue (`features/models`) was that feature — so they are in
 * `@/lib/forms/`, beside `applyServerErrors`, which is the only consumer of what they produce.
 *
 * WHAT STAYED: every per-form CONSTANT. Those are this feature's (plus the two provider screens'),
 * and `tests/unit/known-paths.test.ts` closes over the exact set of `*_KNOWN_PATHS` this module
 * exports, so a sixth added without a spec fails by name. Nothing is re-exported from here — one
 * home for one function.
 */

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

/**
 * ── THE THREE PROVIDER SETS, AND THE ONE THAT IS NOT A STRAIGHT DERIVATION ───────────────────────
 *
 * They live in this file rather than in `features/providers/` because the derivation used to live
 * here too, and putting a second one beside the forms is how one rule gets two spellings. THE
 * DERIVATION HAS SINCE MOVED to `@/lib/forms/known-paths`, exactly as the sentence that used to end
 * this paragraph asked — so the argument for keeping these three here is now weaker than it was:
 * they could move to `features/providers/` without duplicating anything.
 *
 * They have not, and the reason is a boundary rather than a preference: the step that would have
 * moved them (the model catalogue, `features/models`) was scoped out of editing
 * `features/providers/`, and a move is worth nothing if it lands half-done. Recorded here so the
 * next edit to that feature is a move rather than a shrug. The model catalogue's own two sets are
 * NOT here — they are derived in `features/models/api.ts` from the same shared helper.
 */

/**
 * `['provider', 'label', 'credential']` — `StoreProviderConnectionRequest`'s vocabulary MINUS the
 * model sub-tree, and the subtraction is this file's second one after `HIDDEN_PATHS`.
 *
 * WHY IT IS NOT THE WHOLE MANIFEST. That request also rules `models` (`present|array|max:50`) and six
 * `models.*.…` paths. The create form renders NONE of them: the model catalogue is its own screen
 * (A4a, `/settings/providers/[connectionId]`), and the form posts `models: []` because `present` means
 * the KEY must exist — an omitted key is a 422 on a field nobody is looking at.
 *
 * So a 422 keyed on `models.0.model` has no control to land on. `applyServerErrors` routes an unknown
 * key to the single `root.serverError` slot, which is exactly where it belongs — the banner — and
 * leaving `models` in this set would instead write it to a field that displays NOWHERE: the operator
 * clicks Save, the server rejects, nothing changes on screen, and they click again. That is the same
 * failure `HIDDEN_PATHS` exists for, one level of nesting down.
 *
 * `credential` IS in the set and must be: `min:8`/`max:512` are per-field 422s that belong under the
 * input the user just pasted into. Being in this set says only "this form renders a control with this
 * name" — it says nothing about the value, which is never read back out of form state.
 */
export const PROVIDER_CONNECTION_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  storeProviderConnectionRules as FormRulesManifest,
).filter((path) => path !== 'models' && !path.startsWith('models.'));

/**
 * `['label', 'status']` — from `UpdateProviderConnectionRequest`'s own `rules()`.
 *
 * Both survive `HIDDEN_PATHS`, and both are rendered: `label` is a text input and `status` is a
 * `<Select>`. A Radix trigger has no `register` ref, so `applyServerErrors`' `hasFocusableRef` check
 * declines to spend `shouldFocus` on it while `FormMessage` still renders the message — which is the
 * documented reason that check exists.
 *
 * The mutual `required_without` 422 ("An edit names a label, a status, or both") arrives keyed on
 * whichever field Laravel evaluated first, so it lands under a control either way. The client-side
 * mirror of that rule is a `superRefine` in `@kb/contracts/forms`, which normally gets there first.
 */
export const PROVIDER_CONNECTION_EDIT_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  updateProviderConnectionRules as FormRulesManifest,
);

/**
 * `['current_password', 'credential']` — from `RotateProviderCredentialRequest`'s own `rules()`.
 *
 * BOTH ARE RENDERED AND BOTH MUST BE IN THIS SET, and `current_password` is the one that matters:
 * `current_password:web` is the §18.3 re-authentication, it is a validation rule rather than a service
 * call, and "that password is not correct" is a 422 keyed on it. Routed to the banner instead, it would
 * read as a general failure of the rotation rather than as a wrong password in the field directly above
 * — and the operator would try the same password again.
 *
 * The `not_regex` mask rule is keyed on `credential` and lands under that input for the same reason.
 * Its message is Laravel-translated end-user copy ("That looks like the masked display value…"), shown
 * verbatim like every other validation message.
 */
export const ROTATE_CREDENTIAL_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  rotateProviderCredentialRules as FormRulesManifest,
);
