---
name: iframe-postmessage-bridge
description: The postMessage channel between the KnowledgeBot widget loader on a customer's page and the chat iframe on our origin — the handshake, the versioned envelope, exact-origin checks, and what survives storage partitioning. Use whenever editing apps/widget bridge code, adding an SDK event, or debugging a widget that never boots, opens by itself, or forgets its conversation on reload. The host page is hostile; the bridge carries no authority. Pairs with kb-security-baseline (which owns CSP and frame-ancestors).
---

# Iframe ↔ Host-Page postMessage Bridge

`apps/widget/` — a loader on the customer's origin, a Preact chat app on ours (ADR-007). Browser baseline verified 2026-08-05 against MDN and the WHATWG HTML Living Standard: `postMessage` Baseline widely available; `frame-ancestors` Baseline since 2018-01; CHIPS (`Partitioned`) Baseline newly available 2025-12 (Chrome/Edge 114+, Safari 18.4+); Storage Access API Baseline since 2023-12. Build, budget and bootstrap: `preact-vite-library`.
**Authoritative spec:** docs/04-functional-channels-chat.md §8.20, docs/13-security.md §18.5, docs/19-repo-structure-adrs.md §28 (ADR-007), docs/05-tech-stack.md §9.2

## Non-negotiables

- **Every send names an exact `targetOrigin`; every receive compares `event.origin` with `===` against one full origin string.** `'*'` delivers to whatever document currently occupies the frame, including one the host swapped in. `startsWith` admits `https://customer.example.attacker.net`, `endsWith` admits `https://evilcustomer.example`, and an unanchored regex admits both (`kb-security-baseline`).
- **`event.source` is checked as well, and first.** Origin names which *origin* spoke, not which *window*. Any frame on the customer's own origin — their ad slot, their tag manager, an `about:blank` frame they created, which **inherits** their origin rather than being opaque — passes an origin-only test and can drive the whole protocol.
- **The bridge carries no authority.** Authority is the opaque, origin-bound session token Laravel mints (`laravel-sanctum-auth`). No message sets organization, bot, end-user identity, provider, model, retrieval scope, or the origin allow-list. A message asks to change *presentation*; it never asserts who is asking.
- **Every inbound message is untrusted input, validated against a versioned schema before any field is read** — the same posture retrieved source content gets. Nothing from a message reaches `innerHTML`, `eval`, `new Function`, `setAttribute('style'|'srcdoc')`, `location`, a `fetch()` URL, or an `<a href>`.
- **Unsolicited inbound messages are dropped.** The frame advertises readiness first and accepts exactly one `init`; anything arriving before it, or on the wrong channel id, is discarded silently. A listener that acts on the first plausible message it sees is a listener the host page drives at page load.
- **Outbound events are metadata only** — ids, counts, durations, `error_class`. Never message text, citation excerpts or URLs, token usage, cost, or anything on `kb-internal-api-contracts`' never-forward list.

## How we use it

### The handshake — the frame speaks first, always

Each side knows exactly one origin constant. The **loader** has `__KB_WIDGET_ORIGIN__`, baked in by Vite `define` (`preact-vite-library`) — never read from `iframe.src` or `document.currentScript.src`, both host-writable before our code runs. The **frame** has the embedder origin from its own `?origin=` query parameter.

**Trusting `?origin=` is sound, and nothing else is available.** An iframe navigation carries no header naming the embedder: `Origin` is not sent on a GET navigation, and `Referer` is suppressible by the embedder. The parameter is attacker-controlled — and self-defeating, because the server echoes the *validated* value into `Content-Security-Policy: frame-ancestors <that one origin>` on the framed document (`kb-security-baseline`). A page at `https://evil.example` claiming `origin=https://customer.example` gets a frame the browser refuses to render, so by the time our script runs the browser has already proved the claim. This holds only because `frame-ancestors` is a real response header on every `/embed` response — it cannot be set in `<meta>` — and because an unregistered origin yields `frame-ancestors 'none'`, never a permissive default.

A message posted to a frame before its document has installed a listener is **dropped, not queued**, with no error at either end. MDN's own channel-messaging tutorial waits for the iframe's `load` event; we deliberately do not, because `load` also fires for the "not authorized for this domain" page the server returns for an unregistered origin — the loader would hand a session token to a document that is ours but is not the app. So the loader creates the frame with `?bot=…&origin=…&ch=…`, `ch` a fresh `crypto.randomUUID()` scoping the channel to *this* frame instance (which is what stops two widgets on one page, or a frame torn down and recreated, from answering each other). The frame posts `{kb:1, ch, type:'ready'}` to `parent` with `targetOrigin` = its `?origin=`, re-posting every 250 ms up to 20 times because an `async` loader may attach its listener after the frame is already up. The loader validates `source === frame.contentWindow`, then `origin === __KB_WIDGET_ORIGIN__`, then `ch`; only then mints the session and replies `init`. The frame accepts exactly one `init` — a second is ignored, because re-initialisation is a state-machine reset an attacker would enjoy.

**We do not transfer a `MessagePort`.** A port is a capability and messages over one carry no meaningful `origin`, which sounds like an upgrade until you notice it buys nothing here: `event.source === parent` already excludes every sibling frame, and a port cannot survive a frame reload without redoing this window-level handshake anyway. It would be a second protocol guarding the same door. <!-- UNVERIFIED: the capability rationale is documented in Channel Messaging design discussion, not as a normative MDN/WHATWG sentence. -->

### Envelope and validation

`{ kb: 1, ch, type, payload }`. `kb` versions the **envelope**, not the widget: a customer pins `<script src=".../v1/kb-widget.js">` and may hold that build for months while the frame app deploys weekly, so the halves are permanently mismatched by design. Unknown `type` → ignore. Unknown `kb` → ignore plus one `console.warn`. Never throw — an exception in a `message` listener on the host page lands in the *customer's* console with our filename on it.

Validation is hand-written type guards, not a schema library; eleven message types do not justify the loader's byte budget (`preact-vite-library`). Guard type *and* value domain: enums against a frozen `Set`, numbers through `Number.isFinite` and a clamp, strings through a length cap before touching any DOM API.

### What the host page may influence

| Inbound `type` | Effect | Why it is safe |
|---|---|---|
| `open` / `close` / `toggle` | frame visibility | presentation only, no server call |
| `set-theme` | `'light' \| 'dark' \| 'auto'` | closed enum; anything else dropped, never coerced |
| `set-locale` | display locale | matched against the bot's configured list; unmatched falls back to the bot default |
| `set-page-context` | page URL + title kept as conversation **metadata** when the bot enables it (§8.20) | data, never instruction — delimited exactly like retrieved content, and the URL is scheme-checked before display |
| `prefill` | composer text, capped, never auto-sent | the visitor still presses send |
| `destroy` | tear down listeners and the frame | idempotent |

Refused with no exception and no "trusted embedder" flag: bot id, organization, session or end-user token, any identity claim, provider or model selection, retrieval parameters, source scope, the origin allow-list, and any CSS, HTML or URL for us to load. The bot binding comes from the URL that minted the session and is never re-read from a message — a `set-bot` message turns one shared allow-list entry into cross-tenant access.

Outbound is the §8.20 set — `widget.opened`, `widget.closed`, `conversation.started`, `message.sent`, `response.completed`, `citation.opened`, `feedback.submitted`, `error` — plus `resize`. Note the direction: **`resize` is frame → host**, because only the frame knows its content height; the host clamps it into its own layout budget.

### Identity — the host page cannot pass it, and must not try

**There is no way for a host page to hand the browser a credential it could not also forge.** Anything the loader can compute, every other script on that page can compute, including an XSS on the customer's marketing site. So identity never crosses this bridge as a claim; it crosses as a token the customer's **backend** signed, verified server-side. The customer's server renders `KB.init({ botId, userToken })`, where `userToken` is HMAC-SHA256 over `{sub, name, email, iat, exp}` with a per-bot shared secret and a `kid`, minted per page render with `exp` ≤ 5 minutes. The **loader** POSTs `{botId, userToken}` to Laravel — this request, and only this one, carries a real unforgeable `Origin: https://customer.example` — and Laravel verifies signature, `kid` and expiry, binds the resolved subject into the Valkey session record, and returns an opaque token (`laravel-sanctum-auth::mint`). The loader then postMessages **the session token only**. The frame learns who the visitor is from our server on its first API call, never from the host page.

**Why the loader mints and not the frame:** an XHR from the frame carries `Origin: https://widget.kbwidget.example`, so moving the mint inside the frame destroys the one host-page fact that cannot be forged. **Why postMessage and not the URL fragment:** a fragment is not sent to the server but it is still a URL — it lands in `location`, in the frame's history entry, and in whatever the customer's analytics scrapes off the DOM; `laravel-sanctum-auth` NN 4 bars tokens in URLs and the fragment is not an exemption. **And yes, the session token sits in host-page JS for one tick** — not a downgrade, because any script on an allow-listed page can mint its own from the same endpoint. What makes it acceptable is the blast radius: one bot, one origin, three abilities, 30 minutes. Nothing broader is ever handed to the loader.

### Storage: what survives partitioning, and what we do when nothing does

The frame is third-party on every customer site, so it relies on nothing a partitioned or blocked backend can take away. Chrome still allows third-party cookies by default (confirmed 2025-04-22 and again on 2025-10-17, which also retired most of Privacy Sandbox and left Related Website Sets on a removal path); Firefox has partitioned them per top-level site since 103; Safari has blocked them outright since 13.1. Relying on an unpartitioned cookie is relying on the one browser that has not moved yet.

**Decision — exactly one persisted value, a resumption token, in a partitioned cookie our API sets on the frame's own requests:** `Set-Cookie: __Host-kbresume=<opaque>; Secure; SameSite=None; Path=/; Partitioned; Max-Age=1800`. `Partitioned` (CHIPS) keys it to the **top-level site**, not origin and not the full ancestor chain — so `customer.example` and `shop.customer.example` share one continuity context while `customer-a.example` and `customer-b.example` never can. That is both the correct privacy boundary and the correct product boundary. Second tier when the cookie does not come back: `sessionStorage` in the frame, which is per-tab, survives a reload, and is *not* blocked by Firefox's Total Cookie Protection. Third tier: memory. **That last tier is a shipped state, not a workaround** — with neither of the first two, every top-level navigation starts a **new conversation**: `conversation.started` fires again, the composer is empty, and the UI promises no history it cannot restore. Build and test that path first; it is what Safari before 18.4 gets, and what Chrome Incognito gets.

Ruled out, with reasons. `localStorage` and IndexedDB: barred for the token anyway, and under Firefox TCP a frame whose domain lands on Mozilla's tracker list gets `SecurityError` from `localStorage`, `indexedDB`, `caches`, `BroadcastChannel`, `SharedWorker` and `ServiceWorker` — a widget on thousands of sites eventually lands on such a list. The Storage Access API: needs transient user activation and grants *unpartitioned* access we actively do not want, since it would let one customer's site reach a session established on another's; its non-cookie extension (`StorageAccessHandle`) is not Baseline, and `requestStorageAccessFor()` is non-standard and deprecated. Related Website Sets: requires proven common administrative ownership of every member domain, which our customers by definition do not share. **And never let the host page hold the resumption token** — a page that can name a conversation can name *another visitor's*.

### The channel, both sides

```ts
// apps/widget/src/bridge/protocol.ts — imported by BOTH documents. One shape, one source of truth.
export const KB = 1 as const;
export interface Envelope { kb: typeof KB; ch: string; type: string; payload?: unknown }

/** Structure only; the caller has ALREADY checked source, then origin. That order is the contract. */
export function parse(d: unknown, ch: string, ok: ReadonlySet<string>): Envelope | null {
  if (typeof d !== 'object' || d === null) return null;   // other SDKs on the page post strings here
  const e = d as Record<string, unknown>;
  if (e.kb !== KB || e.ch !== ch) return null;            // unknown envelope / wrong frame instance
  return typeof e.type === 'string' && ok.has(e.type) ? (e as unknown as Envelope) : null;
}

// ── apps/widget/src/loader/bridge.ts — runs on the CUSTOMER's origin ────────────────────
const FROM_FRAME = new Set(['ready', 'resize', 'error', 'widget.opened', 'widget.closed',
  'conversation.started', 'message.sent', 'response.completed', 'citation.opened', 'feedback.submitted']);

export function attachBridge(frame: HTMLIFrameElement, ch: string, o: LoaderOptions) {
  let initialised = false;
  // Explicit target origin, always: '*' delivers to whatever document now occupies the frame.
  const send = (type: string, payload?: unknown) =>
    frame.contentWindow?.postMessage({ kb: KB, ch, type, payload }, __KB_WIDGET_ORIGIN__);

  addEventListener('message', async (e: MessageEvent) => {
    if (e.source !== frame.contentWindow) return;   // the check an origin test cannot make
    if (e.origin !== __KB_WIDGET_ORIGIN__) return;  // full-string equality — never startsWith/regex
    const msg = parse(e.data, ch, FROM_FRAME);
    if (msg === null) return;
    if (msg.type === 'ready') {
      if (initialised) return;                      // one init per frame instance
      initialised = true;
      // The ONE request carrying an unforgeable Origin: https://<customer>. Minting here instead of
      // inside the frame is the whole reason signed end-user identity works. Every SDK rejection is
      // a 404 with a byte-identical body (laravel-sanctum-auth) — never branch on it.
      const r = await fetch(`${__KB_API_ORIGIN__}/api/v1/sdk/session`, { method: 'POST', mode: 'cors',
        credentials: 'omit', headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ bot_id: o.botId, user_token: o.userToken ?? null }) });
      return r.ok ? send('init', { session: await r.json(), locale: navigator.language })
                  : o.onEvent?.('error', { error_class: 'authentication' });
    }
    if (msg.type === 'resize') {                    // frame → host: only the frame knows its height
      const h = (msg.payload as { height?: unknown })?.height;
      if (typeof h === 'number' && Number.isFinite(h))   // clamp, because it is untrusted arithmetic
        frame.style.height = `${Math.min(Math.max(Math.round(h), 96), innerHeight - 32)}px`;
      return;
    }
    o.onEvent?.(msg.type, msg.payload);             // §8.20 analytics — metadata only, never text
  });
  return { open: () => send('open'), close: () => send('close'), destroy: () => send('destroy') };
}

// ── apps/widget/src/app/bridge.ts — runs on OUR origin, inside the frame ────────────────
const q = new URLSearchParams(location.search);
// Attacker-supplied, and already proven: the server echoed this value into `frame-ancestors`, so a
// page that lied about it never got to render us (kb-security-baseline).
const HOST = q.get('origin') ?? '', CH = q.get('ch') ?? '';
const FROM_HOST = new Set(['init', 'open', 'close', 'toggle', 'set-theme', 'set-locale',
  'set-page-context', 'prefill', 'destroy']);
const THEMES = new Set(['light', 'dark', 'auto']);
let session: string | null = null;

export function toHost(type: string, payload?: unknown) {
  // An opaque origin serialises to the string "null" and cannot be targeted by name; the only send
  // that would work is '*'. Refuse instead of degrading.
  if (HOST !== '' && HOST !== 'null') parent.postMessage({ kb: KB, ch: CH, type, payload }, HOST);
}

addEventListener('message', (e: MessageEvent) => {
  if (e.source !== parent) return;                      // excludes every sibling frame on HOST
  if (e.origin !== HOST) return;
  const msg = parse(e.data, CH, FROM_HOST);
  if (msg === null) return;
  if (session === null && msg.type !== 'init') return;  // the gate: nothing acts before the handshake
  const p = (msg.payload ?? {}) as Record<string, unknown>;
  switch (msg.type) {
    case 'init': {
      const t = (p.session as { token?: unknown } | undefined)?.token;
      if (typeof t !== 'string') return;
      session = t;                                      // authority arrives once, from OUR server
      clearInterval(readyTimer);
      return boot();                                    // resume via __Host-kbresume, else start fresh
    }
    case 'set-theme':                                   // closed enum; never coerce a stray string
      if (typeof p.value === 'string' && THEMES.has(p.value))
        document.documentElement.dataset.theme = p.value;  // dataset, never setAttribute('style')
      return;
    case 'prefill':                                     // .value, never innerHTML; never auto-send
      if (typeof p.text === 'string') composer.value = p.text.slice(0, 2000);
      return;
    case 'open': case 'close': case 'toggle': return setOpen(msg.type);   // emits widget.opened/closed
    case 'destroy': return teardown();
  }
});

// The frame speaks first: anything posted to us before this listener existed was DROPPED, not queued.
let tries = 0;
const readyTimer = setInterval(
  () => (session !== null || ++tries > 20 ? clearInterval(readyTimer) : toHost('ready')), 250);
toHost('ready');
```

## Gotchas

- **The widget never appears and both consoles are empty.** The loader posted `init` before the frame installed its listener; `postMessage` to a document with no listener yet is dropped, never queued, with no error at either end. The frame must speak first — and the frame's `load` event is not a substitute, because it also fires for the "not authorized for this domain" page.
- **The origin check passes for `https://customer.example.attacker.net`.** `startsWith` on `event.origin`. `endsWith` fails symmetrically on `https://evilcustomer.example`, and an unanchored regex on both. Full-string `===`, or `new URL()` and compare `protocol`/`hostname`/`port` (`kb-security-baseline`).
- **The widget opens by itself on a page that also runs an ad tag or a tag manager.** Origin-only check. A sibling frame on the customer's origin passes it — including an `about:blank` frame the host created, which **inherits** the host's origin instead of being opaque, exactly the case people assume is safe. `event.source` is set by the browser from the actual sending browsing context and is what excludes it.
- **`DataCloneError: … could not be cloned` the first time someone puts a class instance or a signal in a payload.** Structured clone throws outright on functions, DOM nodes, Symbols and `Proxy`. The silent half is worse: a class instance *does* clone, arriving as a plain object with the prototype, every getter and every private field gone — a `TypeError` in the other document one frame later. Payloads are JSON-shaped object literals; assert `structuredClone(payload)` in the envelope builder's unit test.
- **Everything works until the customer embeds their own page inside their tag manager's frame.** `parent` is then the tag-manager frame, so the origin no longer matches — and `frame-ancestors` checks **every** ancestor, so the document usually refuses to render first (`kb-security-baseline`). The symptom is a handshake that times out into `degrade()`, never an exception.
- **`event.origin` is the literal string `"null"`.** An opaque origin — someone dropped `allow-same-origin` from `sandbox`, or the frame sits in a `data:`/sandboxed context. Opaque origins cannot be targeted by name, so the only send that works is `'*'`, and two unrelated opaque contexts are indistinguishable from each other. Refuse to send; never allow-list `"null"`.
- **The frame boots everywhere and throws `SecurityError` on Firefox for one customer.** A bare `localStorage.getItem` in the frame. Under Total Cookie Protection (default for all Firefox users since 103, June 2022) a third-party frame whose domain is on Mozilla's tracker list gets `SecurityError` from `localStorage`, `indexedDB`, `caches`, `BroadcastChannel`, `SharedWorker` and `ServiceWorker` — and a widget deployed across thousands of sites eventually lands on such a list. Do not use them; if you must probe, wrap every access.
- **The conversation resets on every navigation for one customer's Safari visitors — and that is correct.** Safari has blocked third-party cookies outright since 13.1 (2020) and only added opt-in `Partitioned` support in 18.4 (March 2025); Chrome Incognito blocks them too. The fresh-conversation path is the default state, not an edge case; a UI that renders a "restoring…" spinner it can never resolve is the actual bug.
- **A resize ping-pong pins a core at 100%.** The frame emits `resize` from a `ResizeObserver`, the host applies a height, the height reflows the frame, the observer fires again. Emit only when the rounded integer height changes, coalesce to one `requestAnimationFrame`, and clamp host-side as above.
- **The host page's analytics contains the visitor's questions.** An SDK event carried `text`, or `citation.opened` carried the source URL. Outbound payloads are ids, counts, durations and `error_class`; `kb-internal-api-contracts`' never-forward list (`provider.usage`, `retrieval.trace`, `provider.fallback`) binds this channel as much as the SSE relay. There is also no documented size limit on `postMessage`, only a practical cliff — another reason transcripts never cross it. <!-- UNVERIFIED: no normative source states a payload limit; third-party measurements put ~100 KiB inside a 100 ms budget. -->
- **A "restore my conversation" feature that takes the conversation id from the host page.** Any customer page could then name any visitor's conversation, and the origin allow-list does not help — the caller is already on an allowed origin. Resumption is authorized only by the opaque token our server put in partitioned storage.

## Official docs

- [MDN — `Window.postMessage()`](https://developer.mozilla.org/en-US/docs/Web/API/Window/postMessage) and [WHATWG HTML §9.3 Cross-document messaging](https://html.spec.whatwg.org/multipage/web-messaging.html) — `targetOrigin` semantics, the security notes, how `origin` and `source` are populated.
- [MDN — `MessageEvent`](https://developer.mozilla.org/en-US/docs/Web/API/MessageEvent) and [`Window.frameElement`](https://developer.mozilla.org/en-US/docs/Web/API/Window/frameElement) — `source` as a `WindowProxy`; `frameElement` is `null` cross-origin.
- [MDN — Structured clone algorithm](https://developer.mozilla.org/en-US/docs/Web/API/Web_Workers_API/Structured_clone_algorithm) — what survives, what throws `DataCloneError`, what silently loses its prototype — and [Using channel messaging](https://developer.mozilla.org/en-US/docs/Web/API/Channel_Messaging_API/Using_channel_messaging), the `MessagePort` handshake we chose not to use.
- [MDN — CHIPS / partitioned cookies](https://developer.mozilla.org/en-US/docs/Web/Privacy/Guides/Third-party_cookies/Partitioned_cookies), [State Partitioning](https://developer.mozilla.org/en-US/docs/Web/Privacy/Guides/State_Partitioning) and [Storage Access API](https://developer.mozilla.org/en-US/docs/Web/API/Storage_Access_API) — the `Partitioned` attribute, the site-level partition key, the activation requirement, `StorageAccessHandle`'s status.
- [MDN — Firefox Storage Access Policy](https://developer.mozilla.org/en-US/docs/Web/Privacy/Guides/Storage_Access_Policy), [WebKit — Full third-party cookie blocking](https://webkit.org/blog/10218/full-third-party-cookie-blocking-and-more/) and [Privacy Sandbox — Update on plans (2025-10-17)](https://privacysandbox.google.com/blog/update-on-plans-for-privacy-sandbox-technologies) — which APIs throw, Safari's baseline, and Chrome keeping third-party cookies.

## Definition of done

- [ ] `rg -n "postMessage\(" apps/widget` — every call passes a literal origin constant, no `'*'` and no origin built from a template or read from `iframe.src`; every `message` listener checks `event.source`, then `event.origin === <constant>`, then `parse()`, before reading any payload field; `rg -n "startsWith|endsWith|includes|RegExp|\.test\(" apps/widget/src/**/bridge*` finds nothing applied to an origin
- [ ] Harness suite (`vitest-playwright`): handshake attempted from an unregistered origin (frame refuses to render); a forged message from a sibling iframe on the host's origin; one from an `about:blank` frame the host created; wrong `ch`; any message before `init`; a second `init`; unknown `type`; unknown `kb`; `set-theme: "<img onerror=…>"`; 1 MB `prefill` — none change widget state, none throw
- [ ] A `set-bot` / `set-session` / identity-claiming message is rejected, and a test asserts the frame's bot id afterwards still equals the one the URL minted
- [ ] `structuredClone()` round-trips every outbound payload in a unit test; an outbound snapshot test asserts no message text, citation URL or excerpt, token, usage or cost appears in any §8.20 event
- [ ] Signed end-user identity is minted by the loader with a real `Origin` and verified server-side; a test asserts an identity claim sent over the bridge is ignored entirely (`laravel-sanctum-auth`)
- [ ] Continuity: with `Partitioned` cookies blocked, a top-level reload starts a new conversation and the UI shows no restore affordance; with them allowed, the same reload resumes. `rg -n "localStorage|indexedDB|BroadcastChannel" apps/widget/src/app` returns nothing
- [ ] Resize is emitted only on an integer height change, coalesced to one rAF, and clamped host-side; a reflow-feedback test runs 5 s without exceeding 60 messages
