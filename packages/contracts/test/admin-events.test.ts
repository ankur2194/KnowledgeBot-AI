import { describe, expect, it } from 'vitest';

import {
  ADMIN_EVENT_NAMES,
  RETRIEVAL_TRACE_EVENT,
  isRetrievalTraceEvent,
  toKbAdminEvent,
} from '../src/sse/admin-events.js';
import { CLIENT_EVENT_NAMES, isTerminalEvent, toKbEvent } from '../src/sse/events.js';
import { createFrameBuffer } from '../src/sse/parse-frame.js';
import type { SseFrame } from '../src/sse/frame.js';

/**
 * THE ADMIN-ONLY SSE UNION, and the property that makes it a boundary rather than a convenience:
 * the PUBLIC decoder must still refuse `retrieval.trace`, and the ADMIN decoder must still refuse
 * the other three internal frames.
 *
 * Both halves are asserted, because either alone passes against a broken implementation — a union
 * that admitted everything would satisfy the second, and one that admitted nothing would satisfy the
 * first.
 */

const frame = (event: string, data: unknown): SseFrame => ({
  event,
  data: JSON.stringify(data),
  id: null,
  retry: null,
});

describe('the admin union widens by exactly one name', () => {
  it('is the six public names plus retrieval.trace, in that order', () => {
    expect(ADMIN_EVENT_NAMES).toEqual([...CLIENT_EVENT_NAMES, RETRIEVAL_TRACE_EVENT]);
  });

  it('spells the diagnostic name exactly as ClientEvents::DIAGNOSTIC does', () => {
    // Byte-identical to `App\Support\Kb\ClientEvents::DIAGNOSTIC` and to
    // `RetrievalTraceFrame.event`'s Literal. A rename on either side is a frame Laravel forwards and
    // this client silently drops, which is indistinguishable from diagnostics being disabled.
    expect(RETRIEVAL_TRACE_EVENT).toBe('retrieval.trace');
  });
});

describe('the public decoder still refuses the trace', () => {
  it('returns null for retrieval.trace, so a widget build cannot render one', () => {
    // THE SECURITY PROPERTY, stated as a test rather than as a comment. `apps/widget` imports
    // `toKbEvent`; if this ever returned an event, a relay bug would put candidate chunk ids, the
    // resolved tenant filter and the pipeline's topology on a stranger's marketing site.
    expect(toKbEvent(frame(RETRIEVAL_TRACE_EVENT, { trace: {} }))).toBeNull();
  });

  it('and the admin decoder still refuses the other three internal frames', () => {
    // `ClientEvents::INTERNAL_ONLY` is four names and only ONE of them is widened. `provider.usage`
    // carries per-attempt token counts, the cache split and the tenant's `connection_id`;
    // `provider.fallback` carries the routing topology and the model ladder. Neither is diagnostics.
    expect(toKbAdminEvent(frame('provider.usage', { ordinal: 1 }))).toBeNull();
    expect(toKbAdminEvent(frame('provider.fallback', { ordinal: 1 }))).toBeNull();
    expect(toKbAdminEvent(frame('heartbeat', {}))).toBeNull();
  });
});

describe('the admin decoder composes the public one rather than forking it', () => {
  it('returns the public six unchanged', () => {
    const token = toKbAdminEvent(frame('token', { text: 'hello' }));
    expect(token).toEqual({ event: 'token', data: { text: 'hello' } });

    const complete = toKbAdminEvent(
      frame('message.complete', { message_id: '01J', finish_reason: 'stop' }),
    );
    expect(complete?.event).toBe('message.complete');
    // The terminal check works across the wider union — `isTerminalEvent` takes a structural
    // parameter for exactly this reason, so there is one predicate rather than two.
    expect(complete !== null && isTerminalEvent(complete)).toBe(true);
  });

  it('returns the trace with its payload', () => {
    const event = toKbAdminEvent(
      frame(RETRIEVAL_TRACE_EVENT, { trace: { insufficient_evidence: false, candidates: [] } }),
    );
    expect(event).not.toBeNull();
    expect(event !== null && isRetrievalTraceEvent(event)).toBe(true);
    if (event !== null && isRetrievalTraceEvent(event)) {
      expect(event.data.trace?.insufficient_evidence).toBe(false);
    }
  });

  it('refuses an unknown name and unparseable JSON, exactly as the public decoder does', () => {
    expect(toKbAdminEvent(frame('retrieval.traces', { trace: {} }))).toBeNull();
    expect(toKbAdminEvent({ event: RETRIEVAL_TRACE_EVENT, data: '{', id: null, retry: null })).toBeNull();
    // A trace whose data is a JSON scalar rather than an object. `null` rather than a cast, because
    // the panel reads members off it.
    expect(toKbAdminEvent(frame(RETRIEVAL_TRACE_EVENT, 42))).toBeNull();
  });
});

describe('it reads a real trace out of the shared frame buffer', () => {
  it('parses a frame split across chunk boundaries, with a heartbeat in between', () => {
    // THE PARSER IS THE SHARED ONE. This is not a second implementation being tested — it is the
    // admin decoder standing on `createFrameBuffer`, which is the property that stops a fork.
    const buffer = createFrameBuffer();
    const chunks = [
      'event: retrieval.tr',
      'ace\ndata: {"trace":{"can',
      'didates":[{"chunk_id":"c1","dense_rank":1}]}}\n',
      '\n: ping\n\n',
    ];

    const events = chunks
      .flatMap((chunk) => [...buffer.push(chunk)])
      .map(toKbAdminEvent)
      .filter((event) => event !== null);

    expect(events).toHaveLength(1);
    const event = events[0];
    expect(event !== undefined && event !== null && isRetrievalTraceEvent(event)).toBe(true);
    if (event !== undefined && event !== null && isRetrievalTraceEvent(event)) {
      expect(event.data.trace?.candidates?.[0]?.chunk_id).toBe('c1');
      // A candidate found by only one branch keeps the OTHER branch's rank absent, and the panel
      // renders that as an em dash rather than as rank 0 — the asymmetry is the most diagnostic
      // field in the table.
      expect(event.data.trace?.candidates?.[0]?.sparse_rank).toBeUndefined();
    }
  });
});
