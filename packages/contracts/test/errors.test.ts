import { describe, expect, it } from 'vitest';

import { ERROR_CLASSES, STREAM_LOST, isErrorClass } from '../src/error-classes.js';
import { KbError, parseRetryAfter, toKbError } from '../src/errors.js';

/**
 * A stand-in for the three members `toKbError` reads. Not a `Response`: the whole reason the
 * parameter is structural is that `apps/mobile` hands it an `expo/fetch` response, which is a
 * different type from the DOM `Response` `apps/web` hands it.
 */
const responseLike = (
  status: number,
  headers: Record<string, string>,
  body: () => Promise<unknown>,
) => ({
  status,
  headers: { get: (name: string) => headers[name.toLowerCase()] ?? null },
  json: body,
});

describe('the 18 classes', () => {
  it('is exactly 18 and does not contain the client-local sentinel', () => {
    expect(ERROR_CLASSES).toHaveLength(18);
    expect(new Set(ERROR_CLASSES).size).toBe(18);
    expect(isErrorClass(STREAM_LOST)).toBe(false);
  });
});

describe('KbError', () => {
  it('carries the envelope field names verbatim, snake_case', () => {
    const error = new KbError('rate_limit', true, 30, '01JABCDEF', 'upstream said no');
    expect(error.error_class).toBe('rate_limit');
    expect(error.retry_after).toBe(30);
    expect(error.request_id).toBe('01JABCDEF');
    // The camelCase spellings are the bug that turns every retryable class permanent.
    expect((error as unknown as Record<string, unknown>)['errorClass']).toBeUndefined();
    expect((error as unknown as Record<string, unknown>)['retryAfter']).toBeUndefined();
  });

  /**
   * ADR-029 / finding O1. `origin` is not a wire field and there is nothing on `KbError` to branch
   * on — which is exactly why this is asserted here. The two `internal_dependency` sub-cases are
   * indistinguishable by class name; `retryable` is the only thing that separates them, so any
   * consumer deriving retryability from `error_class` alone is wrong for this row.
   */
  it('carries both internal_dependency sub-cases under one class name', () => {
    const downstream = new KbError('internal_dependency', true); // 503
    const ourDefect = new KbError('internal_dependency', false); // 500
    expect(downstream.error_class).toBe(ourDefect.error_class);
    expect(downstream.retryable).toBe(true);
    expect(ourDefect.retryable).toBe(false);
    // No `origin` anywhere on the instance: it selects a status and a flag, and both already exist.
    expect((ourDefect as unknown as Record<string, unknown>)['origin']).toBeUndefined();
  });

  it('is an Error and survives instanceof — the retry predicate depends on it', () => {
    const error = new KbError(null, false);
    expect(error).toBeInstanceOf(Error);
    expect(error).toBeInstanceOf(KbError);
    expect(error.message).toBe('unknown');
  });
});

describe('parseRetryAfter', () => {
  it('reads delta-seconds', () => {
    expect(parseRetryAfter('30')).toBe(30);
    expect(parseRetryAfter(' 30 ')).toBe(30);
  });

  it('reads an HTTP-date', () => {
    const seconds = parseRetryAfter(new Date(Date.now() + 30_000).toUTCString());
    expect(seconds).toBeGreaterThan(27);
    expect(seconds).toBeLessThanOrEqual(30);
  });

  it('clamps a past date to zero rather than returning a negative delay', () => {
    expect(parseRetryAfter(new Date(Date.now() - 60_000).toUTCString())).toBe(0);
  });

  it('returns null for absent or unparseable headers', () => {
    expect(parseRetryAfter(null)).toBeNull();
    expect(parseRetryAfter('')).toBeNull();
    expect(parseRetryAfter('soon')).toBeNull();
    expect(parseRetryAfter('1e3')).toBeNull();
  });
});

/**
 * `toKbError` lives here rather than in either app because the mapping is now needed identically by
 * `apps/web` and `apps/mobile`. Every assertion below is about a field that has NO visible symptom
 * when it is wrong: a dropped `retry_after` retries inside the window it was told to wait, a
 * re-derived `retryable` retries a defect forever, and an invented class name on a malformed
 * response pages somebody.
 */
describe('toKbError', () => {
  const envelope = {
    error_class: 'rate_limit',
    message: 'bucket kb:rl:org_01J:bot_01K exhausted on node api-7.internal',
    retryable: true,
    request_id: '01JREQ',
  };

  it('carries the envelope through and takes retry_after off the HEADER, not the JSON', async () => {
    const error = await toKbError(
      responseLike(429, { 'retry-after': '30' }, () => Promise.resolve(envelope)),
    );

    expect(error).toBeInstanceOf(KbError);
    expect(error.error_class).toBe('rate_limit');
    expect(error.retryable).toBe(true);
    expect(error.request_id).toBe('01JREQ');
    // 30 SECONDS. The envelope has no such key, so a mapper that only reads the body silently
    // produces null and the caller retries immediately, inside the window it was told to wait.
    expect(error.retry_after).toBe(30);
    // Operator-facing: it carries an internal hostname. Kept on the instance to be logged, never
    // rendered — the UI shows a class-mapped sentence plus the request_id.
    expect(error.message).toContain('api-7.internal');
  });

  it('is a straight carry of `retryable`, never a re-derivation from the class name', async () => {
    // The `self` sub-case: an unmapped exception in our own code, 500, NOT retryable, under the
    // same class name as the 503 brownout. The axis is deliberately not on the wire (ADR-029), so
    // the flag is the only thing that can say it.
    const error = await toKbError(
      responseLike(500, {}, () =>
        Promise.resolve({
          error_class: 'internal_dependency',
          message: 'TypeError',
          retryable: false,
        }),
      ),
    );

    expect(error.error_class).toBe('internal_dependency');
    expect(error.retryable).toBe(false);
  });

  it('yields error_class null when no envelope parsed — never an invented class', async () => {
    const notJson = await toKbError(
      responseLike(502, {}, () => Promise.reject(new SyntaxError('Unexpected token <'))),
    );
    expect(notJson.error_class).toBeNull();
    expect(notJson.retryable).toBe(false);
    expect(notJson.message).toBe('HTTP 502');

    // A body that IS JSON but is not our envelope is the same verdict. `internal_dependency` would
    // be the tempting guess for a 502 and it is the worst one available: retryable AND it pages.
    const wrongShape = await toKbError(
      responseLike(502, {}, () => Promise.resolve({ error: 'Bad Gateway' })),
    );
    expect(wrongShape.error_class).toBeNull();
    expect(wrongShape.retryable).toBe(false);
  });

  it('keeps retry_after from an unparseable body — the header is valid either way', async () => {
    const error = await toKbError(
      responseLike(503, { 'retry-after': '120' }, () => Promise.reject(new Error('empty body'))),
    );
    expect(error.error_class).toBeNull();
    expect(error.retry_after).toBe(120);
  });

  it('defaults request_id to null when neither the envelope nor a header carries one', async () => {
    const error = await toKbError(
      responseLike(403, {}, () =>
        Promise.resolve({ error_class: 'authorization', message: 'denied', retryable: false }),
      ),
    );
    expect(error.request_id).toBeNull();
    expect(error.retry_after).toBeNull();
  });
});

/**
 * The 422 `errors` map (finding F-2). `browserFetch` throws `KbError`, not the envelope, so before
 * this the map was declared on the envelope, exported a type guard for, documented in
 * `applyServerErrors` — and read by nothing. Every per-field 422 mapping in apps/web was dead code
 * with no visible symptom: the form simply showed a generic banner where the server had computed a
 * message per field.
 *
 * Both assertions are about a value that fails silently. Nothing throws when `errors` is dropped;
 * the user is told "Please check your input" and is not told which input.
 */
describe('toKbError and the validation errors map', () => {
  it('round-trips the 422 field map, dot-paths and all', async () => {
    const error = await toKbError(
      responseLike(422, {}, () =>
        Promise.resolve({
          error_class: 'validation',
          message: 'The given data was invalid.',
          retryable: false,
          request_id: '01JVALID',
          errors: {
            email: ['These credentials do not match our records.'],
            // Laravel dot-paths nested and indexed fields, and those are valid
            // react-hook-form names as-is — no rename layer, here or in apps/web.
            'retrieval.top_k': ['The retrieval.top_k must be at least 1.'],
            'starter_questions.2': ['Too long.'],
          },
        }),
      ),
    );

    expect(error.error_class).toBe('validation');
    expect(error.errors).toEqual({
      email: ['These credentials do not match our records.'],
      'retrieval.top_k': ['The retrieval.top_k must be at least 1.'],
      'starter_questions.2': ['Too long.'],
    });
    // The keys are what `applyServerErrors` routes on: a known path goes to `setError(path)`, an
    // unknown one to `root.serverError`. A dropped map sends every field error to neither.
    expect(Object.keys(error.errors ?? {})).toContain('starter_questions.2');
  });

  it('yields errors === null on a class that carries no map — never {}', async () => {
    const error = await toKbError(
      responseLike(403, {}, () =>
        Promise.resolve({
          error_class: 'authorization',
          message: 'This action is not permitted.',
          retryable: false,
        }),
      ),
    );

    expect(error.error_class).toBe('authorization');
    // NULL, not `{}`. An empty map reads as "the server sent field errors and there were none",
    // which is not a state that exists, and `Object.keys(e.errors).length === 0` is the check a
    // caller would then write instead of `e.errors === null`.
    expect(error.errors).toBeNull();
  });

  it('yields errors === null when no envelope parsed at all', async () => {
    const error = await toKbError(
      responseLike(502, {}, () => Promise.reject(new SyntaxError('Unexpected token <'))),
    );
    expect(error.error_class).toBeNull();
    expect(error.errors).toBeNull();
  });

  it('defaults to null on the constructor, so no existing call site had to change', () => {
    // The five-argument form is what apps/web, apps/widget and apps/mobile all use (verified: no
    // call site anywhere passes six). `errors` is SIXTH for exactly that reason — beside
    // `error_class`, where it reads better, it would have broken every one of them.
    const error = new KbError(STREAM_LOST, true, null, null, 'connection lost mid-answer');
    expect(error.errors).toBeNull();
  });
});

/**
 * `actionable` (finding J2). Whether the envelope's `message` was written for THIS condition and may
 * be shown to an operator, or is a fixed placeholder chosen to say nothing.
 *
 * WHY IT IS ON THE WIRE AT ALL. A deliberate 4xx our own code raised and an unhandled exception both
 * render `internal_dependency` with `retryable: false` — the taxonomy has no 409 row, on purpose —
 * so the two were structurally the same envelope, and a client wanting to render the actionable one
 * had to compare `message` against a copy of the server's 5xx constant.
 *
 * EVERY ASSERTION HERE IS ABOUT A FAIL-CLOSED DEFAULT, which is the half with no visible symptom
 * when it is wrong in the other direction: a spurious `true` renders an internal hostname to a
 * tenant, and nothing throws.
 */
describe('toKbError and `actionable`', () => {
  const conflict = {
    error_class: 'internal_dependency',
    message: 'Clear the embedding designation first, then delete this connection.',
    retryable: false,
    request_id: '01JREQ',
  };

  it('carries a true flag through, so a deliberate 4xx is renderable', async () => {
    const error = await toKbError(
      responseLike(409, {}, () => Promise.resolve({ ...conflict, actionable: true })),
    );

    expect(error.actionable).toBe(true);
    // The pair it shares with a defect. `actionable` is the ONLY thing separating them, which is
    // why this asserts them together rather than trusting the status the fixture was given.
    expect(error.error_class).toBe('internal_dependency');
    expect(error.retryable).toBe(false);
  });

  it('is false for the same class and status when the envelope says so', async () => {
    const error = await toKbError(
      responseLike(500, {}, () =>
        Promise.resolve({
          error_class: 'internal_dependency',
          message: 'The service could not complete this request.',
          retryable: false,
          request_id: '01JREQ',
          actionable: false,
        }),
      ),
    );

    expect(error.actionable).toBe(false);
  });

  it('is false when the envelope omits the key — the SSE `error` frame does exactly that', async () => {
    // The field is optional on `KbErrorEnvelope` because that interface is the union of the HTTP
    // body and the SSE frame, and the frame carries three fields. Absent must read as false.
    const error = await toKbError(responseLike(409, {}, () => Promise.resolve(conflict)));

    expect(error.actionable).toBe(false);
  });

  it('is false for anything that is not the literal boolean true', async () => {
    // `=== true`, not `??` and not a cast. `isKbErrorEnvelope` does not type-check this field, so a
    // body carrying `"yes"` reaches here; the only safe reading of a non-boolean is "do not render".
    for (const value of ['true', 1, {}, [], null]) {
      const error = await toKbError(
        responseLike(409, {}, () => Promise.resolve({ ...conflict, actionable: value })),
      );
      expect(error.actionable).toBe(false);
    }
  });

  it('is false on the no-envelope branch, where there is no server sentence at all', async () => {
    const error = await toKbError(responseLike(502, {}, () => Promise.reject(new Error('nope'))));

    expect(error.error_class).toBeNull();
    expect(error.actionable).toBe(false);
  });

  it('defaults to false on the constructor, so a six-argument call site is unchanged', () => {
    // SEVENTH and defaulted, for the same reason `errors` is sixth. The default is the OPPOSITE of
    // the server-side one deliberately: on the server the raiser knows it wrote a sentence, while
    // every construction site here has no envelope behind it.
    const error = new KbError(STREAM_LOST, true, null, null, 'connection lost mid-answer');
    expect(error.actionable).toBe(false);
  });
});

/**
 * `X-KB-Request-Id` (finding #69b). `request_id` is the ONE identifier a user is ever shown and the
 * one string a support engineer can grep across both planes — and before this it was read only off
 * the parsed envelope, so it was null on exactly the failures people have to debug: a proxy's 502
 * page, a truncated body, an authentication envelope stamped before the id was bound.
 *
 * Every assertion below is about a value with NO visible symptom when it is wrong. A dropped
 * request_id does not throw; `endUserCopy` simply drops the "(ref …)" suffix, and the user reports
 * "Something went wrong" with nothing attached.
 */
describe('toKbError and the X-KB-Request-Id header', () => {
  it('reads it off the header when NO envelope parsed — the whole point of that branch', async () => {
    // The proxy page: an HTML 502 that never had an envelope. Laravel/Traefik still stamped the
    // header, so there is a reference to hand support even though the body is unparseable.
    const error = await toKbError(
      responseLike(502, { 'x-kb-request-id': '01JPROXY' }, () =>
        Promise.reject(new SyntaxError('Unexpected token <')),
      ),
    );

    expect(error.error_class).toBeNull();
    expect(error.retryable).toBe(false);
    expect(error.request_id).toBe('01JPROXY');
  });

  it('reads it off the header for a body that is JSON but is not our envelope', async () => {
    const error = await toKbError(
      responseLike(503, { 'x-kb-request-id': '01JGATEWAY' }, () =>
        Promise.resolve({ error: 'Service Unavailable' }),
      ),
    );
    expect(error.error_class).toBeNull();
    expect(error.request_id).toBe('01JGATEWAY');
  });

  it('prefers the ENVELOPE when the two disagree', async () => {
    // They agree by construction — one middleware mints both — so a disagreement means a hop in
    // between re-stamped the header. The envelope's value was written by the code that classified
    // the failure and logged it under that id, so that is the one support can search for.
    const error = await toKbError(
      responseLike(429, { 'x-kb-request-id': '01JRELAY' }, () =>
        Promise.resolve({
          error_class: 'rate_limit',
          message: 'bucket exhausted',
          retryable: true,
          request_id: '01JORIGIN',
        }),
      ),
    );
    expect(error.request_id).toBe('01JORIGIN');
  });

  it('falls back to the header when the envelope carries request_id: NULL', async () => {
    // Not hypothetical (5B-S2): FastAPI's `verify_hmac` runs before `request_context` stamps the
    // id, so every authentication-failure envelope from that plane carries an explicit null today.
    // `??` treats that as an absence rather than a decision, which is the only reading that leaves
    // a reference on the error people are most likely to be looking at.
    const error = await toKbError(
      responseLike(401, { 'x-kb-request-id': '01JHEADER' }, () =>
        Promise.resolve({
          error_class: 'authentication',
          message: 'signature mismatch',
          retryable: false,
          request_id: null,
        }),
      ),
    );
    expect(error.error_class).toBe('authentication');
    expect(error.request_id).toBe('01JHEADER');
  });

  it('falls back to the header when the envelope omits the key entirely', async () => {
    const error = await toKbError(
      responseLike(403, { 'x-kb-request-id': '01JHEADER' }, () =>
        Promise.resolve({ error_class: 'authorization', message: 'denied', retryable: false }),
      ),
    );
    expect(error.request_id).toBe('01JHEADER');
  });

  it('is case-insensitive on the header name, as HTTP is', async () => {
    // `Headers.get` lowercases; a hand-rolled record (apps/mobile's expo/fetch response, the
    // fixture above) may not. Reading the lowercase spelling is what makes both work.
    const error = await toKbError(
      responseLike(500, { 'X-KB-Request-Id': '01JMIXED' } as Record<string, string>, () =>
        Promise.reject(new Error('empty body')),
      ),
    );
    // The fixture's `get` lowercases the lookup key, so a mixed-case RECORD key does NOT match —
    // which is the honest simulation of a client that does not normalize. Asserted so the next
    // reader does not mistake this for the DOM `Headers` behaviour.
    expect(error.request_id).toBeNull();
  });

  it('treats a present-but-blank header as an absence, not as an id', async () => {
    // `''` is truthy enough for `??` to keep it, and the user would be shown "(ref )".
    const error = await toKbError(
      responseLike(500, { 'x-kb-request-id': '   ' }, () => Promise.reject(new Error('no body'))),
    );
    expect(error.request_id).toBeNull();
  });

  it('trims surrounding whitespace, exactly as parseRetryAfter does', async () => {
    const error = await toKbError(
      responseLike(500, { 'x-kb-request-id': ' 01JPADDED ' }, () =>
        Promise.reject(new Error('no body')),
      ),
    );
    expect(error.request_id).toBe('01JPADDED');
  });
});
