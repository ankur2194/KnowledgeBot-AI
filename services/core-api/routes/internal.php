<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\IngestionCallbackController;
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
| no Traefik router label, and it never joins the `edge` network.
|
| RETRACTION. This paragraph used to end: "A CI check asserts an external curl against the public
| host returns something other than 200 for /internal/v1/*." THERE IS NO SUCH CHECK AND THERE IS NO
| CI — `.github/` was deleted on 2026-08-17 and nothing replaced it. The sentence also slipped
| CLAUDE.md's own sweep for false enforcement claims, which matches `gates.yml`,
| `.github/workflows` and `CI grep`: it contained none of the three, so the sweep read clean while
| the claim sat on the route file of the surface it was the only stated defence for.
|
| WHAT IS CHECKED, AND BY WHAT — stated exactly, because a substitute named loosely is the same
| defect again. tests/Security/InternalSurfaceExposureTest.php asserts two things about THIS
| service: that config/cors.php covers no path under this prefix, and that every route here runs
| VerifyInternalSignature and no session, cookie or bearer middleware.
|
| WHAT IS CHECKED BY NOTHING: whether the prefix is reachable from the public internet. That is a
| property of the Docker network membership, the Traefik router labels and the absence of a host
| `ports:` mapping — all of which live in infrastructure/, none of which a test booting this
| application can observe. It is asserted nowhere in this repository today, and the two assertions
| named above must not be read as standing in for it.
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
    /*
     * INGESTION PROGRESS — the data plane reporting one step of one run.
     *
     * `POST /internal/v1/callbacks/ingestion`, signed, carrying (job_id, sequence, stage, status)
     * plus the version identity, the verification verdict and the durable delivery counter. Laravel
     * applies it under `WHERE sequence > progress_sequence` AND a `current_job_id` equality, both
     * read under a row lock on `source_items`, inside the transaction that performs every write the
     * frame asks for.
     *
     * THE GUARD'S TWO COLUMNS ARE ON `source_items` AND NOT ON `source_versions`, because a run is
     * scoped to an ITEM — the worker's Valkey lock is per `source_item_id`, and the run's first act
     * is to resolve the ingest key, which is what DECIDES which version row it belongs to. A
     * sequence counter on the version could not guard the frame that creates the version, which is
     * precisely the frame a redelivery duplicates.
     *
     * A REFUSED FRAME IS A 200 WITH `applied: false`. A 4xx would put a permanently-failing request
     * in front of a Celery task that is going to re-emit it, and the taxonomy would then have the
     * caller retry a frame whose whole meaning is "already superseded".
     *
     * THAT IS THE ORDERING GUARD'S REFUSAL AND NOT EVERY REFUSAL. A MALFORMED frame, and an ILLEGAL
     * STATE TRANSITION, are 422s: they are `validation`, which the taxonomy marks non-retryable, so
     * a Celery task reading the class will not hammer them either. The distinction is which of the
     * two is true of the frame — "you are late" is a 200, "this frame cannot be applied at all" is a
     * 422.
     *
     * NO POLICY AND NO `Gate::authorize()`. The caller is a service; authentication is the HMAC,
     * verified by `VerifyInternalSignature` ahead of `SubstituteBindings`, and the tenant scope is
     * `X-KB-Org-Id` — which is inside the canonical string precisely so this route may trust it.
     *
     * WHY IT IS NOT A `{group}` PARAMETER. `callbacks/{group}` reads well and would put four
     * different sets of table writes behind one action switching on a string, which is where the
     * ordering guard gets skipped for one of them. Crawl, deletion and evaluation each land as
     * their own literal path and their own single-action controller.
     *
     * THE DATA PLANE HAS NO INGESTION ROUTER YET (`services/ai-service/app/api/internal/v1/` holds
     * only `embedding.py`), so nothing calls this route today — a source reaches `queued` and
     * stops. That is the expected state, not a defect: this half is written against the contract so
     * the other half can be written against something.
     */
    Route::post('/callbacks/ingestion', IngestionCallbackController::class)
        ->name('callbacks.ingestion');
});
