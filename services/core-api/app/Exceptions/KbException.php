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
    /**
     * @param  array<string, list<string>>|null  $errors  per-field messages, on `validation` ONLY
     * @param  bool  $actionable  whether `$message` was written for THIS condition and may be shown
     *                            to an operator, as opposed to being a fixed placeholder chosen to
     *                            say nothing. Defaults to true because every message this class is
     *                            constructed with locally is condition-specific; the one producer
     *                            that must pass false is a relay carrying a downstream placeholder
     *                            (see relayed()). It is NOT a status and nothing may infer one from
     *                            it — see the envelope's `actionable` in bootstrap/app.php.
     */
    public function __construct(
        public readonly string $errorClass,
        string $message,
        public readonly int $status,
        public readonly string $origin = ErrorTaxonomy::ORIGIN_DOWNSTREAM,
        public readonly ?array $errors = null,
        public readonly bool $actionable = true,
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
     *
     * NO `errors` MAP, AND THE ABSENCE IS LOAD-BEARING RATHER THAN AN OMISSION. There is no field
     * to key this refusal on: the body was well-formed and the resolution rule refused the PAIR it
     * named, which is a fact about the organization's connection set. apps/web's embedding client
     * discriminates the ADR-031 resolver refusal structurally, on `error_class === 'validation' &&
     * errors === null`, and that test is only sound while a FormRequest 422 and a RELAYED Pydantic
     * 422 both carry their map — which is why relayed() takes one.
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
     *
     * ── WHY THE RELAYED `retryable` DECIDES THE ORIGIN, AND WHY THAT IS NOT "DERIVING FROM THE
     *    STATUS" ─────────────────────────────────────────────────────────────────────────────────
     *
     * ADR-029 / finding O1 split `internal_dependency` on an ORIGIN axis: `downstream` means a
     * dependency of ours is briefly unwell (503, retryable), `self` means an unmapped exception in
     * our own code (500, never retryable). services/ai-service/app/main.py::_handle_unexpected
     * raises with `origin=Origin.SELF` and therefore puts `retryable: false` on the wire.
     *
     * This factory used to omit `$origin`, so every relayed envelope defaulted to
     * ORIGIN_DOWNSTREAM — and bootstrap/app.php then RECOMPUTED `retryable` from class plus origin,
     * turning the data plane's "our bug, do not retry" into `retryable: true` on the way to the
     * browser. apps/web/src/lib/query/client.ts runs a full backoff ladder on that field, so O1 was
     * re-opened one hop later, on the exact envelope O1 was about.
     *
     * The relayed `retryable` is the data plane's own verdict and is the only evidence of origin
     * that crosses the wire (`origin` is a rendering input, never a wire field — see
     * tests/Contract/InternalDependencyOriginTest.php, "does not carry origin onto the wire"). So a
     * relayed `false` maps to ORIGIN_SELF and a relayed `true` or a missing field maps to
     * ORIGIN_DOWNSTREAM. NOTE WHAT IS NOT DONE: the STATUS is not consulted, because a status is a
     * rendering of a class and re-deriving anything from it is the failure this whole class exists
     * to prevent. `$status` is relayed verbatim either way.
     *
     * The mapping is inert on the seventeen rows that are not `internal_dependency`:
     * ErrorTaxonomy::retryable() overrides on that row alone, so a relayed `provider_temporary`
     * keeps the taxonomy's verdict whatever origin it is given. That is deliberate — the table is
     * the authority on the class, and the origin axis exists for exactly one row.
     *
     * ── AND THE SAME LESSON APPLIED TO `actionable` BEFORE IT CAN RECUR ────────────────────────
     *
     * ADR-052's generalizable form is that a decision about how two planes agree on a field survives
     * only where every hop that copies the field preserves it — and a relay that reads a SUBSET of
     * an envelope is such a hop. `actionable` is the fourth field of that kind, so it is relayed
     * rather than recomputed. It FAILS CLOSED: a missing or non-boolean value becomes `false`, which
     * costs a client nothing but a blander sentence, where a wrong `true` would render a downstream
     * placeholder at an operator as though it were advice.
     *
     * @param  array<string, list<string>>|null  $errors  the `validation` superset, relayed verbatim
     */
    public static function relayed(
        string $errorClass,
        string $message,
        int $status,
        ?bool $retryable = null,
        ?array $errors = null,
        bool $actionable = false,
    ): self {
        return new self(
            $errorClass,
            $message,
            $status,
            $retryable === false ? ErrorTaxonomy::ORIGIN_SELF : ErrorTaxonomy::ORIGIN_DOWNSTREAM,
            $errors,
            $actionable,
        );
    }

    public function retryable(): bool
    {
        return ErrorTaxonomy::retryable($this->errorClass, $this->origin);
    }
}
