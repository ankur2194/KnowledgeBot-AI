<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SourceState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT .../sources/{source}/status` — the two lifecycle moves a HUMAN makes directly.
 *
 * ── THE ACCEPTED SET IS TWO VALUES, NOT FIFTEEN, AND THAT IS THE DESIGN ──────────────────────
 *
 * `SourceState` has fifteen values and `transitionTable()` describes every legal edge between them,
 * but thirteen of those moves are THE PIPELINE WALKING — `fetching`, `parsing`, `normalizing`,
 * `chunking`, `embedding`, `indexing` and the two terminal `ready` flavours are reported by the
 * ingestion callback, not requested by an operator. `AuditLogger` makes the same cut from the other
 * side and states why: disable and enable are separate operations rather than one generic
 * `source.status_changed` because folding them in "would put those two beside thirteen machine
 * transitions and make 'who turned this source off' a query with a WHERE clause on a detail field".
 *
 * So this endpoint accepts `disabled` and `ready` and nothing else. The transition TABLE is still
 * the authority — a `deleting` source refuses both, and the refusal comes from
 * `SourceState::canTransitionTo()` rather than from a second list here — but the endpoint's own
 * vocabulary is the two moves it has audit operations for. Accepting a value with no operation
 * behind it would mean either a state change with no trail or a `record()` call that raises
 * `InvalidArgumentException` at runtime.
 *
 * `queued` IS DELIBERATELY NOT ACCEPTED HERE even though `Ready -> Queued` is a legal edge:
 * re-submission is `POST .../sources/{source}/reprocess`, which mints the `force_nonce` without
 * which a resubmission dedupes against the completed run and silently does nothing. A `queued`
 * value on this route would be that silent no-op with a 200.
 *
 * `deleting` is not accepted for the same class of reason — `DELETE .../sources/{source}` is phase
 * 1 of a two-phase removal and stamps `deleted_at` with it. A status move alone would exclude the
 * source from retrieval while leaving `deleted_at` null, so the retention clock would never start
 * and `purged_at` could never be written (`knowledge_sources_purge_follows_delete` refuses a proof
 * with no delete).
 *
 * ── `ready_with_warnings` IS REFUSED, AND THE REFUSAL IS THE INTERESTING PART ────────────────
 *
 * The two `Ready` flavours are IDENTICAL for retrieval (§8.11: parser and OCR warnings are advisory
 * and never a retrieval predicate) and differ in exactly one thing — whether this source's live
 * content parsed cleanly. That is a FACT ABOUT THE VERSIONS rather than a preference, so a client
 * that could send either would be able to erase the only durable signal saying "this document
 * parsed badly and published anyway". Send `ready`; the service reads the live versions and
 * resolves which of the two it actually means, and the response says which one it chose.
 */
final class UpdateSourceStatusRequest extends FormRequest
{
    /**
     * Authorization is `Gate::authorize()` in the controller. See `IndexSourcesRequest`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'bail',
                'required',
                'string',
                // Composed from the enum cases rather than from string literals, so a value that
                // stops existing stops being accepted in the same edit.
                Rule::in([SourceState::Disabled->value, SourceState::Ready->value]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'This endpoint performs the two lifecycle moves an operator makes '
                .'directly: `disabled` to exclude the source from retrieval immediately while '
                .'keeping every vector, and `ready` to put it back. Send `ready` to re-enable even '
                .'if the source published with warnings — which of the two ready states it lands in '
                .'is read from its live versions, because that is a fact about the content rather '
                .'than a choice. Re-submitting for processing is POST .../reprocess, and removal is '
                .'DELETE on the source itself.',
        ];
    }

    /**
     * The requested move, as a type.
     */
    public function toStatus(): SourceState
    {
        /** @var array{status: string} $data */
        $data = $this->validated();

        return SourceState::from($data['status']);
    }
}
