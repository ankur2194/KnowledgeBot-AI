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

/**
 * Same `export type` discipline, and one extra reason on this module: it declares `masked_key`, and
 * a runtime value exported alongside it would be the first byte of a path from "the display string"
 * to "a form default". There is none — `src/resources/providers.ts` contains no value at all, and the
 * status tuple the edit form iterates lives behind `@kb/contracts/forms`.
 */
export type {
  EmbeddingCandidate,
  EmbeddingDesignation,
  EmbeddingReadinessResource,
  EmbeddingRejection,
  ProviderConnectionCollectionResource,
  ProviderConnectionCreatedResponse,
  ProviderConnectionResource,
  ProviderConnectionStatus,
  ProviderKey,
} from './resources/providers.js';

/**
 * The bot admin surface, plus `ListMetaResource` — which is not bot-specific and is here because
 * bots is the first paginated list this package mirrors. Same `export type` discipline, and the same
 * reason this module holds no runtime value: the five closed vocabularies are UNIONS, and their
 * iterable tuples live behind `@kb/contracts/forms` where a `<Select>` can reach them without
 * putting them in apps/widget's app shell.
 */
export type {
  BotAccessMode,
  BotAnswerMode,
  BotCollectionResource,
  BotDomainCollectionResource,
  BotDomainResource,
  BotDomainStatus,
  BotResource,
  BotStarterQuestionCollectionResource,
  BotStarterQuestionResource,
  BotStatus,
  BotTheme,
  BotThemeRadius,
  EvidenceThresholdScale,
  ListMetaResource,
} from './resources/bots.js';

/**
 * The model catalog under one connection. Same `export type` discipline, and the same reason this
 * module holds no runtime value: `supported` is an OPEN string array because the capability
 * vocabulary belongs to the data plane and the control plane publishes no enum for it, so there is
 * nothing here to export as a tuple — the admin console's closed checkbox list is a UI affordance
 * declared beside the form that renders it, not a contract.
 */
export type {
  ProviderModelCollectionResource,
  ProviderModelResource,
} from './resources/provider-models.js';

/**
 * The knowledge-source admin surface. Same `export type` discipline, and the same reason this module
 * holds no runtime value — with one asymmetry against `bots.ts` worth naming here rather than only in
 * the module: its two closed vocabularies have NO tuple sibling behind `@kb/contracts/forms`, because
 * no source form ships yet. `SourceStatus` is pinned to the server by the enum comparison in
 * test/resource-drift.test.ts and by nothing else, which is the correct amount of pinning for a
 * vocabulary nothing iterates.
 *
 * `OrgUploadLimits` IS THE ONE SHAPE HERE THAT IS ALSO REACHABLE THROUGH `@kb/contracts/forms`, and
 * that is a re-export of this declaration rather than a second one — `src/forms/upload.ts` needs it
 * as `uploadSchema`'s parameter and its callers already import it from there. Both doors, one
 * definition; the alternative was an `OrgUploadLimitsResource` interface beside an `OrgUploadLimits`
 * one, which is the two-spellings-of-a-shape drift this package's suites exist to catch.
 */
export type {
  OrgUploadLimits,
  SourceActiveVersionResource,
  SourceCollectionResource,
  SourceDetailResource,
  SourceResource,
  SourceStatus,
  SourceType,
  SourceWarningResource,
} from './resources/sources.js';

/**
 * The bot↔source grant. Same `export type` discipline, and the module it comes from is the one place
 * in `src/resources/` that exists for a STRUCTURAL reason rather than a subject one: the shape joins
 * two surfaces, so putting it in `bots.ts` would have made that module import from `sources.ts` while
 * `sources.ts` already imports `ListMetaResource` back out of it. The argument is at the head of the
 * module; the consequence here is that this block is a third source of source-shaped types and the
 * `SourceResource` it nests is the one declared once in `./resources/sources.js`.
 */
export type {
  BotSourceAssignmentCollectionResource,
  BotSourceAssignmentResource,
} from './resources/bot-source-assignments.js';

/**
 * §8.23's dashboard. Same `export type` discipline and the same reason: `src/resources/analytics.ts`
 * holds no runtime value, so this block is erased and the root entry's <=1 kB brotli budget is
 * untouched. There is no tuple sibling behind `@kb/contracts/forms` either — nothing iterates a
 * closed analytics vocabulary; the only enumerable thing on the shape is `usage_by_model`, which is
 * data rather than a vocabulary.
 */
export type {
  AnalyticsFeedback,
  AnalyticsIngestion,
  AnalyticsLatency,
  AnalyticsModelUsage,
  AnalyticsProviderCalls,
  AnalyticsResource,
  AnalyticsWindow,
} from './resources/analytics.js';

/**
 * The quota ceilings. `QuotaMetricName` IS a closed vocabulary and DOES have a tuple sibling —
 * `QUOTA_METRICS` behind `@kb/contracts/forms` — because the settings screen renders one row per
 * metric in a fixed order and a `<form>` needs a list it can iterate. Same split as `ORG_ROLES` and
 * `BOT_STATUSES`: the union narrows, the tuple iterates, and each is pinned to the server
 * independently (this one against the published `enum`, that one against the dumped `rules()`).
 */
export type {
  QuotaMetricName,
  QuotaMetricUsage,
  QuotaResource,
  QuotaUsageSource,
} from './resources/quotas.js';

/**
 * The audit trail. NO tuple sibling for `operation`, deliberately: the vocabulary is 45 names and is
 * already published twice — as the resource's inlined `enum` and as `IndexAuditLogsRequest`'s `in:`
 * rule — so a third transcription here is the copy nothing would compare to either. The console
 * reads the rule manifest through `enumFromRule`, exactly as it does for a sortable set.
 */
export type {
  AuditLogCollectionResource,
  AuditLogResource,
  AuditOutcome,
} from './resources/audit-logs.js';

/**
 * Conversation review, and it is the widest block in this file because the transcript nests four
 * child collections. Same `export type` discipline; the six closed vocabularies are UNIONS with no
 * tuple siblings, because the conversation surface is READ-ONLY — there is no `<Select>` anywhere
 * that needs to iterate a message status.
 */
export type {
  ConversationChannel,
  ConversationCollectionResource,
  ConversationResource,
  ConversationStatus,
  ConversationTranscriptResource,
  FeedbackRating,
  MessageRole,
  MessageStatus,
  ProviderCallResource,
  ProviderCallStatus,
  RetrievalTraceResource,
  TranscriptCitationResource,
  TranscriptFeedbackResource,
  TranscriptMessageResource,
} from './resources/conversations.js';

/**
 * The PUBLIC runtime — the one block here `apps/widget` genuinely consumes, which is why it is in
 * the root entry rather than behind a subpath. It is types only and therefore free.
 *
 * `RetrievalTraceResource` above and the `retrieval.trace` FRAME behind `@kb/contracts/admin` are
 * different shapes for different audiences and neither is exported from the other's home. Nothing
 * about the diagnostic frame reaches this entry.
 */
export type {
  ChatSessionResource,
  RuntimeBotResource,
  RuntimeCitationCollectionResource,
  RuntimeCitationResource,
  RuntimeConversationResource,
  RuntimeMessageCollectionResource,
  RuntimeMessageResource,
} from './resources/runtime.js';
