/**
 * The PUBLIC chat request body — exactly two keys, `extra` rejected by the FormRequest
 * (kb-internal-api-contracts). Every client's type is generated from here.
 *
 * `content`, not `text`: `text` is the `token` EVENT's field name. Posting it 422s every send,
 * and the failure is per-client — the shared suite stays green because each client's fixtures were
 * written from the same source of truth the client was.
 *
 * `client_message_id` is minted by the client (a stable per-message UUID, NOT a per-render one) so
 * Laravel's idempotency key collapses a genuine duplicate — a double submit, a StrictMode double
 * effect, a reconnect — into one conversation, one provider call, one bill.
 */
export interface ChatSendBody {
  readonly client_message_id: string;
  readonly content: string;
}
