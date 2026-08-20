<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\KbException;
use App\Services\Internal\InternalRequestSigner;
use App\Support\Kb\ErrorTaxonomy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * HMAC verification for every inbound request on the `internal` surface — FastAPI -> Laravel.
 *
 * ── THIS IS THE ONLY THING AUTHENTICATING A CALLBACK, SO IT FAILS CLOSED EVERYWHERE ──────────
 *
 * The internal surface carries no cookie, no bearer token, no CORS entry and no session. "It is
 * only reachable from the private network" is TOPOLOGY, NOT AUTHORIZATION (`kb-tenancy-isolation`
 * NN4 and docs/06 §11.1) — Milvus's CVE-2025-64513 was exactly one trusted internal header away
 * from full admin. An unauthenticated ingestion callback would let anyone who reaches the container
 * move a source into `ready`, switch an active-version pointer, or rewind a published version out
 * of retrieval.
 *
 * ── THE FOUR RULES, EACH OF WHICH IS A VULNERABILITY WHEN SKIPPED ────────────────────────────
 *
 * 1. THE COVERED HEADER SET IS RECOMPUTED FROM ALL `X-KB-*` HEADERS ACTUALLY PRESENT, never from a
 *    caller-supplied signed-headers list. A list is itself attacker-controlled, so anything omitted
 *    from it can be added or dropped freely — and the header that matters is `X-KB-Org-Id`, which
 *    is the tenant scope for the entire data plane. Flip one unsigned header and a legitimately
 *    signed request executes against another organization.
 * 2. `hash_equals()`, never `===`. A byte-wise compare leaks the signature one byte at a time.
 * 3. TIMESTAMP SKEW AND REPLAY NONCE ARE SET TOGETHER. A 60 s window with no nonce is not a replay
 *    defence — it is a 60 s window in which every captured request can be resent.
 * 4. ACCEPTED PREFIXES COME FROM CONFIGURATION, so a canonical-string change is a two-deploy
 *    operation: the verifier accepts both for one release window while the signer emits one.
 *
 * ── THE CANONICAL STRING IS BUILT BY THE SIGNER CLASS, NOT RE-IMPLEMENTED HERE ───────────────
 *
 * `InternalRequestSigner::canonicalString()` is the one implementation, and this middleware
 * constructs one per accepted prefix rather than assembling the string a second time. A verifier
 * with its own copy of the format is the bug that cannot be caught by an integration test: it
 * reports a 401 on a request that looks correct in every log.
 *
 * ── WHICH KEYS VERIFY ─────────────────────────────────────────────────────────────────────────
 *
 * Every id in `services.ai.hmac.keys`, not just the `active` one. `active` says which id THIS
 * application SIGNS with; a verifier that only accepted that id would reject every callback signed
 * by the other live key during a rotation, which is precisely the window the id exists to survive.
 * The contract notes that callbacks may use their own key id — that is satisfied by adding an entry
 * to the same map, with no code change here.
 *
 * ── EVERY REFUSAL IS `authentication` / 401, AND THE MESSAGE NEVER SAYS WHICH CHECK FAILED ───
 *
 * A verifier that distinguishes "unknown key id" from "bad signature" from "stale timestamp" is an
 * oracle for tuning an attack. One class, one status, one sentence; the specific reason is a log
 * field on our side, which is where it is useful and where it is not published.
 */
final class VerifyInternalSignature
{
    /**
     * `key_id:hex`. Split on the FIRST colon only, because a key id may not contain one but a
     * malformed value may contain several and `explode` with no limit would silently discard the
     * tail rather than refusing the value.
     */
    private const SIGNATURE_PARTS = 2;

    public function handle(Request $request, Closure $next): Response
    {
        $signature = (string) $request->header('X-KB-Signature', '');
        $timestamp = (string) $request->header('X-KB-Timestamp', '');
        $requestId = (string) $request->header('X-KB-Request-Id', '');

        if ($signature === '' || $timestamp === '' || $requestId === '') {
            throw $this->refuse();
        }

        $parts = explode(':', $signature, self::SIGNATURE_PARTS);

        if (count($parts) !== self::SIGNATURE_PARTS || $parts[0] === '' || $parts[1] === '') {
            throw $this->refuse();
        }

        [$keyId, $provided] = $parts;

        /** @var array<string, string> $keys */
        $keys = (array) config('services.ai.hmac.keys', []);
        $secret = $keys[$keyId] ?? null;

        if (! is_string($secret) || $secret === '') {
            // An unknown key id, or a known id whose secret this deployment does not carry. Both
            // are the same refusal: an empty signing key produces a valid-looking signature that
            // verifies against any peer which also resolved the empty string.
            throw $this->refuse();
        }

        // ── RULE 3, FIRST HALF: SKEW ──────────────────────────────────────────────────────────
        //
        // Checked BEFORE the HMAC, deliberately. It is the cheap test, and a request outside the
        // window is refused whatever it is signed with — so computing an HMAC first would spend the
        // work on a request already decided.
        if (! ctype_digit($timestamp)) {
            throw $this->refuse();
        }

        $skew = abs(time() - (int) $timestamp);

        if ($skew > (int) config('kb.signature_skew_seconds')) {
            throw $this->refuse();
        }

        // ── RULE 1: THE COVERED SET IS EVERY `X-KB-*` HEADER ACTUALLY PRESENT ─────────────────
        $kbHeaders = [];

        foreach ($request->headers->all() as $name => $values) {
            if (! is_string($name) || stripos($name, 'x-kb-') !== 0) {
                continue;
            }

            if (strcasecmp($name, 'X-KB-Signature') === 0) {
                continue;
            }

            // The FIRST value. A repeated header is a smuggling shape rather than a legitimate one
            // on this seam — every X-KB-* header is single-valued by contract — and the signer sent
            // one, so taking `[0]` makes a duplicate produce a mismatch rather than a merge.
            $kbHeaders[$name] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        // The RAW body, exactly as received. `getContent()` and never a re-encoded `json_decode`
        // round trip: re-encoding is not byte-stable and produces intermittent 401s on requests
        // that were signed correctly.
        $body = $request->getContent();
        $body = is_string($body) ? $body : '';

        // ── RULES 2 AND 4 ─────────────────────────────────────────────────────────────────────
        $verified = false;

        /** @var list<string> $prefixes */
        $prefixes = (array) config('kb.accepted_signing_prefixes', []);

        foreach ($prefixes as $prefix) {
            $canonical = (new InternalRequestSigner((string) $prefix, $keyId, $secret))
                ->canonicalString($request->getMethod(), $request->getPathInfo(), $body, $kbHeaders);

            // EVERY prefix is compared even after one matches — `$verified = $verified || …` would
            // short-circuit and make the loop's running time depend on WHICH prefix was used, which
            // is a (weak, but free to avoid) side channel on a value an attacker controls.
            $expected = hash_hmac('sha256', $canonical, $secret);

            if (hash_equals($expected, $provided)) {
                $verified = true;
            }
        }

        if (! $verified) {
            throw $this->refuse();
        }

        // ── RULE 3, SECOND HALF: THE REPLAY NONCE ─────────────────────────────────────────────
        //
        // `Cache::add()` is SET NX EX — one atomic operation, never a read followed by a write,
        // whose losing side would let both copies of a replayed request through.
        //
        // NO ORG SEGMENT, and it is the ONE key family in the catalog without one. The reason is
        // ORDERING: the replay check is part of signature verification, and keying it on
        // `X-KB-Org-Id` would make replay protection depend on a value verification has not yet
        // established. It keys on the signing key id and the request ULID instead, which are
        // globally unique, so an org segment would add no isolation.
        //
        // It resolves through the DEFAULT cache store, which is `valkey-core` — the `noeviction`
        // instance — precisely because an evicted nonce reads as "not seen before".
        $fresh = Cache::add(
            'nonce:'.$keyId.':'.$requestId,
            true,
            (int) config('kb.replay_nonce_ttl_seconds'),
        );

        if (! $fresh) {
            throw $this->refuse();
        }

        return $next($request);
    }

    /**
     * ONE refusal for every failed check. See the class docblock: a verifier that distinguishes
     * them is an oracle for tuning an attack.
     */
    private function refuse(): KbException
    {
        return new KbException(
            'authentication',
            'This request could not be authenticated on the internal surface.',
            401,
            // SELF rather than DOWNSTREAM, and it is inert on this row — the origin axis exists for
            // `internal_dependency` and for nothing else, so `authentication` keeps the taxonomy's
            // verdict (never retryable) whichever origin it is given. Stated because a reader will
            // otherwise wonder which one is correct.
            ErrorTaxonomy::ORIGIN_SELF,
        );
    }
}
