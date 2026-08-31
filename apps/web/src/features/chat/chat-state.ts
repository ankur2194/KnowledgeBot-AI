import { KbError, type Citation, type KbEvent } from '@kb/contracts';

/**
 * The transcript, reduced from the six client-facing SSE events.
 *
 * `kb-rag-query-contract` has twenty stages. A USER NEEDS FOUR, and this file is where that
 * reduction is made once so no component can invent a fifth:
 *
 *   sent       the request was accepted — the user turn is on screen, the composer is cleared
 *   working    before the first token — a skeleton plus ONE honest label
 *   streaming  text is appending, with the single caret; Stop is live, actions are hidden
 *   settled    a terminal event arrived — caret gone, sources resolved, actions shown
 *
 * THE INTERNAL STAGE NAMES NEVER REACH A SCREEN. `status.stage` is `retrieving | reranking |
 * generating`; what a reader sees is "Searching your sources…" then "Writing an answer…". Not a
 * percentage, not a stage counter, and not our vocabulary.
 */
export type Phase = 'sent' | 'working' | 'streaming' | 'settled';

/**
 * Settled has FOUR outcomes and each has its own visual. The one that matters most is `refused`:
 * when evidence is below the threshold the bot says it does not know, and that answer is CORRECT
 * BEHAVIOUR — a grounded system refusing is the feature. It shares no styling with `failed`.
 */
export type Outcome = 'answered' | 'refused' | 'stopped' | 'failed';

export interface AssistantTurn {
  readonly kind: 'assistant';
  /** The client-minted id of the user turn this answers, so the pair reconciles as one unit. */
  readonly id: string;
  /**
   * The SERVER's id for this answer, off `message.start`, and `null` until it arrives.
   *
   * TWO IDS, AND THEY ANSWER DIFFERENT QUESTIONS. `id` above is the client-minted UUID: it is the
   * idempotency key, it reconciles the optimistic user turn with the server's echo, and it exists
   * before the request does. This one is the row `POST /rt/v1/messages/{message}/feedback` addresses,
   * and it cannot exist until the server has opened the turn.
   *
   * SO THE THUMBS ARE GATED ON IT, not on `settled`. A stream that failed before `message.start` —
   * a 401, a refused origin, a dead socket on the first byte — has no row to rate, and offering a
   * control that would 404 is worse than offering none.
   */
  readonly messageId: string | null;
  readonly text: string;
  readonly citations: readonly Citation[];
  readonly phase: Phase;
  readonly outcome: Outcome | null;
  /** Set only on `failed`. Carries the class and the request id; its `message` is never rendered. */
  readonly error: KbError | null;
  /** What the working label should say right now. Never an internal stage name. */
  readonly label: string;
}

export interface UserTurn {
  readonly kind: 'user';
  readonly id: string;
  readonly text: string;
}

export type Turn = UserTurn | AssistantTurn;

/** "Searching your sources…" covers retrieval AND reranking, deliberately: the difference is ours,
 *  not the reader's, and a label that changes twice in two seconds reads as a flicker. */
const WORKING_LABEL = 'Searching your sources…';
const WRITING_LABEL = 'Writing an answer…';

export function startAssistantTurn(id: string): AssistantTurn {
  return {
    kind: 'assistant',
    id,
    messageId: null,
    text: '',
    citations: [],
    phase: 'sent',
    outcome: null,
    error: null,
    label: WORKING_LABEL,
  };
}

/**
 * One event, one turn, no side effects — which is what makes the four visible states testable
 * without a browser, a fixture server or a clock.
 */
export function applyEvent(turn: AssistantTurn, event: KbEvent): AssistantTurn {
  switch (event.event) {
    case 'message.start':
      // The server's row id arrives HERE and nowhere else — `message.complete` carries it again, and
      // reading it only from there would leave a stopped or failed turn unratable even though its
      // row exists.
      return { ...turn, phase: 'working', messageId: event.data.message_id };

    case 'status':
      return {
        ...turn,
        phase: 'working',
        label: event.data.stage === 'generating' ? WRITING_LABEL : WORKING_LABEL,
      };

    case 'citations':
      // Assigned from retrieved evidence BEFORE generation. The UI never parses a marker out of the
      // answer text and looks up what it might mean — a citation the model invented is a fabricated
      // source attribution shown to a customer's customer.
      return { ...turn, citations: event.data.citations };

    case 'token':
      return { ...turn, phase: 'streaming', text: turn.text + event.data.text };

    case 'message.complete':
      return { ...turn, phase: 'settled', outcome: outcomeFor(event.data.finish_reason) };

    case 'error':
      return {
        ...turn,
        phase: 'settled',
        outcome: 'failed',
        // POSITIONAL, matching the constructor: (error_class, retryable, retry_after, request_id,
        // message). `retryable` comes straight off the envelope and is the AUTHORITY — a predicate
        // that re-derives it from the class is wrong for `internal_dependency`, whose two sub-cases
        // render 500/false and 503/true from the same class name.
        //
        // `request_id` is documented as absent on SSE `error` frames and present on HTTP envelopes,
        // so it is read optionally rather than assumed; the retry affordance does not depend on it.
        error: new KbError(
          event.data.error_class,
          event.data.retryable,
          null,
          event.data.request_id ?? null,
          event.data.message,
        ),
      };
  }
}

/**
 * STOPPING IS A FIRST-CLASS OUTCOME, NOT A CANCELLATION. `cancelled` keeps the partial answer, marks
 * it stopped and offers Regenerate — discarding what was already streamed throws away work the
 * tenant was already billed for.
 *
 * `insufficient_evidence` is the REFUSAL, and it is a success: the pipeline found too little to
 * answer from and said so. Mapping it anywhere near the error path is the mistake that generates
 * support tickets about a product that is working.
 */
function outcomeFor(reason: 'stop' | 'length' | 'cancelled' | 'insufficient_evidence' | 'error'): Outcome {
  switch (reason) {
    case 'insufficient_evidence':
      return 'refused';
    case 'cancelled':
      return 'stopped';
    case 'error':
      return 'failed';
    case 'stop':
    case 'length':
      return 'answered';
  }
}

/** What the polite live region announces. STATE TRANSITIONS, never the token stream: a live region
 *  updated per token makes a screen reader stutter unusably, and announcing the state is what actual
 *  screen-reader users prefer — the answer itself stays ordinary content they navigate when ready. */
export function announcementFor(turn: AssistantTurn): string {
  if (turn.phase === 'working') return turn.label;
  if (turn.phase === 'streaming') return 'Answer started.';
  if (turn.phase !== 'settled') return '';

  switch (turn.outcome) {
    case 'answered':
      return turn.citations.length === 1
        ? 'Answer ready, 1 source.'
        : `Answer ready, ${turn.citations.length} sources.`;
    case 'refused':
      return 'No answer: not enough in this bot’s sources.';
    case 'stopped':
      return 'Answer stopped.';
    case 'failed':
      return 'The answer could not be completed.';
    default:
      return '';
  }
}
