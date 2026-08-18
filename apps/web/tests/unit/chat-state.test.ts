import { KbError, type KbEvent } from '@kb/contracts';
import { describe, expect, it } from 'vitest';

import { announcementFor, applyEvent, startAssistantTurn } from '../../src/features/chat/chat-state';

const run = (events: readonly KbEvent[]) =>
  events.reduce((turn, event) => applyEvent(turn, event), startAssistantTurn('m1'));

const start: KbEvent = {
  event: 'message.start',
  data: { message_id: 's1', conversation_id: 'c1', created_at: '2026-08-18T00:00:00Z' },
};
const token = (text: string): KbEvent => ({ event: 'token', data: { text } });
const complete = (finish_reason: 'stop' | 'cancelled' | 'insufficient_evidence' | 'error'): KbEvent => ({
  event: 'message.complete',
  data: { message_id: 's1', finish_reason, usage: { prompt_tokens: 1, completion_tokens: 1 } },
});

describe('the four visible states', () => {
  it('walks sent -> working -> streaming -> settled and no further', () => {
    expect(startAssistantTurn('m1').phase).toBe('sent');
    expect(run([start]).phase).toBe('working');
    expect(run([start, { event: 'status', data: { stage: 'retrieving' } }]).phase).toBe('working');
    expect(run([start, token('Hi')]).phase).toBe('streaming');
    expect(run([start, token('Hi'), complete('stop')]).phase).toBe('settled');
  });

  it('never lets an internal stage name reach a rendered string', () => {
    // `kb-rag-query-contract` has twenty stages; a user needs four. The wire says
    // retrieving/reranking/generating and none of those words may appear on screen.
    for (const stage of ['retrieving', 'reranking', 'generating'] as const) {
      const turn = run([start, { event: 'status', data: { stage } }]);
      expect(turn.label.toLowerCase()).not.toContain(stage);
      expect(turn.label.toLowerCase()).not.toContain('rerank');
      expect(turn.label.toLowerCase()).not.toContain('retriev');
    }
  });

  it('collapses retrieval and reranking into one label and changes it once, at generation', () => {
    const retrieving = run([start, { event: 'status', data: { stage: 'retrieving' } }]);
    const reranking = run([start, { event: 'status', data: { stage: 'reranking' } }]);
    const generating = run([start, { event: 'status', data: { stage: 'generating' } }]);
    expect(reranking.label).toBe(retrieving.label);
    expect(generating.label).not.toBe(retrieving.label);
  });
});

describe('the four settled outcomes', () => {
  it('maps insufficient_evidence to a REFUSAL, which is a success and not a failure', () => {
    const turn = run([start, token('…'), complete('insufficient_evidence')]);
    expect(turn.outcome).toBe('refused');
    expect(turn.error).toBeNull();
  });

  it('keeps the partial answer when a stream is stopped', () => {
    // Discarding what already streamed throws away work the tenant was already billed for.
    const turn = run([start, token('Half an ans'), complete('cancelled')]);
    expect(turn.outcome).toBe('stopped');
    expect(turn.text).toBe('Half an ans');
  });

  it('maps stop and length to answered', () => {
    expect(run([start, token('a'), complete('stop')]).outcome).toBe('answered');
  });

  it('carries error_class, request_id and retryable off the envelope, never the message', () => {
    const turn = run([
      start,
      {
        event: 'error',
        data: {
          error_class: 'provider_temporary',
          message: 'upstream api-7.internal refused',
          retryable: true,
          request_id: 'req_abc',
        },
      },
    ]);
    expect(turn.outcome).toBe('failed');
    expect(turn.error).toBeInstanceOf(KbError);
    expect(turn.error?.error_class).toBe('provider_temporary');
    expect(turn.error?.request_id).toBe('req_abc');
    // The AUTHORITY on retry is the envelope's flag, not a predicate over the class name.
    expect(turn.error?.retryable).toBe(true);
  });

  it('tolerates an error frame with no request_id, which the contract says is the SSE shape', () => {
    const turn = run([
      start,
      { event: 'error', data: { error_class: 'retrieval', message: 'x', retryable: false } },
    ]);
    expect(turn.error?.request_id).toBeNull();
    expect(turn.error?.retryable).toBe(false);
  });
});

describe('citations', () => {
  it('takes them from the citations event, which arrives BEFORE the first token', () => {
    const citation = {
      index: 1,
      source_id: 's',
      source_version_id: 'v',
      chunk_id: 'c',
      title: 'Handbook.pdf',
      url: null,
      score: 0.9,
    };
    const turn = run([start, { event: 'citations', data: { citations: [citation] } }, token('[1]')]);
    expect(turn.citations).toEqual([citation]);
  });

  it('does not derive citations from anything in the answer text', () => {
    // The UI never parses `[1]` out of model output and looks up what it might mean: a citation the
    // model invented is a fabricated source attribution shown to a customer's customer.
    const turn = run([start, token('As shown in [1] and [2].'), complete('stop')]);
    expect(turn.citations).toEqual([]);
  });
});

describe('what the live region announces', () => {
  it('announces state transitions rather than the token stream', () => {
    expect(announcementFor(run([start]))).toBe('Searching your sources…');
    expect(announcementFor(run([start, token('a')]))).toBe('Answer started.');
    // The tokens themselves are never in the announcement — a live region updated per token makes a
    // screen reader stutter unusably.
    expect(announcementFor(run([start, token('secret text')]))).not.toContain('secret text');
  });

  it('says how many sources settled with the answer', () => {
    const one = run([
      start,
      {
        event: 'citations',
        data: {
          citations: [
            { index: 1, source_id: 's', source_version_id: 'v', chunk_id: 'c', title: 't', url: null, score: 1 },
          ],
        },
      },
      token('a'),
      complete('stop'),
    ]);
    expect(announcementFor(one)).toBe('Answer ready, 1 source.');
  });

  it('announces a refusal as an outcome, not as an error', () => {
    expect(announcementFor(run([start, complete('insufficient_evidence')]))).toContain('sources');
  });
});
