import type { ErrorResponseLike } from '@kb/contracts';
import { KbError, toKbError } from '@kb/contracts';

import type { ApiEnvelope, Credential } from '@/lib/api/browser';
import { API_ORIGIN } from '@/lib/env';

/**
 * The browser-side MULTIPART caller: one file, one request, with byte progress and a cancel.
 *
 * ── WHY THIS EXISTS BESIDE `browserFetch` RATHER THAN INSIDE IT ─────────────────────────────────
 * `browserFetch` is the ONE JSON caller and stays that way. It unconditionally does
 * `body: JSON.stringify(request.body)` and sets `Content-Type: application/json`, so it cannot send
 * a multipart body; and `fetch()` exposes no upload progress at all — a request body stream is
 * write-only from the caller's side, and `duplex: 'half'` gives you the ability to STREAM a body,
 * not to observe how much of it left the machine. Widening `browserFetch` to cover both would put
 * two transports, two body encodings and two progress models behind one signature, and the envelope
 * path plus the retry policy would stop having exactly one owner.
 *
 * So this is a SIBLING, and it deliberately reads like one: same `Credential` union, same `path` +
 * `API_ORIGIN` composition, same `signal`, same `idempotency_key`, same snake_case field names, and
 * the same rejection type. What it does NOT do is re-derive any of that — `Credential` and
 * `ApiEnvelope` are imported from `browser.ts`, and the whole error path is `toKbError` from
 * `@kb/contracts`. A near-duplicate of a shared helper is where a fix fails to arrive.
 *
 * ── WHAT IS NOT HERE ────────────────────────────────────────────────────────────────────────────
 * No `retry` option — retry policy lives in `lib/query/client.ts` and nowhere else, and the ESLint
 * selector `Property[key.name='retry']` enforces it for every file but that one. No batching: one
 * file per request, so per-file progress and per-file failure are the natural shapes rather than
 * something a caller has to demultiplex out of a single 422. The batch is composed by the caller.
 * No `Content-Type` header — see `send()`.
 */

/** Bytes moved so far, and the fraction of them, when the browser can compute one. */
export interface UploadProgress {
  /** Bytes of the multipart body handed to the network so far. Includes the part headers. */
  readonly loaded: number;
  /**
   * The whole body's length, or `null` when the browser reports `lengthComputable: false`.
   *
   * NULL IS A REAL STATE AND MUST REACH THE UI. `<Progress value={undefined}>` renders 0% (its
   * shadcn-inherited `value || 0`), not an indeterminate bar, so a row that treats `null` as zero
   * shows a determinate bar parked at 0% for the whole upload — which kb-ui-patterns P13 names as
   * worse than a spinner. The row renders an indeterminate affordance instead.
   */
  readonly total: number | null;
  /** `loaded / total` as 0–100, or `null` when `total` is. Never `NaN`: a zero-byte total is null. */
  readonly percent: number | null;
}

export interface UploadRequest {
  /** Path only, e.g. `/api/v1/organizations/{org}/sources`. The origin is NEXT_PUBLIC_API_ORIGIN. */
  readonly path: string;
  /** POST creates; PUT replaces a known object. No GET/DELETE — neither carries a body. */
  readonly method?: 'POST' | 'PUT';
  /** THE one file. A second file is a second call (see the module docblock). */
  readonly file: File;
  /** The multipart part name Laravel reads it under. Defaults to `file`. */
  readonly field?: string;
  /**
   * Scalar parts sent alongside the file, as an EXPLICIT ALLOW-LIST the caller types out.
   *
   * `FormData` is assembled by hand rather than from a form-state object, because a
   * `z.strictObject` parse cannot protect a payload it never sees (rhf-zod-forms): spreading form
   * state into `FormData` is how an ownership column rides along on the one request shape the
   * schema does not cover. Ownership columns are the server's business and are unrepresentable
   * here by construction — this is a `Record<string, string>` a caller writes key by key.
   */
  readonly fields?: Readonly<Record<string, string>>;
  /** Session cookie + XSRF, or the hosted-chat bearer. Never assumed from the surface. */
  readonly credential: Credential;
  /**
   * Wired to `xhr.abort()`, so a caller cancels an upload exactly the way it cancels a query —
   * `AbortController.abort()`, or `queryClient.cancelQueries()` handing the queryFn its signal.
   */
  readonly signal?: AbortSignal;
  /** Present only where the endpoint honours it; a request without one is never retried by anything. */
  readonly idempotency_key?: string;
  /**
   * Called on every `xhr.upload.progress` event, on the main thread, at whatever rate the browser
   * emits (typically every ~50ms or every chunk). Callers throttle their own renders; this does not
   * rate-limit, because a transport that swallowed the last event before `load` would leave a bar
   * short of its total.
   */
  readonly on_progress?: (progress: UploadProgress) => void;
}

/**
 * Uploads one file and resolves with the UNWRAPPED `data`, or REJECTS with the `KbError` from
 * `@kb/contracts` — never a per-app copy, because `instanceof` against a forked class fails and the
 * retry predicate in `lib/query/client.ts` then treats every error as permanent, silently.
 *
 * ── THE ONE EXCEPTION TO "REJECTS WITH KbError", AND IT IS DELIBERATE ───────────────────────────
 * A CANCELLED upload rejects with the signal's own `reason` — a `DOMException` named `AbortError`
 * by default, exactly what `fetch()` rejects with. It is not a `KbError` because cancellation is
 * not a failure: `features/chat/stream-answer.ts` already establishes the house reading
 * (`error.name === 'AbortError'` means "no error state at all — no banner, no retry"), TanStack
 * Query reads the same name to distinguish a cancelled mutation from a failed one, and dressing a
 * user's own Cancel click as an error class would render a red row for the thing they just asked
 * for. `user_cancellation` is the SERVER's class for a stream the client dropped; it is not
 * something a client mints about itself. Every other outcome — 4xx, 5xx, an unparseable body, a
 * dead socket — is a `KbError`.
 *
 * ── THE `data` UNWRAP HAPPENS HERE, ONCE, AND THAT IS WHY THERE IS ONLY ONE FUNCTION ────────────
 * Every success body on this API is wrapped in `data` (`ApiEnvelope`, declared in `browser.ts` and
 * imported rather than re-declared). `browserFetch`/`browserFetchData` are a pair because the JSON
 * caller has one endpoint whose 201 carries a second top-level key beside `data`; nothing on the
 * upload path does, so a second function here would be a second copy of a one-line unwrap for no
 * caller — and a second copy of the unwrap is the thing this module is under instruction not to
 * write. If an upload response ever grows a sibling key, it gets a sibling function HERE, at the
 * transport boundary, and never a `.data` read at a render site.
 */
export function uploadFile<T>(request: UploadRequest): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    // CHECKED BEFORE ANYTHING IS OPENED. A signal that was already aborted (a row cancelled while
    // its turn in the queue was still pending) must not produce a request that is then torn down —
    // `xhr.abort()` on an unsent request is a no-op in some engines and the promise would hang.
    if (request.signal?.aborted === true) {
      reject(abortReason(request.signal));
      return;
    }

    const xhr = new XMLHttpRequest();

    const onAbort = (): void => {
      xhr.abort();
    };

    /** Runs on every terminal path, so the listener cannot outlive the request it belongs to. */
    const detach = (): void => {
      request.signal?.removeEventListener('abort', onAbort);
    };

    request.signal?.addEventListener('abort', onAbort, { once: true });

    if (request.on_progress !== undefined) {
      const report = request.on_progress;
      // `xhr.upload`, NOT `xhr`. `xhr.onprogress` reports the RESPONSE being downloaded — a few
      // hundred bytes of JSON that arrive after the file is already gone — so a bar wired to it
      // sits at 0% for a 40 MB upload and then jumps to 100%. This is the single reason XHR is
      // still in the platform for anyone: `upload.progress` has no `fetch()` equivalent.
      xhr.upload.addEventListener('progress', (event: ProgressEvent) => {
        report(toUploadProgress(event));
      });
    }

    xhr.addEventListener('abort', () => {
      detach();
      // The signal's reason when there is one (it is the caller's `AbortError`, or whatever they
      // passed to `abort(reason)`); otherwise a DOMException of our own so the shape is the same
      // whether the abort came through the signal or from a direct `xhr.abort()`.
      reject(
        request.signal === undefined
          ? new DOMException('Upload cancelled.', 'AbortError')
          : abortReason(request.signal),
      );
    });

    xhr.addEventListener('error', () => {
      detach();
      // NO CLASS IS INVENTED HERE. A dead socket, a DNS failure, a CORS refusal and a proxy that
      // hung up all land on this event with `status === 0` and no readable body — none of them is
      // one of the 18, so it is `null`: unknown, and unknown is permanently non-retryable. Reaching
      // for `internal_dependency` would be worse than useless, because that class is retryable AND
      // pages: a customer's flaky wifi would wake somebody up.
      reject(
        new KbError(
          null,
          false,
          null,
          null,
          `upload transport failure (no response) for ${request.method ?? 'POST'} ${request.path}`,
        ),
      );
    });

    xhr.addEventListener('timeout', () => {
      detach();
      // Unreachable while `xhr.timeout` stays 0 (see `send`), and handled anyway: an unhandled
      // `timeout` event would leave this promise pending forever and the row stuck at 100%.
      reject(new KbError(null, false, null, null, `upload timed out for ${request.path}`));
    });

    xhr.addEventListener('load', () => {
      detach();
      void settle<T>(xhr).then(resolve, reject);
    });

    send(xhr, request);
  });
}

/**
 * A completed exchange → the resolved value, or a rejection.
 *
 * `async` so the `toKbError` await reads like `browserFetch`'s `throw await toKbError(response)`,
 * which is the same line doing the same job against the same helper.
 */
async function settle<T>(xhr: XMLHttpRequest): Promise<T> {
  // `status < 200 || status >= 300`, matching `Response.ok`, rather than a list of the statuses this
  // endpoint is documented to return. A 3xx that reached here (a redirect XHR did not follow, an
  // authenticating proxy) is not a success and must not be parsed as one.
  if (xhr.status < 200 || xhr.status >= 300) {
    throw await toKbError(responseLike(xhr));
  }

  // 204/205 carry no body BY DEFINITION and parsing one rejects with a SyntaxError that would
  // surface as an unparseable `unknown` failure over a request that actually succeeded. Same
  // short-circuit, same reason, as `browserFetch`.
  if (xhr.status === 204 || xhr.status === 205) return undefined as T;

  let payload: unknown;
  try {
    payload = JSON.parse(xhr.responseText) as unknown;
  } catch {
    // A 2xx whose body is not JSON is a broken response, not a successful upload: something
    // between here and Laravel answered (an HTML interstitial from a captive portal is the classic
    // one) and resolving would hand the caller `undefined` as if the source had been created.
    //
    // BUILT BY `toKbError` RATHER THAN BY HAND, even though the status is a success, and that is
    // not cleverness — it is the only way this branch carries `X-KB-Request-Id`. Doing it by hand
    // needs a private trim-and-nullify of that header, which is `normalizeRequestId` in
    // @kb/contracts spelled a second time in a second place; the copy that eventually forgets the
    // empty-string case renders "(ref )" to a user. `toKbError` sees the same unparseable body,
    // takes its no-envelope branch, and yields `error_class: null` — unknown, permanent — with the
    // header's id and an operator-facing `HTTP 200` that no screen renders.
    throw await toKbError(responseLike(xhr));
  }

  // THE UNWRAP, once, at this module's fetch boundary — see the `uploadFile` docblock. `data` is
  // read here and never at a render site.
  return (payload as ApiEnvelope<T>).data;
}

/** One request, built from the credential union and nothing ambient — `browser.ts`'s `send` shape. */
function send(xhr: XMLHttpRequest, request: UploadRequest): void {
  xhr.open(request.method ?? 'POST', `${API_ORIGIN}${request.path}`, true);

  // ── THE CREDENTIAL SWITCH, AND IT IS THE SAME SWITCH `browser.ts` MAKES ────────────────────────
  // `withCredentials` is XHR's spelling of `credentials: 'include' | 'omit'`, decided by the
  // credential rather than by the surface the code happens to be running on. `'omit'` on the
  // chat-session path is a security control: the session cookie is scoped to app. and api., so
  // including it is silently empty on chat. today and becomes a cross-surface credential leak the
  // day that scope widens by one config line.
  if (request.credential.kind === 'session') {
    xhr.withCredentials = true;
    // Already URL-DECODED by `sessionCredential()`/`refreshCsrfToken()`. Laravel compares the
    // header to the DECRYPTED cookie value, and `%3D` padding echoed verbatim never matches.
    xhr.setRequestHeader('X-XSRF-TOKEN', request.credential.xsrf_token);
  } else {
    xhr.withCredentials = false;
    xhr.setRequestHeader('Authorization', `Bearer ${request.credential.token}`);
  }

  xhr.setRequestHeader('Accept', 'application/json');

  if (request.idempotency_key !== undefined) {
    xhr.setRequestHeader('Idempotency-Key', request.idempotency_key);
  }

  // ── NO `Content-Type` HEADER, AND SETTING ONE BREAKS THE REQUEST SILENTLY ─────────────────────
  // A multipart body is `multipart/form-data; boundary=----WebKitFormBoundary…`, and only the
  // engine knows that boundary. Passing a `FormData` to `send()` makes it write the header WITH the
  // boundary; setting `Content-Type: multipart/form-data` by hand overrides that with a value
  // carrying no boundary, PHP's parser finds no parts, `$request->file('file')` is null, and the
  // 422 says the file is required — over a request whose body contained the whole file.
  //
  // There is also no `cache: 'no-store'` equivalent to set, and none is needed: the HTTP cache does
  // not store a POST/PUT response, so the org-not-in-the-URL hazard `browserFetch` closes with that
  // option has no shape here.
  const body = new FormData();

  // The scalar parts FIRST, so the file is the last part on the wire. It is not required by the
  // spec, but a server-side streaming parser sees every scalar before it starts buffering
  // megabytes, which is what lets it reject an obviously-wrong request early.
  for (const [name, value] of Object.entries(request.fields ?? {})) {
    body.append(name, value);
  }

  // The third argument is the FILENAME and is not optional in practice: without it some engines
  // send `blob`, and the server's extension allow-list — one half of the upload control
  // (kb-security-baseline) — has nothing to read.
  body.append(request.field ?? 'file', request.file, request.file.name);

  // 0 = no timeout, and that is the right value for a transfer whose duration is the user's
  // connection times their file. A wall clock here would cancel a legitimate 200 MB upload on a
  // hotel wifi and report it as a failure. The `timeout` listener above exists so that a future
  // decision to set one cannot produce a promise that never settles.
  xhr.timeout = 0;

  xhr.send(body);
}

/**
 * The three members `toKbError` reads, off an XHR instead of a `Response`.
 *
 * `ErrorResponseLike` is STRUCTURAL for exactly this reason — its docblock says so, naming
 * apps/mobile's `expo/fetch` response as the other non-`Response` implementer. So the whole error
 * path is `@kb/contracts`': the envelope guard, `retry_after` off the `Retry-After` HEADER (it is
 * not in the JSON), the envelope-wins-over-header `request_id` rule, the 422 `errors` map that
 * `applyServerErrors` needs, and `actionable`. None of it is re-derived here, which is the point:
 * a second copy of that mapping drifts on the parts with no visible symptom.
 */
function responseLike(xhr: XMLHttpRequest): ErrorResponseLike {
  return {
    status: xhr.status,
    headers: { get: (name: string) => xhr.getResponseHeader(name) },
    // `async`, so a non-JSON body REJECTS rather than throwing synchronously out of the call. Both
    // land inside `toKbError`'s try/catch today, but the interface says `json(): Promise<unknown>`
    // and a synchronous throw is not that — it would break the first caller that stopped awaiting
    // inside a try.
    json: async () => JSON.parse(xhr.responseText) as unknown,
  };
}

/**
 * A `ProgressEvent` → the shape the UI renders.
 *
 * `lengthComputable` is the gate and `total > 0` is the second half of it: a zero total would make
 * `loaded / total` `NaN`, `NaN` fails every comparison silently, and `<Progress value={NaN}>`
 * renders `translateX(-NaN%)` — which paints nothing at all, with no error anywhere.
 *
 * ── EXPORTED, AND STRUCTURALLY TYPED, BECAUSE THE HARNESS CANNOT REACH IT OTHERWISE ─────────────
 * MSW's service worker answers an intercepted XHR without the browser writing the body to a
 * network, so `xhr.upload.progress` fires ZERO times under the component harness — measured on
 * chromium / msw 2.15.0, and asserted in tests/components/upload-transport.test.tsx so the day that
 * changes, the note goes red instead of stale. That leaves this mapping — the `NaN` guard, the
 * floor, the clamp — reachable by no test at all if it stays private.
 *
 * The parameter is a STRUCTURAL subset of `ProgressEvent` rather than the DOM type, for the same
 * reason `ErrorResponseLike` is structural in @kb/contracts: it lets the `unit` project, which runs
 * in node where `ProgressEvent` does not exist, drive it directly. A real `ProgressEvent` satisfies
 * it, so the call site above needs no cast.
 */
export function toUploadProgress(event: {
  readonly lengthComputable: boolean;
  readonly loaded: number;
  readonly total: number;
}): UploadProgress {
  if (!event.lengthComputable || event.total <= 0) {
    return { loaded: event.loaded, total: null, percent: null };
  }

  // Floored, never rounded: `Math.round` reaches 100 while bytes are still in flight, and a bar
  // that says 100% for the last two seconds of a large upload is the determinate-bar-parked-at-100
  // failure P13 warns about wearing a different number.
  const percent = Math.min(100, Math.floor((event.loaded / event.total) * 100));
  return { loaded: event.loaded, total: event.total, percent };
}

/**
 * The signal's `reason`, guaranteed to be an `Error`.
 *
 * `AbortSignal.reason` defaults to a `DOMException` named `AbortError`, which is what every caller
 * branches on — but `abort(reason)` accepts ANY value, including a string, and a rejected promise
 * carrying a string breaks `error.name === 'AbortError'` at every call site at once. Normalising
 * here keeps that check honest without taking the caller's reason away when it is already an Error.
 */
function abortReason(signal: AbortSignal): Error {
  const reason: unknown = signal.reason;
  return reason instanceof Error ? reason : new DOMException('Upload cancelled.', 'AbortError');
}
