import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

/**
 * The SSE fixture server exists twice — once in `apps/web`, once in `apps/mobile` — and this suite
 * is why that is allowed.
 *
 * There are exactly two workspace packages, `@kb/contracts` and `@kb/design-tokens`, and inventing a
 * third is a finding rather than a refactor (CLAUDE.md). The two copies are test harnesses for two
 * different runners (Vitest and Jest) in workspaces with their own lockfiles. The thing that
 * genuinely must not be duplicated is the frame PARSER, and both import that from here.
 *
 * So the copies are permitted, on one condition: they must be provably identical, and the proof runs
 * in CI rather than living in a comment. Duplication that is checked is a maintenance cost;
 * duplication that has silently diverged is a suite where one client's streaming bug is invisible
 * because its fixture no longer reproduces the boundary the other client's fixture does — and
 * nobody finds that by reading either file.
 *
 * This lives in `packages/contracts` rather than in either app because it is the only place that can
 * see both. A drift check inside `apps/web` would be a test that reaches into `apps/mobile`, which
 * is exactly the cross-workspace dependency the split was meant to avoid.
 */

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

const MARKER = '// ─── TWIN REGION BEGIN';

const TWINS = {
  web: join(REPO_ROOT, 'apps', 'web', 'tests', 'fixtures', 'sse-server.ts'),
  mobile: join(REPO_ROOT, 'apps', 'mobile', 'tests', 'fixtures', 'sse-server.ts'),
} as const;

/**
 * Read a twin, failing loudly when it is absent.
 *
 * Deliberately not `describe.skipIf(...)`. A moved or renamed fixture would turn this whole suite
 * into a skip, and a skipped drift check is indistinguishable from a passing one in a CI summary —
 * the same failure shape as an isolation test whose surface returned nothing.
 */
function read(which: keyof typeof TWINS): string {
  const path = TWINS[which];
  if (!existsSync(path)) {
    throw new Error(
      `${path} does not exist. The SSE fixture server is duplicated on purpose and this test is ` +
        `the only thing keeping the two copies honest. If it moved, update TWINS here — do not ` +
        `delete the check, and do not create a third workspace package to hold it.`,
    );
  }
  return readFileSync(path, 'utf8');
}

function twinRegion(source: string, path: string): string {
  const at = source.indexOf(MARKER);
  expect(at, `${path} has no TWIN REGION marker`).toBeGreaterThanOrEqual(0);
  expect(source.indexOf(MARKER, at + 1), `${path} has more than one TWIN REGION marker`).toBe(-1);
  return source.slice(at);
}

describe('the two SSE fixture servers have not drifted', () => {
  const web = read('web');
  const mobile = read('mobile');

  it('has a shared region in each file', () => {
    // Positive control. Without it, a marker accidentally deleted from BOTH files would leave two
    // empty regions that compare equal, and every assertion below would pass over nothing.
    expect(twinRegion(web, TWINS.web).length).toBeGreaterThan(1000);
    expect(twinRegion(mobile, TWINS.mobile).length).toBeGreaterThan(1000);
  });

  it('is byte-identical below the marker', () => {
    const a = twinRegion(web, TWINS.web);
    const b = twinRegion(mobile, TWINS.mobile);
    // Compared as strings so a failure prints a readable diff; compared again as bytes so a
    // difference that normalizes away in UTF-16 — a stray BOM, a lone CR — is still caught.
    expect(a).toBe(b);
    expect(Buffer.from(a, 'utf8').equals(Buffer.from(b, 'utf8'))).toBe(true);
  });

  it('names its twin in its own header, so neither copy can be edited in ignorance', () => {
    const header = (source: string) => source.slice(0, source.indexOf(MARKER));
    expect(header(web)).toContain('apps/mobile/tests/fixtures/sse-server.ts');
    expect(header(mobile)).toContain('apps/web/tests/fixtures/sse-server.ts');
  });

  it('does not reach across workspaces to import the other copy', () => {
    // The duplication is only defensible while the two are independent. A relative import from one
    // app into another creates a build-time dependency between two workspaces with separate
    // lockfiles, which is worse than either the duplication or the third package.
    for (const source of [web, mobile]) {
      expect(source).not.toMatch(/from\s+['"]\.\.\/\.\.\/\.\.\/\.\.\/apps\//);
      expect(source).not.toMatch(/@kb\/(sse|fixtures|test-utils)/);
    }
  });
});

/**
 * Identity is necessary and not sufficient: both copies could be edited identically into something
 * that no longer reproduces the bugs the fixture exists for. These assertions pin the capabilities
 * that make it a real fixture rather than a mock with a port number.
 */
describe('the shared fixture keeps the capabilities that make it worth running', () => {
  const region = twinRegion(read('web'), TWINS.web);

  it('can hang up by destroying the socket, not by ending the response', () => {
    // `res.end()` is a clean FIN and drives the COMPLETED-answer path. Only an RST reproduces a
    // vanished peer, which is what must yield `stream_lost` and a Retry affordance. A fixture whose
    // `cut()` calls `end()` makes the cancellation suite green over a bug it cannot see.
    expect(region).toContain('cut(): void');
    expect(region).toContain('response.socket?.destroy()');
  });

  it('can split a payload at a byte offset, with a real gap between the halves', () => {
    // Byte offset, not character offset: landing inside a multi-byte codepoint is the only way to
    // catch a decoder constructed per chunk, and that bug is invisible in English.
    expect(region).toContain('split(text: string, atByte: number)');
    expect(region).toContain("Buffer.from(text, 'utf8')");
    expect(region).toMatch(/setTimeout\(resolve, \d+\)/);
  });

  it('flushes headers before the first token', () => {
    // Without it Node holds the headers until the first body write, `await fetch()` in the test
    // blocks until generation starts, and nothing about the pre-token UI is observable.
    expect(region).toContain('response.flushHeaders()');
  });

  it('disables Nagle, so two events written back to back arrive as two reads', () => {
    expect(region).toContain('setNoDelay(true)');
  });

  it('emits the heartbeat as an SSE comment', () => {
    expect(region).toContain("write(': ping\\n\\n')");
  });

  it('awaits the write callback rather than the return value', () => {
    // `write()` returning false means the buffer is full, not that the bytes left. Awaiting the
    // return value makes every inter-event gap in every spec a measurement of nothing.
    expect(region).toMatch(/response\.write\(chunk, \(error\) =>/);
  });

  it('records every request it saw', () => {
    // The positive control for auth assertions: the mobile spec proves the send carried a bearer
    // token and no cookie, and the hosted-chat spec proves the opposite. Both need the raw request.
    expect(region).toContain('requests(): readonly IncomingMessage[]');
  });
});
