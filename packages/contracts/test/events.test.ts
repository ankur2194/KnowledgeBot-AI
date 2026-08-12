import { describe, expect, it } from 'vitest';

import { isKbEventName, isTerminalEvent, toKbEvent } from '../src/sse/events.js';
import { parseFrame } from '../src/sse/parse-frame.js';

const frame = (raw: string) => {
  const parsed = parseFrame(raw);
  if (parsed === null) throw new Error('fixture did not parse');
  return parsed;
};

describe('the client event allow-list', () => {
  it('accepts the six forwarded events', () => {
    for (const name of [
      'message.start',
      'status',
      'citations',
      'token',
      'message.complete',
      'error',
    ]) {
      expect(isKbEventName(name)).toBe(true);
    }
  });

  it('refuses the internal-only events Laravel never forwards', () => {
    // Cost data and internal topology stop at Laravel. A client that can name them can render them.
    for (const name of ['provider.usage', 'retrieval.trace', 'provider.fallback', 'heartbeat']) {
      expect(isKbEventName(name)).toBe(false);
      expect(toKbEvent(frame(`event: ${name}\ndata: {"leak":1}`))).toBeNull();
    }
  });
});

describe('toKbEvent', () => {
  it('types a token event', () => {
    const event = toKbEvent(frame('event: token\ndata: {"text":"Refunds are "}'));
    expect(event?.event).toBe('token');
    expect(event?.event === 'token' ? event.data.text : null).toBe('Refunds are ');
  });

  it('returns null for a frame with no event name or with unparseable data', () => {
    expect(toKbEvent(frame('data: {"text":"x"}'))).toBeNull();
    expect(toKbEvent(frame('event: token\ndata: not json'))).toBeNull();
  });

  it('recognises exactly two terminal events', () => {
    const complete = toKbEvent(
      frame(
        'event: message.complete\ndata: {"message_id":"01J","finish_reason":"cancelled","usage":{"prompt_tokens":1,"completion_tokens":0}}',
      ),
    );
    const token = toKbEvent(frame('event: token\ndata: {"text":"x"}'));
    expect(complete && isTerminalEvent(complete)).toBe(true);
    expect(token && isTerminalEvent(token)).toBe(false);
  });
});
