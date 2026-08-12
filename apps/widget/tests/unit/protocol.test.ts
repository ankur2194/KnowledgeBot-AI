import { describe, expect, it } from 'vitest';

import { KB, parse } from '../../src/bridge/protocol.js';
import { PREFILL_MAX, clampPrefill, isTheme, safePageUrl } from '../../src/app/bridge.js';

/**
 * The envelope table.
 *
 * What this layer proves: STRUCTURE and VALUE DOMAIN — the two halves that are pure functions.
 * What it deliberately does NOT prove: the origin and `event.source` checks, the "nothing acts
 * before init" gate, and "exactly one init". Those are properties of a real MessageEvent arriving
 * from a real second origin, and a unit test that fakes them passes with every check removed.
 * They live in tests/e2e/origin-checks.spec.ts against the two-origin harness.
 */

const CH = 'e4b1c0f2-0000-4000-8000-000000000001';
const FROM_HOST: ReadonlySet<string> = new Set(['init', 'set-theme', 'prefill', 'destroy']);

const envelope = (over: Record<string, unknown> = {}): Record<string, unknown> => ({
  kb: KB,
  ch: CH,
  type: 'set-theme',
  payload: { value: 'dark' },
  ...over,
});

describe('parse()', () => {
  it('accepts a well-formed envelope on the right channel', () => {
    expect(parse(envelope(), CH, FROM_HOST)).not.toBeNull();
  });

  it('drops a WRONG CHANNEL — this is what stops a second widget instance, or a frame torn down and recreated, from answering this one', () => {
    expect(parse(envelope({ ch: 'some-other-channel' }), CH, FROM_HOST)).toBeNull();
  });

  it('drops an envelope whose ch is absent or not a string', () => {
    expect(parse(envelope({ ch: undefined }), CH, FROM_HOST)).toBeNull();
    expect(parse(envelope({ ch: 1 }), CH, FROM_HOST)).toBeNull();
  });

  it('accepts NOTHING when this document never learned its own channel id', () => {
    // A frame whose `?ch=` was stripped would otherwise match every envelope carrying `ch: ''`.
    expect(parse(envelope({ ch: '' }), '', FROM_HOST)).toBeNull();
  });

  it('drops an UNKNOWN kb VERSION and does not throw — a customer pins /v1/kb-widget.js for months while the frame deploys weekly, so mismatched halves are normal', () => {
    expect(parse(envelope({ kb: 2 }), CH, FROM_HOST)).toBeNull();
    expect(parse(envelope({ kb: '1' }), CH, FROM_HOST)).toBeNull();
    expect(parse(envelope({ kb: undefined }), CH, FROM_HOST)).toBeNull();
  });

  it('drops an UNKNOWN TYPE, silently — this is what lets the frame add an event while an 18-month-old loader is still live', () => {
    expect(parse(envelope({ type: 'set-bot' }), CH, FROM_HOST)).toBeNull();
    expect(parse(envelope({ type: 'set-session' }), CH, FROM_HOST)).toBeNull();
    expect(parse(envelope({ type: 42 }), CH, FROM_HOST)).toBeNull();
  });

  it('drops non-objects: other SDKs on the page post strings here', () => {
    for (const value of [null, undefined, 'webpackHotUpdate', 7, true, Symbol.iterator]) {
      expect(parse(value, CH, FROM_HOST)).toBeNull();
    }
  });

  it('NEVER THROWS — an exception in a message listener on the host page lands in the CUSTOMER console with our filename on it', () => {
    const hostile: unknown[] = [
      Object.create(null),
      new Proxy({}, { get: () => undefined }),
      { kb: KB, ch: CH, type: 'set-theme', payload: undefined },
    ];
    for (const value of hostile) {
      expect(() => parse(value, CH, FROM_HOST)).not.toThrow();
    }
  });

  /**
   * KNOWN LIMIT, recorded rather than hidden: `parse()` reads `.kb`, `.ch` and `.type` directly,
   * so an object with a THROWING ACCESSOR would throw straight out of a message listener — into
   * the CUSTOMER's console, with our filename on it.
   *
   * It cannot arrive over `postMessage`: the browser structured-serializes `event.data` at the
   * sending end, and StructuredSerialize invokes `[[Get]]` on every own enumerable property, so a
   * hostile getter throws in the ATTACKER's document and no message is ever delivered. The hazard
   * is only reintroduced by a future call site that hands `parse()` a raw object it did not
   * receive as `event.data`. Both call sites in this app pass `event.data`; if a third appears,
   * wrap it.
   */
  it('is only ever called with structured-cloned event.data (see the comment above)', () => {
    expect(parse(structuredClone(envelope()), CH, FROM_HOST)).not.toBeNull();
  });

  it('is STRUCTURAL ONLY: a 1 MB prefill and an XSS-shaped theme both parse, and are the handler’s problem', () => {
    const megabyte = 'a'.repeat(1024 * 1024);
    expect(
      parse(envelope({ type: 'prefill', payload: { text: megabyte } }), CH, FROM_HOST),
    ).not.toBeNull();
    expect(
      parse(envelope({ payload: { value: '<img src=x onerror=alert(1)>' } }), CH, FROM_HOST),
    ).not.toBeNull();
  });
});

describe('value domain — the guards that sit where the field is read', () => {
  it('set-theme is a CLOSED ENUM; a script-shaped string is dropped, never coerced', () => {
    expect(isTheme('light')).toBe(true);
    expect(isTheme('dark')).toBe(true);
    expect(isTheme('auto')).toBe(true);
    expect(isTheme('<img src=x onerror=alert(1)>')).toBe(false);
    expect(isTheme('Light')).toBe(false);
    expect(isTheme('')).toBe(false);
    expect(isTheme(null)).toBe(false);
    expect(isTheme(['dark'])).toBe(false);
  });

  it('a 1 MB prefill is CLAMPED before it reaches any DOM API — postMessage has no documented size limit, only a practical cliff', () => {
    const clamped = clampPrefill('a'.repeat(1024 * 1024));
    expect(clamped).not.toBeNull();
    expect(clamped).toHaveLength(PREFILL_MAX);
  });

  it('prefill rejects a non-string rather than stringifying it', () => {
    expect(clampPrefill(undefined)).toBeNull();
    expect(clampPrefill({ toString: () => 'x' })).toBeNull();
  });

  it('set-page-context URLs are SCHEME-ALLOW-LISTED, because that URL is later shown to an admin reviewing conversations with real privileges', () => {
    expect(safePageUrl('https://customer.example/pricing')).toBe(
      'https://customer.example/pricing',
    );
    expect(safePageUrl('javascript:alert(1)')).toBeNull();
    expect(safePageUrl('data:text/html,<script>alert(1)</script>')).toBeNull();
    expect(safePageUrl('  javascript:alert(1)')).toBeNull();
    expect(safePageUrl('not a url')).toBeNull();
    expect(safePageUrl(`https://customer.example/${'a'.repeat(4096)}`)).toBeNull();
  });
});

describe('outbound payloads survive structuredClone', () => {
  /**
   * `DataCloneError` throws outright on functions, DOM nodes, Symbols and Proxy. The SILENT half
   * is worse: a class instance DOES clone, arriving as a plain object with its prototype, every
   * getter and every private field gone — a TypeError in the other document one frame later.
   */
  it('clones a §8.20 event payload', () => {
    const payload = { conversation_id: '01J8', duration_ms: 1200, error_class: 'rate_limit' };
    expect(structuredClone(payload)).toEqual(payload);
  });

  it('refuses a payload carrying a function', () => {
    expect(() => structuredClone({ onDone: () => undefined })).toThrow();
  });
});
