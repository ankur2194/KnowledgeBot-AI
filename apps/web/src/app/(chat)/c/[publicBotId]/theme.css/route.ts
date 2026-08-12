import { API_ORIGIN } from '@/lib/env';
import { safeThemeDeclarations } from '@/lib/theme';

/**
 * The bot's palette as a REAL stylesheet response.
 *
 * Why a route and not an inline <style>: a `style-src 'self'` directive covers a same-origin
 * stylesheet with no nonce, and the response stays cacheable. A nonced inline <style> cannot do
 * both — a nonce must be per response, and reading it forces the page dynamic. A `style=""`
 * attribute is worse: nonces never apply to attributes, so it works in development and is refused
 * in production.
 *
 * CACHE KEY: `publicBotId`, and nothing else. This handler reads NO cookie, NO header and NO
 * session — if it ever did, its cached response would vary by something the URL does not name,
 * which is the whole shape of a cross-org serve.
 */

/**
 * THE PUBLIC CHAT RUNTIME SURFACE — `rt/v1`, NEVER `api/v1` (5B-B2, the seventh D1 call site).
 *
 * `api/v1` is the ADMIN group (services/core-api/bootstrap/app.php): Sanctum cookie session, CSRF,
 * `surface:admin`, `org.member`, every route nested under `organizations/{organization}`. There is
 * no `api/v1/public/*` in it and none is planned. This handler is a server-to-server call carrying
 * no cookie and no token, so every request it made to that prefix would have been a 401 — and the
 * fail-open below turned each one into "render the platform default", silently, forever.
 *
 * `rt/v1` is the group whose route file already names bot public configuration as one of its areas
 * (services/core-api/routes/api_public.php): no session, no cookie, no CSRF, no ambient authority,
 * and a byte-identical 404 for every rejection.
 *
 * NOT `sdk/v1`. The palette could in principle ride the session-mint bootstrap, and it must not:
 * that response carries a per-visitor chat-session token, so it is the one response on the public
 * surface that can never be cached — folding the palette into it would either forfeit the cache or
 * put a credential in a Next Data Cache entry keyed by a URL that does not name the visitor. It is
 * also a POST behind an Origin check, and the Next server sends no browser Origin. Keeping the
 * palette on a GET whose entire response is a function of `publicBotId` is what keeps the cache key
 * honest.
 *
 * THIS ENDPOINT DOES NOT EXIST YET (`rt/v1` is out of scope — plan-status §9). The URL now names
 * the surface it will live on instead of one that never will, and the failure is logged instead of
 * swallowed. Until it lands, every hosted-chat page renders the platform default AND says so.
 */
const themeEndpoint = (publicBotId: string): string =>
  `${API_ORIGIN}/rt/v1/bots/${publicBotId}/theme`;

/**
 * Which of the three outcomes produced this stylesheet, on the response itself.
 *
 * `unavailable` is the whole point of the header. The body for "this bot configured no theme" and
 * the body for "we could not ask" are both empty, so nothing downstream — not a browser, not an
 * E2E test, not an operator with curl — could previously tell a bot rendering its own defaults
 * apart from an entire seam that has been broken since it was written. It carries no detail beyond
 * the token: the status, the reason and the request id go to the server log, not to a visitor.
 */
type ThemeOutcome = 'bot' | 'default' | 'unavailable';

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ publicBotId: string }> },
): Promise<Response> {
  const { publicBotId } = await params;

  // Public bot ids are opaque tokens; anything else is not a bot and must not reach the API as a
  // path segment. Not a failure to report: nothing was asked, because there was nothing to ask
  // about.
  if (!/^[A-Za-z0-9_-]{1,64}$/.test(publicBotId)) {
    return css('', 'default');
  }

  let theme: Record<string, unknown> = {};
  try {
    const response = await fetch(themeEndpoint(publicBotId), {
      headers: { accept: 'application/json' },
      // Cached by URL — and the URL contains the only thing this response varies by. Expired on
      // publish via revalidateTag(`bot:${publicBotId}`, <cacheLife profile>).
      next: { tags: [`bot:${publicBotId}`], revalidate: 60 },
    });

    if (!response.ok) {
      // A theme is decoration and the platform default is always a valid ANSWER — but a non-2xx
      // here is never a valid EVENT. `X-KB-Request-Id` is read for the same reason `toKbError`
      // reads it: it is the one string that lets an operator find this request in Laravel's log,
      // and on a 401 from a route that structurally cannot serve us it is the only evidence there
      // is. The response BODY is never logged: it is operator-facing text that may carry an
      // internal hostname, and this process has no business relaying it anywhere.
      reportThemeFailure(publicBotId, {
        status: response.status,
        request_id: response.headers.get('x-kb-request-id'),
      });
      return css('', 'unavailable');
    }

    const body: unknown = await response.json();
    if (typeof body === 'object' && body !== null) theme = body as Record<string, unknown>;
  } catch (cause) {
    // DNS failure, connection refused, a body that is not JSON. Same verdict, same visibility.
    reportThemeFailure(publicBotId, { status: null, request_id: null, cause });
    return css('', 'unavailable');
  }

  // Values are re-validated here against the closed key set and the exact grammar, because the row
  // may predate the validator. Nothing tenant-supplied is interpolated except a value that has
  // already matched an anchored pattern with no `}`, no `url(` and no `var(` in its language.
  const declarations = safeThemeDeclarations(theme)
    .map(([property, value]) => `  ${property}: ${value};`)
    .join('\n');

  // An empty declaration list is `default`, not `unavailable`: we asked, we were answered, and the
  // answer was "nothing to override".
  return declarations === '' ? css('', 'default') : css(`:root {\n${declarations}\n}\n`, 'bot');
}

/**
 * The trace. Server-side only — this runs in the Next process, never in a browser — and it carries
 * the public bot id, which is already in the URL of the request being served, plus the two things
 * that identify the failure upstream.
 *
 * `console.warn` and not a thrown error, because the RENDERING decision is unchanged: an error page
 * is not a better answer than a bot in the platform's colours. What changes is that the failure now
 * leaves something to find. The `[kb] ` prefix matches the one convention this app already has
 * (src/app/(admin)/error.tsx), and the field names match the log schema
 * (kb-observability-conventions): snake_case, no user content, no response body.
 */
function reportThemeFailure(
  publicBotId: string,
  detail: { status: number | null; request_id: string | null; cause?: unknown },
): void {
  console.warn('[kb] bot theme unavailable', {
    public_bot_id: publicBotId,
    status: detail.status,
    request_id: detail.request_id,
    // The Error's message only. A whole exception object would drag a stack containing absolute
    // paths into a log line that is otherwise a fixed shape.
    reason: detail.cause instanceof Error ? detail.cause.message : undefined,
  });
}

function css(body: string, outcome: ThemeOutcome): Response {
  return new Response(body, {
    headers: {
      'content-type': 'text/css; charset=utf-8',
      // Public: this response is identical for every visitor of this bot. Contrast with every
      // (admin) response, which carries `private, no-store`.
      //
      // EXCEPT WHEN IT IS A FAILURE. Caching a fail-open answer is how a one-second blip becomes a
      // five-minute brand outage for every visitor of that bot: `s-maxage=60,
      // stale-while-revalidate=300` pins the wrong colours into the CDN and the next successful
      // fetch cannot evict them. `no-store` is what makes this self-heal.
      //
      // NOTE, and it is not fixed here: the `next: { revalidate: 60 }` above is a SEPARATE cache,
      // and Next's Data Cache does not take its instruction from this header. A non-2xx may be
      // held there for the full 60 s regardless. It is bounded and it self-heals, unlike the CDN
      // entry, but it is why the log line above is the thing to trust rather than the absence of
      // repeated upstream requests.
      'cache-control':
        outcome === 'unavailable'
          ? 'no-store'
          : 'public, max-age=0, s-maxage=60, stale-while-revalidate=300',
      'x-content-type-options': 'nosniff',
      // See ThemeOutcome. `unavailable` is the state that previously had no representation
      // anywhere: same empty body as `default`, no log, no failing test.
      'x-kb-theme': outcome,
    },
  });
}
