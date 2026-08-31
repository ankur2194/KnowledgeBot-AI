<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ActorType;

/**
 * One authorized chat turn's identity — everything `X-KB-*` is built from, plus the two message ids
 * the transcript is written under.
 *
 * ═══ EVERY FIELD IS RESOLVED SERVER-SIDE. NOTHING HERE COMES FROM REQUEST INPUT ═════════════
 *
 * `organizationId` and `botId` come from the resolved chat session, never from a header, a query
 * parameter or a body field (`kb-tenancy-isolation` NN6). `conversationId` comes from a route-model
 * binding that was scoped to this organization before the model existed. `actorType` comes from the
 * credential. The ONLY value in this object with a client origin is `clientMessageId`, and it is a
 * ULID validated by a FormRequest whose whole job is to be an idempotency fingerprint — it selects
 * nothing and authorizes nothing.
 *
 * ═══ `messageId` IS MINTED BEFORE THE CALL, NOT BY THE DATA PLANE ═══════════════════════════
 *
 * `message.start` and `message.complete` both carry it, and it must be the id the `messages` row is
 * written under. Minting one on the far side would hand the client an id that resolves to no row —
 * and it is also what makes the finalizer idempotent: the abort path and the completion path key
 * their single write on this value.
 *
 * ═══ `idempotencyKey` IS DERIVED, NEVER MINTED ═════════════════════════════════════════════
 *
 * `sha256(org_id | operation | conversation_id | client_message_id)`, per
 * `kb-internal-api-contracts`' fingerprint table for `chat.message`. A random key would make every
 * retry of a submit a SECOND generation — same question, same retrieval, a second provider bill —
 * and would defeat the entire reason `client_message_id` is client-minted and stable across
 * re-renders.
 *
 * `requestId` doubles as the replay nonce on the internal seam, so it is per-REQUEST and must never
 * be derived from anything stable: two legitimate submissions of the same message would then look
 * like a replay and the second would be refused with a 401.
 */
final readonly class ChatContext
{
    /**
     * @param  string  $query  the visitor's question, already validated and length-bounded by
     *                         `SendChatMessageRequest`. It is the ONE piece of tenant text on this
     *                         object and it is `query` here because that is the INTERNAL field
     *                         name — the public body posts `content`, and neither name is an alias
     *                         for the other.
     * @param  list<string>  $history  stage 3's conversation window, oldest first, already rendered
     *                                 as turns. PER-TURN content and therefore deliberately outside
     *                                 the configuration snapshot: inside it, `configuration_version`
     *                                 would move on every turn and stop being a configuration
     *                                 identity at all.
     */
    public function __construct(
        public string $organizationId,
        public string $botId,
        public string $conversationId,
        public string $messageId,
        public string $clientMessageId,
        public ActorType $actorType,
        public ?string $actorId,
        public string $requestId,
        public string $query,
        public array $history,
        /**
         * Whether this actor may be shown `retrieval.trace`. Resolved by `ChatGate` from the actor's
         * permissions, never from a request parameter, and AND-ed with the actor type inside
         * `ClientEvents::allows()` — so a `true` here on an anonymous session still forwards nothing.
         */
        public bool $diagnostics = false,
    ) {}

    /**
     * `X-KB-Idempotency-Key` for `chat.message`.
     *
     * The `\x1f` unit separator rather than `|`: a pipe is legal inside several of the values this
     * fingerprint family concatenates elsewhere, and one joiner used everywhere is one fewer thing
     * to get right per operation.
     */
    public function idempotencyKey(): string
    {
        return hash(
            'sha256',
            $this->organizationId."\x1fchat.message\x1f".$this->conversationId."\x1f".$this->clientMessageId,
        );
    }
}
