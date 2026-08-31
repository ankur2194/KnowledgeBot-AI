import type { KbEvent } from '@kb/contracts';
import { KbError } from '@kb/contracts';
import { isRetrievalTraceEvent, toKbAdminEvent, type KbAdminEvent } from '@kb/contracts/admin';
import { HttpResponse, http } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  createPlaygroundConnection,
  mintPlaygroundSession,
  playgroundSessionPath,
} from '@/features/bots/playground-session';
import {
  isReMintable,
  streamAnswer,
  streamWithReMint,
  type OpenedConversation,
} from '@/features/chat/stream-answer';
import type { Credential } from '@/lib/api/browser';

import { startSseFixture, type SseFixture } from '../fixtures/sse-server';

/**
 * THE PLAYGROUND'S TWO WIRE-LEVEL DIFFERENCES, OVER A REAL SOCKET WHERE IT MATTERS.
 *
 * `stream-answer.test.ts` proves the read loop. This file proves what D5 adds to it:
 *
 *   1. THE DECODER SEAM — that it widens the union by exactly one name in one direction and by
 *      nothing in the other.
 *   2. THE CREDENTIAL — mint on the ADMIN route with the cookie session, then talk `rt/v1` as a
 *      BEARER, and replace that bearer once when the server refuses it.
 *
 * ── THE STREAMING HALF RUNS AGAINST THE FIXTURE SERVER AND NOT AGAINST A MOCK ───────────────────
 * A mocked response hands the reader one complete body, so every chunk is a whole frame and every
 * assertion about the read loop would be vacuous — the suite would go green over a decoder that only
 * works when a frame arrives intact. The trace frame is also the LARGEST frame on this wire (a
 * candidate table and an exclusion list), which makes it the one most likely to straddle a TCP
 * boundary in production and never in a fixture, so the split test below is the point of the file
 * rather than a flourish.
 *
 * ── AND THE CREDENTIAL HALF USES MSW, WHICH IS LEGITIMATE FOR EXACTLY THESE TWO DOCUMENTS ───────
 * The mint response and the pre-stream 401 are single buffered JSON documents — that is what the
 * server really sends for both, since a failure before the first byte IS the ordinary error envelope
 * with a real status. There is no chunk boundary in either, so there is nothing a mock can flatten.
 * The moment bytes have to arrive over time the fixture server takes over, in the same test.
 *
 * `environment: 'node'` (the `unit` project): jsdom drops `ReadableStream`, `TextDecoder` and
 * `Response`, which is the entire surface under test.
 */

const CONVERSATION = '01JCONV0000000000000000000';
const ADMIN: Credential = { kind: 'session', xsrf_token: 'decoded==token' };
const BODY = { client_message_id: '01JMSGCLIENT0000000000000', content: 'Where is my refund?' };

/** `vitest.config.ts`'s `NEXT_PUBLIC_API_ORIGIN`. `.invalid` is RFC 2606 and never resolves, so a
 *  request MSW fails to intercept dies on DNS instead of reaching a host that exists. */
const ORIGIN = 'http://api.invalid';
const ORG = '01JORG00000000000000000000';
const BOT = '01JBOT00000000000000000000';
const MINT_PATH = playgroundSessionPath(ORG, BOT);

/** `kbw_{org}.{bot}.{32 CSPRNG bytes}` in shape. The suffix is what every assertion below greps
 *  for, so it must not be a substring of anything else in a URL or a log line. */
const FIRST_TOKEN = 'kbw_01JORG.01JBOT.firstbearer0000000000000000';
const SECOND_TOKEN = 'kbw_01JORG.01JBOT.secondbearer000000000000000';

let fixture: SseFixture | undefined;

const server = setupServer();

beforeAll(() => {
  server.listen({
    // A FUNCTION RATHER THAN `'bypass'`, so the two harnesses stay distinguishable. The SSE fixture
    // is a real socket on 127.0.0.1 and MSW must let it through untouched — buffering it would undo
    // the entire reason it exists. Everything else that reaches the network unhandled is a spec that
    // thinks it is asserting something and is actually watching a DNS failure.
    onUnhandledRequest(request, print) {
      if (new URL(request.url).hostname === '127.0.0.1') return;
      print.error();
    },
  });
});

afterAll(() => {
  server.close();
});

afterEach(async () => {
  await fixture?.close();
  fixture = undefined;
  server.resetHandlers();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const drain = async <TEvent>(iterator: AsyncGenerator<TEvent>): Promise<TEvent[]> => {
  const events: TEvent[] = [];
  for await (const event of iterator) events.push(event);
  return events;
};

const TRACE = {
  trace: {
    original_query: 'where is my refund',
    rewritten_query: null,
    branches_queried: ['dense', 'sparse'],
    rerank_skip_reason: 'provider_cannot_rerank',
    candidates: [
      {
        chunk_id: 'c1',
        source_id: 's1',
        dense_rank: 1,
        dense_score: 0.81,
        sparse_rank: null,
        sparse_score: null,
        fused_score: 0.5,
        rerank_score: null,
      },
    ],
    exclusions: [
      { chunk_id: 'c2', reason: 'no_branch_agreement', score: null, stage: 'evidence_threshold' },
    ],
    packed_order: [['c1', '1']],
    insufficient_evidence: false,
  },
};

describe('the admin decoder over a real socket', () => {
  it('yields the trace beside the public six, in wire order', async () => {
    fixture = await startSseFixture();

    const run = drain(
      streamAnswer<KbAdminEvent>({
        conversationId: CONVERSATION,
        body: BODY,
        credential: ADMIN,
        signal: new AbortController().signal,
        apiOrigin: fixture.url,
        decode: toKbAdminEvent,
      }),
    );

    const control = await fixture.next();
    await control.send('message.start', {
      message_id: '01JMSG',
      conversation_id: CONVERSATION,
      created_at: '2026-08-27T10:00:00+00:00',
    });
    // THE ORDER MATTERS AND IS THE PIPELINE'S: the trace explains a turn, so it arrives with the
    // evidence rather than after the answer. Nothing here reorders it.
    await control.send('retrieval.trace', TRACE);
    await control.send('citations', { citations: [] });
    await control.send('token', { text: 'Refunds ' });
    await control.send('message.complete', {
      message_id: '01JMSG',
      finish_reason: 'stop',
      usage: { prompt_tokens: 10, completion_tokens: 2 },
    });
    await control.end();

    const events = await run;
    expect(events.map((event) => event.event)).toEqual([
      'message.start',
      'retrieval.trace',
      'citations',
      'token',
      'message.complete',
    ]);

    const trace = events.find(isRetrievalTraceEvent);
    expect(trace?.data.trace?.candidates?.[0]?.chunk_id).toBe('c1');
    // A candidate found by ONE branch keeps the other's rank null — the asymmetry `CandidateRow`
    // calls the most diagnostic field in the panel, and the one a decoder must not fill in.
    expect(trace?.data.trace?.candidates?.[0]?.sparse_rank).toBeNull();
    // The degraded path: reranking did not run, so there is no rerank score and the panel leads with
    // the reason rather than leaving a column of em dashes to be interpreted.
    expect(trace?.data.trace?.rerank_skip_reason).toBe('provider_cannot_rerank');
    expect(trace?.data.trace?.exclusions?.[0]?.reason).toBe('no_branch_agreement');
  });

  it('reassembles a trace split across chunk boundaries and mid-frame', async () => {
    fixture = await startSseFixture();

    const run = drain(
      streamAnswer<KbAdminEvent>({
        conversationId: CONVERSATION,
        body: BODY,
        credential: ADMIN,
        signal: new AbortController().signal,
        apiOrigin: fixture.url,
        decode: toKbAdminEvent,
      }),
    );

    const control = await fixture.next();
    const payload = JSON.stringify(TRACE);
    // Split INSIDE the JSON body, then between the two terminating newlines — the boundary that
    // arrives often enough to matter in production and never in a hand-written fixture.
    await control.raw(`event: retrieval.trace\ndata: ${payload.slice(0, 40)}`);
    await control.raw(`${payload.slice(40)}\n`);
    await control.raw('\n');
    // A heartbeat between frames. It must never surface as an event, on this decoder as on the
    // public one — the parser answers a comment with no frame at all.
    await control.ping();
    await control.send('message.complete', { message_id: '01JMSG', finish_reason: 'stop' });
    await control.end();

    const events = await run;
    expect(events.map((event) => event.event)).toEqual(['retrieval.trace', 'message.complete']);
    const trace = events.find(isRetrievalTraceEvent);
    expect(trace?.data.trace?.packed_order?.[0]?.[1]).toBe('1');
  });

  it('DROPS the trace when the caller did not ask for the wider union', async () => {
    /**
     * THE SECURITY HALF, AND IT IS THE REASON THE SEAM IS AN ARGUMENT RATHER THAN A FLAG.
     *
     * Hosted chat, the widget and mobile all call `streamAnswer` with no `decode`, so it defaults to
     * `toKbEvent` — and a `retrieval.trace` frame is then not merely unrendered, it is
     * unrepresentable: the generator's yield type is the public six, so there is no branch a caller
     * could add. This asserts the runtime half of that.
     *
     * A relay bug is the case it is written against. `ClientEvents::allows()` is fail-closed and
     * AND-ed with the actor type, so the frame should never reach a public client — but "should
     * never" is what a defence in depth is for, and this is the depth.
     */
    fixture = await startSseFixture();

    const run = drain(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: ADMIN,
        signal: new AbortController().signal,
        apiOrigin: fixture.url,
      }) as AsyncGenerator<KbEvent>,
    );

    const control = await fixture.next();
    await control.send('retrieval.trace', TRACE);
    await control.send('token', { text: 'hi' });
    await control.send('message.complete', { message_id: '01JMSG', finish_reason: 'stop' });
    await control.end();

    const events = await run;
    expect(events.map((event) => event.event)).toEqual(['token', 'message.complete']);
  });

  it('refuses the other three internal frames even WITH the admin decoder', async () => {
    // `ClientEvents::INTERNAL_ONLY` is four names and only ONE is widened. `provider.usage` carries
    // per-attempt token counts, the cache split and the tenant's `connection_id`;
    // `provider.fallback` carries the routing topology and the model ladder. Neither is diagnostics,
    // and the playground has no business rendering either.
    fixture = await startSseFixture();

    const run = drain(
      streamAnswer<KbAdminEvent>({
        conversationId: CONVERSATION,
        body: BODY,
        credential: ADMIN,
        signal: new AbortController().signal,
        apiOrigin: fixture.url,
        decode: toKbAdminEvent,
      }),
    );

    const control = await fixture.next();
    await control.send('provider.usage', { ordinal: 1, connection_id: 'conn_secret' });
    await control.send('provider.fallback', { ordinal: 2, to_connection_id: 'conn_other' });
    await control.send('message.complete', { message_id: '01JMSG', finish_reason: 'stop' });
    await control.end();

    const events = await run;
    expect(events.map((event) => event.event)).toEqual(['message.complete']);
    // And nothing from those frames survived into the yielded values.
    expect(JSON.stringify(events)).not.toContain('conn_secret');
    expect(JSON.stringify(events)).not.toContain('conn_other');
  });
});

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE CREDENTIAL: MINT ON THE ADMIN ROUTE, TALK `rt/v1` AS A BEARER, REPLACE IT ONCE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The panel used to hand the admin cookie session straight to `openRuntimeConversation`, and
 * `ResolveChatSession::handle()` is `$this->sessions->resolve($request->bearerToken())` and nothing
 * else — so `bearerToken()` was null and every send answered 401. That middleware is UNCHANGED. What
 * these specs pin is the client side of the route that fixes it without touching it.
 */

interface Seen {
  readonly method: string;
  readonly url: string;
  readonly headers: Headers;
  readonly body: string;
}

let seen: Seen[] = [];

async function record(request: Request): Promise<void> {
  seen.push({
    method: request.method,
    url: request.url,
    headers: request.headers,
    body: await request.clone().text(),
  });
}

beforeEach(() => {
  seen = [];
});

/** The admin cookie a browser would have. Node has no `document` at all in this project, and
 *  `sessionCredential()` would otherwise fetch `/sanctum/csrf-cookie` and then throw. */
function withAdminCookie(): void {
  vi.stubGlobal('document', { cookie: 'kb_session=abc; XSRF-TOKEN=decoded==token' });
}

/**
 * `POST .../bots/{bot}/playground-session` -> 201 `{data: {token, expires_in}}`.
 *
 * THE FIXTURE IS WRAPPED, AND THAT IS THE POINT OF THE FILE'S SECOND HALF. `ChatSessionResource` is
 * published inside the envelope every success body on this API carries; a spec written against an
 * UNWRAPPED fixture passes while the panel sends `Bearer undefined`, which is precisely how the same
 * defect survived a green e2e suite in the widget loader.
 */
function mintHandler(...tokens: readonly string[]) {
  let issued = 0;
  return http.post(`${ORIGIN}${MINT_PATH}`, async ({ request }) => {
    await record(request);
    const token = tokens[Math.min(issued, tokens.length - 1)] ?? tokens[0];
    issued += 1;
    return HttpResponse.json(
      { data: { token, expires_in: 900 } },
      // `no-store`, as the controller sets it: a live credential in an intermediary's cache is a
      // live credential for whoever shares that cache.
      { status: 201, headers: { 'cache-control': 'no-store' } },
    );
  });
}

/** `POST /rt/v1/conversations` -> 201. Only `id` is read, but the shape is the resource's. */
function conversationHandler() {
  return http.post(`${ORIGIN}/rt/v1/conversations`, async ({ request }) => {
    await record(request);
    return HttpResponse.json(
      {
        data: {
          id: CONVERSATION,
          status: 'active',
          locale: null,
          consent_required: false,
          consent_text: null,
          consent_granted_at: null,
          started_at: '2026-08-27T10:00:00+00:00',
          last_activity_at: '2026-08-27T10:00:00+00:00',
        },
      },
      { status: 201 },
    );
  });
}

/**
 * The pre-stream refusal, and it is a BUFFERED JSON DOCUMENT because that is what the server sends.
 * A failure before the first byte is the ordinary error envelope with a real status; only after the
 * SSE headers are on the wire is the status spent and an `error` FRAME the only way to refuse.
 */
function refusedStreamHandler() {
  return http.post(`${ORIGIN}/rt/v1/conversations/:conversation/messages`, async ({ request }) => {
    await record(request);
    return HttpResponse.json(
      {
        error_class: 'authentication',
        message: 'Unauthenticated.',
        retryable: false,
        request_id: 'req_expired_bearer',
      },
      { status: 401 },
    );
  });
}

describe('the playground credential', () => {
  it('reads the token out of `data`, and mints with the cookie session rather than a bearer', async () => {
    withAdminCookie();
    server.use(mintHandler(FIRST_TOKEN));

    const session = await mintPlaygroundSession(ORG, BOT);

    expect(session.token).toBe(FIRST_TOKEN);
    expect(session.expires_in).toBe(900);
    // THE ENVELOPE, ASSERTED FROM THE OTHER SIDE. `browserFetchData` unwrapped it, so nothing
    // resembling the wrapper survives onto the resource — a reader that took `token` off the top
    // level of the response would have got `undefined` from this same fixture.
    expect((session as unknown as Record<string, unknown>)['data']).toBeUndefined();

    expect(seen).toHaveLength(1);
    expect(seen[0]?.method).toBe('POST');
    expect(seen[0]?.url).toBe(`${ORIGIN}${MINT_PATH}`);
    // `api/v1` is the ADMIN group. `RejectBearerToken` refuses a bearer there by design, so the
    // mint is authorized by the cookie plus the CSRF header and by nothing else.
    expect(seen[0]?.headers.get('x-xsrf-token')).toBe('decoded==token');
    expect(seen[0]?.headers.get('authorization')).toBeNull();
    // It carries no body at all: the organization and the bot are path segments the route binds,
    // and there is nothing a caller may say about a credential it is asking for.
    expect(seen[0]?.body).toBe('');
  });

  it('mints, opens the thread as a BEARER, and streams with that same bearer', async () => {
    withAdminCookie();
    server.use(mintHandler(FIRST_TOKEN), conversationHandler());
    fixture = await startSseFixture();

    const connection = createPlaygroundConnection(ORG, BOT);
    const opened = await connection.connect();

    expect(opened.conversationId).toBe(CONVERSATION);
    expect(opened.credential).toEqual({ kind: 'chat_session', token: FIRST_TOKEN });

    // THE CONVERSATION IS OPENED WITH THE BEARER AND NOT WITH THE COOKIE — the whole defect, in one
    // assertion. `Bearer undefined` is what the envelope mistake produces, so it is refused by name.
    const conversation = seen.find((entry) => entry.url === `${ORIGIN}/rt/v1/conversations`);
    expect(conversation?.headers.get('authorization')).toBe(`Bearer ${FIRST_TOKEN}`);
    expect(conversation?.headers.get('authorization')).not.toContain('undefined');
    expect(conversation?.headers.get('x-xsrf-token')).toBeNull();

    // AND THE STREAM CARRIES IT TOO, over a real socket rather than a mock.
    const run = drain(
      streamAnswer<KbAdminEvent>({
        conversationId: opened.conversationId,
        body: BODY,
        credential: opened.credential,
        signal: new AbortController().signal,
        apiOrigin: fixture.url,
        decode: toKbAdminEvent,
      }),
    );

    const control = await fixture.next();
    await control.send('retrieval.trace', TRACE);
    await control.send('message.complete', { message_id: '01JMSG', finish_reason: 'stop' });
    await control.end();

    const events = await run;
    expect(events.map((event) => event.event)).toEqual(['retrieval.trace', 'message.complete']);

    const streamed = fixture.requests().at(-1);
    expect(streamed?.headers['authorization']).toBe(`Bearer ${FIRST_TOKEN}`);
    expect(streamed?.headers['x-xsrf-token']).toBeUndefined();

    // ONE MINT AND ONE CONVERSATION, and `connect()` is idempotent across calls — `ChatSurface`
    // calls it once per send and a second thread per send would bill one operator's test run to two
    // rows in the tenant's own analytics.
    await connection.connect();
    await connection.connect();
    expect(seen.filter((entry) => entry.url === `${ORIGIN}${MINT_PATH}`)).toHaveLength(1);
    expect(seen.filter((entry) => entry.url === `${ORIGIN}/rt/v1/conversations`)).toHaveLength(1);
  });

  it('re-mints on 401 + authentication and re-sends the SAME message on the new bearer', async () => {
    /**
     * THE IDLE-TAB CASE, AND IT IS THE ONE AN OPERATOR ACTUALLY HITS. `expires_in` is 900 s and it
     * SLIDES on every authorized request, so it is the floor for an idle tab rather than a promise
     * about an active conversation — nothing schedules a re-mint against it, and the trigger is the
     * server saying so. Without this the operator is shown "Your session has ended. Sign in again to
     * continue.", which is false about the console session they are holding.
     *
     * ATTEMPT 1 IS THE MSW ORIGIN AND ATTEMPT 2 IS THE FIXTURE, because the two halves of this
     * sequence belong in different harnesses: the 401 is a buffered envelope the server really does
     * send before the first byte, and the answer is bytes arriving over time.
     */
    withAdminCookie();
    server.use(mintHandler(FIRST_TOKEN, SECOND_TOKEN), conversationHandler(), refusedStreamHandler());

    const bodies: string[] = [];
    fixture = await startSseFixture((request) => {
      const chunks: Buffer[] = [];
      request.on('data', (chunk: Buffer) => chunks.push(chunk));
      request.on('end', () => bodies.push(Buffer.concat(chunks).toString('utf8')));
    });

    const connection = createPlaygroundConnection(ORG, BOT);
    const bearers: string[] = [];
    // ATTEMPT 1 IS THE MSW ORIGIN AND ATTEMPT 2 IS THE FIXTURE. Two harnesses in one sequence,
    // each holding the half it can actually prove.
    const streamOrigin = fixture.url;
    let attempt = 0;

    const run = drain(
      streamWithReMint<KbAdminEvent>({
        open: connection.connect,
        reMint: connection.reMint,
        run: (opened: OpenedConversation) => {
          const apiOrigin = attempt === 0 ? ORIGIN : streamOrigin;
          attempt += 1;
          bearers.push(
            opened.credential.kind === 'chat_session' ? opened.credential.token : 'NOT-A-BEARER',
          );
          return streamAnswer<KbAdminEvent>({
            conversationId: opened.conversationId,
            body: BODY,
            credential: opened.credential,
            signal: new AbortController().signal,
            apiOrigin,
            decode: toKbAdminEvent,
          });
        },
      }),
    );

    const control = await fixture.next();
    await control.send('retrieval.trace', TRACE);
    await control.send('token', { text: 'Refunds ' });
    await control.send('message.complete', { message_id: '01JMSG', finish_reason: 'stop' });
    await control.end();

    const events = await run;
    // THE ANSWER ARRIVES. The 401 never reaches the caller at all.
    expect(events.map((event) => event.event)).toEqual([
      'retrieval.trace',
      'token',
      'message.complete',
    ]);

    // TWO BEARERS, AND THE SECOND IS THE ONE THAT WAS RE-MINTED.
    expect(bearers).toEqual([FIRST_TOKEN, SECOND_TOKEN]);
    expect(seen.filter((entry) => entry.url === `${ORIGIN}${MINT_PATH}`)).toHaveLength(2);

    // ONE CONVERSATION ACROSS BOTH ATTEMPTS. A playground thread is owned by `conversations.user_id`
    // and `ChatGate` looks it up by that column, so a fresh mint for the same administrator still
    // owns it — opening a second would split one test run across two rows.
    expect(seen.filter((entry) => entry.url === `${ORIGIN}/rt/v1/conversations`)).toHaveLength(1);

    // AND THE SAME `client_message_id` ON BOTH POSTS, which is what makes the second attempt an
    // idempotent replay rather than a second provider call. Laravel collapses a genuine duplicate on
    // exactly this key; a fresh one would be "answer that again", which is a different request.
    const refused = seen.find((entry) => entry.url.endsWith('/messages'));
    expect(JSON.parse(refused?.body ?? '{}')).toEqual(BODY);
    expect(JSON.parse(bodies[0] ?? '{}')).toEqual(BODY);
    expect(fixture.requests().at(-1)?.headers['authorization']).toBe(`Bearer ${SECOND_TOKEN}`);
  });

  it('re-mints for `authentication` and for NOTHING else', () => {
    /**
     * THE PREDICATE READS `error_class` AND NEVER A STATUS. One class renders several statuses per
     * surface, and `KbError` deliberately carries no status at all.
     *
     * `authorization` is the one that must not fire: it is the bot leaving `testing`/`published` or
     * the administrator losing `bots.manage`, both of which the mint route ALSO enforces — so a
     * re-mint would spend a request to reach the same answer with a worse message. `null` is
     * unknown, and unknown is permanently non-retryable.
     */
    expect(isReMintable(new KbError('authentication', false, null, 'req_1', 'Unauthenticated.'))).toBe(true);
    expect(isReMintable(new KbError('authorization', false, null, 'req_2', 'Not found.'))).toBe(false);
    expect(isReMintable(new KbError('rate_limit', true, 30, 'req_3', 'Slow down.'))).toBe(false);
    expect(isReMintable(new KbError(null, false, null, null, 'HTTP 502'))).toBe(false);
    // Not our error class at all — a raw TypeError from a dead socket, which `instanceof` refuses.
    expect(isReMintable(new TypeError('terminated'))).toBe(false);
  });

  it('does not re-mint after the first event, and never twice', async () => {
    /**
     * A failure AFTER `message.start` means a turn exists server-side, so a second post with the
     * same id would replay persisted state and hand the surface a duplicate of an answer it already
     * rendered. The 401 this mechanism exists for is raised before routing, so it always lands with
     * nothing yielded — this pins that the guard is the yield and not the class.
     */
    let calls = 0;
    let reMints = 0;

    const failing = async function* (): AsyncGenerator<KbAdminEvent> {
      calls += 1;
      yield { event: 'message.start', data: { message_id: '01JMSG' } } as KbAdminEvent;
      throw new KbError('authentication', false, null, null, 'Unauthenticated.');
    };

    const opened: OpenedConversation = {
      conversationId: CONVERSATION,
      credential: { kind: 'chat_session', token: FIRST_TOKEN },
    };

    await expect(
      drain(
        streamWithReMint<KbAdminEvent>({
          open: () => Promise.resolve(opened),
          reMint: () => {
            reMints += 1;
            return Promise.resolve(opened);
          },
          run: failing,
        }),
      ),
    ).rejects.toBeInstanceOf(KbError);

    expect(calls).toBe(1);
    expect(reMints).toBe(0);
  });

  it('gives up after ONE replacement rather than looping over a paid endpoint', async () => {
    let calls = 0;
    let reMints = 0;

    const failing = async function* (): AsyncGenerator<KbAdminEvent> {
      calls += 1;
      throw new KbError('authentication', false, null, null, 'Unauthenticated.');
    };

    await expect(
      drain(
        streamWithReMint<KbAdminEvent>({
          open: () =>
            Promise.resolve({
              conversationId: CONVERSATION,
              credential: { kind: 'chat_session', token: FIRST_TOKEN },
            }),
          reMint: () => {
            reMints += 1;
            return Promise.resolve({
              conversationId: CONVERSATION,
              credential: { kind: 'chat_session', token: SECOND_TOKEN },
            });
          },
          run: failing,
        }),
      ),
    ).rejects.toBeInstanceOf(KbError);

    // Two attempts, one replacement. A server answering `authentication` to a freshly minted bearer
    // is saying something a loop cannot fix, and a loop over a paid endpoint gets expensive while
    // looking like resilience.
    expect(calls).toBe(2);
    expect(reMints).toBe(1);
  });

  it('does not attempt a second send at all when the surface supplied no reMint', async () => {
    // Hosted chat, the widget and mobile pass none, so their behaviour is byte-identical to calling
    // `streamAnswer` directly. This is the assertion that keeps that true.
    let calls = 0;
    const failing = async function* (): AsyncGenerator<KbAdminEvent> {
      calls += 1;
      throw new KbError('authentication', false, null, null, 'Unauthenticated.');
    };

    await expect(
      drain(
        streamWithReMint<KbAdminEvent>({
          open: () =>
            Promise.resolve({
              conversationId: CONVERSATION,
              credential: { kind: 'chat_session', token: FIRST_TOKEN },
            }),
          run: failing,
        }),
      ),
    ).rejects.toBeInstanceOf(KbError);

    expect(calls).toBe(1);
  });

  it('puts the bearer in no URL, no log and no storage', async () => {
    /**
     * THE THREE PLACES A LIVE CREDENTIAL MUST NEVER LAND, asserted rather than reviewed.
     *
     * A URL: it would reach Traefik access logs, `Referer` on every navigation away, and browser
     * history (`laravel-sanctum-auth` non-negotiable 4). A log: the same, on our side. Storage:
     * closing a laptop would leave a bearer on the disk, and the whole design is that a reload mints
     * a new one.
     */
    withAdminCookie();
    server.use(mintHandler(FIRST_TOKEN), conversationHandler());
    fixture = await startSseFixture();

    const stores: string[] = [];
    const store = (name: string) => ({
      getItem: () => {
        stores.push(`${name}.getItem`);
        return null;
      },
      setItem: (key: string, value: string) => stores.push(`${name}.setItem ${key} ${value}`),
      removeItem: (key: string) => stores.push(`${name}.removeItem ${key}`),
      clear: () => stores.push(`${name}.clear`),
    });
    vi.stubGlobal('localStorage', store('localStorage'));
    vi.stubGlobal('sessionStorage', store('sessionStorage'));

    const logged: string[] = [];
    for (const level of ['log', 'info', 'warn', 'error', 'debug'] as const) {
      vi.spyOn(console, level).mockImplementation((...args: unknown[]) => {
        logged.push(args.map((arg) => String(arg)).join(' '));
      });
    }

    const connection = createPlaygroundConnection(ORG, BOT);
    const opened = await connection.connect();

    const events = drain(
      streamAnswer<KbAdminEvent>({
        conversationId: opened.conversationId,
        body: BODY,
        credential: opened.credential,
        signal: new AbortController().signal,
        apiOrigin: fixture.url,
        decode: toKbAdminEvent,
      }),
    );
    const control = await fixture.next();
    await control.send('message.complete', { message_id: '01JMSG', finish_reason: 'stop' });
    await control.end();
    await events;

    // NO URL — neither the absolute ones MSW saw nor the paths the fixture server saw.
    const urls = [
      ...seen.map((entry) => entry.url),
      ...fixture.requests().map((request) => request.url ?? ''),
    ];
    expect(urls.length).toBeGreaterThan(0);
    for (const url of urls) expect(url).not.toContain('kbw_');

    // NO STORAGE, and the assertion is that nothing was even ATTEMPTED — an empty write log rather
    // than a log with no token in it, because a bearer under any key is the same disk.
    expect(stores).toEqual([]);

    // NO LOG. Every console level, not just `error`: the token is a live credential and a debug line
    // is the one people add while chasing a 401 and forget to remove.
    for (const line of logged) expect(line).not.toContain('kbw_');

    // AND NOTHING RESEMBLING IT IN THE MINT REQUEST EITHER — the token travels in the RESPONSE, and
    // a client that echoed it back into a query string would still pass every check above.
    for (const entry of seen) expect(entry.body).not.toContain('kbw_');
  });
});
