import type { InvitationPreview, KbErrorEnvelope, SessionResource } from '@kb/contracts';
import { http, HttpResponse, type RequestHandler } from 'msw';

/**
 * MSW mocks PLAIN JSON endpoints in component tests, and NOTHING on the chat path.
 *
 * It can stream — `HttpResponse` takes a ReadableStream and `sse()` exists — but a client
 * `AbortController.abort()` does not reach the handler under `setupServer`, so `request.signal`
 * never fires, the stream's `cancel()` never runs, and the consumer keeps receiving chunks after
 * the stop button. That is the exact path the §8.18 stop-generation action and the
 * `finish_reason: "cancelled"` accounting exist for. The chat path uses tests/fixtures/sse-server.ts.
 *
 * Two more reasons `sse()` is unusable here: it requires `accept: text/event-stream` on the
 * request, and constructing the handler throws outright when the browser's built-in SSE
 * constructor is missing from `globalThis` — which it is in Vitest's node environment. (That
 * identifier is deliberately not spelled anywhere in this package, and ESLint is what refuses it:
 * `no-restricted-globals` in eslint.base.mjs's `kbRules`, which covers tests/ too — the tests
 * override turns off only no-explicit-any and the fs rule. `pnpm web:lint` is the run, and nothing
 * runs it for you, `.github/` having been deleted on 2026-08-17. The rule matches global
 * REFERENCES, so this comment's avoidance of the name is convention rather than mechanism.)
 *
 * Every payload built here is typed from `@kb/contracts`. A fixture typed by hand encodes what the
 * author believed the wire says, so a server-side rename ships instead of failing a typecheck.
 *
 * ── THE ONE THING EVERY SPEC USING THESE HANDLERS MUST DO ────────────────────────────────────────
 * `readCookie` in src/lib/api/browser.ts reads the PAGE's `document.cookie`, and MSW cannot set a
 * cookie for a cross-origin host (`api.invalid`) from a service worker — the response's `Set-Cookie`
 * is simply not applied to the test page. So every spec seeds
 *
 *     beforeEach(() => { document.cookie = 'XSRF-TOKEN=test-token'; });
 *
 * Without it every spec silently takes the `refreshCsrfToken()` path, that call answers 204 with no
 * cookie, and the code under test throws the "XSRF-TOKEN cookie absent" KbError — so the assertion
 * fails for a reason that has nothing to do with what the spec is about.
 */

/** vitest.config.ts sets NEXT_PUBLIC_API_ORIGIN to this. RFC 2606 reserves `.invalid`, so an
 *  un-intercepted request fails on DNS instead of reaching a host that happens to exist. */
export const ORIGIN = 'http://api.invalid';

/** The one envelope shape `toKbError` parses. Built through the exported interface so a field
 *  rename in @kb/contracts fails the typecheck here rather than degrading every error to `null`. */
export const envelope = (
  error_class: KbErrorEnvelope['error_class'],
  overrides: Partial<KbErrorEnvelope> = {},
): KbErrorEnvelope => ({
  error_class,
  // Deliberately operator-shaped: it names an internal host, so any spec that renders it verbatim
  // shows up as a failing assertion rather than as plausible copy.
  message: `operator detail from api-7.internal for ${error_class}`,
  retryable: false,
  request_id: '01JREQFROMLARAVEL',
  // FALSE BY DEFAULT, matching the server's own default reading of an absent field and matching what
  // the DEFAULT MESSAGE above is: operator detail naming an internal host, which no screen may
  // render. A spec that wants the deliberate-4xx path — the one `deleteConflictMessage` reads —
  // opts in explicitly with `{ actionable: true }`, which is also the shape the server sends.
  actionable: false,
  ...overrides,
});

/**
 * EVERY SUCCESS BODY ON THIS API IS WRAPPED IN `data`, and the wrapper is not decoration.
 *
 * `App\Support\Contracts\ResponseShape` maps a response *key* to a schema class, so an unwrapped body
 * is literally unpublishable by `php artisan kb:dump-openapi` — and the two endpoints that predate all
 * auth work (`embedding-configuration`, `provider-connections`) already wrap. Laravel therefore returns
 * `{"data": {...}}` for `/me`, login, the org switch, register, accept-invitation, the invitation
 * preview, and every `{"acknowledged": true}`.
 *
 * These fixtures were originally written unwrapped, against an assumption rather than against the
 * controllers. Fixing them here rather than unwrapping in the client is deliberate: a fixture that
 * disagrees with the server is the exact shape of bug the whole `msw` layer exists to catch, and a
 * component spec that passes against a wrong fixture is worse than no spec.
 *
 * Callers unwrap once, at the fetch boundary — `browserFetch<Wrapped<SessionResource>>(…)` then
 * `.data` — never by reaching into `data` at a render site.
 */
const wrapped = <T>(data: T): { data: T } => ({ data });

/** A session for a user with two ACTIVE organizations — the minimum fixture that can distinguish an
 *  org switch from a refetch. A one-organization fixture cannot fail an isolation test. */
export const sessionFixture = (overrides: Partial<SessionResource> = {}): SessionResource => ({
  user: {
    id: '01JUSERAAAAAAAAAAAAAAAAAAA',
    name: 'Ada Lovelace',
    email: 'ada@example.test',
    email_verified: true,
    is_platform_owner: false,
  },
  current_organization_id: '01JORGAAAAAAAAAAAAAAAAAAAA',
  organizations: [
    {
      id: '01JORGAAAAAAAAAAAAAAAAAAAA',
      name: 'Acme Research',
      slug: 'acme-research',
      role: 'owner',
      status: 'active',
    },
    {
      id: '01JORGBBBBBBBBBBBBBBBBBBBB',
      name: 'Brightwater Legal',
      slug: 'brightwater-legal',
      role: 'analyst',
      status: 'active',
    },
  ],
  ...overrides,
});

export const invitationFixture = (
  overrides: Partial<InvitationPreview> = {},
): InvitationPreview => ({
  organization_name: 'Acme Research',
  email: 'newcomer@example.test',
  role: 'knowledge_manager',
  expires_at: '2026-08-20T09:00:00+00:00',
  ...overrides,
});

/**
 * The DEFAULT happy path for every auth endpoint, at the URLs the approved route table names
 * (decision D1). A spec that needs a 422, a 429 or a 401 overrides one of these with
 * `worker.use(...)`; nothing here answers an error, so a spec asserting an error path has to say so
 * out loud.
 *
 * THERE IS NO 204 ANYWHERE (decision D8). Every one of these returns a body, which matters because
 * `browserFetch` short-circuits 204/205 to `undefined` before parsing — a handler answering 204
 * where Laravel answers 200 would make a spec pass against a response shape the server never sends.
 *
 * `/sanctum/csrf-cookie` is the one exception and it is not an `/api/v1` endpoint: Laravel really
 * does answer 204 there, with the value in a `Set-Cookie` header the code under test never reads
 * from the response anyway.
 */
export const handlers: RequestHandler[] = [
  http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),

  // ── identity ───────────────────────────────────────────────────────────────────────────────────
  // 200 SessionResource | 401 envelope. NEVER 200 with `{user: null}`: that would make "anonymous"
  // indistinguishable from "authenticated with an empty resource" and would bypass the retry
  // predicate entirely, which refuses to retry `authentication` by class.
  http.get(`${ORIGIN}/api/v1/me`, () => HttpResponse.json(wrapped(sessionFixture()))),

  // ── sign in / sign out ─────────────────────────────────────────────────────────────────────────
  // 200 SessionResource | 422 validation | 429 (+Retry-After) | 419. No Idempotency-Key: login is
  // not replayable and must never be retried by anything, including a double-click.
  http.post(`${ORIGIN}/api/v1/auth/login`, () => HttpResponse.json(wrapped(sessionFixture()))),
  http.post(`${ORIGIN}/api/v1/auth/logout`, () => HttpResponse.json(wrapped({ acknowledged: true }))),

  // ── the org switch ─────────────────────────────────────────────────────────────────────────────
  // 200 SessionResource | 403. It returns the WHOLE session rather than an acknowledgement, so the
  // new `current_organization_id` arrives in the same round trip that changed it. A 403 here is the
  // "switched into an org you were just removed from" case and is the one error that also
  // invalidates the cached session.
  http.post(`${ORIGIN}/api/v1/session/organization`, async ({ request }) => {
    const body = (await request.json()) as { organization_id?: string };
    const organizations = sessionFixture().organizations;
    const target = organizations.find((org) => org.id === body.organization_id);
    if (target === undefined) {
      return HttpResponse.json(envelope('authorization'), { status: 403 });
    }
    return HttpResponse.json(wrapped(sessionFixture({ current_organization_id: target.id })));
  }),

  // ── registration ───────────────────────────────────────────────────────────────────────────────
  // 201 SessionResource | 422. Registration is invitation-gated and signs the new user in, so it
  // answers the same resource login does.
  http.post(`${ORIGIN}/api/v1/auth/register`, () =>
    HttpResponse.json(wrapped(sessionFixture()), { status: 201 }),
  ),

  // ── password reset ─────────────────────────────────────────────────────────────────────────────
  // ALWAYS 200, with IDENTICAL BYTES whether the address exists or not. Any difference — copy,
  // status, field list — makes this an account-enumeration oracle, so there is deliberately no
  // "unknown address" branch to override.
  http.post(`${ORIGIN}/api/v1/auth/forgot-password`, () =>
    HttpResponse.json(wrapped({ acknowledged: true })),
  ),
  http.post(`${ORIGIN}/api/v1/auth/reset-password`, () =>
    HttpResponse.json(wrapped({ acknowledged: true })),
  ),

  // ── email verification ─────────────────────────────────────────────────────────────────────────
  // The token is a QUERY-STRING parameter on the page (decision D2) and a body field here, so there
  // is no `:token` path segment to match — and therefore no path shape that a lookalike route could
  // collide with.
  http.post(`${ORIGIN}/api/v1/auth/email/verify`, () => HttpResponse.json(wrapped({ acknowledged: true }))),
  http.post(`${ORIGIN}/api/v1/auth/email/verification-notification`, () =>
    HttpResponse.json(wrapped({ acknowledged: true })),
  ),

  // ── invitations ────────────────────────────────────────────────────────────────────────────────
  // POST, not GET, for the preview: the token is the credential, and a GET would put it in a URL
  // that lands in access logs and in the browser's history. 404 for bad, expired AND already
  // consumed, with a byte-identical body — the preview is guest-reachable and must not answer
  // "this token existed once".
  http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, () =>
    HttpResponse.json(wrapped(invitationFixture())),
  ),
  http.post(`${ORIGIN}/api/v1/auth/invitations/accept`, () => HttpResponse.json(wrapped(sessionFixture()))),
];
