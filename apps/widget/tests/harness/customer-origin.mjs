import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * THE CUSTOMER'S ORIGIN — a hostile third-party page, served from a DIFFERENT ORIGIN than the
 * widget. Node builtins only; this workspace adds no dependency for a fixture.
 *
 * Query switches, so one HTML file covers every variant a spec needs:
 *
 *   ?inject=2      the snippet twice — the tag-manager / duplicated-footer-partial case
 *   ?csp=strict    A real customer CSP as a RESPONSE HEADER (the only place `frame-src` can be set
 *                  — it is not settable in <meta>). It ALLOWS our loader and the mint and withholds
 *                  `frame-src`, so the iframe is refused and degrade() runs. See the header block
 *                  below for why a blanket `default-src 'self'` tested nothing at all.
 *   ?css=hostile   `* { font-family: cursive !important; line-height: 3 }` plus
 *                  `div { position: static !important }` — real resets from real CSS frameworks
 *   ?attacker=1    a SIBLING iframe on this same customer origin, which posts forged envelopes.
 *                  It passes an origin-only check and is exactly what `event.source` excludes.
 */

const here = dirname(fileURLToPath(import.meta.url));
const PORT = Number(process.env['KB_CUSTOMER_PORT'] ?? 4174);
const WIDGET_ORIGIN = process.env['KB_WIDGET_ORIGIN'] ?? 'http://127.0.0.1:4173';
/** Needed only by `?csp=strict`, so the mint stays permitted and cannot be mistaken for the frame
 *  failure the spec is measuring. Two DIFFERENT origins by rule (`traefik-routing`). */
const API_ORIGIN = process.env['KB_API_ORIGIN'] ?? 'http://127.0.0.1:4175';

const HOSTILE_CSS = `<style>
  /* Aggressive global resets are not hypothetical; these two ship in real CSS frameworks and are
     the reason the shadow host's layout is written on the element with !important and the
     stylesheet begins with :host { all: initial }. */
  * { font-family: cursive !important; line-height: 3 !important; letter-spacing: 2px !important; }
  div { position: static !important; }
  html { font-size: 62.5%; }  /* every rem in our CSS would render at 62.5% — hence: no rem */
  iframe { border: 5px dashed red !important; }
</style>`;

/**
 * A frame on the CUSTOMER's own origin that forges envelopes at our frame. It carries the
 * customer's origin, so it passes an origin-only test; only `event.source !== parent` stops it.
 * `about:blank` is used deliberately — such a frame INHERITS the creator's origin rather than
 * being opaque, which is the case people assume is safe.
 */
const ATTACK_FRAME = `<iframe id="kb-attacker" src="about:blank" style="width:1px;height:1px"></iframe>
<script>
  // The forging code must RUN INSIDE the sibling frame, or event.source is the top window and
  // the test proves nothing about the case it names. An about:blank frame INHERITS this page's
  // origin rather than being opaque, which is exactly the case people assume is safe: it passes
  // an origin-only check and only the event.source comparison excludes it.
  // (No backticks below this line: the whole block is a JS template literal.)
  window.__kbForge = function (envelope) {
    var attacker = document.getElementById('kb-attacker').contentWindow;
    attacker.__envelope = envelope;
    var script = attacker.document.createElement('script');
    script.textContent = [
      'var e = window.__envelope;',
      // At the loader: a message whose origin IS the customer's, from a window that is not the
      // frame's contentWindow.
      "try { parent.postMessage(e, '*'); } catch (err) { void err; }",
      // At our frame: a message from a window that is not its parent. Note this loop does NOT
      // find our chat iframe, because it lives in a CLOSED shadow root — a second, independent
      // layer. The message that DOES land is the parent.postMessage above, at the loader.
      'var fs = parent.document.querySelectorAll("iframe");',
      'for (var i = 0; i < fs.length; i++) {',
      // Deliberately a wildcard: this is the ATTACK, and a harness that cannot express it proves
      // nothing about the defence.
      '  try { fs[i].contentWindow.postMessage(e, "*"); } catch (err) { void err; }',
      '}',
    ].join('\\n');
    attacker.document.body.appendChild(script);
  };
</script>`;

const template = await readFile(join(here, 'customer-page.html'), 'utf8');

/**
 * EVERY MARKER MUST APPEAR EXACTLY ONCE, asserted at startup.
 *
 * `String.replace()` with a string pattern substitutes the FIRST occurrence and returns quietly.
 * The template used to name these tokens a second time in its own header comment, so the server
 * filled in the COMMENT copy and left the real marker as literal text — the hostile stylesheet, the
 * second `<script>` and the attacker frame were all injected into an HTML comment and did nothing.
 * Three specs failed with symptoms that pointed at the widget (`forge is not a function`, no
 * `[kb] already loaded` warning, zero `<style>` tags) and a fourth passed for the wrong reason.
 *
 * Nothing detected it because a missing substitution is not an error anywhere: the page still
 * renders, the server still returns 200. So it is made an error here. Failing at boot with the
 * marker named beats debugging four specs that each look like a different widget bug.
 */
const count = (marker) => template.split(marker).length - 1;

// Substituted with replaceAll, so repeats are harmless — it only has to be present.
if (count('__WIDGET_ORIGIN__') < 1) {
  throw new Error(
    '[kb] customer-page.html is missing __WIDGET_ORIGIN__; the loader URL is not hard-codable.',
  );
}

// The three VARIANT markers are substituted with a single replace(), so a second occurrence is the
// bug described above and a zeroth means a spec has no subject. Exactly one, or refuse to serve.
for (const marker of ['__EXTRA_SCRIPT__', '__HOSTILE_CSS__', '__ATTACK_FRAME__']) {
  const seen = count(marker);
  if (seen !== 1) {
    throw new Error(
      `[kb] customer-page.html must contain ${marker} exactly once; found ${seen}. ` +
        `More than one means replace() fills in the wrong copy and the variant silently never ` +
        `applies; zero means the spec depending on it can never exercise its subject.`,
    );
  }
}

createServer((request, response) => {
  const url = new URL(request.url ?? '/', `http://localhost:${PORT}`);

  if (url.pathname === '/healthz') {
    response.writeHead(200, { 'content-type': 'text/plain' });
    response.end('ok');
    return;
  }

  const headers = {
    'content-type': 'text/html; charset=utf-8',
    'cache-control': 'no-store',
  };

  if (url.searchParams.get('csp') === 'strict') {
    /**
     * THE POLICY MUST ALLOW OUR SCRIPT AND WITHHOLD `frame-src`. Anything stricter tests nothing.
     *
     * This was `default-src 'self'` and it could never work. `script-src` falls back to
     * `default-src`, so `'self'` on the CUSTOMER's origin blocked our own loader — verified in a
     * real browser:
     *
     *   Loading the script 'http://127.0.0.1:4173/v1/kb-widget.js' violates the following
     *   Content Security Policy directive: "default-src 'self'".
     *   → { loaderRan: false, events: null, shadowRoots: 0 }
     *
     * degrade() lives INSIDE the loader, so with the loader blocked there is no launcher, no
     * timeout, no `securitypolicyviolation` handler and no SDK event — the spec asserted a subject
     * that never executed. A test that cannot run its subject is worse than no test, because the
     * suite reads as coverage.
     *
     * What ships below is the policy a real customer is documented to need, MINUS the one directive
     * under test:
     *
     *   script-src  <widget-origin>   the loader is allowed to run — the whole precondition
     *   connect-src <api-origin>      the mint is allowed, so a failure here cannot masquerade as
     *                                 the frame failure and inflate the `error` count
     *   frame-src   ABSENT            → falls back to `default-src 'self'` → our cross-origin frame
     *                                 is refused. THIS is the condition under test.
     *
     * `'unsafe-inline'` is for the FIXTURE PAGE's own inline script, which registers the `window.kbq`
     * handlers this spec reads `__kbEvents` from — it is not something we ask a customer for. The
     * zero-config install is a single `data-*` tagged <script> with no inline block precisely so a
     * customer on `script-src 'nonce-…'` can adopt it; that property is asserted by the loader's own
     * bootstrap, not here.
     *
     * NOT REPRESENTABLE, and deliberately so: a policy that also blocks our script. The loader never
     * executes, so there is no code of ours to assert anything about. It is a real customer state
     * and the correct response to it is documentation, not a spec.
     */
    headers['content-security-policy'] = [
      "default-src 'self'",
      `script-src 'self' 'unsafe-inline' ${WIDGET_ORIGIN}`,
      `connect-src 'self' ${API_ORIGIN}`,
    ].join('; ');
  }

  const html = template
    .replaceAll('__WIDGET_ORIGIN__', WIDGET_ORIGIN)
    .replace(
      '__EXTRA_SCRIPT__',
      url.searchParams.get('inject') === '2'
        ? `<script src="${WIDGET_ORIGIN}/v1/kb-widget.js" async data-kb-bot="pub_01J8FIXTUREBOT0000000000" data-kb-position="right"></script>`
        : '',
    )
    .replace('__HOSTILE_CSS__', url.searchParams.get('css') === 'hostile' ? HOSTILE_CSS : '')
    .replace('__ATTACK_FRAME__', url.searchParams.get('attacker') === '1' ? ATTACK_FRAME : '');

  response.writeHead(200, headers);
  response.end(html);
}).listen(PORT, () => {
  console.log(`[kb] customer-origin harness on http://localhost:${PORT}`);
});
