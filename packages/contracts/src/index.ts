/**
 * `@kb/contracts` root entry — ZERO runtime dependencies, budgeted at <=1 kB brotli inside
 * apps/widget's 30 kB app-shell entry (preact-vite-library). Nothing here may import `zod`:
 * the schemas live behind `@kb/contracts/forms` (ADR-028), because relying on tree-shaking to
 * remove a library from a budget is the silently-passing size gate that skill warns about.
 */
export { ERROR_CLASSES, isErrorClass, STREAM_LOST } from './error-classes.js';
export type { ClientErrorClass, ErrorClass, StreamLost } from './error-classes.js';

export { KbError, parseRetryAfter, toKbError } from './errors.js';
export type { ErrorResponseLike } from './errors.js';

export { isKbErrorEnvelope, isKbValidationEnvelope } from './envelope.js';
export type { KbErrorEnvelope, KbValidationEnvelope } from './envelope.js';

export { createFrameBuffer, parseFrame } from './sse/parse-frame.js';
export type { SseFrame } from './sse/frame.js';

export {
  CLIENT_EVENT_NAMES,
  TERMINAL_EVENTS,
  isKbEventName,
  isTerminalEvent,
  toKbEvent,
} from './sse/events.js';
export type {
  Citation,
  CitationsData,
  FinishReason,
  KbEvent,
  KbEventName,
  MessageCompleteData,
  MessageStartData,
  StatusData,
  TokenData,
} from './sse/events.js';

export type { ChatSendBody } from './chat.js';

/**
 * `export type` ONLY, and the two halves of that are both load-bearing:
 *
 *   1. `tsconfig.base.json:12` sets `verbatimModuleSyntax: true`, so a type-only re-export is
 *      ERASED — it emits nothing into dist/index.js and the <=1 kB brotli budget above is provably
 *      untouched. A bare `export { … }` would be a real re-export and would drag the module in.
 *   2. every module under `src/resources/` contains no runtime value at all, so there is nothing here
 *      that COULD be emitted. That is why `Role` is a union and not a `ROLES` tuple, and why
 *      `InvitationStatus` is a union rather than a status tuple.
 *
 * test/resource-drift.test.ts asserts the built entry's export list, so this stays a property
 * rather than a comment.
 */
export type {
  InvitationPreview,
  MembershipStatus,
  Role,
  SessionMembership,
  SessionResource,
  SessionUser,
} from './resources/session.js';

export type {
  AcknowledgementResource,
  InvitationCollectionResource,
  InvitationResource,
  InvitationStatus,
  MemberCollectionResource,
  MemberResource,
} from './resources/members.js';
