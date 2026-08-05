# The SSE fixture server — reference

Depth for `vitest-playwright`. Spec: docs/17-testing-performance.md §22.1–22.6.
`apps/web/tests/fixtures/sse-server.ts` in full — the `Stream` control surface (`send`, `split`, `ping`, `end`, `cut`), the `setNoDelay` and `flushHeaders` calls that keep the chunking real, and the awaited write callback — followed by the `unit` spec that drives it: a frame split mid-codepoint, a heartbeat that must not surface as an event, and a cut connection yielding `stream_lost`. Both layers share this one fixture; `route.fulfill()` is banned on the chat path and the reason stays in `SKILL.md`.

```ts
// apps/web/tests/fixtures/sse-server.ts
import { createServer, type ServerResponse } from 'node:http';
import { once } from 'node:events';
import type { AddressInfo } from 'node:net';

export type Stream = {
  send(event: string, data: unknown, eol?: string): Promise<void>;  // eol '\r\n': proxies rewrite them
  split(event: string, data: unknown, atByte: number): Promise<void>;  // two segments; aim INSIDE a
  ping(): Promise<void>;      //   multi-byte codepoint to exercise TextDecoder({stream:true}) — the
  end(): void;                //   corruption is invisible in English (nextjs-app-router). ping() is an
  cut(): void;                //   SSE *comment*: the parser must never surface it as an event.
};

export async function startSseServer(script: (s: Stream) => Promise<void>) {
  const server = createServer(async (req, res) => {
    const cors = { 'access-control-allow-origin': req.headers.origin ?? '*',
                   'access-control-allow-credentials': 'true', vary: 'Origin' };
    if (req.method === 'OPTIONS') return void res.writeHead(204, cors).end();
    // Nagle coalesces small back-to-back writes into ONE segment, so the client reads two frames
    // as one chunk and the incremental loop is never crossed. Skip this and the fixture quietly
    // degrades into the single-shot mock it exists to replace.
    res.socket?.setNoDelay(true);
    res.writeHead(200, { ...cors, 'content-type': 'text/event-stream',
      'cache-control': 'no-cache, no-transform', 'x-accel-buffering': 'no' });
    // Node ships headers with the first body write. Without this `await fetch()` does not resolve
    // until the first token, so no pre-token UI state (status events, skeleton) is observable.
    res.flushHeaders();
    await script(controller(res));
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  return { url: `http://127.0.0.1:${(server.address() as AddressInfo).port}`,
           async close() { server.close(); } };
}

function controller(res: ServerResponse): Stream {
  // Await the write callback: `await s.send(...)` must mean "on the socket", not "queued in
  // Node's stream buffer" — otherwise every delay the script encodes is fiction.
  const w = (b: Buffer) => new Promise<void>((ok) => void res.write(b, () => ok()));
  const frame = (e: string, d: unknown, eol = '\n') =>
    Buffer.from(`event: ${e}${eol}data: ${JSON.stringify(d)}${eol}${eol}`, 'utf8');
  return {
    send: (e, d, eol) => w(frame(e, d, eol)),
    ping: () => w(Buffer.from(': ping\n\n', 'utf8')),
    async split(e, d, at) {
      const f = frame(e, d);
      await w(f.subarray(0, at));
      await new Promise((ok) => setTimeout(ok, 20));  // guarantee a second segment
      await w(f.subarray(at));
    },
    end: () => res.end(),
    // end() is a clean FIN — the reader sees a normal end-of-stream and the UI renders the answer
    // as complete, so the cancellation path is never entered. destroy() sends RST, which is what a
    // vanished client or a dead proxy looks like: undici raises `TypeError: terminated`.
    cut: () => res.socket?.destroy(),
  };
}
```

```ts
// apps/web/tests/unit/stream-answer.test.ts — project `unit`, environment 'node'.
it('parses a frame split mid-codepoint, ignores the heartbeat, and reports a cut', async () => {
  const fx = await startSseServer(async (s) => {
    await s.send('message.start', { message_id: '01J' });
    await s.send('citations', { citations: [{ index: 1, title: 'Rückgaberecht', url: null, score: 0.83 }] }, '\r\n');
    await s.ping();                                  // must not appear in `seen`
    await s.split('token', { text: 'Rückgabe ' }, 34);   // byte 34 lands inside the ü
    await s.send('token', { text: 'in 30 Tagen.' });
    s.cut();                                         // no terminal event will ever arrive
  });
  const seen: KbEvent[] = [];
  await expect(async () => {                                 // one options object, explicit credential
    for await (const e of streamAnswer({ conversationId: '01J8…', body, apiOrigin: fx.url,
      credential: { kind: 'chat_session', token: 'cs_test' },   // hosted chat's own token, never the
      signal: new AbortController().signal })) seen.push(e);    // cookie; apiOrigin is fixture-only
    // snake_case, as packages/contracts' KbError: spelled `errorClass` the matcher fails against a
    // correct error, and the reflex fix is a second spelling on the class — one field, four names.
  }).rejects.toMatchObject({ error_class: 'stream_lost' });  // never 'complete' (kb-internal-api-contracts)
  expect(seen.map((e) => e.name)).toEqual(['message.start', 'citations', 'token', 'token']);
  expect(seen.flatMap((e) => (e.name === 'token' ? [e.data.text] : [])).join('')).toBe('Rückgabe in 30 Tagen.');
  await fx.close();
});
```
