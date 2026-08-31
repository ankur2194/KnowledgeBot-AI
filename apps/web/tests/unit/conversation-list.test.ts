import type { ProviderCallResource, TranscriptMessageResource } from '@kb/contracts';
import indexConversationsRules from '@kb/contracts/rules/IndexConversationsRequest.json';
import { describe, expect, it } from 'vitest';

import {
  CONVERSATION_CHANNELS,
  CONVERSATION_FILTER_PARAMS,
  CONVERSATION_LIST_CONFIG,
  CONVERSATION_SORTABLE_COLUMNS,
  CONVERSATION_STATUSES,
  canViewConversations,
  channelDisplay,
  conversationPollInterval,
  conversationStatusDisplay,
  messageStatusDisplay,
  turnMetrics,
} from '@/features/conversations/api';
import { assertTableParamsConfig, MAX_PER_PAGE } from '@/lib/table/params';

/**
 * THE CONVERSATION LIST'S DERIVATIONS AND THE ONE PIECE OF ARITHMETIC ON THE TRANSCRIPT.
 *
 * As with the audit list, the point is not the values: it is that a manifest whose shape changed
 * fails HERE rather than degrading to an empty set at render time — a filter with no options and a
 * header that cannot be clicked, with nothing red anywhere.
 */

const RULES = (indexConversationsRules as { rules: Record<string, readonly string[]> }).rules;

describe('the sortable set and the default sort come from the endpoint', () => {
  it('offers the two the FormRequest permits', () => {
    expect(CONVERSATION_SORTABLE_COLUMNS).toEqual(['last_activity_at', 'started_at']);
  });

  it('defaults to most recently active first', () => {
    // `started_at` is when the thread OPENED; `last_activity_at` is when a turn was last TAKEN. A
    // reviewer opening this screen is asking "what is happening now", and every stale-thread sweep
    // is built on the second column.
    expect(CONVERSATION_LIST_CONFIG.defaultSort).toEqual({ id: 'last_activity_at', desc: true });
    expect(CONVERSATION_SORTABLE_COLUMNS).toContain(CONVERSATION_LIST_CONFIG.defaultSort.id);
  });

  it('declares a config the params module will accept', () => {
    expect(() => assertTableParamsConfig(CONVERSATION_LIST_CONFIG)).not.toThrow();
    expect(Math.max(...CONVERSATION_LIST_CONFIG.pageSizes)).toBeLessThanOrEqual(MAX_PER_PAGE);
  });
});

describe('the two filter vocabularies are the endpoint’s closed sets', () => {
  it('offers all five channels, including the one that is unreachable in practice', () => {
    expect(CONVERSATION_CHANNELS).toEqual(['hosted', 'embedded', 'mobile', 'playground', 'api']);
    // `mobile` IS IN THE VOCABULARY AND UNREACHABLE TODAY: nothing in the control plane mints a
    // personal access token, so the React Native client has no credential for the runtime surface —
    // and the server declines to label those turns `hosted` because that would put a number in the
    // channel breakdown that is simply untrue. So the option renders and correctly returns nothing.
    expect(CONVERSATION_CHANNELS).toContain('mobile');
  });

  it('offers the three thread states', () => {
    expect(CONVERSATION_STATUSES).toEqual(['active', 'ended', 'expired']);
  });

  it('names only parameters the endpoint validates', () => {
    for (const name of CONVERSATION_FILTER_PARAMS) {
      expect(Object.keys(RULES)).toContain(name);
    }
  });

  it('keeps session_id and user_id in the URL vocabulary even though no control writes them', () => {
    // The CONTROLS are absent — a session id is a one-way digest nobody types, a user id is a ULID
    // whose useful form is a picker on a different screen — but the params stay in `filterNames` so a
    // URL carrying either survives a page change and reaches the request. Dropping them from the
    // config would silently unfilter such a URL, which is the worst of the three options.
    expect(CONVERSATION_FILTER_PARAMS).toContain('session_id');
    expect(CONVERSATION_FILTER_PARAMS).toContain('user_id');
    expect(CONVERSATION_LIST_CONFIG.filterNames).toEqual(CONVERSATION_FILTER_PARAMS);
  });
});

describe('the list does not poll, and that is a decision rather than an omission', () => {
  it('returns false and never a number', () => {
    /**
     * A CONVERSATION LIST HAS NO TERMINAL STATE TO STOP ON.
     *
     * The sources list polls because a source walks a lifecycle whose transient states END, and its
     * interval returns `false` the moment no row is going anywhere — that stop condition is what
     * keeps a tab left open from becoming permanent traffic. `active` is NOT transient: a thread
     * stays active until a visitor stops talking or the idle sweeper expires it, which can be hours.
     * So a function-form interval here would return a number for ever, which is the bare-number
     * failure wearing a function's shape.
     */
    expect(conversationPollInterval()).toBe(false);
  });
});

describe('the display vocabularies', () => {
  it('paints expired as inert rather than as a failure', () => {
    expect(conversationStatusDisplay('active').kind).toBe('running');
    expect(conversationStatusDisplay('ended').kind).toBe('ready');
    // The retention sweeper marked it, which is the platform doing what the organization asked. A
    // red pill would read as an incident.
    expect(conversationStatusDisplay('expired').kind).toBe('disabled');
  });

  it('renders a state this build has never heard of as itself, neutrally', () => {
    expect(conversationStatusDisplay('archived')).toEqual({ kind: 'pending', label: 'archived' });
  });

  it('paints a cancelled turn as inert rather than as a failure', () => {
    // A closed tab is a NORMAL outcome and the tokens generated before it were still billed;
    // painting it red would make an ordinary Tuesday look like an incident.
    expect(messageStatusDisplay('cancelled').kind).toBe('disabled');
    expect(messageStatusDisplay('failed').kind).toBe('failed');
    // `pending` and `streaming` are turns still IN FLIGHT, and a stuck one is what an operator opens
    // this screen to find — so they move rather than being hidden.
    expect(messageStatusDisplay('pending').kind).toBe('running');
    expect(messageStatusDisplay('streaming').kind).toBe('running');
  });

  it('gives every channel a distinct tone, assigned from a stable key', () => {
    const tones = CONVERSATION_CHANNELS.map((channel) => channelDisplay(channel).tone);
    // Distinct, so the tint is a scanning aid rather than decoration that reads as information — and
    // keyed by the channel NAME, so re-sorting the table cannot recolour a row.
    expect(new Set(tones).size).toBe(CONVERSATION_CHANNELS.length);
    expect(channelDisplay('unheard-of')).toEqual({ label: 'unheard-of', tone: 'slate' });
  });
});

describe('who may read a transcript', () => {
  it('is the owner, the admin and the analyst — and not the knowledge manager', () => {
    expect(canViewConversations('owner')).toBe(true);
    expect(canViewConversations('admin')).toBe(true);
    // §6.5's first two responsibilities are "Review conversations" and "Add feedback", so this is
    // the reporting role's stated job rather than an inference from it.
    expect(canViewConversations('analyst')).toBe(true);
    // §6.4's "review parsed content" is the SOURCE detail projection that `sources.view` already
    // serves; a transcript is verbatim END-USER text rather than parsed content.
    expect(canViewConversations('knowledge_manager')).toBe(false);
    expect(canViewConversations(null)).toBe(false);
  });
});

// ── THE ONE PIECE OF ARITHMETIC ON THE TRANSCRIPT ──────────────────────────────────────────────

const call = (overrides: Partial<ProviderCallResource>): ProviderCallResource => ({
  id: '01JCALLAAAAAAAAAAAAAAAAAAA',
  provider_connection_id: '01JCONNAAAAAAAAAAAAAAAAAAA',
  model_id: '01JMODELAAAAAAAAAAAAAAAAAA',
  provider_request_id: null,
  status: 'succeeded',
  error_class: null,
  input_tokens: null,
  cache_read_tokens: null,
  cache_write_tokens: null,
  output_tokens: null,
  reasoning_tokens: null,
  estimated_cost: null,
  estimated_cost_currency: null,
  first_token_latency_ms: null,
  total_latency_ms: null,
  fallback_metadata: {},
  created_at: '2026-08-27T10:00:00+00:00',
  ...overrides,
});

const message = (
  calls: readonly ProviderCallResource[],
  settlingId: string | null,
): TranscriptMessageResource => ({
  id: '01JMSGAAAAAAAAAAAAAAAAAAAA',
  role: 'assistant',
  content: 'An answer.',
  status: 'complete',
  parent_message_id: null,
  settling_provider_call_id: settlingId,
  created_at: '2026-08-27T10:00:00+00:00',
  updated_at: '2026-08-27T10:00:02+00:00',
  citations: [],
  retrieval_trace: null,
  feedback: [],
  provider_calls: calls,
});

describe('turnMetrics reads the attempts, never the message', () => {
  it('finds the attempt that settled the turn by id rather than by position', () => {
    const first = call({ id: 'a', status: 'failed', error_class: 'provider_temporary' });
    const second = call({ id: 'b', fallback_metadata: { ordinal: 2 } });
    const metrics = turnMetrics(message([first, second], 'b'));

    expect(metrics.settling?.id).toBe('b');
    // NOT "the last one": a fallback that also failed leaves `settling_provider_call_id` null, and
    // reading the array's tail would attribute the turn to an attempt that settled nothing.
    expect(turnMetrics(message([first, second], null)).settling).toBeNull();
  });

  it('detects a fallback by a non-empty fallback_metadata and by nothing else', () => {
    const primary = call({ id: 'a' });
    const fallback = call({ id: 'b', fallback_metadata: { ordinal: 2, trigger: 'provider_temporary' } });
    const metrics = turnMetrics(message([primary, fallback], 'b'));

    // A FALLBACK ROW WITH AN EMPTY OBJECT IS INDISTINGUISHABLE FROM A FIRST ATTEMPT, which is the
    // whole reason the column exists — so the test is on the key count and never on position.
    expect(metrics.fallbacks.map((attempt) => attempt.id)).toEqual(['b']);
    expect(turnMetrics(message([primary], 'a')).fallbacks).toHaveLength(0);
  });

  it('keeps null when NO attempt reported a token count, and sums the ones that did', () => {
    // `null` MEANS THE PROVIDER TOLD US NOTHING, which is different from zero — a turn that used no
    // output tokens and a turn whose vendor reported no usage are indistinguishable if the type
    // collapses them, and the second is the one that makes a cost figure wrong.
    expect(turnMetrics(message([call({}), call({})], null)).inputTokens).toBeNull();

    // ...and an unreported attempt beside a reported one does NOT drag the sum to zero: the figure
    // we have is reported, and the one we do not have is omitted rather than counted as 0.
    const mixed = turnMetrics(
      message([call({ id: 'a', input_tokens: 120 }), call({ id: 'b', input_tokens: null })], 'a'),
    );
    expect(mixed.inputTokens).toBe(120);

    expect(
      turnMetrics(
        message([call({ id: 'a', output_tokens: 10 }), call({ id: 'b', output_tokens: 5 })], 'b'),
      ).outputTokens,
    ).toBe(15);
  });

  it('exposes no summed cost, because two currencies may not be added', () => {
    const metrics = turnMetrics(
      message(
        [
          call({ id: 'a', estimated_cost: '0.00120000', estimated_cost_currency: 'USD' }),
          call({ id: 'b', estimated_cost: '0.00090000', estimated_cost_currency: 'EUR' }),
        ],
        'b',
      ),
    );
    // THE ABSENCE IS THE ASSERTION. `estimated_cost` is an exact decimal STRING and its currency
    // travels with it; summing means either parsing to a double — which is what `numeric(16,8)`
    // exists to avoid — or assuming one currency, which is a silent wrong answer the moment a
    // fallback crosses vendors. The screen renders each attempt's cost beside its own currency.
    expect(Object.keys(metrics)).not.toContain('estimatedCost');
    expect(metrics.attempts).toHaveLength(2);
  });
});
