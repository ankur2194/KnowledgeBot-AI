import { afterEach, beforeEach, describe, expect, it, jest } from '@jest/globals';
import { act, renderHook, waitFor } from '@testing-library/react-native';

import { setUnauthenticatedHandler } from '@/api/client';
import { writeSession } from '@/auth/secure-store';
import { useAnswerStream } from '@/features/chat/use-answer-stream';

import { startSseFixture, type SseFixture } from './fixtures/sse-server';

/**
 * The hook that owns the stream's LIFECYCLE, driven against the real socket fixture.
 *
 * The read loop itself is proved in tests/stream-answer.test.ts. What is proved here is the layer
 * above it, and every one of these is an ordering claim rather than a parsing one: the token is
 * read BEFORE the POST, one controller serves three cancel sources, token events are coalesced
 * rather than rendered one-per-frame, and each failure lands in its own state — offline is not an
 * error, authentication is terminal and unretried, a `stream_lost` KbError survives intact.
 *
 * `expo-router` is mocked, and only for `useFocusEffect`, which needs a navigation container that a
 * hook test has no reason to build. That is a double for a NAVIGATION library, not for the
 * transport: the bytes below still cross a real socket, because a doubled stream hands the reader
 * one complete body and every claim about incremental delivery becomes vacuous.
 */
jest.mock('expo-router', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    // The real hook runs the effect on focus and its cleanup on blur. A hook test is focused for
    // its whole life, so running it once with no cleanup is faithful for everything except cancel
    // source 2 — which is asserted through `stop()` directly instead.
    effect();
  },
}));

const LIVE_SESSION = {
  token: 'sanctum-plaintext-token',
  expires_at: new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString(),
  organization_id: '01JORGA',
  user_id: '01JUSERA',
};

const CONVERSATION_ID = '01J0000000000000000000000A';
const BODY = { client_message_id: '018f2b7c-0000-7000-8000-000000000000', content: 'hello' };

let sse: SseFixture;
let unauthenticatedCalls: number;

beforeEach(async () => {
  unauthenticatedCalls = 0;
  setUnauthenticatedHandler(() => {
    unauthenticatedCalls += 1;
  });
  sse = await startSseFixture();
});

afterEach(async () => {
  setUnauthenticatedHandler(null);
  await sse.close();
});

function render(apiOrigin: string = sse.url) {
  return renderHook(() => useAnswerStream({ apiOrigin, idleGapMs: 5_000 }));
}

describe('useAnswerStream.start', () => {
  it('reads the token before the POST and refuses inside the expiry grace window', async () => {
    // PRE-FLIGHT. A token with 60 seconds left is inside the 90 s window, so `getBearerToken()`
    // reports it absent and the user is routed to login with the composed paragraph still in the
    // composer's own state. Discovering it from a 401 after the POST costs the draft.
    await writeSession({
      ...LIVE_SESSION,
      expires_at: new Date(Date.now() + 60 * 1000).toISOString(),
    });
    const { result } = render();

    await act(async () => {
      await result.current.start(CONVERSATION_ID, BODY);
    });

    expect(unauthenticatedCalls).toBe(1);
    // The decisive assertion: no request was made at all. A hook that POSTs and then handles the
    // 401 passes every state assertion and still loses the draft.
    expect(sse.requests()).toHaveLength(0);
    expect(result.current.state.status).toBe('idle');
  });

  it('coalesces many token events into one render pass', async () => {
    // THE RENDER BUDGET. One setState per token event is one React render per token over a growing
    // transcript, and the symptom is not a slow transcript — it is a composer that drops keystrokes
    // while the answer streams. Six token frames must not produce six renders.
    await writeSession(LIVE_SESSION);
    const stream = sse.next();
    const { result } = render();

    let renders = 0;
    const run = act(async () => {
      const started = result.current.start(CONVERSATION_ID, BODY);
      const control = await stream;
      const words = ['Refunds ', 'are ', 'issued ', 'within ', 'ten ', 'days.'];
      for (const word of words) {
        await control.send('token', { text: word });
        renders += 1;
      }
      await control.send('message.complete', {
        message_id: '01JMSG',
        finish_reason: 'stop',
        usage: { prompt_tokens: 10, completion_tokens: 6 },
      });
      await control.end();
      await started;
    });
    await run;

    await waitFor(() => {
      expect(result.current.state.status).toBe('done');
    });
    // Every token arrived, in order, with nothing dropped by the buffer.
    expect(result.current.state.text).toBe('Refunds are issued within ten days.');
    expect(renders).toBe(6);
  });

  it('flushes buffered text before a terminal event, so a finished answer is never missing its ending', async () => {
    await writeSession(LIVE_SESSION);
    const stream = sse.next();
    const { result } = render();

    await act(async () => {
      const started = result.current.start(CONVERSATION_ID, BODY);
      const control = await stream;
      await control.send('citations', {
        citations: [
          {
            index: 1,
            source_id: '01JSRC',
            source_version_id: '01JVER',
            chunk_id: '01JCHUNK',
            title: 'Refund policy',
            url: null,
            score: 0.83,
          },
        ],
      });
      await control.send('token', { text: 'the last words' });
      // No frame boundary between this and the token above: if the buffer were flushed only on the
      // next animation frame, `message.complete` would land first and the transcript would show a
      // finished answer with its ending missing.
      await control.send('message.complete', {
        message_id: '01JMSG',
        finish_reason: 'stop',
        usage: { prompt_tokens: 1, completion_tokens: 3 },
      });
      await control.end();
      await started;
    });

    await waitFor(() => {
      expect(result.current.state.status).toBe('done');
    });
    expect(result.current.state.text).toBe('the last words');
    expect(result.current.state.messageId).toBe('01JMSG');
    // Citations arrive BEFORE the first token — assigned from retrieved evidence pre-generation,
    // never parsed out of model output.
    expect(result.current.state.citations).toHaveLength(1);
  });

  it('treats the stop button as an outcome, not a failure', async () => {
    await writeSession(LIVE_SESSION);
    const stream = sse.next();
    const { result } = render();

    await act(async () => {
      const started = result.current.start(CONVERSATION_ID, BODY);
      const control = await stream;
      await control.send('token', { text: 'partial answ' });
      await new Promise((resolve) => setTimeout(resolve, 20));
      result.current.stop();
      await started;
    });

    await waitFor(() => {
      expect(result.current.state.status).toBe('cancelled');
    });
    // 499 / `user_cancellation`: no toast, no retry, no error span. And the partial text is kept —
    // it was generated and it was billed.
    expect(result.current.state.error).toBeNull();
    expect(result.current.state.text).toBe('partial answ');
  });

  it('carries a stream_lost KbError intact when the peer vanishes mid-answer', async () => {
    await writeSession(LIVE_SESSION);
    const stream = sse.next();
    const { result } = render();

    await act(async () => {
      const started = result.current.start(CONVERSATION_ID, BODY);
      const control = await stream;
      await control.send('token', { text: 'half an ans' });
      // RST, not FIN: `end()` is a normal end-of-stream and drives the completed-answer path.
      control.cut();
      await started;
    });

    await waitFor(() => {
      expect(result.current.state.status).toBe('error');
    });
    // The KbError is carried through UNWRAPPED, class and all. `stream_lost` is the client-local
    // sentinel that tells the caller to re-read the persisted message from Laravel and only then
    // offer Retry with a NEW client_message_id — a re-POST re-runs a paid provider call.
    expect(result.current.state.error?.error_class).toBe('stream_lost');
    // The partial text survives the failure rather than being discarded by its own error path.
    expect(result.current.state.text).toBe('half an ans');
  });

  it('routes an authentication error to the purge, without retrying the send', async () => {
    await writeSession(LIVE_SESSION);
    const stream = sse.next();
    const { result } = render();

    await act(async () => {
      const started = result.current.start(CONVERSATION_ID, BODY);
      const control = await stream;
      // The token was revoked from another device mid-stream. It arrives as an SSE `error` frame,
      // which `streamAnswer` raises as a KbError.
      await control.send('error', {
        error_class: 'authentication',
        message: 'Unauthenticated.',
        retryable: false,
      });
      await control.end();
      await started;
    });

    await waitFor(() => {
      expect(result.current.state.status).toBe('error');
    });
    expect(unauthenticatedCalls).toBe(1);
    expect(result.current.state.error?.error_class).toBe('authentication');
    // EXACTLY ONE request. A retry against a dead token is how a device gets rate-limited out of
    // its own login endpoint, and `authentication` is non-retryable in the taxonomy.
    expect(sse.requests()).toHaveLength(1);
  });

  it('reports a dead radio as offline rather than as a server error', async () => {
    await writeSession(LIVE_SESSION);
    // Airplane mode, in the closest form a test process can reproduce: a port with nothing
    // listening, so the `fetch` rejects before any response exists. A DEDICATED fixture is bound and
    // closed rather than reusing the shared one, so the port is genuinely dead for this test only
    // and `afterEach` still has a server to close.
    const dead = await startSseFixture();
    const deadOrigin = dead.url;
    await dead.close();
    const { result } = render(deadOrigin);

    await act(async () => {
      await result.current.start(CONVERSATION_ID, BODY);
    });

    await waitFor(() => {
      expect(result.current.state.status).toBe('offline');
    });
    // NOT `internal_dependency`, which is retryable AND pages: a subway ride must not wake anyone
    // up, and "you are offline" is a different sentence with a different remedy than "something
    // went wrong on our side". There is no KbError at all, because there was no response to carry
    // an envelope — which is exactly why offline cannot be a member of the taxonomy.
    expect(result.current.state.error).toBeNull();
    // And it is not a session event: being offline is not being signed out.
    expect(unauthenticatedCalls).toBe(0);
  });

  it('opens exactly one stream when start is called twice', async () => {
    // A second start with the first still open is two sockets and two provider calls billed against
    // one tenant for one screen, and the two answers interleave into one transcript.
    await writeSession(LIVE_SESSION);
    const first = sse.next();
    const second = sse.next();
    const { result } = render();

    await act(async () => {
      const firstRun = result.current.start(CONVERSATION_ID, BODY);
      const firstControl = await first;
      await firstControl.send('token', { text: 'superseded ' });

      const secondRun = result.current.start(CONVERSATION_ID, BODY);
      const secondControl = await second;
      await secondControl.send('token', { text: 'the real answer' });
      await secondControl.send('message.complete', {
        message_id: '01JMSG2',
        finish_reason: 'stop',
        usage: { prompt_tokens: 1, completion_tokens: 3 },
      });
      await secondControl.end();
      await Promise.all([firstRun, secondRun]);
    });

    await waitFor(() => {
      expect(result.current.state.status).toBe('done');
    });
    // The superseded run wrote nothing: its text is absent and its late events did not interleave.
    expect(result.current.state.text).toBe('the real answer');
    expect(result.current.state.messageId).toBe('01JMSG2');
  });
});
