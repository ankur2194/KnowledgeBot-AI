import { KbError } from '@kb/contracts';
import { HttpResponse, delay, http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { uploadSourceFile } from '@/features/sources/api';
import { uploadFile, type UploadProgress } from '@/lib/api/upload';

import { ORIGIN, envelope } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * `lib/api/upload.ts` against a real transport.
 *
 * ── WHY THIS SPEC IS IN `tests/components/` AND CARRIES A `.tsx` SUFFIX IT DOES NOT NEED ────────
 * It renders nothing. It is here because `XMLHttpRequest` is a BROWSER global and the `unit`
 * project is `environment: 'node'`, where the constructor does not exist — a node spec would fail on
 * the first line of the code under test rather than on an assertion. The `components` project's glob
 * is `tests/components/ **\/*.test.tsx`, so the extension is what makes the file run at all. The
 * alternative (a jsdom project) is refused for the reasons vitest-playwright gives: jsdom drops the
 * stream and fetch globals the rest of the suite needs, and it has no layout.
 *
 * ── MSW INTERCEPTS XHR HERE BECAUSE THE TRANSPORT IS A SERVICE WORKER, NOT A NODE PATCH ────────
 * Browser Mode runs the tests IN chromium and `tests/msw/setup.ts` uses `setupWorker`, so
 * interception happens at the service worker, which sees every request the page makes regardless of
 * which API issued it. `onUnhandledRequest: 'error'` under that transport answers `500 Request
 * Handler Error` rather than rejecting the fetch — measured, and asserted in msw-harness.test.tsx —
 * so a spec that forgets a handler fails on a status, not a timeout.
 */

const ORG = '01JORGAAAAAAAAAAAAAAAAAAAA';
const SOURCES = `${ORIGIN}/api/v1/organizations/${ORG}/sources`;

/** The credential is an ARGUMENT, never an ambient assumption — the whole design of `Credential`. */
const SESSION = { kind: 'session', xsrf_token: 'decoded-token' } as const;
const CHAT = { kind: 'chat_session', token: 'opaque-chat-token' } as const;

const pdf = (name = 'quarterly-report.pdf', bytes = 64): File =>
  new File([new Uint8Array(bytes)], name, { type: 'application/pdf' });

beforeEach(() => {
  // MSW cannot set a cookie for a cross-origin host from a service worker, so every spec that
  // reaches `sessionCredential()` seeds the page's own cookie or the code under test takes the
  // `refreshCsrfToken()` path and throws for a reason unrelated to the assertion.
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the multipart body', () => {
  it('sends the file under the part name the caller asked for, with its filename intact', async () => {
    let seen: { part: unknown; name: string | null; type: string | null } | null = null;

    worker.use(
      http.post(SOURCES, async ({ request }) => {
        const body = await request.formData();
        const part = body.get('files[0]');
        seen = {
          part,
          name: part instanceof File ? part.name : null,
          // NOT a header we set. `xhr.send(FormData)` makes the ENGINE write
          // `multipart/form-data; boundary=…`; setting the header by hand overrides it with a value
          // carrying no boundary, PHP finds no parts, and the 422 says the file is required over a
          // request whose body contained the whole file.
          type: request.headers.get('content-type'),
        };
        return HttpResponse.json({ data: { id: '01JSOURCE' } }, { status: 201 });
      }),
    );

    await uploadFile<{ id: string }>({
      path: `/api/v1/organizations/${ORG}/sources`,
      method: 'POST',
      file: pdf(),
      field: 'files[0]',
      credential: SESSION,
    });

    const captured = seen as unknown as { name: string | null; type: string | null } | null;
    expect(captured?.name).toBe('quarterly-report.pdf');
    expect(captured?.type).toMatch(/^multipart\/form-data; boundary=/);
  });

  it('writes the scalar allow-list before the file, and sends nothing the caller did not name', async () => {
    let keys: string[] = [];

    worker.use(
      http.post(SOURCES, async ({ request }) => {
        keys = [...(await request.formData()).keys()];
        return HttpResponse.json({ data: null }, { status: 201 });
      }),
    );

    await uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf(),
      field: 'files[0]',
      fields: { title: 'Q3 report' },
      credential: SESSION,
    });

    // The file LAST, so a streaming parser on the server sees every scalar before it buffers
    // megabytes. And exactly two parts: `fields` is an allow-list a caller types out, so there is no
    // spread that could carry an ownership column into a payload no schema parses.
    expect(keys).toEqual(['title', 'files[0]']);
  });

  it('resolves the UNWRAPPED `data`, so no render site knows about the envelope', async () => {
    worker.use(
      http.post(SOURCES, () => HttpResponse.json({ data: { id: '01JSOURCE', status: 'queued' } })),
    );

    await expect(
      uploadFile<{ id: string; status: string }>({
        path: `/api/v1/organizations/${ORG}/sources`,
        file: pdf(),
        credential: SESSION,
      }),
    ).resolves.toEqual({ id: '01JSOURCE', status: 'queued' });
  });
});

describe('the credential switch', () => {
  it('sends the XSRF header on the session path', async () => {
    let header: string | null = null;
    worker.use(
      http.post(SOURCES, ({ request }) => {
        header = request.headers.get('x-xsrf-token');
        return HttpResponse.json({ data: null });
      }),
    );

    await uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf(),
      credential: SESSION,
    });

    // Already URL-DECODED by `sessionCredential()`. Laravel compares the header to the decrypted
    // cookie value, and `%3D` padding echoed verbatim never matches.
    expect(header as unknown as string | null).toBe('decoded-token');
  });

  it('sends a bearer and no XSRF header on the hosted-chat path', async () => {
    let authorization: string | null = null;
    let xsrf: string | null = null;
    worker.use(
      http.post(SOURCES, ({ request }) => {
        authorization = request.headers.get('authorization');
        xsrf = request.headers.get('x-xsrf-token');
        return HttpResponse.json({ data: null });
      }),
    );

    await uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf(),
      credential: CHAT,
    });

    expect(authorization as unknown as string | null).toBe('Bearer opaque-chat-token');
    // `withCredentials = false` is a security control rather than a default: the session cookie is
    // scoped to app. and api., so including it is silently empty on chat. today and becomes a
    // cross-surface credential leak the day that scope widens by one config line.
    expect(xsrf as unknown as string | null).toBeNull();
  });

  it('sends an Idempotency-Key only when one was supplied', async () => {
    const keys: (string | null)[] = [];
    worker.use(
      http.post(SOURCES, ({ request }) => {
        keys.push(request.headers.get('idempotency-key'));
        return HttpResponse.json({ data: null });
      }),
    );

    const base = { path: `/api/v1/organizations/${ORG}/sources`, credential: SESSION } as const;
    await uploadFile<null>({ ...base, file: pdf() });
    await uploadFile<null>({ ...base, file: pdf(), idempotency_key: '01JIDEMPOTENT' });

    // A request without one must never be retried by anything, including a double-click.
    expect(keys).toEqual([null, '01JIDEMPOTENT']);
  });
});

describe('failures reject with the ONE KbError', () => {
  it('carries the 422 field map keyed as the wire keyed it', async () => {
    worker.use(
      http.post(SOURCES, () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { 'files.0': ['A PDF that is really a ZIP is not accepted.'] },
          }),
          { status: 422 },
        ),
      ),
    );

    const failure = await uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf(),
      credential: SESSION,
    }).catch((error: unknown) => error);

    // `instanceof` against the class from @kb/contracts. A per-app copy makes this false and the
    // retry predicate in lib/query/client.ts then treats every error as permanent, silently.
    expect(failure).toBeInstanceOf(KbError);
    const error = failure as KbError;
    expect(error.error_class).toBe('validation');
    expect(error.errors).toEqual({
      'files.0': ['A PDF that is really a ZIP is not accepted.'],
    });
    // `files.0` and not `files` — the index is what `reindexFileErrors` moves onto the row.
    expect(Object.keys(error.errors ?? {})).toEqual(['files.0']);
  });

  it('takes `retry_after` off the HEADER, which is not in the JSON', async () => {
    worker.use(
      http.post(SOURCES, () =>
        HttpResponse.json(envelope('rate_limit', { retryable: true }), {
          status: 429,
          headers: { 'Retry-After': '42', 'X-KB-Request-Id': '01JFROMHEADER' },
        }),
      ),
    );

    const error = (await uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf(),
      credential: SESSION,
    }).catch((cause: unknown) => cause)) as KbError;

    expect(error.retry_after).toBe(42);
    expect(error.retryable).toBe(true);
    // The ENVELOPE wins over the header, and the fixture's envelope carries its own id.
    expect(error.request_id).toBe('01JREQFROMLARAVEL');
  });

  it('yields `error_class: null` for a response with no parseable envelope, and keeps the request id', async () => {
    worker.use(
      http.post(
        SOURCES,
        () =>
          new HttpResponse('<html>502 Bad Gateway</html>', {
            status: 502,
            headers: { 'X-KB-Request-Id': '01JPROXYPAGE' },
          }),
      ),
    );

    const error = (await uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf(),
      credential: SESSION,
    }).catch((cause: unknown) => cause)) as KbError;

    // Unknown, and unknown is permanently non-retryable. No class is invented to fill the slot —
    // and in particular not `internal_dependency`, which is retryable AND pages.
    expect(error.error_class).toBeNull();
    expect(error.retryable).toBe(false);
    // The one string a user is asked to quote back survives a body that never had an envelope.
    expect(error.request_id).toBe('01JPROXYPAGE');
  });

  it('refuses to resolve a 2xx whose body is not JSON', async () => {
    worker.use(
      http.post(
        SOURCES,
        () =>
          new HttpResponse('<html>captive portal</html>', {
            status: 200,
            headers: { 'X-KB-Request-Id': '01JPORTAL' },
          }),
      ),
    );

    const failure = await uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf(),
      credential: SESSION,
    }).catch((cause: unknown) => cause);

    // Resolving would hand the caller `undefined` as if the source had been created.
    expect(failure).toBeInstanceOf(KbError);
    expect((failure as KbError).error_class).toBeNull();
    expect((failure as KbError).request_id).toBe('01JPORTAL');
  });
});

describe('cancellation', () => {
  it('aborts the request and rejects with an AbortError, NOT a KbError', async () => {
    let handlerEntered = false;
    worker.use(
      http.post(SOURCES, async () => {
        handlerEntered = true;
        await delay(500);
        return HttpResponse.json({ data: { id: 'must-never-arrive' } });
      }),
    );

    const controller = new AbortController();
    const pending = uploadFile<{ id: string }>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf('cancelled-upload.pdf', 4096),
      credential: SESSION,
      signal: controller.signal,
    });

    await vi.waitFor(() => {
      expect(handlerEntered).toBe(true);
    });
    controller.abort();

    const failure = await pending.catch((cause: unknown) => cause);

    // CANCELLATION IS AN OUTCOME, NOT A FAILURE. `stream-answer.ts` reads the same name and treats
    // it as "no error state at all"; dressing a user's own Cancel click as an error class would
    // render a red row for the thing they just asked for.
    expect(failure).toBeInstanceOf(Error);
    expect((failure as Error).name).toBe('AbortError');
    expect(failure).not.toBeInstanceOf(KbError);
  });

  it('rejects immediately when the signal was already aborted, without opening a request', async () => {
    let requests = 0;
    worker.use(
      http.post(SOURCES, () => {
        requests += 1;
        return HttpResponse.json({ data: null });
      }),
    );

    const failure = await uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf(),
      credential: SESSION,
      signal: AbortSignal.abort(),
    }).catch((cause: unknown) => cause);

    expect((failure as Error).name).toBe('AbortError');
    // A row cancelled while its turn in the queue was still pending must not produce a request that
    // is then torn down — and `xhr.abort()` on an unsent request is a no-op in some engines, which
    // would leave the promise pending forever.
    expect(requests).toBe(0);
  });

  it('normalises a non-Error abort reason, so `error.name === "AbortError"` stays honest', async () => {
    worker.use(
      http.post(SOURCES, async () => {
        await delay(500);
        return HttpResponse.json({ data: null });
      }),
    );

    const controller = new AbortController();
    const pending = uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      file: pdf('reason-string.pdf'),
      credential: SESSION,
      signal: controller.signal,
    });

    // `abort(reason)` accepts ANY value. A rejected promise carrying a bare string breaks the name
    // check at every call site at once.
    controller.abort('user pressed cancel');

    const failure = await pending.catch((cause: unknown) => cause);
    expect(failure).toBeInstanceOf(Error);
    expect((failure as Error).name).toBe('AbortError');
  });
});

/**
 * ── THE ONE THING THIS HARNESS STRUCTURALLY CANNOT PROVE, ASSERTED SO IT CANNOT GO STALE ────────
 *
 * `xhr.upload.progress` fires ZERO times when MSW's service worker answers the request. Measured on
 * chromium via @vitest/browser-playwright 4.1.10 and msw 2.15.0, and it is not a bug in the code
 * under test: the worker resolves the request inside the page, so the browser never writes the body
 * to a network and has no bytes-sent milestones to report. The same upload against a real Laravel
 * emits them normally.
 *
 * That is the browser-side twin of the rule vitest-playwright states for the chat path — a mocked
 * transport cannot prove a streaming behaviour — and it is why the progress coverage is split three
 * ways rather than faked here:
 *
 *   · the MAPPING (the NaN guard, the floor, the clamp, the indeterminate case) is
 *     `toUploadProgress`, exported and driven directly by tests/unit/upload-progress.test.ts;
 *   · the WIRING (a progress event reaching a bar, a percentage and a live region) is
 *     tests/components/upload-dropzone.test.tsx, through the hook's injected uploader;
 *   · that real bytes produce real events is left to Playwright against a real server, and is
 *     recorded here as unproven rather than asserted from a fixture.
 *
 * The assertion below pins the LIMITATION. If MSW or chromium starts emitting upload progress, this
 * goes red, and the correct response is to delete this test and restore a real one — not to relax
 * it. A note in a comment would have quietly become false instead.
 */
describe('progress', () => {
  it('is not observable under the service-worker harness, which is why it is proved elsewhere', async () => {
    worker.use(http.post(SOURCES, () => HttpResponse.json({ data: null })));

    const events: UploadProgress[] = [];

    await uploadFile<null>({
      path: `/api/v1/organizations/${ORG}/sources`,
      // Half a megabyte: large enough that a real network would emit several milestones, so this
      // assertion is about the transport being mocked and not about the body being small.
      file: pdf('large-enough.pdf', 512 * 1024),
      credential: SESSION,
      on_progress: (progress) => events.push(progress),
    });

    expect(
      events,
      'MSW now reports upload progress — delete this test and assert the real behaviour',
    ).toEqual([]);
  });

  it('still passes the callback through without it ever being required', async () => {
    // The negative control on the test above: the upload SUCCEEDS with a progress callback attached
    // and zero events, so "no events" above is the harness rather than a request that never ran.
    worker.use(http.post(SOURCES, () => HttpResponse.json({ data: { id: '01JSOURCE' } })));

    await expect(
      uploadFile<{ id: string }>({
        path: `/api/v1/organizations/${ORG}/sources`,
        file: pdf(),
        credential: SESSION,
        on_progress: () => {},
      }),
    ).resolves.toEqual({ id: '01JSOURCE' });
  });
});

describe('uploadSourceFile', () => {
  it('posts to the org-scoped path with the indexed part name the server’s rules expect', async () => {
    let path: string | null = null;
    let partNames: string[] = [];

    worker.use(
      http.post(SOURCES, async ({ request }) => {
        path = new URL(request.url).pathname;
        partNames = [...(await request.formData()).keys()];
        return HttpResponse.json({ data: { id: '01JSOURCE' } }, { status: 201 });
      }),
    );

    await uploadSourceFile(ORG, pdf('handbook.pdf'), {
      signal: new AbortController().signal,
      on_progress: () => {},
    });

    // The organization is a ROUTING HINT in the path — `TenantContext` re-reads the membership row
    // per request and Laravel would ignore a client-supplied organization. The SCOPE is the session.
    expect(path as unknown as string | null).toBe(`/api/v1/organizations/${ORG}/sources`);
    // `files[0]`, not `file`: one file per request is a CLIENT decision, and sending the array shape
    // means the FormRequest declares `files`/`files.*` whether it receives one part or five — which
    // is what makes its 422 key `files.0` and therefore matchable against `files.*`.
    expect(partNames).toEqual(['files[0]']);
  });
});
