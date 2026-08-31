import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  MINT_FAILED,
  REFRESH_FAILED,
  SESSION_MALFORMED,
  attachBridge,
  readSessionGrant,
  resetMalformedWarningForTest,
} from '../../src/loader/bridge.js';
import { KB } from '../../src/bridge/protocol.js';
// The PHP-generated contract, read as data. See the last describe block for why.
import openapi from '@kb/contracts/openapi/core-api.openapi.json' with { type: 'json' };

/**
 * THE MINT RESPONSE CONTRACT, AND THE TWO CALL SITES THAT READ IT.
 *
 * `POST sdk/v1/session` answers `{"data": {"token": …, "expires_in": …}}`. The loader read `token`
 * at the TOP LEVEL, which is `undefined` — and `undefined` does not throw. It was posted over the
 * bridge toward a header that would read `Bearer undefined`, every later request 401ed, and NOTHING
 * appeared in either console. That is indistinguishable from a wrong bot id and from an unlisted
 * origin, both of which are deliberately silent 404s on this surface.
 *
 * THE ORIGINAL CODE PASSED ITS TESTS, so a happy path against a fixture written to match the parser
 * proves nothing here. Every test below is therefore anchored on one of two things the old code
 * could not have satisfied:
 *
 *   1. a WRAPPED body — byte-shaped like `ChatSessionResource` — must yield a real token;
 *   2. an UNWRAPPED or otherwise malformed body must be REFUSED with a named, visible failure,
 *      never coerced and never allowed to leave as `undefined`.
 *
 * And the second describe drives BOTH call sites, because the fix has to reach both: `ready` mints
 * the first grant and `session-expiring` renews it. A widget fixed only at `ready` works for the
 * length of one grant — about fifteen minutes — and then dies exactly as quietly as before.
 *
 * WHAT THIS LAYER DOES NOT PROVE, stated so nobody reads it as cover. It supplies the message
 * events itself, so it says nothing whatsoever about the `event.source` and `event.origin` checks:
 * a test that hands the listener an event carrying the expected origin passes with those checks
 * deleted, which is worse than no test. Those live in tests/e2e/origin-checks.spec.ts against the
 * two-origin Playwright harness, and the wire shape is additionally pinned there by
 * tests/harness/widget-origin.mjs, which now answers WRAPPED like the real endpoint.
 */

/** Byte-shaped like `ChatSessionResource`: a `data` wrapper, an opaque `kbw_` bearer, and a
 *  DURATION in seconds. Not a shape invented to match the parser under test. */
const WRAPPED = { data: { token: 'kbw_01J8REALLOOKINGTOKEN', expires_in: 900 } };

/** What the loader used to expect, and what the e2e harness used to serve. Kept as a named
 *  constant because it is the regression, not merely one malformed case among many. */
const UNWRAPPED = { token: 'kbw_01J8REALLOOKINGTOKEN', expires_in: 900 };

describe('readSessionGrant: the mint body is WRAPPED and the client is the side that adapts', () => {
  it('reads the wrapped body `ChatSessionResource` actually emits', () => {
    expect(readSessionGrant(WRAPPED)).toEqual({
      token: 'kbw_01J8REALLOOKINGTOKEN',
      expires_in: 900,
    });
  });

  it('the top-level read is `undefined` on that same body — which is why the defect was silent', () => {
    // Not a test of our code; a statement of the fact that made the original failure invisible.
    // `undefined` does not throw, it interpolates, and `Bearer undefined` 401s forever.
    expect((WRAPPED as unknown as { token?: unknown }).token).toBeUndefined();
    expect(`Bearer ${String((WRAPPED as unknown as { token?: unknown }).token)}`).toBe(
      'Bearer undefined',
    );
  });

  it('REFUSES the unwrapped shape instead of accepting both — a liberal parser cannot report drift', () => {
    // `data.token ?? body.token` would make this pass, make the fixture above meaningless, and
    // guarantee the next envelope change is silent too.
    expect(readSessionGrant(UNWRAPPED)).toBeNull();
  });

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['a string (an HTML error page parsed as JSON text)', '{"data":{"token":"x"}}'],
    ['a number', 7],
    ['an array', [{ token: 'kbw_x', expires_in: 900 }]],
    ['an empty object', {}],
    ['a null `data`', { data: null }],
    ['a `data` that is a string', { data: 'kbw_x' }],
    ['an empty `data`', { data: {} }],
    ['a numeric token', { data: { token: 12345, expires_in: 900 } }],
    ['an EMPTY token (`Bearer ` with nothing after it)', { data: { token: '', expires_in: 900 } }],
    ['a nested envelope', { data: { data: { token: 'kbw_x', expires_in: 900 } } }],
    ['no `expires_in`', { data: { token: 'kbw_x' } }],
    ['a STRING `expires_in`', { data: { token: 'kbw_x', expires_in: '900' } }],
    ['a null `expires_in`', { data: { token: 'kbw_x', expires_in: null } }],
    ['`expires_in: 0` (expiry "now" — a refresh loop against the limiter)', {
      data: { token: 'kbw_x', expires_in: 0 },
    }],
    ['a negative `expires_in`', { data: { token: 'kbw_x', expires_in: -900 } }],
    ['`expires_in: NaN` (every comparison false — never renewed at all)', {
      data: { token: 'kbw_x', expires_in: Number.NaN },
    }],
    ['`expires_in: Infinity`', { data: { token: 'kbw_x', expires_in: Number.POSITIVE_INFINITY } }],
  ])('refuses %s', (_label, body) => {
    expect(readSessionGrant(body)).toBeNull();
  });

  it('never returns a grant whose token is anything but a non-empty string', () => {
    // The property, asserted over the whole table rather than case by case: there is no input for
    // which this function yields an object with an unusable `token`. That is the invariant the old
    // code violated for the ONE input that matters — the real one.
    const inputs: unknown[] = [
      WRAPPED,
      UNWRAPPED,
      null,
      {},
      { data: {} },
      { data: { token: undefined, expires_in: undefined } },
      { data: { token: 'kbw_x', expires_in: '900' } },
    ];
    for (const input of inputs) {
      const grant = readSessionGrant(input);
      if (grant === null) continue;
      expect(typeof grant.token).toBe('string');
      expect(grant.token.length).toBeGreaterThan(0);
      expect(Number.isFinite(grant.expires_in)).toBe(true);
      expect(grant.expires_in).toBeGreaterThan(0);
    }
  });

  it('copies the two fields out rather than forwarding the body, so nothing else crosses the bridge', () => {
    const grant = readSessionGrant({
      data: {
        token: 'kbw_x',
        expires_in: 900,
        // A field a future server might add, and one an intermediary might inject.
        organization_id: 'org_01J8',
        __proto__: { polluted: true },
      },
    });
    expect(grant).toEqual({ token: 'kbw_x', expires_in: 900 });
    expect(Object.keys(grant ?? {})).toEqual(['token', 'expires_in']);
  });
});

/* ────────────────────────────────────────────────────────────────────────────────────────────
 * The bridge driver.
 *
 * `attachBridge` needs three ambient things the Node unit project does not have: a `message`
 * listener registry, `fetch`, and a frame whose `contentWindow` records what was posted to it.
 * Supplying them is not faking a browser — it is supplying the event loop so the two mint call
 * sites can be driven. Nothing here stands in for an origin check (see the header).
 * ──────────────────────────────────────────────────────────────────────────────────────────── */

/** A scripted mint answer. `json` is a THUNK so "a 200 that is not JSON at all" is expressible. */
interface MintAnswer {
  readonly ok: boolean;
  readonly json: () => unknown;
}

const answers = {
  wrapped: (body: unknown = WRAPPED): MintAnswer => ({ ok: true, json: () => body }),
  /** A captive portal or an intercepting proxy: 200, `content-type` says JSON, body is HTML. */
  notJson: (): MintAnswer => ({
    ok: true,
    json: () => {
      throw new SyntaxError('Unexpected token < in JSON at position 0');
    },
  }),
  /** Every SDK rejection is a 404 with a byte-identical body. */
  rejected: (): MintAnswer => ({ ok: false, json: () => ({}) }),
};

interface Posted {
  readonly type: string;
  readonly payload: unknown;
  readonly targetOrigin: string;
}

interface Driver {
  readonly posted: Posted[];
  readonly events: Array<[string, unknown]>;
  readonly mintCount: () => number;
  /** Deliver a frame → host envelope. The caller has already been vouched for by the real checks
   *  in production; here it is simply the shape that reaches `parse()`. */
  readonly fromFrame: (type: string, payload?: unknown) => void;
  readonly settle: () => Promise<void>;
  readonly teardown: () => void;
}

const CH = 'ch_01J8FIXTURECHANNEL';

let restore: Array<() => void> = [];

function drive(script: MintAnswer[]): Driver {
  const listeners = new Set<(event: MessageEvent) => void>();
  const posted: Posted[] = [];
  const events: Array<[string, unknown]> = [];
  let mints = 0;

  const contentWindow = {
    postMessage: (data: unknown, targetOrigin: string): void => {
      const envelope = data as { kb: number; ch: string; type: string; payload?: unknown };
      // The envelope contract is protocol.ts's and is asserted in protocol.test.ts; recorded here
      // only so a test can name the type it is looking for.
      expect(envelope.kb).toBe(KB);
      expect(envelope.ch).toBe(CH);
      posted.push({ type: envelope.type, payload: envelope.payload, targetOrigin });
    },
  };
  const frame = { contentWindow, style: {} } as unknown as HTMLIFrameElement;

  const ambient = globalThis as unknown as {
    addEventListener?: unknown;
    removeEventListener?: unknown;
    fetch?: unknown;
  };
  // SAVE AND RESTORE, never delete. Undo runs in reverse, so a test that drives two bridges has
  // the second undo hand the ambient functions back to the FIRST one — which still has to be able
  // to call `removeEventListener` inside its own `destroy()`.
  const prior = {
    add: ambient.addEventListener,
    remove: ambient.removeEventListener,
    fetch: ambient.fetch,
  };
  ambient.addEventListener = (type: string, fn: (event: MessageEvent) => void): void => {
    if (type === 'message') listeners.add(fn);
  };
  ambient.removeEventListener = (type: string, fn: (event: MessageEvent) => void): void => {
    if (type === 'message') listeners.delete(fn);
  };
  ambient.fetch = async (input: unknown): Promise<unknown> => {
    // `sdk/v1`, NOT `api/v1`: posting a mint made from a hostile customer page into the admin
    // group's session/CSRF stack is the inheritance the four-group split exists to prevent.
    expect(String(input)).toBe(`${__KB_API_ORIGIN__}/sdk/v1/session`);
    mints += 1;
    const answer = script.shift();
    if (answer === undefined) throw new Error('unscripted mint: the code under test minted twice');
    return { ok: answer.ok, json: async () => answer.json() };
  };

  const handle = attachBridge(frame, CH, {
    botId: 'pub_01J8FIXTUREBOT0000000000',
    onEvent: (type, payload) => {
      events.push([type, payload]);
    },
  });

  restore.push(() => {
    handle.destroy();
    ambient.addEventListener = prior.add;
    ambient.removeEventListener = prior.remove;
    ambient.fetch = prior.fetch;
  });

  return {
    posted,
    events,
    mintCount: () => mints,
    fromFrame: (type, payload) => {
      for (const listener of [...listeners]) {
        listener({
          source: contentWindow,
          origin: __KB_WIDGET_ORIGIN__,
          data: { kb: KB, ch: CH, type, payload },
        } as unknown as MessageEvent);
      }
    },
    // A macrotask turn drains the microtask queue behind `mint()`'s two awaits and the handler's
    // `.then`, with no assumption about how many ticks that takes.
    settle: () => new Promise<void>((resolve) => setTimeout(resolve, 0)),
    teardown: () => {
      handle.destroy();
    },
  };
}

describe('attachBridge: BOTH mint call sites read the wrapped body', () => {
  let warn: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    restore = [];
    resetMalformedWarningForTest();
    // The loader runs in someone else's console, so the warning is latched to one per document.
    // Spying keeps the suite output clean AND lets the latch itself be asserted.
    warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
  });

  afterEach(() => {
    for (const undo of restore.reverse()) undo();
    restore = [];
    warn.mockRestore();
  });

  it('`ready` → a wrapped mint → `init` carrying a REAL token, and no error event', async () => {
    const driver = drive([answers.wrapped()]);

    driver.fromFrame('ready');
    await driver.settle();

    const init = driver.posted.find((message) => message.type === 'init');
    expect(init).toBeDefined();
    expect(init?.payload).toMatchObject({
      session: { token: 'kbw_01J8REALLOOKINGTOKEN', expires_in: 900 },
    });
    // Never `'*'`, and never an origin derived from `frame.src`.
    expect(init?.targetOrigin).toBe(__KB_WIDGET_ORIGIN__);
    expect(driver.events).toEqual([]);
  });

  it('`ready` → the UNWRAPPED body → no `init` at all, one `error` naming session_malformed', async () => {
    const driver = drive([answers.wrapped(UNWRAPPED)]);

    driver.fromFrame('ready');
    await driver.settle();

    // THE REGRESSION. The old code posted an `init` whose token was `undefined`.
    expect(driver.posted.filter((message) => message.type === 'init')).toEqual([]);
    expect(driver.events).toEqual([['error', SESSION_MALFORMED]]);
    // And it is loud in the console too, which is the half that was missing entirely.
    expect(warn).toHaveBeenCalledTimes(1);
  });

  it('a wrapped mint then a wrapped refresh → `session` carrying a REAL token', async () => {
    const driver = drive([
      answers.wrapped(),
      answers.wrapped({ data: { token: 'kbw_02RENEWED', expires_in: 900 } }),
    ]);

    driver.fromFrame('ready');
    await driver.settle();
    driver.fromFrame('session-expiring');
    await driver.settle();

    // THE SECOND CALL SITE. A fix applied only at `ready` leaves this payload's token `undefined`
    // — a widget that works for one grant and then dies just as silently as before.
    const renewal = driver.posted.find((message) => message.type === 'session');
    expect(renewal?.payload).toEqual({ session: { token: 'kbw_02RENEWED', expires_in: 900 } });
    expect(driver.events).toEqual([]);
    expect(driver.mintCount()).toBe(2);
  });

  it('a wrapped mint then an UNWRAPPED refresh → no `session`, one `error` naming session_malformed', async () => {
    const driver = drive([answers.wrapped(), answers.wrapped(UNWRAPPED)]);

    driver.fromFrame('ready');
    await driver.settle();
    driver.fromFrame('session-expiring');
    await driver.settle();

    expect(driver.posted.filter((message) => message.type === 'session')).toEqual([]);
    expect(driver.events).toEqual([['error', SESSION_MALFORMED]]);
  });

  it('keeps `rejected` and `malformed` apart: a 404 is still mint_failed / refresh_failed', async () => {
    // The distinction is the point of the fix. Collapsing them would send a customer hunting
    // through their origin allow-list for a defect that is ours, or the reverse.
    const first = drive([answers.rejected()]);
    first.fromFrame('ready');
    await first.settle();
    expect(first.events).toEqual([['error', MINT_FAILED]]);

    const second = drive([answers.wrapped(), answers.rejected()]);
    second.fromFrame('ready');
    await second.settle();
    second.fromFrame('session-expiring');
    await second.settle();
    expect(second.events).toEqual([['error', REFRESH_FAILED]]);

    // No console noise for a rejection: it is the customer's configuration, it is already an SDK
    // event, and it is not our contract breaking.
    expect(warn).not.toHaveBeenCalled();
  });

  it('a 200 that is not JSON is malformed, not rejected', async () => {
    const driver = drive([answers.notJson()]);

    driver.fromFrame('ready');
    await driver.settle();

    expect(driver.events).toEqual([['error', SESSION_MALFORMED]]);
    expect(driver.posted.filter((message) => message.type === 'init')).toEqual([]);
  });

  it('warns ONCE per document however many malformed answers arrive', async () => {
    const driver = drive([answers.wrapped(UNWRAPPED), answers.wrapped(UNWRAPPED)]);

    driver.fromFrame('ready');
    await driver.settle();
    // `ready` is latched after the first one, so the second malformed answer has to come through
    // the refresh path — which is also a second, independent proof that the path exists.
    driver.fromFrame('session-expiring');
    await driver.settle();

    expect(driver.events).toHaveLength(2);
    expect(warn).toHaveBeenCalledTimes(1);
    // The message names the failure and nothing else: no URL, no body, no token fragment, no
    // status. It lands in a stranger's console.
    const [message] = warn.mock.calls[0] as [string];
    expect(message).toMatch(/^\[kb] /);
    expect(message).not.toMatch(/kbw_|http|Bearer/);
  });

  it('mints once for a repeated `ready`, malformed or not — the latch is not a retry ladder', async () => {
    const driver = drive([answers.wrapped(UNWRAPPED)]);

    driver.fromFrame('ready');
    driver.fromFrame('ready');
    driver.fromFrame('ready');
    await driver.settle();

    // A second scripted answer was never provided; `drive()` throws on an unscripted mint, so this
    // also fails loudly rather than quietly if the latch is ever removed.
    expect(driver.mintCount()).toBe(1);
    expect(driver.events).toEqual([['error', SESSION_MALFORMED]]);
  });
});

/**
 * ═══ THE HALF THE FIXTURES ABOVE CANNOT PROVE (`docs/22` § T51) ════════════════════════════
 *
 * Every body above is hand-written in this file. That is the right shape for the REFUSAL cases —
 * a malformed body has to be authored, because no server produces one on purpose — and it is
 * exactly the wrong shape for the acceptance case, which is the one that asks "does the loader
 * read what the server actually sends?" A hand-written happy path answers "does the loader read
 * what the author of this file believed the server sends", and the author of this file was wrong
 * about that once already, which is why § T48 exists.
 *
 * So this block builds its body FROM THE PUBLISHED CONTRACT — `@kb/contracts`'s
 * `core-api.openapi.json`, generated by `php artisan kb:dump-openapi` from
 * `ChatSessionResource::openApiSchemas()`, and held current by
 * `services/core-api/tests/Contract/OpenApiDocumentTest.php`'s "keeps the committed document
 * current". Nothing between that PHP class and this assertion is hand-transcribed.
 *
 * `packages/contracts/test/session-envelope.test.ts` is the server's half of the same seam: it
 * pins the envelope and the bounds so a change there is a red test rather than a silent
 * renegotiation. Neither file alone is the check — a client agreeing with itself and a server
 * agreeing with itself is the state this seam was already in when it was broken.
 */
describe('the loader against the published contract, not against a fixture', () => {
  type Json = Record<string, unknown>;

  const document = openapi as unknown as {
    paths: Record<string, Record<string, Json> | undefined>;
    components: { schemas: Record<string, Json | undefined> };
  };

  /**
   * Narrow an index lookup, and turn a missing key into a sentence rather than a cast.
   *
   * `noUncheckedIndexedAccess` is on. A cast would silence exactly the failure this block exists
   * to catch — the document not holding what this file assumes — so absence is raised by name.
   */
  function must<T>(value: T | undefined, what: string): T {
    if (value === undefined) {
      throw new Error(`the published document has no ${what}`);
    }

    return value;
  }

  /**
   * The smallest instance the published schema permits, derived rather than typed out.
   *
   * DERIVED, BECAUSE A LITERAL WOULD BE THE THING UNDER TEST. If this returned
   * `{ token: 'kbw_x', expires_in: 900 }` it would pass whatever the schema said, including a
   * schema that had renamed both fields. Building each value from the property's own `type` and
   * bound means a rename produces a body missing the key the loader reads, and the loader refuses
   * it — which is the failure this whole block exists to make visible.
   */
  function minimalInstance(schema: Json): Json {
    const required = must(schema.required as string[] | undefined, 'a required list');
    const properties = must(schema.properties as Record<string, Json | undefined> | undefined, 'properties');
    const instance: Json = {};

    for (const key of required) {
      const property = must(properties[key], `a schema for ${key}`);

      if (property.type === 'string') {
        // `minLength` characters, and the `kbw_` prefix the description names — long enough to be
        // a plausible bearer and never shorter than the bound.
        instance[key] = `kbw_${'a'.repeat(Math.max(Number(property.minLength ?? 1), 1))}`;
      } else if (property.type === 'integer' || property.type === 'number') {
        instance[key] = Number(property.minimum ?? 1);
      } else {
        throw new Error(`ChatSessionResource.${key} has an unhandled type: ${String(property.type)}`);
      }
    }

    return instance;
  }

  /** The 201 body schema of a mint operation, and the component its `data` key points at. */
  function mintContract(path: string): { envelope: Json; payload: Json } {
    const post = must(must(document.paths[path], `POST ${path}`).post, `POST ${path}`);
    const responses = must(post.responses as Record<string, Json | undefined> | undefined, `${path} responses`);
    const created = must(responses['201'], `${path} 201`);
    const content = must(
      (created.content as Record<string, Json | undefined> | undefined)?.['application/json'],
      `${path} 201 JSON content`,
    );
    const envelope = must(content.schema as Json | undefined, `${path} 201 schema`);
    const properties = must(envelope.properties as Record<string, Json | undefined> | undefined, `${path} envelope properties`);
    const ref = must((must(properties.data, `${path} data property`) as { $ref?: string }).$ref, `${path} data $ref`);
    const component = must(ref.split('/').pop(), 'a component name');

    return { envelope, payload: must(document.components.schemas[component], component) };
  }

  it('accepts the minimal body the published sdk/v1 mint schema permits', () => {
    const { envelope, payload } = mintContract('/sdk/v1/session');

    // The envelope key is read from the schema rather than typed, for the same reason the values
    // are: `data` is the contract's word, not this file's.
    const envelopeKey = must((envelope.required as string[] | undefined)?.[0], 'a required envelope key');

    const grant = readSessionGrant({ [envelopeKey]: minimalInstance(payload) });

    // A REAL TOKEN, not merely a non-null return. `expires_in` is asserted as the schema's own
    // floor rather than as a number this file chose: if the bound moves, this moves with it, and
    // if the FIELD is renamed there is no grant at all.
    expect(grant).not.toBeNull();
    expect(grant?.token.startsWith('kbw_')).toBe(true);

    const properties = must(payload.properties as Record<string, Json | undefined> | undefined, 'payload properties');
    const expiresIn = must(properties.expires_in, 'ChatSessionResource.expires_in');

    expect(grant?.expires_in).toBe(Number(expiresIn.minimum));
  });

  it('refuses the same instance unwrapped, which is the shape that shipped', () => {
    const { payload } = mintContract('/sdk/v1/session');

    // THE EXACT DEFECT, EXPRESSED AGAINST THE CONTRACT RATHER THAN AGAINST A LITERAL. The inner
    // object IS a valid `ChatSessionResource`; it is simply not the response body. A loader that
    // reads the payload at the top level accepts this and is wrong.
    expect(readSessionGrant(minimalInstance(payload))).toBeNull();
  });
});
