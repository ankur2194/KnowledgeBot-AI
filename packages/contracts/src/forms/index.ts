/**
 * `@kb/contracts/forms` — the ONLY entry point that touches Zod.
 *
 * Zod is an OPTIONAL PEER dependency here (ADR-028): apps/web and apps/mobile install it,
 * apps/widget does not and never imports this subpath, so the library stays outside its 30 kB
 * brotli app-shell budget by construction rather than by tree-shaking luck.
 */
export { OWNERSHIP_KEYS, isOwnershipPath } from './ownership.js';
export type { OwnershipKey } from './ownership.js';

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
