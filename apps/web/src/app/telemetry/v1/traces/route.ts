/**
 * Browser OTLP ingest. ADR-025: `otel-collector` joins `edge` with NO Traefik router, so the
 * browser posts SAME-ORIGIN to this path and this handler forwards over the private network. The
 * browser never learns the Collector's address, CSP stays `connect-src 'self'` plus the API origin,
 * and the public hostname count stays at four.
 *
 * OUTSIDE BOTH ROUTE GROUPS ON PURPOSE. `(admin)` and `(chat)` are two origins with two layouts and
 * two threat models; this is neither. A route handler needs no layout, so `src/app/telemetry/...`
 * resolves cleanly and inherits no group-level `dynamic` or header policy by accident.
 *
 * THIS IS THE ONE PLACE THE NEXT SERVER MAKES AN OUTBOUND REQUEST THAT IS NOT LARAVEL, AND IT
 * CARRIES NO TENANT DATA. Spans are already redacted at the emitter (instrumentation-client.ts
 * strips query strings and captures no message text, header values, cookies or form fields), the
 * body is forwarded verbatim without being parsed, and nothing here reads the session. That is what
 * keeps nextjs-app-router NN1 true: no organization-scoped byte is produced by the Next server.
 * `otel-collector` is not FastAPI — apps/web is on `edge` only (ADR-013), so `ai-api` does not even
 * resolve from this process.
 *
 * CACHING: none, and it cannot happen by accident — a POST is never served from the Data, Full
 * Route or Router cache. `force-dynamic` is restated anyway so the decision is greppable next to
 * every other route's, and `revalidate = 0` closes the door on a future GET being added here.
 * NOTHING CHANGES ON AN ORG SWITCH: the response body is a constant and this handler has no org
 * scope to invalidate.
 */
export const dynamic = 'force-dynamic';
export const revalidate = 0;
export const runtime = 'nodejs';

/**
 * Server-only, and deliberately NOT a `NEXT_PUBLIC_` value: the Collector's address is exactly the
 * thing ADR-025 keeps out of the bundle. The default matches the Compose service name so a
 * self-hoster who never sets it still works.
 */
const COLLECTOR_ENDPOINT = process.env.OTEL_EXPORTER_OTLP_ENDPOINT ?? 'http://otel-collector:4318';

const COLLECTOR_TRACES_URL = `${COLLECTOR_ENDPOINT.replace(/\/+$/, '')}/v1/traces`;

/**
 * A `BatchSpanProcessor` flush of the default 512-span queue is a few tens of kB. 512 kB is roughly
 * an order of magnitude of headroom and still small enough that this endpoint is not a free relay
 * for anything interesting. Enforced on the buffered body, not on Content-Length, because a
 * chunked request does not have to send one.
 */
const MAX_BODY_BYTES = 512 * 1024;

/** `exporter-trace-otlp-http` sends JSON; `sendBeacon` sends the same bytes as a typed Blob. */
const ALLOWED_CONTENT_TYPES = ['application/json', 'application/x-protobuf'];

/** OTLP/HTTP wants a serialized ExportTraceServiceResponse. An empty object is a valid one. */
const OTLP_EMPTY_RESPONSE = '{}';

/** Never the Collector's body, never its headers — an upstream error string is operator-facing. */
function opaque(status: number): Response {
  return new Response(null, { status, headers: { 'cache-control': 'no-store' } });
}

export async function POST(request: Request): Promise<Response> {
  /**
   * `Sec-Fetch-Site` is set by the browser and cannot be overridden by page script, so this stops a
   * third-party page from using us as a span relay. It is NOT a security boundary against a
   * non-browser client, which can send anything: what bounds this endpoint for those is the byte
   * cap above plus a rate limit at Traefik. Absent header => allow, because dropping telemetry from
   * a client that does not send it is worse than the relay it would prevent.
   */
  const site = request.headers.get('sec-fetch-site');
  if (site !== null && site !== 'same-origin') return opaque(403);

  const contentType = (request.headers.get('content-type') ?? '').split(';')[0]?.trim() ?? '';
  if (!ALLOWED_CONTENT_TYPES.includes(contentType)) return opaque(415);

  let body: ArrayBuffer;
  try {
    body = await request.arrayBuffer();
  } catch {
    return opaque(400);
  }
  if (body.byteLength === 0) return opaque(400);
  if (body.byteLength > MAX_BODY_BYTES) return opaque(413);

  let upstream: Response;
  try {
    upstream = await fetch(COLLECTOR_TRACES_URL, {
      method: 'POST',
      // Constructed, never forwarded. `request.headers` carries the session cookie on the admin
      // origin, and a credential has no business leaving this process toward a telemetry sink.
      headers: { 'content-type': contentType },
      body,
      // A wedged Collector must not hold a Next request slot open; spans are droppable by design.
      signal: AbortSignal.timeout(5_000),
      // Next caches `fetch` unless told otherwise, and an argument-keyed cache in front of a POST
      // to a telemetry sink is nonsense in both directions.
      cache: 'no-store',
    });
  } catch {
    return opaque(502);
  }

  if (!upstream.ok) return opaque(502);

  return new Response(OTLP_EMPTY_RESPONSE, {
    status: 200,
    headers: { 'content-type': 'application/json', 'cache-control': 'no-store' },
  });
}
