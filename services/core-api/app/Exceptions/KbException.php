<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Kb\ErrorTaxonomy;
use InvalidArgumentException;
use RuntimeException;

/**
 * An error that already knows which of the 18 classes it is.
 *
 * IT EXISTS BECAUSE OF ONE RULE: the error class FastAPI assigned crosses to the client VERBATIM,
 * and Laravel never re-derives a class from an HTTP status (laravel-control-plane,
 * kb-error-taxonomy). Without a carrier the relayed class has nowhere to live — the renderer's
 * `match` maps status to class, so a `provider_temporary` relayed as a 503 would come back out as
 * `internal_dependency`, and a client's retry decision would be made against a class the data
 * plane never assigned.
 *
 * The class is validated against the taxonomy at construction rather than in review: an invented
 * class name renders as a class no client branches on, which is a silent failure, and the taxonomy
 * stays at 18.
 */
final class KbException extends RuntimeException
{
    public function __construct(
        public readonly string $errorClass,
        string $message,
        public readonly int $status,
        public readonly string $origin = ErrorTaxonomy::ORIGIN_DOWNSTREAM,
    ) {
        if (! array_key_exists($errorClass, ErrorTaxonomy::RETRYABLE)) {
            throw new InvalidArgumentException(
                "[{$errorClass}] is not one of the 18 error classes. The taxonomy is closed; a "
                .'condition that seems to need a nineteenth is expressible as one of the existing '
                .'rows (kb-error-taxonomy).',
            );
        }

        parent::__construct($message);
    }

    /**
     * Fails schema or business validation: 422, never retried, never fallback-eligible.
     *
     * The class embedding configuration failures land on, matching
     * services/ai-service/app/providers/embedding_selection.py — which records at length why the
     * four plausible alternatives are each wrong, and in particular why `internal_dependency` is
     * wrong under BOTH ADR-029 origins.
     */
    public static function validation(string $message): self
    {
        return new self('validation', $message, 422, ErrorTaxonomy::ORIGIN_SELF);
    }

    /**
     * The AI service could not be reached, or answered in a shape we cannot read.
     *
     * DOWNSTREAM, not SELF: something we depend on is briefly unavailable, so 503 and retryable.
     * The SELF reading of this same class is our own defect and renders 500 with retryable=false;
     * conflating them tells a client to hammer a guaranteed failure down a full backoff ladder.
     */
    public static function aiServiceUnavailable(string $message): self
    {
        return new self('internal_dependency', $message, 503, ErrorTaxonomy::ORIGIN_DOWNSTREAM);
    }

    /**
     * Relay a class the data plane already assigned. The message is operator-facing.
     */
    public static function relayed(string $errorClass, string $message, int $status): self
    {
        return new self($errorClass, $message, $status);
    }

    public function retryable(): bool
    {
        return ErrorTaxonomy::retryable($this->errorClass, $this->origin);
    }
}
