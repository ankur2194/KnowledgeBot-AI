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
