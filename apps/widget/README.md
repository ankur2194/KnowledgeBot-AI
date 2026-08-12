# `@kb/widget` — the embedded chat widget

Two artifacts from one package (ADR-007):

|                 | `dist/loader/kb-widget.js`                                                   | `dist/app/`                                     |
| --------------- | ---------------------------------------------------------------------------- | ----------------------------------------------- |
| What            | the file the customer pastes on their page                                   | the chat application                            |
| Runs on         | the **customer's** origin, in their document                                 | `<widget-domain>`, in our iframe, under our CSP |
| Runtime         | none — plain TypeScript and DOM                                              | Preact 10 + hooks                               |
| Build           | `build.lib`, `formats: ['iife']`, no code splitting                          | ordinary Vite app build, code-split             |
| Served as       | `/v1/kb-widget.js` — path pinned to the **envelope major**, contents mutable | content-hashed, immutable                       |
| Budget (brotli) | 5 kB                                                                         | 30 kB shell, 60 kB lazy renderer, 100 kB total  |

The loader has no authority. It measures, mounts, and forwards; every decision that matters happens
inside the frame, on our origin.

## The embed snippet

This is documentation, not source. It lives here so a customer-facing change to it is a diff a
reviewer sees.

```html
<script
  src="https://<widget-domain>/v1/kb-widget.js"
  async
  data-kb-bot="pub_01J8…"
  data-kb-position="right"
></script>
```

One classic script, `async`, configuration in `data-*` attributes.

- **No inline `<script>` for the zero-config install.** A customer running `script-src 'nonce-…'`
  cannot add one for us, and asking them to loosen their policy to install a chat widget is not an
  install instruction.
- **No `integrity=`.** SRI pins exact bytes and `/v1/kb-widget.js` is mutable within the major, so
  an SRI hash takes the widget down on our next patch — and the patch most likely to matter is a
  security one.
- **`type="module"` would break the bootstrap.** `document.currentScript` is `null` for modules,
  always, so the loader would never see its own `data-*` configuration.

### Signed end-user identity, and the queue

Customers who pass authenticated end-user identity have to run code anyway — their backend renders
the token — so they use the queue form:

```html
<script>
  window.kbq = window.kbq || [];
  window.kbq.push(['init', { userToken: '<%= kbUserToken %>' }]);
  window.kbq.push([
    'on',
    { event: 'response.completed', handler: (p) => analytics.track('kb', p) },
  ]);
</script>
```

**The queue is the identity path, never a direct `KB.init(...)`.** The script is `async`, so on a
fast connection the customer's call runs before we exist and throws in their console. The queue is
drained on boot, whenever boot happens.

**`userToken` is never a `data-*` attribute.** Any script on the host page can rewrite an attribute
before our code reads it. It is HMAC-SHA256 over `{sub, name, email, iat, exp}`, signed by the
customer's **backend** with a per-bot shared secret and a `kid`, minted per page render with
`exp ≤ 5 minutes`, and verified server-side. An identity claim sent over the `postMessage` bridge is
ignored entirely — there is no way for a host page to hand the browser a credential it could not
also forge.

### The three CSP directives a customer needs

```
script-src  https://<widget-domain>
frame-src   https://<widget-domain>
connect-src https://api.<domain>
```

The third names a **different registrable domain** from the first two, deliberately. A reviewer who
tidies them into one is reintroducing the defect the split exists to prevent: the admin session
cookie is scoped to the main domain, so anything of ours on the widget's eTLD+1 makes a widget
iframe on a hostile customer page _same-site_ with a real admin credential.

`connect-src` is needed for exactly one request — the session mint, a POST from the **host**
document, which is the one request in the system carrying an unforgeable `Origin`. Every other API
call is the frame's, under our CSP, and needs nothing from the customer.

If the policy is missing `frame-src`, the frame silently never loads — no `error` event, nothing in
our logs. The loader watches `securitypolicyviolation` and a 10 s timeout, then swaps in an anchor
to hosted chat on `chat.<domain>` and emits one `error` SDK event.

## The SDK event API

`widget.opened`, `widget.closed`, `conversation.started`, `message.sent`, `response.completed`,
`citation.opened`, `feedback.submitted`, `error`.

**This is a commitment to strangers' code.** Any change to a name or a payload shape is breaking.
Payloads are **metadata only** — ids, counts, durations, `error_class`. Never message text, never a
citation excerpt or URL, never token usage or cost. A host page's analytics must not end up holding
the visitor's questions.

## Build

**Prerequisites: Node 22 (`>=22.20.0 <23`) and pnpm 10 on the host.** `.npmrc` sets
`engine-strict=true`, so an install on any other major is refused rather than silently resolved.
If `node` is not found, it is most likely installed under `nvm` and absent from a non-interactive
shell's `PATH` — `nvm use` (the repo pins the major in `.nvmrc`) is usually the whole fix.
**There is no container fallback for these commands.** The `sdk` Compose service builds this
package and then serves the static output — its runtime image carries nginx, not pnpm, so
`docker compose exec sdk pnpm …` does not work. `docker compose build sdk` will produce the
artifacts without a host toolchain, and `ci.yml`'s `node` job runs the full lint / typecheck /
test / `size-limit` set on every push; but iterating locally needs Node on the host.

```bash
pnpm --filter @kb/widget build     # both artifacts
pnpm --filter @kb/widget size      # the four brotli budgets — a build gate, not a target
pnpm --filter @kb/widget test      # the envelope + value-domain unit layer
pnpm --filter @kb/widget e2e       # the two-origin Playwright harness
```

The build requires either `WIDGET_DOMAIN` + `DOMAIN`, or the full-origin form
`KB_WIDGET_ORIGIN` + `KB_API_ORIGIN`. With neither set the build **fails**: `define` is a text
substitution, so `?? ''` would bake `https://undefined` onto a customer's page and every mint would
fail CORS with nothing on our side to see.

`.size-limit.json` measures with **brotli** — size-limit's default — which is why every entry names
the compression. Half a team assumes gzip, and "28 kB" against a 33 kB transfer is how a budget
stops meaning anything.

## Testing notes

- **The harness is cross-origin, and that is the point.** A same-origin fixture passes with every
  origin check removed, which makes it worse than no fixture. `tests/harness/customer-origin.mjs`
  serves the hostile page; `tests/harness/widget-origin.mjs` stands in for nginx, for Laravel's
  `/embed` document (per-request `frame-ancestors`), and for the API on a third port.
- **Streaming is never mocked.** `route.fulfill()` cannot chunk a `text/event-stream` and MSW's
  abort does not reach the handler, so both produce a green suite over a broken read loop. The chat
  path runs against a fixture server that emits over time: `tests/harness/sse-scenarios.mjs`, mounted
  on the API half of `widget-origin.mjs` and keyed by the conversation id, so the request under test
  is the request the application makes. It is deliberately **not** a third copy of the
  `apps/web` / `apps/mobile` twin fixture (those two are held byte-identical by a drift test in
  `packages/contracts`, whose header argues for exactly two) and deliberately not promoted into
  `@kb/contracts`, which carries no test-only code.
- **The stream probe is the one page with no production counterpart.** `/__probe` (served by the
  widget-origin harness, built by `tests/harness/probe.vite.config.ts` from
  `tests/harness/probe/main.ts`) bundles the real `src/app/stream.ts` so the read loop can be driven
  in a browser, on our origin, against the API origin — while `app.tsx`'s submit handler is still a
  stub waiting on `rt/v1`. It lives entirely under `tests/`: **no branch in `src/` knows it exists**,
  so no spec can pass by virtue of test-only code.
- **Every negative assertion is gated behind a positive control.** "Nothing happened" is also true of
  a widget that never booted, which is the worst possible false green on a security spec.
  `expectWidgetBooted()` in `tests/e2e/_shadow.ts` proves the opposite from four observables — one
  shadow root, one launcher and one frame, the frame present in the page's frame tree at our
  `/embed`, and the app inside it at `data-phase="ready"`, which only the completed handshake
  produces. Forged messages are followed by a delivery receipt or a `resize` barrier rather than a
  sleep, so a message that never arrived fails the spec instead of passing it.
- **Loopback cannot express eTLD+1.** `localhost` and `127.0.0.1` are different origins but not
  different registrable domains, so the widget/API domain separation is asserted by the build-time
  guard in `vite.config.ts` and by `traefik-routing`'s deployment test — not here. Point
  `KB_CUSTOMER_BASE_URL` / `KB_WIDGET_BASE_URL` / `KB_API_BASE_URL` at hosts-file names to exercise
  the cookie behaviours too.

## Things that are not negotiable here

- Never `postMessage` to `'*'`, and never handle a message without `event.source` first, then
  `event.origin === <baked constant>`, then `parse()`.
- Never read an origin from `iframe.src`, `document.currentScript.src` or a `data-*` attribute.
- Never store a credential or a conversation identifier where the host page can read it.
- Never inject our styles into the host document beyond the shadow root, and never let the host
  page's CSS reach the chat UI.
- Never import `@kb/contracts/forms` — that subpath carries zod, and keeping it out is what keeps
  the app shell inside 30 kB (ADR-028).
- Never add a React compatibility alias. The build error is the feature. (The package name is
  spelled out only in `eslint.config.mjs`, which bans it — CI greps this tree for the string, and
  the enforcement is the one place it is allowed to appear.)
