/**
 * `@kb/contracts/forms` — the ONLY entry point that touches Zod.
 *
 * Zod is an OPTIONAL PEER dependency here (ADR-028): apps/web and apps/mobile install it,
 * apps/widget does not and never imports this subpath, so the library stays outside its 30 kB
 * brotli app-shell budget by construction rather than by tree-shaking luck.
 */
export { OWNERSHIP_KEYS, isOwnershipPath } from './ownership.js';
export type { OwnershipKey } from './ownership.js';

/**
 * `ORG_ROLES` is the ONE runtime value in this barrel that is not a schema or a defaults factory, and
 * this subpath is the only place in the package it may live: `src/resources/session.ts` declares
 * `Role` as a union with zero runtime values because it is re-exported from the ROOT entry, budgeted
 * at <=1 kB brotli inside apps/widget's app shell. `test/resource-drift.test.ts` asserts the root
 * entry's export list, so re-exporting it from there would go red.
 */
export {
  forgotPasswordFormDefaults,
  forgotPasswordSchema,
  inviteMemberFormDefaults,
  inviteMemberSchema,
  loginFormDefaults,
  loginSchema,
  ORG_ROLES,
  registerFormDefaults,
  registerSchema,
  resetPasswordFormDefaults,
  resetPasswordSchema,
} from './auth.js';
export type {
  ForgotPasswordIn,
  ForgotPasswordOut,
  InvitationPreviewSource,
  InviteMemberIn,
  InviteMemberOut,
  LoginIn,
  LoginOut,
  RegisterIn,
  RegisterOut,
  ResetPasswordIn,
  ResetPasswordLink,
  ResetPasswordOut,
} from './auth.js';

export { botFormDefaults, botSettingsSchema } from './bot.js';
export type { BotFormSource, BotSettingsIn, BotSettingsOut } from './bot.js';

export {
  embeddingDesignationDefaults,
  embeddingDesignationSchema,
} from './embedding-designation.js';
export type {
  EmbeddingDesignationIn,
  EmbeddingDesignationOut,
  EmbeddingDesignationSource,
} from './embedding-designation.js';

/**
 * The provider EDIT schema, and only the edit schema. The create and rotate bodies carry the
 * plaintext `credential` and have no shared schema at all, on purpose — `NO_CLIENT_FORM` in
 * test/form-drift.test.ts records both decisions with their reasons, and this barrel is exactly the
 * surface those reasons are about: anything exported here is importable by apps/mobile and
 * apps/widget by default.
 *
 * `PROVIDER_CONNECTION_STATUSES` is the second runtime value in this barrel that is not a schema or
 * a defaults factory (after `ORG_ROLES`), and it is here for the same reason: the root entry is
 * type-only.
 */
export {
  PROVIDER_CONNECTION_STATUSES,
  providerConnectionEditDefaults,
  providerConnectionEditSchema,
} from './provider-connection.js';
export type {
  ProviderConnectionEditIn,
  ProviderConnectionEditOut,
  ProviderConnectionEditSource,
} from './provider-connection.js';

/**
 * BOTH model-catalog schemas, and the contrast with the block above is the whole reason this one
 * needs no caveat: neither request carries a credential, a password, or anything a `defaults(row)`
 * could seed into a secret. What they do carry is a decimal SCALE, a price CEILING and an element
 * cap — three numbers that drift in silence — so they ship as MIRRORS entries with probes rather
 * than as an exemption with a reason.
 *
 * `providerModelEditDefaults` returns the schema's OUTPUT type rather than its input, which is
 * unlike every other defaults factory here and is deliberate: `PUT …/models/{model}` refuses a
 * partial body, so the same value serves as the form's `defaultValues` AND as a complete replacement
 * payload for the inline `enabled` toggle. See its docblock.
 */
export {
  providerModelCreateDefaults,
  providerModelCreateSchema,
  providerModelEditDefaults,
  providerModelEditSchema,
} from './provider-model.js';
export type {
  ProviderModelCreateIn,
  ProviderModelCreateOut,
  ProviderModelEditIn,
  ProviderModelEditOut,
  ProviderModelEditSource,
} from './provider-model.js';

export { uploadSchema } from './upload.js';
export type { OrgUploadLimits, UploadIn, UploadOut, UploadSchema } from './upload.js';
