<?php

declare(strict_types=1);

namespace App\Support\Kb;

use App\Enums\ActorType;

/**
 * THE FORWARD ALLOW-LIST. Which of the data plane's nine SSE frames may reach a client, and to whom.
 *
 * ═══ THIS IS A SECURITY BOUNDARY, NOT A FILTER FOR TIDINESS ═════════════════════════════════
 *
 * `packages/contracts/src/sse/events.ts:8` states the rule this class implements, and states it as
 * the reason the four names are absent from the published union: *"They are internal-only — Laravel
 * consumes them and does not forward them — and a client that can NAME them is a client that can
 * RENDER them. Cost data and internal topology stop at Laravel."*
 *
 * That union is shared by the hosted chat, the React Native app AND the embedded widget, and the
 * widget runs inside an iframe on a page the customer controls and we do not. So the blast radius
 * of forwarding one of these is a stranger's marketing site rendering:
 *
 *   provider.usage      per-attempt token counts, cache read/write split, latency, the vendor's own
 *                       request id, and the `connection_id` of the tenant's provider account — the
 *                       cost data §8.23 bills on.
 *   provider.fallback   that the primary model failed and which one answered instead: our routing
 *                       topology and the tenant's model ladder, exposed per turn.
 *   retrieval.trace     candidate chunk ids, scores, the whole pipeline trace — including the
 *                       `filters` object that names the organization and every allowed version id.
 *   heartbeat           see below: it never crosses this class at all.
 *
 * ═══ ALLOW-LIST, NEVER A DENY-LIST, AND THE DEFAULT IS `false` ══════════════════════════════
 *
 * A tenth frame added on the data plane arrives here unnamed and is DROPPED. The opposite shape —
 * "forward everything except these four" — forwards it, and the failure is silent in the direction
 * that matters: a new internal frame reaches every widget on the internet and nothing says so
 * (`kb-security-baseline`: every deny-list is a bug waiting for an encoding trick).
 *
 * ═══ THE ONE EXCEPTION, AND WHY IT IS AN ACTOR CHECK RATHER THAN A ROUTE ════════════════════
 *
 * `retrieval.trace` reaches an ACTOR OF TYPE `user` and nobody else. That is what makes the D5 admin
 * playground possible without a second streaming endpoint: the same relay, the same frames, one
 * predicate. `kb-internal-api-contracts` words it as *"returned only to the playground when
 * `X-KB-Actor-Type=user` and the actor holds the diagnostics permission"* — the actor-type half is
 * here; the PERMISSION half is the caller's, because this class holds no user and must not resolve
 * one. `ChatGate` is where the permission is checked, and the two are AND-ed: an
 * `anonymous_session` can never satisfy this method, and a `user` who fails the permission check
 * never reaches a stream that was resolved with diagnostics enabled.
 *
 * ═══ `heartbeat` IS NOT ON EITHER LIST AND THAT IS DELIBERATE ═══════════════════════════════
 *
 * It is not one of the nine names in `app/contracts/internal/chat.py::EVENT_NAMES`, because on the
 * wire it is an SSE COMMENT (`: ping`) rather than a named event — FastAPI's own SSE path inserts it
 * after 15 s of generator idleness. `UpstreamStream` surfaces it as a `ParsedEvent` named
 * `heartbeat` so the relay has one thing to match on, and the relay answers it by writing its OWN
 * `: ping` comment rather than by forwarding anything. It is listed in `INTERNAL_ONLY` below so that
 * a caller which DOES ask about it gets `false` rather than a silent fall-through — and so the name
 * appears in exactly one place if the data plane ever promotes it to a real event.
 *
 * ═══ THE NAMES ARE NOT TRANSCRIBED TWICE ════════════════════════════════════════════════════
 *
 * `tests/Contract/ClientEventAllowListTest.php` reads
 * `services/ai-service/app/contracts/internal/chat.py` and `packages/contracts/src/sse/events.ts`
 * as data and fails on any disagreement with the two constants below. Three transcriptions of one
 * list is exactly the shape ADR-052 and finding O1 recurred through; the parity test is what keeps
 * this one honest.
 */
final class ClientEvents
{
    /**
     * The six Laravel forwards. Byte-for-byte `CLIENT_FORWARDED_EVENTS` in
     * `app/contracts/internal/chat.py` and `CLIENT_EVENT_NAMES` in
     * `packages/contracts/src/sse/events.ts`.
     *
     * @var list<string>
     */
    public const FORWARDED = [
        'message.start',
        'status',
        'citations',
        'token',
        'message.complete',
        'error',
    ];

    /**
     * Consumed by the relay and never forwarded — except `retrieval.trace` to an admin actor.
     *
     * The first three are `INTERNAL_ONLY_EVENTS` on the data plane. `heartbeat` is ours: see the
     * class docblock for why it is here and why it is not one of the nine.
     *
     * @var list<string>
     */
    public const INTERNAL_ONLY = [
        'provider.usage',
        'provider.fallback',
        'retrieval.trace',
        'heartbeat',
    ];

    /**
     * The one internal frame an admin actor may receive, and the whole of the D5 playground's
     * server-side story.
     */
    public const DIAGNOSTIC = 'retrieval.trace';

    /**
     * May this frame be written to this client?
     *
     * @param  string  $name  the `event:` name as it arrived from the data plane. Never trusted to
     *                        be one of the nine: an unknown name is `false`, not a pass-through.
     * @param  ActorType  $actor  the actor the stream was authorized for, from the resolved
     *                            credential — never from a request header or a body field.
     * @param  bool  $diagnostics  whether the CALLER established that this actor holds the
     *                             diagnostics permission. Defaults to `false` so a call site that
     *                             has not asked cannot accidentally answer `true`; the actor-type
     *                             check below still applies on top of it, so passing `true` for an
     *                             anonymous session grants nothing.
     */
    public static function allows(string $name, ActorType $actor, bool $diagnostics = false): bool
    {
        if (in_array($name, self::FORWARDED, true)) {
            return true;
        }

        // AND-ed, never OR-ed. `$diagnostics` alone would let a caller that resolved the permission
        // for one surface forward the trace on another; `$actor` alone would hand the trace to every
        // signed-in user whether or not they may read diagnostics.
        return $name === self::DIAGNOSTIC && $actor === ActorType::User && $diagnostics;
    }
}
