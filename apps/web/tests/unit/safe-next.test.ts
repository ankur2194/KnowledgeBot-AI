import { describe, expect, it } from 'vitest';

import { MAX_NEXT_LENGTH, PUBLIC_PATHS, safeNext } from '@/lib/auth/safe-next';

/**
 * `safeNext` is the highest-value unit in this batch, because it is the ONE function standing between
 * an attacker-supplied query parameter and a `Location` header on an authenticated origin. Get it
 * wrong and `src/proxy.ts` is an open redirector reachable by anyone who can get a signed-in admin to
 * click a link — a redirect the admin's own browser performs, from a URL on our own domain.
 *
 * Every vector below is named for what it defeats, because the failure mode is that someone
 * "simplifies" this to `raw.startsWith('/') ? raw : '/'` and every one of these passes it.
 */

const FALLBACK = '/';

describe('accepted destinations', () => {
  it('accepts the root', () => {
    // Not because it is interesting, but because the fallback and a legitimate answer are the same
    // string: a bug that always rejects is invisible in the one case people test by hand.
    expect(safeNext('/')).toBe('/');
  });

  it('accepts a plain admin path', () => {
    expect(safeNext('/sources')).toBe('/sources');
  });

  it('accepts a path WITH its query string, which is the whole point of round-tripping ?next=', () => {
    expect(safeNext('/sources?page=2&sort=-created_at')).toBe('/sources?page=2&sort=-created_at');
  });

  it('drops a #fragment rather than rejecting the path that carried it', () => {
    // A fragment is never sent to a server and has no business in a Location header, but rejecting
    // the whole value would send a user to the overview for using an anchor link.
    expect(safeNext('/sources#row-4')).toBe('/sources');
  });

  it('normalizes dot segments through the re-parse', () => {
    // What the browser would resolve, not what the attacker typed — so a later `startsWith` check
    // anywhere downstream sees the real destination.
    expect(safeNext('/bots/../sources')).toBe('/sources');
  });

  it('keeps an embedded absolute URL inside the QUERY, where it is data and not a destination', () => {
    // `?url=http://x/y` is a legitimate parameter shape. It must not be confused with the value
    // itself naming a host — the origin check is what tells those apart.
    expect(safeNext('/sources?url=http://x.example/y')).toBe('/sources?url=http://x.example/y');
  });
});

describe('rejected: values that name another origin', () => {
  it('rejects an absolute URL', () => {
    expect(safeNext('https://evil.com')).toBe(FALLBACK);
  });

  it('rejects a protocol-relative URL — the one `new URL(raw, request.url)` resolves off-origin', () => {
    // `new URL('//evil.com', 'https://app.kb.test/login')` is `https://evil.com/`. This single line
    // is the open redirector; the leading-slash lookahead is what refuses it.
    expect(safeNext('//evil.com')).toBe(FALLBACK);
  });

  it('rejects a backslash after the leading slash, which browsers normalize toward //', () => {
    // A naive startsWith('/') ACCEPTS this, and every major browser then treats `\` as `/` in the
    // authority position and leaves our origin.
    expect(safeNext('/\\evil.com')).toBe(FALLBACK);
  });

  it('rejects a leading backslash', () => {
    expect(safeNext('\\/evil.com')).toBe(FALLBACK);
  });

  it('rejects a percent-encoded double slash, which decodes to a protocol-relative URL', () => {
    // `/%2F%2Fevil.com` passes every character check and every leading-slash regex applied to the
    // RAW string. It is why the rules are re-applied to the decoded form.
    expect(safeNext('/%2F%2Fevil.com')).toBe(FALLBACK);
  });

  it('rejects a pseudo-scheme', () => {
    expect(safeNext('javascript:alert(1)')).toBe(FALLBACK);
  });

  it('rejects a literal backslash-t sequence', () => {
    // Two characters, `\` and `t`. Caught by the backslash rule.
    expect(safeNext('/\\tevil.com')).toBe(FALLBACK);
  });

  it('rejects a REAL tab, which a browser strips before resolving the URL', () => {
    // One character, U+0009. After stripping, `/<TAB>/evil.com` is `//evil.com` — and the strip
    // happens AFTER any regex we ran, which is why the character itself is rejected.
    expect(safeNext('/\t/evil.com')).toBe(FALLBACK);
  });

  it('rejects a REAL newline', () => {
    expect(safeNext('/\n/evil.com')).toBe(FALLBACK);
  });

  it('rejects a carriage return', () => {
    expect(safeNext('/\r/evil.com')).toBe(FALLBACK);
  });

  it('rejects a percent-encoded tab', () => {
    expect(safeNext('/%09/evil.com')).toBe(FALLBACK);
  });

  it('rejects a malformed percent escape rather than throwing', () => {
    // decodeURIComponent throws on a lone `%`. An uncaught throw here would be a 500 on the login
    // route, reachable by anybody with the URL.
    expect(safeNext('/sources?q=%')).toBe(FALLBACK);
  });
});

describe('rejected: values that are not one string', () => {
  it('rejects an array, because Next hands back string[] for a REPEATED param', () => {
    // `?next=/a&next=//evil.com` arrives as ['/a', '//evil.com']. Honouring `raw[0]` would silently
    // accept a link whose visible first value is harmless.
    expect(safeNext(['/a', '//evil.com'])).toBe(FALLBACK);
  });

  it('rejects undefined', () => {
    expect(safeNext(undefined)).toBe(FALLBACK);
  });

  it('rejects null', () => {
    // `searchParams.get()` returns null for an absent param, so this is the ordinary path.
    expect(safeNext(null)).toBe(FALLBACK);
  });

  it('rejects the empty string', () => {
    expect(safeNext('')).toBe(FALLBACK);
  });
});

describe('rejected: values that are too long', () => {
  it('rejects a 600-character path', () => {
    expect(safeNext(`/${'a'.repeat(599)}`)).toBe(FALLBACK);
  });

  it('accepts exactly the cap and rejects one character past it', () => {
    const atCap = `/${'a'.repeat(MAX_NEXT_LENGTH - 1)}`;
    expect(atCap).toHaveLength(MAX_NEXT_LENGTH);
    expect(safeNext(atCap)).toBe(atCap);
    expect(safeNext(`${atCap}a`)).toBe(FALLBACK);
  });
});

describe('rejected by DESTINATION — paths we could safely reach and still must not', () => {
  it.each([...PUBLIC_PATHS])('rejects the public auth path %s', (path) => {
    // `next=/login` is a redirect loop: the bounce out of /login would send the visitor back to
    // /login. Driven off PUBLIC_PATHS itself so a seventh public route is covered the day it is
    // added rather than the day someone remembers this file.
    expect(safeNext(path)).toBe(FALLBACK);
  });

  it('rejects a public auth path even when it carries a query string', () => {
    // The token-laundering case: a single-use reset credential relayed through a Location header
    // and into whatever proxy logs sit between the browser and us. Matched on the PATHNAME, so the
    // query cannot smuggle it past.
    expect(safeNext('/reset-password?token=single-use-secret')).toBe(FALLBACK);
  });

  it('rejects /login?next=/login, the doubled loop', () => {
    expect(safeNext('/login?next=/login')).toBe(FALLBACK);
  });

  it('rejects the hosted-chat namespace', () => {
    // /c/* renders model-generated Markdown and the proxy already redirects it away from the admin
    // origin. A ?next= must not be the thing that carries an admin session there.
    expect(safeNext('/c/abc')).toBe(FALLBACK);
    expect(safeNext('/c')).toBe(FALLBACK);
  });

  it('does NOT reject a path that merely resembles the chat namespace', () => {
    // `/conversations` starts with `/c` but is not `/c/…`. Over-rejecting here would silently break
    // a real admin route's post-login destination.
    expect(safeNext('/conversations')).toBe('/conversations');
  });

  it('does NOT reject a path that merely resembles a public path', () => {
    // The mirror of the proxy's `/logins` case: exact matching, in both directions.
    expect(safeNext('/logins')).toBe('/logins');
    expect(safeNext('/invitations/accepted')).toBe('/invitations/accepted');
  });
});

describe('the function never returns anything but a same-origin path', () => {
  it('returns a value starting with exactly one slash for every input', () => {
    const inputs: readonly (string | readonly string[] | null | undefined)[] = [
      '/',
      '/sources',
      'https://evil.com',
      '//evil.com',
      '/\\evil.com',
      '/%2F%2Fevil.com',
      'javascript:alert(1)',
      '/\t/evil.com',
      ['/a', '//evil.com'],
      `/${'a'.repeat(999)}`,
      '/login',
      '/c/abc',
      '',
      null,
      undefined,
    ];

    for (const input of inputs) {
      const out = safeNext(input);
      expect(out.startsWith('/'), `input ${JSON.stringify(input)} -> ${out}`).toBe(true);
      expect(out.startsWith('//'), `input ${JSON.stringify(input)} -> ${out}`).toBe(false);
      expect(out.startsWith('/\\'), `input ${JSON.stringify(input)} -> ${out}`).toBe(false);
      // The property that actually matters: resolved against ANY origin, it stays on that origin.
      expect(new URL(out, 'https://app.knowledgebot.test').origin).toBe(
        'https://app.knowledgebot.test',
      );
    }
  });
});
