<?php

declare(strict_types=1);

namespace App\Support\Kb;

/**
 * The retry half of the 18-class error taxonomy, as data.
 *
 * THIS IS A MIRROR, AND THE THING IT MIRRORS IS services/ai-service/app/core/errors.py. Both planes
 * answer the same clients with the same envelope, and a consumer cannot tell which one produced it,
 * so a class that is retryable in one and not the other is a client that retries half its failures
 * and reports the other half — which is finding O1, reproduced on a different row.
 *
 * The transcription is unavoidable (PHP cannot import a Python module) but the DRIFT is not:
 * tests/Contract/ErrorTaxonomyParityTest.php reads errors.py as data and fails on any disagreement,
 * in either direction, including a class one plane has and the other does not. That test is the
 * only reason this file is allowed to be a copy.
 *
 * It lives here rather than inline in bootstrap/app.php's render closure for exactly that reason:
 * a table a test can reference is a table a test can check, and an array literal buried in a
 * closure is one it can only re-parse. The closure still owns the RENDERING; this owns the verdict.
 *
 * Statuses are deliberately NOT here. Laravel mints only a handful of these classes itself and
 * relays the rest verbatim from FastAPI, so a status column would be mostly dead rows that nobody
 * exercises — and a dead row is where drift hides.
 */
final class ErrorTaxonomy
{
    /**
     * A dependency of ours is briefly unavailable — the reading `internal_dependency` has by
     * default, and the one that renders 503 and invites a retry.
     */
    public const ORIGIN_DOWNSTREAM = 'downstream';

    /**
     * An unmapped exception in our own code — a defect. Renders 500 and is never retryable
     * (ADR-029, finding O1). Second axis on `internal_dependency` and on no other row.
     */
    public const ORIGIN_SELF = 'self';

    /**
     * Whether the CLIENT may retry. Answers "may the caller try again", not "do we retry
     * internally" — we never self-retry a 429; the client does, once Retry-After has elapsed.
     *
     * All 18 rows are listed explicitly, including the false ones. A "list of the retryable ones"
     * cannot express the difference between `false` and `absent`, so the parity test could not
     * tell a class we deliberately call non-retryable from one we forgot to transcribe.
     *
     * @var array<string, bool>
     */
    public const RETRYABLE = [
        'validation' => false,
        'authentication' => false,
        'authorization' => false,
        'tenant_quota' => false,
        'rate_limit' => true,
        'provider_auth' => false,
        'provider_rate_limit' => true,     // bounded; Retry-After is a floor, not a hint
        'provider_billing' => false,       // an exhausted account will never self-heal
        'provider_temporary' => true,
        'provider_permanent_request' => false,
        'retrieval' => true,               // query only — never an index write
        'parsing' => true,                 // transient sub-cases only; an unsupported file is not
        'ocr' => true,
        'crawl' => true,
        'vector_indexing' => true,
        'storage' => true,
        'internal_dependency' => true,     // the DOWNSTREAM row; see retryable() for the other one
        'user_cancellation' => false,
    ];

    /**
     * The value rendered into the envelope's `retryable` field.
     *
     * Mirrors `retryable_for()` in errors.py, override for override. Reading self::RETRYABLE
     * directly at a rendering site is how the origin sub-case gets lost again, so don't.
     */
    public static function retryable(string $errorClass, string $origin = self::ORIGIN_DOWNSTREAM): bool
    {
        // ADR-029: a self-origin internal_dependency is not retryable. A bug is not a brownout —
        // no number of attempts fixes it.
        if ($errorClass === 'internal_dependency' && $origin === self::ORIGIN_SELF) {
            return false;
        }

        // Unknown classes fall back to NOT retryable rather than throwing. This runs inside an
        // exception renderer, which is the one place an exception cannot be handled — and "do not
        // retry" is the safe default for something we cannot classify. An unknown class is caught
        // by the parity test instead, where a failure is loud and costs nobody a 500.
        return self::RETRYABLE[$errorClass] ?? false;
    }
}
