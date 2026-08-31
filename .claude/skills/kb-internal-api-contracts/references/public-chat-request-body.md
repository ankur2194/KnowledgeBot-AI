# The public chat request body — reference

Depth for `kb-internal-api-contracts`. Spec: `docs/12-api-areas.md` §17, `docs/04-functional-channels-chat.md` §8.18.

The seam has two halves and both are pinned by that skill. This one is the **public** request every client sends to Laravel — it is not an internal endpoint, but it is the input side of the same contract, and leaving it unowned is how two clients ship two different field names against one FormRequest.

```json
POST /rt/v1/conversations/{conversation}/messages   ·   Accept: text/event-stream
{"client_message_id": "01J…", "content": "Do you refund after 30 days?"}
```

- **Exactly two keys, and `extra` is rejected.** The message text is `content` — the same vocabulary as `messages.Content` (docs/11 §16) and as the provider adapter's `Message.content` (`kb-provider-adapter-contract`), so one name survives browser → Laravel → FastAPI → provider → `messages` row. `text` is *not* an accepted alias: it is already the `token` event's field, and one word meaning "the whole question" on the request and "one delta" on the response is how a client ends up sending a token frame's shape.
- `client_message_id` is a **client-minted ULID, stable across re-renders and retries** of the same composed message. It is the fingerprint half of `chat.message`'s idempotency key, so a double-submit collapses instead of billing two generations.
- A client that posts `text` gets `422` with `errors: {"content": ["The content field is required."]}` on **every** send — a total outage for that one client, invisible to the others and to any test that exercises only the surface that happens to be right.
- **The path is `rt/v1`, and this line was wrong until 2026-08-27.** It read `/api/v1/chat/{conversation}/messages`, which no client has ever called. Both shipped clients pin the `rt/v1` spelling and are tested against it — `apps/web/src/features/chat/stream-answer.ts:89` and `apps/widget/src/app/stream.ts:118` — so the clients won on evidence, not on preference. Worth knowing *why* it survived: this reference owns the **body**, every assertion about the body was correct, and the path sits in the same fenced block as a decoration. A reference that is right about its own subject reads as right about the line above it.

- Laravel derives everything else — organization, bot, actor — from the credential (`laravel-sanctum-auth`). A body field naming an org, a bot, or a model is privilege escalation, not configuration.
