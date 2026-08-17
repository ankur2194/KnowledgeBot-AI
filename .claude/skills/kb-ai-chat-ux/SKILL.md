---
name: kb-ai-chat-ux
description: The chat surface every KnowledgeBot client renders — the first-run hero and suggestion chips, the composer, message turns, the visible stages of a streaming answer, citation markers and the sources panel, the refusal state when evidence is thin, degraded-mode disclosure, feedback and regenerate. Use whenever building or changing hosted chat, the widget panel, the mobile chat screen, the admin playground or conversation review; whenever wiring an SSE event to something a user sees; and whenever deciding how an answer shows where it came from. Pairs with kb-rag-query-contract (the stages behind it) and kb-internal-api-contracts (the events it consumes).
---

# The AI chat surface

**Consumes:** `kb-internal-api-contracts` (the normalized SSE schema) · **Reflects:** `kb-rag-query-contract` (the 20 stages), `bge-reranker` (why stage 11 is capability-gated), `kb-error-taxonomy` (the 18 classes) · **Built from:** `kb-design-language`, `kb-ui-patterns`, `kb-motion-and-effects` · **Rendered by:** `nextjs-app-router` (hosted chat + playground), `preact-vite-library` (widget), `expo-react-native` (mobile)

This is the product. Four surfaces render it — hosted chat, the embedded widget, the mobile app, the admin playground — and they render **the same chat**, not four interpretations of one. The widget is not a reduced chat in a small box; it is this chat in a small box.

## Non-negotiables

- **Citations come from retrieved evidence, assigned before generation — never from model output.** A marker in the rendered answer maps to an evidence item the pipeline chose; the UI never parses `[1]` out of the text and looks up what it might mean. A citation the model invented is a fabricated source attribution shown to a customer's customer, and it is platform non-negotiable #8, not a nicety.
- **Retrieved content and model output are untrusted data, always rendered as text.** One sanitizer path — `markdown-it` → DOMPurify → `replaceChildren` — serves all four surfaces, and a chat feature never widens it. No `img` added back to make an avatar render, no raw-HTML typography plugin, no styling hooks on attributes the model can write (`kb-security-baseline`, `tailwind-shadcn`).
- **A refusal is a designed state, not an error.** When evidence is below the threshold the bot says it does not know, and that answer is *correct behaviour* — a grounded system refusing is the feature. It renders as a normal assistant turn with a distinct, calm treatment and a next step ("Try rephrasing", "Add a source"), never as a red error, never as a retry prompt, and never in a way that makes the user think the product broke.
- **The client talks only to Laravel.** There is no code path from a browser, a widget iframe, or a phone to FastAPI. Adding one is an architecture violation (`kb-architecture-map`).
- **Never render the error envelope's `message`.** Map `error_class` to a sentence and show the `request_id`. `error_class: null` means unknown, and unknown is permanently non-retryable — so no retry affordance on it (`kb-error-taxonomy`, `kb-ui-patterns` → `references/states.md`).
- **Nothing animates per streaming token** — one caret for the whole stream (`kb-motion-and-effects` E8).
- **Autoscroll is conditional and surrenders immediately.** Follow the stream only while the viewport is already pinned to the bottom; the first upward scroll stops it for the rest of the message and shows a "Jump to latest" affordance. A view that drags the user away from the paragraph they scrolled back to re-read is the single most complained-about behaviour in a streaming UI.
- **No provider credential, internal hostname, model id, or span/trace identifier reaches this surface.** The model *name* may be shown where the bot is configured to show it; nothing from the internal envelope is.

## The screen

```
┌ header: bot avatar · bot name · [ new chat ] · [ ⋯ ] ────────────────────┐
│                                                                          │
│                        (empty state, first run)                          │
│                          ✦  bot avatar mark                              │
│                     Ask anything about <corpus>                          │
│                  one sentence on what this bot knows                     │
│                                                                          │
│      [◐ Suggestion one ]  [◑ Suggestion two ]  [◒ Suggestion three ]     │
│                                                                          │
│  ── or, once a conversation exists ─────────────────────────────────────  │
│                                              ┌─────────────────────────┐ │
│                                              │ user turn, --primary-soft│ │
│                                              └─────────────────────────┘ │
│  assistant turn — full width, no bubble, --text-md, --foreground         │
│  …with citation markers¹ ² inline                                        │
│  ┌ Sources ───────────────────────────────────────────────────────────┐  │
│  │ ¹ Handbook.pdf · p. 14   ² acme.com/pricing                        │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│  [copy] [regenerate] [👍] [👎]                          gpt-5 · 1.2s      │
├──────────────────────────────────────────────────────────────────────────┤
│ ┌ composer: --card, --radius-xl, gradient ring on focus (E5) ──────────┐ │
│ │ Ask a question…                                                      │ │
│ │ [ 📎 Attach ] [ ⚙ Tools ]                          [ 🎙 ] [ ➤ send ] │ │
│ └──────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

### Turns

- **User turn** — right-aligned, `--primary-soft` on `--primary-soft-foreground`, `--radius-2xl` with the bottom-right corner at `--radius-sm` (the tail), max 80% width, `--text-md`. It is a bubble because it is short and attributable.
- **Assistant turn** — **full width, no bubble, no background.** `--text-md` on `--foreground` with normal prose spacing. A long grounded answer in a bubble is unreadable, and the visual asymmetry is what makes it obvious at a glance who is speaking.
- Avatars: the bot's on the assistant side at 1.75rem, `--radius-full`, aligned to the first line. The user gets no avatar — they know who they are, and it costs a column of horizontal space the answer needs.
- Turn spacing `--space-6`; within a turn, prose spacing from the sanitizer's styles.
- Tables, code blocks and lists inside an answer use the same patterns as the rest of the product (`kb-ui-patterns`), with code in `--card-inset` and a copy affordance.

### The composer

- `--card`, `--radius-xl`, `--shadow-md`, auto-growing textarea to a max of ~40% of the viewport then scrolling. Placeholder in `--muted-foreground` (it is text; `--subtle-foreground` does not clear 4.5:1).
- The focus treatment is the gradient ring, E5 — **and it is decoration.** The keyboard focus ring still applies to the textarea (`kb-motion-and-effects` → *Focus*).
- Controls row beneath the input, inside the same surface: attach, tools/prompts, then right-aligned voice and send. Send is `--primary`, `--radius-full`, 2rem, disabled while empty, and **becomes Stop while a stream is running** — same position, so the muscle memory works.
- Enter sends, Shift+Enter newlines, on desktop. **On mobile and in the widget, Enter newlines and send is the button** — a phone keyboard's return key is not a submit key and treating it as one loses half-typed questions.
- Attachment chips appear above the input with a per-file progress bar, a remove control, and per-file errors in place. Constraints (type, size, count) are stated before the picker opens, not discovered after.

### Suggestion chips

The first-run affordance, and the thing that makes the empty state useful instead of intimidating.

- A `--card` pill, `--radius-full`, `--shadow-sm`, with a **tone-tinted 1.5rem icon badge** on the left (`kb-motion-and-effects` E3) and `--text-base` label.
- Three to six, from the bot's configuration — **never generated client-side from the corpus**, which would leak what the corpus contains to anyone who can open the widget.
- Tone assignment is by stable key, not by index (`kb-ui-patterns` → `references/catalog.md`, P7).
- Clicking one fills the composer and sends. It does not open a menu.

## The visible stages of an answer

`kb-rag-query-contract` has twenty stages; a user needs four. Map the SSE events onto exactly these, and do not invent a fifth.

| Visible state | Trigger | What renders |
| --- | --- | --- |
| **Sent** | request accepted | The user turn appears immediately, optimistically. The composer clears and disables |
| **Working** | before the first token | The assistant turn's skeleton plus **one honest label**: "Searching your sources…" then "Writing an answer…". Not a percentage, not a stage counter, not the internal stage names |
| **Streaming** | first token | Text appends with the single caret (E8). Actions are hidden; Stop is live |
| **Settled** | terminal event | Caret removed, citations and sources resolve, actions appear, metadata line renders |

Settled has four outcomes and each has a visual:

- **Answered** — sources panel, actions, metadata.
- **Refused** — the calm treatment above. The assistant turn carries a small `--tone-slate-surface` note: *"I couldn't find enough in this bot's sources to answer that."* plus one or two next steps. No red, no error glyph, no retry button.
- **Degraded** — an answer was produced but a stage did not run as configured; the commonest is reranking being unavailable because the provider cannot rerank (`bge-reranker`, ADR-030). A `--warning-soft` inline note under the answer: *"Answered without re-ranking results — the provider for this bot doesn't support it."* Quiet, in place, and never a modal.
- **Failed** — class-mapped sentence, `request_id`, and a retry **only** if the class is retryable.

**Stopping is a first-class outcome, not a cancellation.** Stop keeps the partial answer, marks it as stopped, and offers Regenerate. Discarding what was already streamed throws away work the tenant was already billed for.

## Citations and sources

- **Marker** — a superscript numeral, `--text-caption`, `--primary`, with a 4px hit-area pad so it is tappable. Hovering or focusing it previews the evidence in a popover; activating it scrolls the sources panel to that item and highlights it (E13).
- **Sources panel** — beneath the answer, collapsed to one line when there are more than three. Each item: index, source title, locator (page, sheet, slide, heading or URL), and the source-type tone badge. It links to the source detail where the user has access to it, and to nothing where they do not.
- **Every marker resolves.** A marker with no evidence item behind it is a bug in the pipeline that must render as *nothing* rather than as a dangling superscript — drop the marker, keep the text, and log it. Showing a broken citation is worse than showing none, because a citation is a claim about provenance.
- **The evidence snippet shown in the preview is source text, and it is untrusted** — sanitized, never HTML, and clearly framed as a quotation from the source rather than as the bot speaking.
- Conversation review in the admin shows the same panel plus the retrieval detail an operator needs; it does not get a different citation model.

## Feedback, regenerate, and the metadata line

- Actions appear on the settled turn only: copy, regenerate, thumbs up/down. On desktop they may appear on hover; on touch they are always visible, because there is no hover.
- Thumbs-down opens a small reason picker — inaccurate, not from my sources, incomplete, unsafe — because an unqualified downvote is not usable signal for `rag-eval-engineer`.
- The metadata line is `--text-caption` `--muted-foreground`: the model name where the bot exposes it, and the elapsed time. **Not** the token count (billing detail on a customer's customer's screen), **not** the request id (that appears on errors, where it is a handle), and **not** anything from the internal envelope.

## Accessibility of a streaming answer

The single most-broken thing in every chat UI, and it is worth getting right once.

- The assistant turn is a `aria-live="polite"` region with `aria-atomic="false"` — **and the live region is the turn, not each token.** A live region updated per token makes a screen reader stutter unusably.
- Better still: leave the streaming text out of the live region and announce the **state transitions** instead ("Searching your sources", "Answer ready, 3 sources"), leaving the answer itself as ordinary readable content the user navigates when they choose. This is what actual screen-reader users prefer, and it is what the four-state model above is shaped for.
- The Stop control is reachable by keyboard the entire time a stream runs, and it is the first thing in tab order after the streaming turn.
- Citation markers are `<button>`s with accessible names that say what they cite ("Source 1: Handbook.pdf, page 14"), not bare superscript text.
- Composer: `aria-label` on the textarea, `aria-describedby` for the send hint, and the character/attachment limits announced when approached, not when exceeded.
- Full checklist in `kb-ui-accessibility`.

## Surface deltas

| | Hosted chat | Widget | Mobile | Playground |
| --- | --- | --- | --- | --- |
| Shell | full app shell, conversation list | panel only, no list unless configured | tab-less full screen, composer above the keyboard | inside the admin shell, in a card |
| Width | 48rem centred | panel width | full bleed | card width |
| Send key | Enter | button only | button only | Enter |
| Sources | inline panel | inline panel, collapsed by default | bottom sheet | inline panel + retrieval detail |
| Extra | — | branding per `bots.theme_configuration` | offline banner; a backgrounded stream is **stopped**, not left billing | model/parameter controls, no tenant theming |

The mobile row's last item is a real requirement, not a polish item: an abandoned stream keeps consuming the tenant's quota for an answer nobody will read (`expo-react-native`).

## Gotchas

- **The answer streams beautifully in the browser and arrives in one jump on a phone.** Hermes' XHR-based `fetch` exposes no `ReadableStream` and fails *silently* — the request succeeds and the body arrives whole. Use `expo/fetch`. A Node-based test suite cannot catch this, because `fetch` streams in Node (`docs/23`).
- **Tokens go missing in production and never in development.** The SSE parser does not handle an event split across chunk boundaries. It must also handle multi-line `data:` fields, the `: ping` heartbeat comment, and a stream that ends with no terminal event. Import the parser from `packages/contracts`; a second copy is the drift `contract-steward` exists to catch.
- **`EventSource` looks like the obvious tool and is forbidden.** It is GET-only, carries neither the POST body nor a header, and its automatic `Last-Event-ID` reconnect would replay a token stream that is not resumable.
- **The user turn appears twice.** It was rendered optimistically and again from the server's echo. Reconcile on the client-generated id, and send that id as the idempotency key so a retried request does not create a second turn server-side either (`kb-internal-api-contracts`).
- **A refusal gets styled as an error, and support tickets follow.** Someone mapped "no answer" to the error path. A refusal is a successful, correct outcome; it shares no styling with a failure.
- **The sources panel shows a source the user cannot open, and the link 404s.** Access to a *source detail* is not implied by access to a *bot that answers from it*. Render the citation, link only where the viewer has access, and never explain the difference in the UI on a public surface (`kb-ui-patterns` → `references/states.md`, *Forbidden*).
- **Markdown from the model breaks the layout.** A very wide table or a long unbroken URL. Constrain: tables scroll inside their own container, code blocks wrap or scroll, and `overflow-wrap: anywhere` on the prose container. The page body never scrolls horizontally.
- **The composer's gradient ring is mistaken for the focus indicator** and the real one gets removed as a duplicate. E5 says this explicitly for this reason.
- **A new conversation inherits the previous bot's theme for one frame.** The theme is scoped per bot and the scope element re-renders after the messages do. Key the scope on the bot id so React remounts it (`tailwind-shadcn`).

## Definition of done

- [ ] All four visible states render, and the internal stage names appear in none of them
- [ ] Refused, degraded, stopped and failed are four distinct treatments, and refused shares nothing with failed
- [ ] Every citation marker resolves to an evidence item; an unresolvable marker is dropped and logged, never rendered
- [ ] No marker is parsed out of model text; citations come from the evidence the pipeline assigned
- [ ] Model output and source text render through the single sanitizer with no widened allow-list
- [ ] The SSE parser is imported from `packages/contracts`, and the chat path is tested against a fixture server that emits real chunks over time — `route.fulfill()` cannot chunk an event stream, and a mocked stream is a false green
- [ ] Autoscroll follows only while pinned to the bottom and surrenders on the first upward scroll
- [ ] Stop preserves the partial answer and offers Regenerate
- [ ] One caret, no per-token animation
- [ ] State transitions are announced; the token stream is not announced per token
- [ ] No envelope `message`, no token count, no internal identifier is rendered; `request_id` appears on errors only
- [ ] Enter sends on desktop and does not send on touch surfaces
- [ ] A backgrounded mobile stream is stopped
- [ ] The same conversation was viewed in hosted chat and in the widget, in both colour modes
