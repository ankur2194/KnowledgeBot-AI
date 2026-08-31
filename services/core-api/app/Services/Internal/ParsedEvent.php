<?php

declare(strict_types=1);

namespace App\Services\Internal;

/**
 * One SSE frame as it arrived from the data plane, holding BOTH the decoded payload and the exact
 * bytes it arrived as.
 *
 * ── WHY BOTH, AND WHY THE RAW STRING IS THE ONE THAT IS FORWARDED ─────────────────────────────
 *
 * The relay's job on a forwarded frame is to pass it through unaltered: `Event` on the data plane
 * sets `extra="forbid"` and every `*Data` interface in `packages/contracts/src/sse/events.ts` is
 * generated from the same models, so what FastAPI emitted IS what three clients parse. Re-encoding
 * it through `json_decode()` + `json_encode()` would be a second serializer in the path, and PHP's
 * differs from Python's in ways nobody would notice until they mattered: `/` escaping, `+0.0`,
 * large integers beyond 2^53, and the ordering of an object's keys after a decode into an
 * associative array. `json()` therefore returns the ORIGINAL string.
 *
 * `data()` exists for the frames the relay CONSUMES rather than forwards — `provider.usage`,
 * `retrieval.trace`, `message.complete` — where the finalizer needs the values. Those never go back
 * on the wire from this object, so a decode is free of the round-trip hazard.
 *
 * ── `data` MAY LEGITIMATELY BE ABSENT OR UNPARSEABLE ──────────────────────────────────────────
 *
 * A `: ping` comment carries no `data:` line at all, and a truncated upstream (a killed worker, a
 * proxy that cut the body mid-frame) can deliver `data:` bytes that are not JSON. Neither is an
 * exception here: `data()` answers `[]` and the caller decides. Raising would turn a heartbeat into
 * a 500 and would make a truncated stream fail with a JSON error rather than with the terminal
 * `error` frame the relay is about to write.
 */
final readonly class ParsedEvent
{
    /**
     * @param  string  $name  the `event:` line's value, or `heartbeat` for an SSE comment. Never
     *                        trusted to be one of the nine: `ClientEvents::allows()` is an
     *                        allow-list and an unknown name is dropped.
     * @param  string  $json  the concatenated `data:` payload, VERBATIM. Empty string when the frame
     *                        carried no data line.
     */
    public function __construct(
        public string $name,
        public string $json,
    ) {}

    /**
     * The decoded payload, or `[]` when there is none or it does not parse.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        if ($this->json === '') {
            return [];
        }

        $decoded = json_decode($this->json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The frame, re-framed for the client. One `event:` line, one `data:` line, blank line.
     *
     * The payload is NOT re-encoded — see the class docblock. Newlines inside the payload would
     * break the framing, and they cannot occur: the data plane emits `model_dump_json()`, which
     * escapes every control character inside a string and never pretty-prints. The `str_replace` is
     * the belt on that brace, and it is a REPLACE rather than a refusal because a frame that arrives
     * with an embedded newline is still an answer the reader is owed; splitting it across two
     * `data:` lines is the SSE-correct spelling and is what a compliant parser reassembles.
     */
    public function toSseFrame(): string
    {
        return "event: {$this->name}\ndata: ".str_replace("\n", "\ndata: ", $this->json)."\n\n";
    }
}
