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

/**
 * BOTH bot schemas, plus the five closed vocabularies as runtime tuples.
 *
 * The tuples are the third, fourth, fifth, sixth and seventh runtime values in this barrel that are
 * not a schema or a defaults factory (after `ORG_ROLES` and `PROVIDER_CONNECTION_STATUSES`), and
 * they are here for the identical reason: `src/resources/bots.ts` declares each of them as a UNION
 * with zero runtime values, because it is re-exported from the ROOT entry and budgeted at <=1 kB
 * brotli inside apps/widget's app shell. A `<Select>` needs a list it can iterate; the resource type
 * needs a union it can narrow. Neither spelling is the other's source — each is pinned to the server
 * by its own drift suite.
 */
export {
  BOT_ACCESS_MODES,
  BOT_ANSWER_MODES,
  BOT_STATUSES,
  botCreateDefaults,
  botCreateSchema,
  botFormDefaults,
  botSettingsSchema,
  botStatusTransitionDefaults,
  botStatusTransitionSchema,
  EVIDENCE_THRESHOLD_SCALES,
  THEME_RADII,
} from './bot.js';
export type {
  BotCreateIn,
  BotCreateOut,
  BotFormSource,
  BotSettingsIn,
  BotSettingsOut,
  BotStatusTransitionIn,
  BotStatusTransitionOut,
} from './bot.js';

/**
 * THE TWO CHILD COLLECTIONS UNDER A BOT, and they are separate modules rather than four more
 * declarations in `bot.js` for the reason `provider-model.ts` is separate from
 * `provider-connection.ts`: they are different resources with their own endpoints, their own
 * lifecycles and their own manifests, and the only thing they share with the bot body is a URL
 * prefix.
 *
 * `BOT_DOMAIN_STATUSES` is the eighth runtime value in this barrel that is not a schema or a
 * defaults factory, and it is here for the identical reason as the seven before it:
 * `src/resources/bots.ts` declares `BotDomainStatus` as a UNION with zero runtime values, because
 * that module is re-exported from the ROOT entry and budgeted at <=1 kB brotli inside apps/widget's
 * app shell.
 *
 * NEITHER MODULE MIRRORS ITS SERVER-SIDE GRAMMAR. `App\Rules\ExactWidgetOrigin` is a security
 * control whose refusals are its content, and a third spelling of it here would be the copy nothing
 * compares to the other two — see the module docblock in `bot-domain.ts`, which also records the one
 * rule that DOES matter to a caller: render `origin` from the response, because the server
 * normalises it on write.
 */
export {
  BOT_DOMAIN_STATUSES,
  botDomainCreateDefaults,
  botDomainCreateSchema,
  botDomainStatusDefaults,
  botDomainStatusSchema,
} from './bot-domain.js';
export type {
  BotDomainCreateIn,
  BotDomainCreateOut,
  BotDomainStatusIn,
  BotDomainStatusOut,
} from './bot-domain.js';

export {
  starterQuestionCreateDefaults,
  starterQuestionCreateSchema,
  starterQuestionUpdateDefaults,
  starterQuestionUpdateSchema,
} from './bot-starter-question.js';
export type {
  StarterQuestionCreateIn,
  StarterQuestionCreateOut,
  StarterQuestionSource,
  StarterQuestionUpdateIn,
  StarterQuestionUpdateOut,
} from './bot-starter-question.js';

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

/**
 * The upload form, and it is the one entry here that exports a schema FACTORY rather than a schema:
 * §8.10 makes the size cap and the MIME allow-list per-organization, so there is no byte constant
 * and no MIME constant in this package to build a fixed schema out of. Callers instantiate it with
 * the limits the bootstrap config returned for the organization they are rendering for.
 *
 * `uploadDefaults` takes no argument for the same reason its siblings take one — see its docblock.
 *
 * `OrgUploadLimits` IS NO LONGER DECLARED IN `./upload.js` AND IS STILL EXPORTED FROM IT, which is
 * deliberate and is the only entry in this barrel where the two differ. The endpoint that returns it
 * landed (`GET .../sources/upload-limits`, published as `OrgUploadLimitsResource`), so the shape is
 * the server's and its one mirror lives in `src/resources/sources.ts` beside every other mirrored
 * response, under the three pins in test/resource-drift.test.ts. The line below re-exports that
 * declaration rather than a copy of it: `uploadSchema(limits: OrgUploadLimits)` is public API inside
 * this monorepo and moving where callers import the parameter type from would be a rename with a
 * blast radius and no benefit. It stays erased at runtime — `export type`, so the `/forms` bundle is
 * unchanged and the root entry's <=1 kB budget never sees it.
 */
export { uploadDefaults, uploadSchema } from './upload.js';
export type { OrgUploadLimits, UploadIn, UploadOut, UploadSchema } from './upload.js';

/**
 * The quota ceilings. `QUOTA_METRICS` is the ninth runtime value in this barrel that is not a schema
 * or a defaults factory, and it is here for the identical reason as the eight before it:
 * `src/resources/quotas.ts` declares `QuotaMetricName` as a UNION with zero runtime values, because
 * that module is re-exported from the ROOT entry and budgeted at <=1 kB brotli inside apps/widget's
 * app shell. The settings screen renders one row per metric in a fixed order and needs a list it can
 * iterate; the resource type needs a union it can narrow.
 *
 * `quotaFieldFor` is the tenth, and it is a FUNCTION rather than a second tuple on purpose: the four
 * request keys are the four metric names plus a suffix, and spelling them out twice is how a fifth
 * metric lands with three of its four spellings updated.
 */
export {
  QUOTA_METRICS,
  quotaFieldFor,
  quotaLimitsFormDefaults,
  quotaLimitsSchema,
} from './quota-limits.js';
export type { QuotaLimitsIn, QuotaLimitsOut } from './quota-limits.js';
