---
name: preact-vite-library
description: The apps/widget build — Preact 10 and Vite 8 library mode, the split between a tiny host-page loader and the iframe chat app, the brotli budget enforced by size-limit in CI, and the shadow-DOM-plus-iframe isolation model. Use whenever editing apps/widget's Vite config, the embed snippet, the loader bootstrap, or a lazy chunk, or when the widget inherits the host page's fonts, boots twice, or blows its budget. Owns the build and the bootstrap; the postMessage protocol is iframe-postmessage-bridge.
---

# Preact + Vite Library Build (`apps/widget`)

**preact 10.29.8** (11.0.0-beta.2 exists — not shipped) · **vite 8.2.0** (Rolldown, Oxc minifier, Node ≥20.19/22.12) · **@preact/preset-vite 2.10.6** (dev only) · **size-limit 13.0.3** · markdown-it 15 + dompurify ≥3.4.13 in the lazy renderer chunk.
**Authoritative spec:** docs/04-functional-channels-chat.md §8.20, docs/05-tech-stack.md §9.2, docs/13-security.md §18.5, docs/19-repo-structure-adrs.md ADR-007 ADR-008

## Non-negotiables

- **The budget is a build gate, not a target.** This code runs on someone else's page, competing with their analytics and their ad tags for the same main thread. The numbers below are asserted by `size-limit` in CI and a PR that exceeds one does not merge. Everything else in this skill is downstream of that: it is why the runtime is Preact, why `apps/web`'s component library is not importable here, and why the Markdown renderer is a lazy chunk.
- **The widget talks only to Laravel.** No FastAPI host, no Qdrant, no provider SDK, no provider credential appears in either bundle (`kb-architecture-map`). The only credential is the opaque origin-bound chat-session token — minted by the *loader* so the request carries the embedder's real `Origin`, handed to the frame over the bridge, and held there in a module-scoped variable. Never `localStorage`, never a query string, never a URL fragment (`laravel-sanctum-auth`, `iframe-postmessage-bridge`).
- **`EventSource` is never used.** It cannot set `Authorization`, it is GET-only, and sending a message is a POST. Every stream is `fetch()` + `response.body.getReader()` + an SSE parser + `AbortController` for stop-generation. `EventSource`'s automatic `Last-Event-ID` reconnect is separately barred — token streams are not resumable (`kb-internal-api-contracts`).
- **The widget is served from `<widget-domain>`, a separate registrable domain; the API is `api.<domain>`, on the main one.** `traefik-routing` owns both spellings and the four hostnames (`app.<domain>`, `chat.<domain>`, `api.<domain>`, `<widget-domain>`); this skill only consumes them. `<widget-domain>` must share **no registrable suffix** with `app`/`chat`/`api`: the admin session cookie is scoped to the main domain, so putting the API — or anything else — on the widget's eTLD+1 makes a widget iframe embedded on a hostile customer page *same-site* with a real admin credential, and CHIPS cannot help because the cookie is not the widget's (docs/22 defect 17; `laravel-sanctum-auth` gotcha). Collapsing them is a security regression, not a simplification. Corollary from `kb-security-baseline`: never frame this widget from a page on our *own* widget origin, or the `allow-scripts` + `allow-same-origin` sandbox escape becomes live.
- **The rendering path is fixed elsewhere.** markdown-it (`html: false`) → DOMPurify (array config, `RETURN_DOM_FRAGMENT`) → `replaceChildren`, images never auto-loaded. The widget reproduces that configuration verbatim in its lazy renderer chunk and invents nothing of its own; the configuration belongs to `kb-security-baseline` → `references/widget-embedding-and-output.md`.
- **The cross-document channel belongs to `iframe-postmessage-bridge`.** Event names, payload schemas, the `event.source` + exact-origin checks, and the handshake are defined there. This skill decides only *when* the bridge is constructed and torn down.

## How we use it

### Two artifacts, two builds, one package

| | `dist/loader/kb-widget.js` | `dist/app/` |
|---|---|---|
| What | the file the customer pastes on their page | the chat application |
| Build | `build.lib`, `formats: ['iife']`, no code splitting | ordinary Vite app build (`index.html` entry), code-split |
| Runtime, and where it runs | none — plain TypeScript and DOM, in the host document | Preact 10 + hooks, in our iframe under our origin and our CSP |
| Cached | `/v1/kb-widget.js` — path pinned to the envelope major, contents mutable, held by customer caches for months | content-hashed, immutable |

The loader is *not* a Preact app. It creates a launcher, mints the session, opens and closes an iframe, and constructs the bridge — nothing else. Putting Preact in the loader would triple the only file whose size the customer's page actually pays for on every navigation.

### The budget, and the thing that enforces it

```jsonc
// apps/widget/.size-limit.json — brotli is size-limit's DEFAULT compression; never restate it as kB-gzip
[
  { "name": "loader br",      "path": "dist/loader/kb-widget.js",      "limit": "5 kB"  },
  { "name": "app shell br",   "path": "dist/app/assets/index-*.js",    "limit": "30 kB" },
  { "name": "renderer br",    "path": "dist/app/assets/renderer-*.js", "limit": "60 kB" },
  { "name": "app total br",   "path": "dist/app/assets/*.{js,css}",    "limit": "100 kB" }
]
```

`pnpm --filter widget size` runs in the same CI job as the build and fails the PR. A budget nobody measures is a wish. markdown-it plus DOMPurify does not fit beside the shell, which is why the renderer is a **lazy chunk** prefetched when the panel opens rather than a static import — the first assistant token is seconds away, and the launcher is not.

**What 30 kB for the shell rules out, and the two decisions that follow.** `react` + `react-dom` is roughly 45 kB gzip on its own — the entire app budget before one component exists; Preact 10 with hooks is single-digit kB. <!-- UNVERIFIED: published figures for both disagree by 2× depending on entry points measured; re-measure against our own build before quoting a number in a PR. --> That is ADR-007's runtime half. It also rules out sharing `apps/web`'s UI layer: shadcn/ui sits on Radix primitives, which are React-only, reach into `react-dom` internals, and arrive with Tailwind's class surface. `preact/compat` can host some of them, but it is an aliasing layer that suppresses exactly the build error that tells you a React dependency got in. **So `apps/widget` defines no `react` → `preact/compat` alias.** An accidental `import … from '@/components/ui/button'` then fails the build with `Failed to resolve import "react"` instead of silently adding 12 kB. Exactly two things *are* shared. **`packages/design-tokens`** is zero-runtime (CSS custom properties in a `.css` file, no JS, re-declared on the iframe's `:root` rather than inherited). **`packages/contracts` is not, and the budget line must stop pretending otherwise:** most of it is types that erase, but it also carries **real runtime code** — the SSE frame parser, the client event union's guards, and `KbError` — because three clients now read the same stream and three hand-written parsers is three ways to disagree about `: ping` (`expo-react-native`, `nextjs-app-router`; `admin-web-engineer` owns the package and we import it, never fork it). Budget it explicitly: **≤ 1 kB brotli, inside the 30 kB app-shell entry** — a field parser, a regex and an `Error` subclass, with no dependencies. If it ever exceeds that the answer is to trim what got added to the package, never to raise the `app shell br` limit; `size-limit` is what decides, and the loader never imports it at all. Those are the only workspace packages that exist (docs/19). The Markdown renderer is **not** a third one: markdown-it and DOMPurify are ordinary third-party dependencies, installed directly by each app that renders model output and pinned to one version by the root manifest. `kb-security-baseline`'s *one renderer everywhere* is a rule about **configuration**, and a workspace package would not have enforced it — one call site passing a looser `ALLOWED_ATTR` forks it just as completely. What enforces it is the shared §22.5 XSS corpus every renderer in the monorepo runs. The widget's copy lives in `apps/widget/src/render/markdown.ts`, and it is the lazy chunk the budget above names.

### Isolation: iframe for the app, shadow root for the loader's chrome

| | scoped class names | Shadow DOM | iframe |
|---|---|---|---|
| Host selectors can't reach in | no | yes | yes |
| Our selectors can't leak out | mostly | yes | yes |
| Inherited props (`font`, `color`, `line-height`) blocked | no | **no** | yes |
| Separate JS realm / host can't read our DOM | no | no | yes |
| Our own CSP and `connect-src` | no | no | yes |
| Can paint outside its own box | yes | yes | **no** |
| Cost | 0 kB | 0 kB, `:host { all: initial }`, no `rem` | one extra document + a `postMessage` resize channel |

Neither alone is sufficient, so we use both at their strengths. The iframe carries the chat app (ADR-007) — the only option that also isolates JS and CSP, so the *host page's* `connect-src` constrains nothing we do except the one session-mint POST. But the launcher and the sliding panel must animate and overflow the host viewport, which an iframe cannot do without a full-screen transparent frame that would swallow the host page's clicks; those ~2 kB of chrome live in a shadow root instead.

### Vite config

The two-mode `vite.config.ts` in full — the Vite 8 `oxc` / `rolldownOptions` names, the dev-only preset plugin, the pinned `build.target`, the loader's single-file iife output, and the `define` block that bakes in `__KB_VERSION__`, `__KB_WIDGET_ORIGIN__` and `__KB_API_ORIGIN__` → **[references/vite-config.md](references/vite-config.md)**.

### The loader — bootstrap, double-inclusion, CSP degradation

Public snippet. One classic script, `async`, config in `data-*` attributes so the zero-config install needs **no inline `<script>`** — a customer running `script-src 'nonce-…'` cannot add one for us. No `integrity=`: SRI pins exact bytes, and `/v1/kb-widget.js` is mutable within the major, so an SRI hash would take the widget down on our next patch.

```html
<script src="https://<widget-domain>/v1/kb-widget.js" async data-kb-bot="pub_01J8…" data-kb-position="right"></script>
```

Customers who pass signed end-user identity must run code anyway (their backend renders the token), so they use the queue form — `window.kbq=window.kbq||[];kbq.push(['init',{userToken}])` — which the loader drains on boot. A direct `KB.init(…)` call is *not* the documented entry point: the script is `async`, so on a fast connection the customer's call runs before we exist and throws in their console.

```ts
// apps/widget/src/loader/index.ts
import launcherCss from './launcher.css?inline'   // a STRING; `?inline` stops Vite emitting a .css file

const VERSION = __KB_VERSION__, SENTINEL = '__kbWidget'
// Non-null ONLY at a classic script's synchronous top level — null in every callback, null for
// modules. Read it now or lose the config forever.
const tag = document.currentScript as HTMLScriptElement | null
// A tag manager firing the snippet twice is normal; without this guard, two launchers, two mints
// against the composite rate limit, two bridges — every message sends twice.
const prior = (window as any)[SENTINEL]
if (prior) { console.warn(`[kb] already loaded (${prior.version}); ignoring ${VERSION}`) }
else if (tag?.dataset.kbBot) boot(tag.dataset.kbBot, tag.dataset.kbPosition ?? 'right')

function boot(publicBotId: string, position: string) {
  // A plain <div>, not a custom element: customElements.define() throws NotSupportedError on a name
  // already registered, so a second copy at another version would die instead of warning as above.
  const host = document.createElement('div')
  // :host rules lose to ANY host-page selector matching this element, and `* { position: static
  // !important }` resets are real. Layout that must survive goes on the element, with priority.
  for (const [k, v] of Object.entries({ position: 'fixed', bottom: '16px', [position]: '16px',
    width: '0', height: '0', 'z-index': '2147483000', 'color-scheme': 'light dark' }))
    host.style.setProperty(k, v, 'important')
  const root = host.attachShadow({ mode: 'closed' })   // closed: host script cannot walk our chrome
  // Constructed stylesheets are NOT gated by `style-src`; a <style> element or a style="" attribute
  // is inline CSS and needs 'unsafe-inline'. This is why the launcher survives a strict host CSP.
  const sheet = new CSSStyleSheet(); sheet.replaceSync(launcherCss); root.adoptedStyleSheets = [sheet]

  const frame = document.createElement('iframe')
  // ?bot/?origin/?ch and the handshake are `iframe-postmessage-bridge`'s. `ch` is generated ONCE and
  // reused — URL and every envelope carry the same value or `parse()` drops everything. The origin
  // travels in the URL so Laravel can emit a per-request `frame-ancestors` naming this embedder.
  const ch = crypto.randomUUID()
  frame.src = `${__KB_WIDGET_ORIGIN__}/embed?bot=${encodeURIComponent(publicBotId)}`
            + `&origin=${encodeURIComponent(location.origin)}&ch=${ch}`
  frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox')
  frame.setAttribute('referrerpolicy', 'strict-origin'); frame.title = 'Support chat'; frame.loading = 'lazy'

  // If the customer's CSP lacks `frame-src`, the frame silently never loads: no onerror, nothing in our
  // logs. Watch both signals; degrade() swaps in an anchor to the bot's hosted-chat URL on
  // `chat.<domain>` — same Laravel surface, no frame needed — and emits one `error` SDK event (§8.20).
  let loaded = false
  frame.addEventListener('load', () => { loaded = true }, { once: true })
  const onViolation = (e: SecurityPolicyViolationEvent) =>
    { if (e.blockedURI.startsWith(__KB_WIDGET_ORIGIN__)) degrade(root, publicBotId) }
  document.addEventListener('securitypolicyviolation', onViolation)
  setTimeout(() => { if (!loaded) degrade(root, publicBotId) }, 10_000)

  root.append(buildLauncher(root, frame), frame)
  document.body.appendChild(host)
  // Mint, handshake and envelope validation are `iframe-postmessage-bridge`'s — and so is this exact
  // signature, `attachBridge(frame, ch, o)`. Passing the frame alone leaves `ch` undefined, every
  // envelope then fails `parse()`, and the widget silently never boots.
  const kbq = ((window as any).kbq ?? []) as [string, Record<string, unknown>][]
  ;(window as any)[SENTINEL] = { version: VERSION, destroy: () => {
    document.removeEventListener('securitypolicyviolation', onViolation); host.remove() },
    bridge: attachBridge(frame, ch, { botId: publicBotId, onEvent: emitSdkEvent,  // §8.20 dispatcher
      userToken: kbq.find(([c]) => c === 'init')?.[1].userToken as string | undefined }) }
}
```

## Gotchas

- **The customer sees two launchers and every message arrives twice.** The snippet was injected twice, typically by a tag manager or by a CMS that renders the footer partial on both the layout and the page. The sentinel above is the fix; note it must be checked *before* any DOM is created, and `attachShadow()` on an element that already has a shadow root throws `NotSupportedError`, so re-running `boot()` on the same host fails hard rather than duplicating.
- **`Cannot read properties of null (reading 'dataset')` on some sites and not others.** `document.currentScript` was read inside a `DOMContentLoaded` handler, a `setTimeout`, or a promise callback — it is null everywhere except a classic script's synchronous top level, and null for `type="module"` always. Read it at module top level and close over it; this is also why the loader is built `formats: ['iife']` and not `['es']`.
- **The chat renders in the host's serif font at triple line-height, inside a shadow root.** Shadow DOM blocks *selector matching*, not *inheritance*: `font-family`, `font-size`, `color`, `line-height`, `letter-spacing`, `direction` and `visibility` all cross the boundary, and custom properties pierce it by design. Start the shadow stylesheet with `:host { all: initial; }`, re-declare what you need, and never size anything in `rem` — `rem` resolves against the *host page's* root font size.
- **The launcher renders unstyled, or not at all, on the one customer with a real CSP.** A `<style>` element and a `style="…"` attribute are both inline CSS under `style-src` and need `'unsafe-inline'`. `new CSSStyleSheet()` + `replaceSync()` + `adoptedStyleSheets` is not gated by `style-src` (a known CSP gap — WICG issue below), which is why the shadow chrome survives. <!-- UNVERIFIED: acknowledged at spec level; not re-tested against every 2026 browser, so the launcher must still be legible with no stylesheet applied. --> The customer-facing requirement is exactly three directives: `script-src https://<widget-domain>`, `frame-src https://<widget-domain>` (one origin — Traefik serves the loader and the frame assets from the same `sdk` backend) and `connect-src https://api.<domain>` — the last **only** because the session mint is a POST from the *host* document (that is the whole point: it is the one request carrying an unforgeable `Origin`; `iframe-postmessage-bridge`). Every other API call is the frame's, under our CSP, and needs nothing from them. Note that the third directive names a **different registrable domain** from the first two; a reviewer who "tidies" it into one is reintroducing docs/22 defect 17.
- **The loader is already live on a customer's page and dies with `ReferenceError: __KB_API_ORIGIN__ is not defined` before the launcher paints.** The constant was declared in `apps/widget/src/env.d.ts`, so `tsc` and the editor were both satisfied, but never added to Vite's `define` — and `define` is a *text substitution*, not a binding, so an undeclared `__KB_*` is not a build error; it survives verbatim into the IIFE. Declare every `__KB_*` in both places and assert it: `rg -o '__KB_[A-Z_]+__' apps/widget/src | sort -u` must equal the `define` keys, and the built bundles must contain no `__KB_` substring. The same failure with an *unset* `DOMAIN`/`WIDGET_DOMAIN` is quieter — the loader ships `https://undefined` and every mint fails CORS — which is why `env()` throws at build time.
- **`app shell br` fails on a PR that changed no file in `apps/widget`.** `packages/contracts` is a runtime dependency of the shell now, not a types-only import, so anything added to its entry point is billed to this budget by a build the widget team did not trigger. That is the intended pressure — it is also why the loader must import nothing from it: a loader that pulls in the frame parser to "reuse the types" turns a 5 kB budget into a build failure, and the type-only fix is `import type`, which erases. Watch for the inverse too: a `size-limit` entry silently passing because tree-shaking dropped the parser from a build where the chat app never streams.
- **CI passes at "28 kB" and the actual transfer is 33 kB.** `size-limit` compresses with **Brotli by default** (`"gzip": true` switches it, `"brotli": false` disables compression). Half a team assumes gzip. Put the compression in the `name` field so a diff in a PR comment is unambiguous.
- **The app bundle doubles overnight and no widget file changed.** Someone imported a component from `apps/web` and a workspace hoist made `react` resolvable. With no `preact/compat` alias configured this is a build error, which is the point — do not add the alias to "unblock" a build, and keep the `app total` size-limit entry as the backstop for the transitive case.
- **Production ships `preact/debug` and customers see our warnings in their console.** It is imported for side effects, so tree-shaking cannot drop it and `NODE_ENV` does not gate it. Import it only behind `if (import.meta.env.DEV) await import('preact/debug')`, and grep for it in the build job.
- **The loader build emits a `dist/loader/kb-widget.css` nobody loads, and the launcher is invisible.** `build.cssCodeSplit` defaults to **`false` when `build.lib` is set**, so Vite bundles imported CSS into one sibling `.css` file — and an IIFE on a customer page has nothing to inject it with. Import the stylesheet with the `?inline` suffix so it arrives as a string, as above.
- **A Vite 7 config keeps working, so the deprecation is invisible until it isn't.** Vite 8 auto-converts `build.rollupOptions` → `build.rolldownOptions`, `esbuild.*` → `oxc.*`, and `optimizeDeps.esbuildOptions` → `optimizeDeps.rolldownOptions`. The conversion is lossy for anything Rolldown names differently, and it is scheduled for removal. Write the Vite 8 names; a stale `esbuild: { jsx: … }` silently loses JSX config the day it is dropped.
- **The widget works everywhere except one customer's older mobile traffic.** `build.target` defaults to `'baseline-widely-available'` = `chrome111, edge111, firefox114, safari16.4, ios16.4`. That is *our* risk appetite applied to *their* audience. Pin the list explicitly so a Vite minor cannot move it, and re-run `size-limit` after lowering it — extra transpilation and polyfilling is the single largest uncontrolled source of budget growth.
- **Preact 11 looks released; it is not.** `npm view preact dist-tags` shows `latest: 10.29.8` and `beta: 11.0.0-beta.2`. A `^11` range in the manifest is unsupported and the signals/compat ecosystem has not moved. Stay on 10.x. (Third-party storage partitioning is a real second trap here — it belongs to `iframe-postmessage-bridge`.)

## Official docs

- [Vite — Library Mode](https://vite.dev/guide/build), [Build Options](https://vite.dev/config/build-options) and [Shared Options](https://vite.dev/config/shared-options) — `build.lib.{entry,name,formats,fileName}`, `cssCodeSplit`'s lib default, `target`, `minify`, `oxc.jsx`, `resolve.alias`, and **`define`** (a text substitution, not a binding).
- [Vite — Migration from v7](https://vite.dev/guide/migration) and [Vite 8 announcement](https://vite.dev/blog/announcing-vite8) — the Rolldown swap, `rolldownOptions`/`oxc` renames, Oxc minifier, Lightning CSS.
- [Preact — Getting Started](https://preactjs.com/guide/v10/getting-started/), [@preact/preset-vite](https://github.com/preactjs/preset-vite) and [size-limit](https://github.com/ai/size-limit) — `jsxImportSource`, prefresh, `preact/debug`; config keys, Brotli default, CI failure behaviour.
- [MDN — Using shadow DOM](https://developer.mozilla.org/en-US/docs/Web/API/Web_components/Using_shadow_DOM), [`adoptedStyleSheets`](https://developer.mozilla.org/en-US/docs/Web/API/Document/adoptedStyleSheets), [`document.currentScript`](https://developer.mozilla.org/en-US/docs/Web/API/Document/currentScript) and [WICG/construct-stylesheets #98](https://github.com/WICG/construct-stylesheets/issues/98) — inheritance across the boundary, constructable stylesheets, the null cases that break bootstrap, and why `replaceSync()` escapes `style-src`.

## Definition of done

- [ ] `pnpm --filter widget build && pnpm --filter widget size` is a required CI check; all four `.size-limit.json` entries pass and the PR comment names the compression
- [ ] `rg -n "rollupOptions|esbuild:|EventSource|preact/compat|localStorage" apps/widget` returns nothing; `preact/debug` appears only inside an `import.meta.env.DEV` branch
- [ ] `dist/loader/kb-widget.js` is a single file — no dynamic import, no emitted `.css`, no second request from the host document — and `pnpm --filter widget why react` finds nothing
- [ ] Host-page harness tests (browser rules: `vitest-playwright`): snippet injected **twice** → one launcher, one iframe, one session mint, one warning; `Content-Security-Policy: default-src 'self'` → launcher still styled and `degrade()` links to hosted chat within 10 s; `* { font-family: cursive !important; line-height: 3 } div { position: static !important }` → computed position and typography unchanged
- [ ] Streaming runs against a real SSE fixture that stalls and hangs up mid-stream; stop-generation aborts via `AbortController` and the `error` event's `error_class` reaches the UI (`kb-internal-api-contracts`)
- [ ] The frame parser and `KbError` are imported from `@kb/contracts` and defined nowhere in `apps/widget` (`rg -n "function parseFrame|class KbError" apps/widget` is empty); `dist/loader/kb-widget.js` contains no `@kb/contracts` runtime code, and the shared package's contribution to `app shell br` is under 1 kB
- [ ] `rg -o '__KB_[A-Z_]+__' apps/widget/src | sort -u` equals the `define` keys and `src/env.d.ts`'s declarations; neither built bundle contains the substring `__KB_`; a build with `DOMAIN` unset fails; a test asserts `__KB_WIDGET_ORIGIN__` and `__KB_API_ORIGIN__` differ in eTLD+1 (`traefik-routing` DoD)
- [ ] The `postMessage` handshake, origin checks, `attachBridge`'s arity and the event schema are asserted by `iframe-postmessage-bridge`'s suite, not re-tested here
