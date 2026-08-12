import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

import { SSE_PATH, serveStream } from './sse-scenarios.mjs';

/**
 * THE WIDGET ORIGIN, and the API origin, from one process on two ports.
 *
 * That is faithful to production rather than a shortcut: `traefik-routing` puts the loader and the
 * static iframe assets on `<widget-domain>` behind nginx, routes `<widget-domain>/embed/*` to
 * LARAVEL, and puts the public API on `api.<domain>`. This file stands in for all three, which is
 * why it can be honest about the one thing loopback cannot reproduce — `<widget-domain>` and
 * `api.<domain>` are different eTLD+1s in production and merely different ports here. The eTLD+1
 * assertion belongs to the deployment test and to vite.config.ts's build-time guard.
 *
 * Node builtins only.
 */

const here = dirname(fileURLToPath(import.meta.url));
const dist = join(here, '..', '..', 'dist');

const WIDGET_PORT = Number(process.env['KB_WIDGET_PORT'] ?? 4173);
const API_PORT = Number(process.env['KB_API_PORT'] ?? 4175);
const CUSTOMER_ORIGIN = process.env['KB_CUSTOMER_ORIGIN'] ?? 'http://localhost:4174';

/**
 * The bot's allow-list, server-side. FULL ORIGINS, never bare hostnames and never regexes — a
 * `*.customer.example` entry grants every subdomain including a dangling-DNS takeover, and there
 * is no safe wildcard for "any customer who signed up".
 */
const ALLOWED_ORIGINS = new Set([CUSTOMER_ORIGIN]);
const FIXTURE_BOT = 'pub_01J8FIXTUREBOT0000000000';

/**
 * WHICH ORIGINS GET CORS HEADERS, which is a DIFFERENT question from which origins may embed a bot.
 *
 * The mint arrives from the CUSTOMER's page; every runtime call after it arrives from the FRAME, on
 * `<widget-domain>`, because that is where the chat application runs. So the API answers two
 * origins, and neither of them is a wildcard — `access-control-allow-origin: *` on a surface that
 * mints sessions would let any page on the internet do it, and `null` is exploitable from any
 * sandboxed or data: frame in existence.
 *
 * The bot's embed allow-list (`ALLOWED_ORIGINS`) stays what it was and is checked separately, at
 * mint time, against the `Origin` HEADER. A frame origin appearing here grants nothing: it cannot
 * mint, because it is not on the bot's list.
 */
const WIDGET_ORIGIN = process.env['KB_WIDGET_ORIGIN'] ?? `http://127.0.0.1:${WIDGET_PORT}`;
const CORS_ORIGINS = new Set([CUSTOMER_ORIGIN, WIDGET_ORIGIN]);

const MIME = {
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
};

/** Read the hashed entry filename the way Laravel does in production — from the Vite manifest.
 *  This is what `build.manifest: true` exists for: dist/app/index.html is never served. */
async function entryAsset() {
  const manifest = JSON.parse(await readFile(join(dist, 'app', '.vite', 'manifest.json'), 'utf8'));
  const entry =
    manifest['index.html'] ?? Object.values(manifest).find((chunk) => chunk.isEntry === true);
  if (entry === undefined) throw new Error('[kb] no entry chunk in dist/app/.vite/manifest.json');
  return { js: `/${entry.file}`, css: (entry.css ?? []).map((href) => `/${href}`) };
}

async function serveFile(response, absolute) {
  try {
    const body = await readFile(absolute);
    response.writeHead(200, {
      'content-type': MIME[extname(absolute)] ?? 'application/octet-stream',
      'x-content-type-options': 'nosniff',
      // The loader is fetched by a <script> tag from an origin we do not control.
      'access-control-allow-origin': '*',
      'cross-origin-resource-policy': 'cross-origin',
      'cache-control': 'no-store',
    });
    response.end(body);
  } catch {
    response.writeHead(404, { 'content-type': 'text/plain' });
    response.end('not found');
  }
}

// ── <widget-domain> ────────────────────────────────────────────────────────────────────────────
createServer(async (request, response) => {
  const url = new URL(request.url ?? '/', `http://127.0.0.1:${WIDGET_PORT}`);

  if (url.pathname === '/healthz') {
    response.writeHead(200, { 'content-type': 'text/plain' });
    response.end('ok');
    return;
  }

  // The loader, at the path pinned to the ENVELOPE MAJOR. Contents mutable, held by customer
  // caches for months — which is the entire reason the envelope is versioned from day one.
  if (url.pathname === '/v1/kb-widget.js') {
    await serveFile(response, join(dist, 'loader', 'kb-widget.js'));
    return;
  }

  /**
   * `/embed` — LARAVEL's route in production, and the reason dist/app/index.html is never served.
   *
   * `frame-ancestors` is derived PER REQUEST from the bot's live allow-list. It cannot be a static
   * header, it cannot be set in <meta>, and there is no safe wildcard. An unregistered origin gets
   * `'none'` plus a "not authorized for this domain" page — never a permissive default. Emit the
   * SINGLE MATCHED origin, never the tenant's whole list.
   *
   * This is also what makes trusting `?origin=` sound: a page at https://evil.example claiming
   * `origin=<allowed>` gets a document the browser refuses to render, so by the time our script
   * runs the browser has already proved the claim.
   */
  if (url.pathname === '/embed' || url.pathname.startsWith('/embed/')) {
    const claimed = url.searchParams.get('origin') ?? '';
    const bot = url.searchParams.get('bot') ?? '';
    const authorized = bot === FIXTURE_BOT && ALLOWED_ORIGINS.has(claimed);
    const { js, css } = await entryAsset();

    response.writeHead(200, {
      'content-type': 'text/html; charset=utf-8',
      // Start from 'none', not 'self' — 'self' silently permits frame-src, media-src, manifest-src.
      // object-src and base-uri are the two people omit and the two that reinstate script
      // execution. form-action is not covered by default-src.
      'content-security-policy': [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self' data:",
        `connect-src 'self' http://127.0.0.1:${API_PORT}`,
        "font-src 'self'",
        "object-src 'none'",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-src 'none'",
        `frame-ancestors ${authorized ? claimed : "'none'"}`,
      ].join('; '),
      // The framed document names one embedder; a shared cache serving it to another is a
      // cross-tenant header leak.
      'cache-control': 'private, no-store',
      vary: 'Origin',
      'x-content-type-options': 'nosniff',
    });

    if (!authorized) {
      response.end(
        '<!doctype html><title>Not authorized</title><p>Not authorized for this domain.',
      );
      return;
    }
    response.end(
      `<!doctype html><html lang="en" data-theme="auto"><head><meta charset="utf-8">` +
        css.map((href) => `<link rel="stylesheet" href="${href}">`).join('') +
        `</head><body><div id="kb-root"></div><script type="module" src="${js}"></script></body></html>`,
    );
    return;
  }

  /**
   * `/__probe` — THE STREAM PROBE, and the one thing on this server that has no production
   * counterpart.
   *
   * It exists because `src/app/app.tsx`'s submit handler is still a stub (it cascades into a
   * `rt/v1` conversation-creation route that does not exist), so there is no in-app path that can
   * drive `streamAnswer` in a browser yet — and a read loop proven anywhere but a browser, over
   * anything but a real socket, is proven nowhere. The probe imports the REAL `src/app/stream.ts`
   * (tests/harness/probe/main.ts, built by tests/harness/probe.vite.config.ts) and exposes one
   * function. It adds no branch to src/, so no spec can pass by virtue of test-only code inside the
   * widget, and it runs on THIS origin against the API origin, which is the same cross-origin
   * relationship the frame has in production.
   *
   * The CSP is the frame's CSP minus `frame-ancestors` (the probe is a top-level document, not a
   * framed one). Keeping `connect-src` identical is the point: a stream that only works under a
   * looser policy than the real frame gets would prove nothing about the real frame.
   */
  if (url.pathname === '/__probe') {
    response.writeHead(200, {
      'content-type': 'text/html; charset=utf-8',
      'content-security-policy': [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self' data:",
        `connect-src 'self' http://127.0.0.1:${API_PORT}`,
        "object-src 'none'",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-src 'none'",
      ].join('; '),
      'cache-control': 'no-store',
      'x-content-type-options': 'nosniff',
    });
    response.end(
      `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>kb stream probe</title></head>` +
        `<body><div id="kb-probe-output"></div>` +
        `<script type="module" src="/__probe/probe.js"></script></body></html>`,
    );
    return;
  }

  if (url.pathname.startsWith('/__probe/')) {
    const file = normalize(join(dist, 'probe', url.pathname.slice('/__probe/'.length)));
    if (!file.startsWith(join(dist, 'probe'))) {
      response.writeHead(403, { 'content-type': 'text/plain' });
      response.end('forbidden');
      return;
    }
    await serveFile(response, file);
    return;
  }

  // Hashed assets. `normalize` + prefix check: a fixture is still a file server.
  const absolute = normalize(join(dist, 'app', url.pathname));
  if (!absolute.startsWith(join(dist, 'app'))) {
    response.writeHead(403, { 'content-type': 'text/plain' });
    response.end('forbidden');
    return;
  }
  await serveFile(response, absolute);
}).listen(WIDGET_PORT, '127.0.0.1', () => {
  console.log(`[kb] widget-origin harness on http://127.0.0.1:${WIDGET_PORT}`);
});

// ── api.<domain> ───────────────────────────────────────────────────────────────────────────────
let mintCount = 0;
/** Every streaming request the fixture saw, in order: the bearer it carried, the body it posted,
 *  and which attempt it was for that conversation. Recorded on the SERVER so a spec can assert the
 *  refresh flush without asking the page under test what it thinks it sent. */
const streamRequests = [];
const attempts = new Map();

createServer((request, response) => {
  const url = new URL(request.url ?? '/', `http://127.0.0.1:${API_PORT}`);
  const origin = request.headers.origin ?? '';

  const cors = {
    // Echoed only for an origin we actually answer. NEVER '*', never '*' with credentials, and
    // never `null` — `Access-Control-Allow-Origin: null` is exploitable from any sandboxed or
    // data: frame on the internet. Two origins are legitimate: the customer's page (the mint) and
    // the frame (every runtime call). Which of them may EMBED a bot is a separate check, made at
    // mint time against the bot's own allow-list.
    ...(CORS_ORIGINS.has(origin) ? { 'access-control-allow-origin': origin } : {}),
    // ALWAYS. Without it a CDN caches one tenant's ACAO and serves it to another — which reads as
    // an intermittent CORS failure and is actually a cross-tenant header leak.
    vary: 'Origin',
    // `authorization` is what makes the streaming POST a preflighted request; `accept` is not a
    // CORS-safelisted value once it names `text/event-stream`.
    'access-control-allow-headers': 'content-type, authorization, accept',
    'access-control-allow-methods': 'POST, OPTIONS',
    // Both are on cors.php's `exposed_headers`; without exposing them the client reads null for a
    // request id it was sent.
    'access-control-expose-headers': 'retry-after, x-kb-request-id',
  };

  if (request.method === 'OPTIONS') {
    response.writeHead(204, cors);
    response.end();
    return;
  }

  if (url.pathname === '/healthz') {
    response.writeHead(200, { 'content-type': 'text/plain' });
    response.end('ok');
    return;
  }

  // Test-only introspection, on the API fixture rather than in page script, so a spec can assert
  // "exactly ONE mint" after a double injection without the page being able to influence it.
  if (url.pathname === '/__mints') {
    response.writeHead(200, { 'content-type': 'application/json', ...cors });
    response.end(JSON.stringify({ count: mintCount }));
    return;
  }

  /** The same idea for the stream: what the SERVER saw, not what the client claims it sent. */
  if (url.pathname === '/__stream-requests') {
    response.writeHead(200, { 'content-type': 'application/json', ...cors });
    response.end(JSON.stringify({ requests: streamRequests }));
    return;
  }

  /**
   * The public chat runtime stream, on `rt/v1` — NOT `api/v1`, which is the admin group.
   *
   * The conversation id selects the scenario (tests/harness/sse-scenarios.mjs), so the request the
   * probe makes is byte-for-byte the request the application makes: no test-only query parameter,
   * no test-only header, and nothing in src/ that knows a fixture exists.
   */
  const streaming = SSE_PATH.exec(url.pathname);
  if (streaming !== null && request.method === 'POST') {
    let raw = '';
    request.on('data', (chunk) => (raw += chunk));
    request.on('end', () => {
      const conversationId = decodeURIComponent(streaming[1]);
      const attempt = (attempts.get(conversationId) ?? 0) + 1;
      attempts.set(conversationId, attempt);
      let body = null;
      try {
        body = JSON.parse(raw || 'null');
      } catch {
        body = null;
      }
      streamRequests.push({
        conversation_id: conversationId,
        attempt,
        // The bearer is recorded so a spec can prove the flush carried the NEW token; the body is
        // recorded so it can prove the flush carried the SAME client_message_id.
        authorization: request.headers.authorization ?? null,
        accept: request.headers.accept ?? null,
        body,
      });
      void serveStream(request, response, { conversationId, attempt, cors });
    });
    return;
  }

  /**
   * `sdk/v1`, NOT `api/v1`. bootstrap/app.php mounts `api/v1` on Laravel's `api` middleware group —
   * the admin session, CSRF and cookie stack — while the SDK bootstrap is its own `sdk/v1` group
   * with a 404 on every rejection, and config/cors.php lists `sdk/*` explicitly because the
   * unpublished default covers only `api/*`.
   *
   * This fixture faked the `api/v1` spelling, so the whole e2e suite was green over the loader's
   * matching defect. Nothing else on this server answers a POST, so a loader that regresses to the
   * old path gets the catch-all 404 below, `mint()` returns null, no session is ever handed over
   * the bridge, and every mint-counting spec fails. That is deliberate: the fixture must be able to
   * fail the defect it previously concealed.
   */
  if (url.pathname === '/sdk/v1/session' && request.method === 'POST') {
    let body = '';
    request.on('data', (chunk) => (body += chunk));
    request.on('end', () => {
      let parsed = {};
      try {
        parsed = JSON.parse(body || '{}');
      } catch {
        parsed = {};
      }
      /**
       * `Origin` from the HEADER only — never a body or query field. The browser sets it and page
       * script cannot forge it, and this POST is the ONE request in the whole system that carries
       * the embedder's real origin. A missing `Origin` on a cross-origin request is a rejection,
       * not a default-allow.
       *
       * EVERY rejection is a byte-identical 404: on a public/SDK surface a 403 on a foreign id
       * confirms the row exists.
       */
      const ok = ALLOWED_ORIGINS.has(origin) && parsed.bot_id === FIXTURE_BOT;
      if (!ok) {
        response.writeHead(404, { 'content-type': 'application/json', ...cors });
        response.end('{}');
        return;
      }
      mintCount += 1;
      response.writeHead(200, { 'content-type': 'application/json', ...cors });
      // Opaque, not a JWT carrying claims the client could read or that we would then trust.
      response.end(JSON.stringify({ token: `kbw_fixture.${mintCount}`, expires_in: 1800 }));
    });
    return;
  }

  response.writeHead(404, { 'content-type': 'application/json', ...cors });
  response.end('{}');
}).listen(API_PORT, '127.0.0.1', () => {
  console.log(`[kb] api harness on http://127.0.0.1:${API_PORT}`);
});
