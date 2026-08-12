<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Internal API — Surface: internal. FastAPI -> Laravel ONLY.
|--------------------------------------------------------------------------
|
| Mounted at `internal/v1` on the `internal` middleware group by bootstrap/app.php.
|
| This surface must not be reachable from the internet. It carries no cookie, no bearer token, no
| CORS entry (config/cors.php lists every browser-reachable prefix and deliberately omits this one),
| no Traefik router label, and it never joins the `edge` network. A CI check asserts an external
| curl against the public host returns something other than 200 for /internal/v1/*.
|
| Authentication is HMAC-SHA256 over the canonical string, verified by middleware:
|
|   canonical = "KB1\n" + METHOD + "\n" + PATH + "\n" + X-KB-Timestamp + "\n"
|             + sha256_hex(raw_body) + "\n"
|             + "\n".join(f"{k}:{v}" for k, v in sorted(kb_headers))
|
| Four rules the verifier must implement, each of which is a vulnerability when skipped:
|   1. Recompute the covered header set from ALL X-KB-* headers ACTUALLY PRESENT — never from a
|      caller-supplied signed-headers list, which is itself attacker-controlled.
|   2. hash_equals(), never ===. A byte-wise compare leaks the signature one byte at a time.
|   3. Reject |now - X-KB-Timestamp| > 60 s AND reject a repeated X-KB-Request-Id inside 120 s
|      (SET NX EX 120 on nonce:{key_id}:{request_id}). A wide window with no nonce is not a replay
|      defence; the two must be set together.
|   4. Accepted prefixes come from CONFIGURATION (config/kb.php), so a prefix bump can be a
|      two-deploy operation — verifier accepts both for one window while the signer emits one.
|
| Endpoints this file will hold (kb-internal-api-contracts): the async job callbacks
| POST /internal/v1/callbacks/{ingestion|crawl|deletion|evaluation}. Laravel NEVER polls FastAPI;
| the only legal poll is the nightly reconciliation sweep over jobs that have gone silent past their
| timeout.
|
| Every callback carries (job_id, sequence, stage, status) and is applied under
| `WHERE sequence > progress_sequence`. Without that guard a Celery retry re-emitting stage 6 after
| stage 9 flips a `ready` source back to `processing` and takes an already-published version out of
| retrieval.
|
| NOTE ON DIRECTION: the OUTBOUND half of this seam — Laravel -> FastAPI — is not a route. It lives
| in App\Services\Internal\InternalAiClient, which is the only class in this application permitted
| to open a connection to ai-api.
|
*/

Route::group([], function (): void {
    // TODO: signed callback endpoints land here.
});
