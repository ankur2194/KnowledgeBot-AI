import { z } from 'zod';

import type { Role } from '../resources/session.js';

/**
 * The five identity forms, each MIRRORING one Laravel FormRequest:
 *
 *   loginSchema          → `App\Http\Requests\LoginRequest`          POST /api/v1/auth/login
 *   forgotPasswordSchema → `App\Http\Requests\ForgotPasswordRequest` POST /api/v1/auth/forgot-password
 *   resetPasswordSchema  → `App\Http\Requests\ResetPasswordRequest`  POST /api/v1/auth/reset-password
 *   registerSchema       → `App\Http\Requests\RegisterRequest`       POST /api/v1/auth/register
 *   inviteMemberSchema   → `App\Http\Requests\StoreInvitationRequest`
 *                          POST /api/v1/organizations/{organization}/invitations
 *
 * The fifth is not an *authentication* form — nobody proves an identity with it — but it is the other
 * half of invitation-gated registration, it mirrors the same `emailField`, and its manifest lives
 * beside the other four. A sixth file for one schema would separate it from the helper it shares.
 *
 * The namespace is the FLAT one the two existing FormRequests use
 * (services/core-api/app/Http/Requests/DesignateEmbeddingConnectionRequest.php). If they land under
 * `App\Http\Requests\Auth\` instead, these five lines and the MIRRORS keys both change — the key is
 * whatever `kb:dump-form-rules` writes into the manifest's `class` field, not a name chosen here.
 *
 * MIRRORS, does not enforce. Client validation is a UX affordance; the FormRequest is the
 * authority, and test/form-drift.test.ts — probing each schema against the manifest
 * `php artisan kb:dump-form-rules` writes into rules/ — is what keeps the two honest. All five
 * manifests are now committed and all five are MIRRORS entries, so every schema below is compared
 * to the server's own `rules()` on every run. (The ordering rule that produced them still holds: a
 * MIRRORS entry for an absent manifest fails the same set-equality assertion it exists to satisfy,
 * so a new schema lands WITH its dump, never before it.)
 *
 * TWO SERVER BEHAVIOURS ARE INVISIBLE IN THE DUMP, because `kb:dump-form-rules` records only
 * `rules()`, and both are noted where they bite:
 *   1. `prepareForValidation()` lowercases and trims `email` on login, forgot-password and
 *      register. It is not a `lowercase` rule on purpose — `Bob@X.com` must log in, not 422.
 *   2. The global `TrimStrings` middleware trims every string field EXCEPT the three password
 *      ones. See `passwordField` below; that exception is the subtlest line in this file.
 */

/**
 * Laravel: `['bail','required','string','email:rfc,strict','max:254']`.
 *
 * TRIM BEFORE THE CHECK, not after, because `TrimStrings` runs before `max:254` server-side: with
 * the order reversed, "  " + 253 characters is 253 to Laravel and 255 to the browser, and the form
 * rejects an address the server accepts.
 *
 * `z.email()` is a regex and Laravel's `email:rfc,strict` is egulias/EmailValidator; they will
 * never agree on the edge corpus, which is why `email` sits in the drift harness's VALUE_EXEMPT set
 * rather than being probed value-by-value — `grep -n 'VALUE_EXEMPT = ' test/form-drift.test.ts`,
 * per ADR-036, because the line number this comment used to carry had already moved.
 *
 * `max:254` IS still probed, through the harness's email synthesizer rather than through
 * VALUE_EXEMPT: the exemption covers the FORMAT disagreement, not the length one. The number is the
 * thing most likely to drift, so it is read off the manifest rather than trusted here.
 *
 * NOT lowercased here even though the server lowercases in `prepareForValidation()`. Normalisation
 * is not validation: the server does it unconditionally, so a mixed-case address submitted verbatim
 * works, and lowercasing client-side would only mean the user watches their own input change.
 */
const emailField = (max = 254) =>
  z.preprocess(
    (value) => (typeof value === 'string' ? value.trim() : value),
    z.email({ error: 'Enter a valid email address.' }).max(max),
  );

/**
 * THE ONE PLACE `.trim()` IS DELIBERATELY ABSENT IN THIS PACKAGE, AND IT IS NOT AN OVERSIGHT.
 *
 * Laravel's global `TrimStrings` middleware carries a `$except` list, and `password`,
 * `password_confirmation` and `current_password` are on it — the framework refuses to alter a
 * credential in transit. Trimming client-side would therefore SILENTLY CHANGE THE SECRET, and
 * asymmetrically:
 *
 *   registration sends "hunter2 " → server stores the hash of "hunter2 " (untrimmed)
 *   login sends       "hunter2 " → a trimming form submits "hunter2" → hash mismatch → 422
 *
 * The user is now locked out of an account they just created, with correct-looking input, no error
 * anywhere, and no way to discover the trailing space. Verify `$except` against the installed
 * Laravel before touching this line.
 *
 * `max:255` IS mirrored (Laravel: `['bail','required','string','max:255',…]`). bcrypt truncates at
 * 72 bytes, so a 200-character passphrase is equivalent to its first 72 — a property of the
 * algorithm, and not this schema's problem.
 */
const passwordField = (min: number, error = 'Enter your password.') =>
  z.string().min(min, { error }).max(255);

/**
 * The NEW-password policy, mirrored constraint for constraint from
 * `['bail','required','string','min:12','max:255','confirmed','regex:/\p{Ll}/u','regex:/\p{Lu}/u','regex:/\d/u']`.
 *
 * All four, not just the length. The server writes the policy as explicit string rules rather than
 * `Password::defaults()` for exactly this reason: `Illuminate\Validation\Rules\Password` has no
 * `__toString`, so the dump would record the class name and the manifest would be unmirrorable.
 * Mirroring only `min:12` is the failure that gets shipped — the form accepts "aaaaaaaaaaaa", the
 * server 422s on a rule the user was never shown, and the message lands on `password` from the
 * server instead of before the request.
 *
 * `\p{Ll}` / `\p{Lu}` with the `u` flag, matching the server's rules character-class for
 * character-class: `[a-z]` would reject "ärger" where Laravel accepts it.
 */
const newPasswordField = () =>
  passwordField(12, 'Use at least 12 characters.')
    .regex(/\p{Ll}/u, { error: 'Include a lower-case letter.' })
    .regex(/\p{Lu}/u, { error: 'Include an upper-case letter.' })
    .regex(/\d/u, { error: 'Include a digit.' });

/**
 * Laravel: `['bail','required','string']` — no `min`, no `max`. Mirrored exactly: adding a `max`
 * here would be a form stricter than the server, which is the failure mode nobody reports.
 * Untrimmed for the same reason as `passwordField`.
 */
const passwordConfirmationField = () =>
  z.string().min(1, { error: 'Re-enter the password.' });

/**
 * The `confirmed` rule, which the drift harness classifies as CROSS_FIELD
 * (test/form-drift.test.ts:111-132) so its presence probes are suppressed and the equality is
 * asserted by hand — the same arrangement as the `required_with` pair in embedding-designation.ts.
 *
 * The issue is raised on `password_confirmation`, never on `password`: the field the user must fix
 * is the second one, and react-hook-form focuses the field the error is keyed to.
 */
const confirmationMatches = (
  value: { readonly password: string; readonly password_confirmation: string },
  ctx: z.RefinementCtx,
): void => {
  if (value.password !== value.password_confirmation) {
    ctx.addIssue({
      code: 'custom',
      path: ['password_confirmation'],
      message: 'The two passwords do not match.',
    });
  }
};

// ── login ────────────────────────────────────────────────────────────────────────────────────────

/**
 * Mirrors `LoginRequest`: `email` + `password`, and NOTHING ELSE.
 *
 * NO `remember` FIELD. `LoginRequest` does not validate one, and the drift suite asserts SET
 * EQUALITY between the schema's paths and the manifest's keys — a Zod path with no matching rule
 * fails it. If "remember me" is wanted later it is a server change first: a rule in the
 * FormRequest, a dumped manifest, then a field here.
 *
 * `password` carries `min(1)` only. The server's login rule is `required|string|max:255` with no
 * minimum, and mirroring the 12-character policy here would lock out every account created before
 * it — a form stricter than the server, removing access nobody would report as a bug.
 */
export const loginSchema = z.strictObject({
  email: emailField(),
  password: passwordField(1),
});

export type LoginIn = z.input<typeof loginSchema>;
export type LoginOut = z.output<typeof loginSchema>;

/**
 * NO SOURCE ARGUMENT, on purpose. There is no server resource a login form may be seeded from, and
 * a `loginFormDefaults(source)` signature is an invitation to seed one — the `reset(resource)`
 * mistake, arriving through the back door.
 */
export const loginFormDefaults = (): LoginIn => ({ email: '', password: '' });

// ── forgot password ──────────────────────────────────────────────────────────────────────────────

/** Mirrors `ForgotPasswordRequest`: the same email rule, and only that. */
export const forgotPasswordSchema = z.strictObject({
  email: emailField(),
});

export type ForgotPasswordIn = z.input<typeof forgotPasswordSchema>;
export type ForgotPasswordOut = z.output<typeof forgotPasswordSchema>;

/**
 * `seed` is a QUERY-STRING prefill, not a resource: `/forgot-password?email=…` arriving from a
 * failed login. Optional, and its own type — a caller that hands over a session resource here would
 * be seeding form state from server data, which is the thing `*FormDefaults` exists to prevent.
 */
export const forgotPasswordFormDefaults = (seed?: { readonly email?: string }): ForgotPasswordIn => ({
  email: seed?.email ?? '',
});

// ── reset password ───────────────────────────────────────────────────────────────────────────────

/**
 * Mirrors `ResetPasswordRequest`:
 * `token: ['bail','required','string','max:255']`, `email` as above, `password` under the full
 * policy plus `confirmed`, `password_confirmation: ['bail','required','string']`.
 *
 * `token` MUST be a schema path even though it renders as a hidden input, because the drift suite
 * asserts set equality against the manifest keys. Note the consequence for apps/web, which is the
 * subtlest thing in this batch: `token` must NOT be in that form's `knownPaths`. A hidden input has
 * no focusable ref, so an "invalid or expired link" error keyed `token` routed through
 * `setError('token')` would be written to a field that displays nowhere — it belongs on
 * `root.serverError`. "Paths the FormRequest validates" and "paths this form renders" are two
 * different sets, and this is the field that proves it.
 */
export const resetPasswordSchema = z
  .strictObject({
    token: z.string().trim().min(1, { error: 'This reset link is incomplete.' }).max(255),
    email: emailField(),
    password: newPasswordField(),
    password_confirmation: passwordConfirmationField(),
  })
  .superRefine(confirmationMatches);

export type ResetPasswordIn = z.input<typeof resetPasswordSchema>;
export type ResetPasswordOut = z.output<typeof resetPasswordSchema>;

/**
 * The narrow shape `resetPasswordFormDefaults` reads: the two values that arrive in the reset LINK.
 * Structural on purpose, exactly like `BotFormSource` (bot.ts:43-49) — there is no resource here at
 * all, and both fields are untrusted query-string input that the server re-validates.
 */
export interface ResetPasswordLink {
  readonly token: string;
  readonly email: string;
}

export const resetPasswordFormDefaults = (link: ResetPasswordLink): ResetPasswordIn => ({
  token: link.token,
  email: link.email,
  password: '',
  password_confirmation: '',
});

// ── register (invitation-gated) ──────────────────────────────────────────────────────────────────

/**
 * Mirrors `RegisterRequest`: `token`, `name`, `password`, `password_confirmation`.
 *
 * NO `email` FIELD, DELIBERATELY, AND THIS IS A SECURITY PROPERTY RATHER THAN A TIDINESS ONE. The
 * invitation PINS the address; the server reads it off the invitation row. A submitted `email`
 * would make the address client-supplied, and an invitee could register under someone else's — so
 * `RegisterRequest` must not validate one either, or the drift suite's path-set equality forces it
 * back into this schema. The address is shown to the user from `InvitationPreview.email`
 * (src/resources/session.ts) and never submitted.
 *
 * `name` mirrors `['bail','required','string','min:1','max:120']`.
 *
 * `token` mirrors the invitation token rule. It is a 32-byte hex capability, and the server's rule
 * is `size:64`; this schema asserts only `min(1)`, which is LOOSER on purpose — a token of the
 * wrong length must reach the server and come back as the deliberately indistinguishable
 * "This invitation is no longer valid.", not be rejected locally by a length check that tells the
 * holder their token is the wrong SHAPE. It is never in a URL path segment for the same reason a
 * capability never is (Traefik access logs, `Referer`, browser history).
 */
export const registerSchema = z
  .strictObject({
    token: z.string().trim().min(1, { error: 'This invitation link is incomplete.' }),
    name: z.string().trim().min(1, { error: 'Enter your name.' }).max(120),
    password: newPasswordField(),
    password_confirmation: passwordConfirmationField(),
  })
  .superRefine(confirmationMatches);

export type RegisterIn = z.input<typeof registerSchema>;
export type RegisterOut = z.output<typeof registerSchema>;

/**
 * The narrow shape `registerFormDefaults` reads. Structural on purpose, exactly like `BotFormSource`
 * (bot.ts:43-49), so it keeps compiling against whatever the register screen holds.
 *
 * IT IS NOT `InvitationPreview`, AND THAT IS NOT AN OVERSIGHT: the preview resource deliberately
 * carries NO token (src/resources/session.ts) — it is the response to presenting one. The token
 * comes from the invitation link the user followed. `name` is not seeded either: the preview carries
 * no invited name, and the person filling the form is the one who gets to say what they are called.
 */
export interface InvitationPreviewSource {
  readonly token: string;
}

/**
 * NEVER `reset(preview)`. The preview response carries `organization_name`, `email`, `role` and
 * `expires_at`; `reset()` keeps every key it is handed, `getValues()` returns them, and submit posts
 * them back — including the `email` this schema exists to not have. This pick is the ONLY path from
 * server or link data into register form state, and it can reach exactly one field.
 */
export const registerFormDefaults = (invitation: InvitationPreviewSource): RegisterIn => ({
  token: invitation.token,
  name: '',
  password: '',
  password_confirmation: '',
});

// ── invite a member (admin) ──────────────────────────────────────────────────────────────────────

/**
 * The organization role catalog as a RUNTIME tuple, and this is the one place in the package it may
 * exist.
 *
 * `src/resources/session.ts` declares `Role` as a union type and carries zero runtime values on
 * purpose: it is re-exported from the ROOT entry, budgeted at <=1 kB brotli inside apps/widget's app
 * shell, and only an erased `export type` keeps that free. That docblock also says where a runtime
 * list belongs when one is needed — "behind the `./forms` subpath, never here" — and this is that
 * subpath, which apps/widget never imports (ADR-028; Zod is an optional peer for the same reason).
 *
 * IT REPLACES A SECOND COPY rather than adding a first one. `apps/web/src/features/auth/roles.ts`
 * carried this list as a declared stopgap while `StoreInvitationRequest.json` did not exist; with the
 * manifest dumped, `test/form-drift.test.ts` probes `in:` MEMBER BY MEMBER against the server's own
 * `Rule::in(OrgRole::values())`, so a fifth role added server-side now fails the contracts suite here
 * instead of being invisible until a select silently omits it.
 *
 * `satisfies readonly Role[]` catches the direction a type cannot: adding a member that is not in the
 * union is a typecheck failure. The other direction — the union gaining a member this tuple lacks — is
 * caught by the manifest probe, which is the better check anyway, because the union is itself a
 * hand-written mirror and only the manifest is dumped from the server.
 *
 * ORDER IS MOST- TO LEAST-PRIVILEGED, matching `App\Enums\OrgRole`'s declaration order: the select
 * reads as a ladder, and a shuffled one invites a mis-click on the one grant that cannot be undone.
 */
export const ORG_ROLES = [
  'owner',
  'admin',
  'knowledge_manager',
  'analyst',
] as const satisfies readonly Role[];

/**
 * Mirrors `StoreInvitationRequest`: `{email, role}`.
 *
 * `email` is the shared `emailField`, so `max:254` and the trim-before-check ordering are the same
 * here as on login — including the deliberate absence of `.toLowerCase()`. The server's
 * `prepareForValidation()` lowercases unconditionally (it must: `organization_invitations_email_lowercase`
 * is a database CHECK, so a mixed-case address would otherwise fail the INSERT with a constraint name
 * and the administrator would read a 500 for typing `Bob@x.com`). Because it is unconditional,
 * normalising client-side changes nothing observable except the user watching their own input rewrite
 * itself. THIS RESOLVES A DISAGREEMENT BETWEEN TWO DOCBLOCKS in favour of this one:
 * `StoreInvitationRequest` says the Zod mirror "must lowercase and trim on its own side". Trim, yes —
 * parity with the body the server validates. Lowercase, no, for the reason above.
 *
 * `role` is `z.enum(ORG_ROLES)`, mirroring `Rule::in(OrgRole::values())`. A `<Select>` cannot emit a
 * value outside its options, so this is not the control that stops a forged role — `Gate::authorize`
 * is, and `owner` needs a SECOND permission (`members.manage_owner`) that a non-owner admin does not
 * hold. What the enum buys is that a role the server dropped fails the drift suite rather than
 * rendering an option that 422s.
 *
 * NO `organization_id`: the organization is a path segment, and `TenantContext` re-reads the
 * membership row per request. A form field naming the tenant is banned outright (OWNERSHIP_KEYS).
 */
export const inviteMemberSchema = z.strictObject({
  email: emailField(),
  role: z.enum(ORG_ROLES),
});

export type InviteMemberIn = z.input<typeof inviteMemberSchema>;
export type InviteMemberOut = z.output<typeof inviteMemberSchema>;

/**
 * `analyst`, the LEAST privileged, deliberately.
 *
 * A default is what a distracted administrator ships. Defaulting too low costs a second invitation;
 * defaulting too high creates a member who can do things nobody meant to grant. It is also the only
 * default that is never refused — `owner` would 403 for every admin who is not one
 * (`Gate::authorize('inviteOwner', …)`), which would make the form's INITIAL state an error for half
 * the people allowed to use it.
 */
export const inviteMemberFormDefaults = (): InviteMemberIn => ({ email: '', role: 'analyst' });
