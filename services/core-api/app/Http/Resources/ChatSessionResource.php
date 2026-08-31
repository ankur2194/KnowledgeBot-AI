<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The freshly minted chat-session token and its lifetime.
 *
 * ═══ THIS IS THE ONLY RESPONSE IN THE SYSTEM THAT CONTAINS A LIVE CREDENTIAL ═══════════════
 *
 * The plaintext exists here, in the caller's memory, and nowhere else: no column, no log line, no
 * audit `details` payload. Valkey holds only its digest. Two consequences the routes enforce rather
 * than this class:
 *
 *   `Cache-Control: no-store` on the response, because a credential in an intermediary's cache is a
 *   credential for whoever shares that cache.
 *
 *   NO TOKEN IN A URL, EVER — not a query string, not a path segment, not a fragment. It lands in
 *   Traefik access logs, in `Referer` on every navigation away from the page, and in browser history
 *   (`laravel-sanctum-auth` non-negotiable 4). It exists in exactly two places after this response:
 *   the loader's `postMessage` payload and the frame's own `Authorization` header.
 *
 * ═══ THE ENVELOPE IS `{"data": {...}}`, AND THE LOADER NOW UNWRAPS IT ══════════════════════
 *
 * Every success body on this API carries a `data` key — `DumpOpenApiCommand` refuses to publish an
 * operation without one and names the reason — so this ships wrapped and the client is the side
 * that adapts. This paragraph reported the loader as NOT unwrapping it, which was true for the few
 * hours between the two landing on 2026-08-27 and is not true now:
 * `apps/widget/src/loader/bridge.ts` parses through `readSessionGrant()`, which requires
 * `body.data` to be an object, refuses the unwrapped shape outright rather than accepting either,
 * and emits a `session_malformed` SDK error when the shape does not match.
 *
 * Two corrections to what this paragraph claimed, kept because both were wrong in instructive
 * directions. It said the frame "receives a session whose token is the string 'undefined'" — it
 * does not; `apps/widget/src/app/bridge.ts:177` guards `typeof grant?.token !== 'string'` and
 * drops the grant, so the widget never boots. Same silence, one layer earlier, and the
 * `"Bearer undefined"` outcome is what that guard exists to prevent. And it called the repair "a
 * one-line change", which under-read the real problem: the mint is reached from two message
 * handlers, and the harness at `apps/widget/tests/harness/widget-origin.mjs:360` was serving this
 * response UNWRAPPED — so the widget's e2e suite was green over the defect because the fixture
 * agreed with it. The harness is fixed too.
 *
 * WHAT IS STILL OWED, and it is not in either repository yet: nothing compares the widget's
 * expectation against this class's REAL output. `tests/contract/test_object_key_cross_language.py`
 * is the precedent that works — it runs the PHP and compares bytes — and it exists for exactly
 * this failure, two transcriptions agreeing with their own documentation and disagreeing on the
 * wire. Until that check exists for this envelope, both sides are pinned only by fixtures they
 * each wrote.
 *
 * ═══ `expires_in` IS A DURATION AND NOT AN INSTANT, DELIBERATELY ═══════════════════════════
 *
 * An absolute expiry would be compared against the CLIENT'S clock, which on a stranger's machine may
 * be minutes or years wrong — and the proactive refresh (re-mint when within five minutes of
 * lapsing) would then fire constantly or never. A duration is measured from the moment the response
 * arrived, which both sides agree on.
 *
 * It is also not a promise: the TTL SLIDES on every authorized request, so an active conversation
 * outlives this number. It is the floor for an IDLE frame.
 */
final class ChatSessionResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  array{token: string, expires_in: int}  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource['token'],
            'expires_in' => $this->resource['expires_in'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'ChatSessionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'A minted chat-session token. THIS BODY CONTAINS A LIVE '
                    .'CREDENTIAL: never log it, never put it in a URL or a fragment, never store it '
                    .'in localStorage. It belongs in memory and in an `Authorization` header.',
                'required' => ['token', 'expires_in'],
                'properties' => [
                    'token' => [
                        'type' => 'string',
                        // BOUNDED BELOW, BECAUSE EVERY CONSUMER ALREADY REFUSES THE EMPTY STRING
                        // AND THE CONTRACT DID NOT SAY SO (`docs/22` § T51). `''` satisfies a bare
                        // `type: string` and a `typeof x === 'string'` check alike, and yields
                        // `Authorization: Bearer ` with nothing after it — a 401 the client reports
                        // as an authentication failure rather than as the malformed body it is.
                        // The loader refuses it; publishing the bound is what makes that refusal
                        // contract enforcement instead of a client being defensive on its own.
                        'minLength' => 1,
                        'description' => 'Opaque bearer, prefixed `kbw_`. It carries no readable '
                            .'claims — everything about the session is server-side under a key this '
                            .'value derives. It grants exactly three abilities against exactly one '
                            .'bot and can never carry an admin one: it is minted with no human '
                            .'authentication at all, so anyone who can put the loader on an '
                            .'allow-listed page gets one.',
                    ],
                    'expires_in' => [
                        'type' => 'integer',
                        // AND BOUNDED BELOW FOR THE SAME REASON. A published `integer` with no
                        // minimum promises that `0` and `-1` are legal, and a duration of zero
                        // seconds is not a short session — it is a session that has already
                        // expired, which every refresh scheduler turns into either an immediate
                        // re-mint loop or a timer that never fires.
                        'minimum' => 1,
                        'description' => 'Seconds from now. A DURATION and not an instant, because '
                            .'an absolute expiry would be compared against a client clock that may '
                            .'be badly wrong. The TTL slides on every authorized request, so this '
                            .'is the floor for an idle frame rather than a promise about an active '
                            .'conversation.',
                    ],
                ],
            ],
        ];
    }
}
