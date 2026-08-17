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

export { uploadSchema } from './upload.js';
export type { OrgUploadLimits, UploadIn, UploadOut, UploadSchema } from './upload.js';
