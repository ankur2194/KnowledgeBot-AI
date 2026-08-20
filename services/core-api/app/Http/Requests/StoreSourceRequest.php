<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SourceType;
use App\Services\Sources\NewSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST .../sources` — add one knowledge source.
 *
 * ── THE PART NAME IS `files[0]`, INDEXED EVEN FOR ONE FILE, AND THAT IS A PINNED CONTRACT ────
 *
 * The admin console posts multipart with `files[0]`, `files[1]`, … — indexed from the start rather
 * than switching to `files[]` for a single file — so this request declares `files` and `files.*`
 * and its 422 keys read `files.0`. That spelling is not cosmetic: `apps/web` renders a per-file
 * error against the row the operator can see, and a flat `files` key can only produce a banner
 * about "the upload". Laravel's own array validation produces exactly `files.0` for `files.*`, so
 * the rule set below IS the contract rather than a description of it.
 *
 * ── `type: file` IS DECLARED HERE AND REFUSED IN THE CONTROLLER ──────────────────────────────
 *
 * The upload INTAKE — the six-step gate of `kb-security-baseline`
 * (size, extension allow-list, content-sniffed MIME, extension/MIME cross-check, OPC macro and
 * embedded-object refusal, content hash), the object write, the `source.upload.accepted` and
 * `source.upload.rejected` audit rows — is a separate unit of work and is not in this change. What
 * this change owes is that the ROUTE and this REQUEST do not CONTRADICT the shape the console was
 * already written against, so that landing the intake is a controller change rather than a wire
 * change on both sides.
 *
 * So the rules are complete and the refusal is one `TODO(phase-c)` in the controller. The
 * alternative — omitting `files` from `rules()` — would be worse in the specific way an absent rule
 * always is here: `validated()` SILENTLY DISCARDS an undeclared field, so a console posting files
 * against this endpoint would receive a 201 for a source with no content and no version, and would
 * discover it only when the bot could not answer.
 *
 * ── NO `unique:` AND NO `exists:` RULE, ANYWHERE IN THIS FILE ────────────────────────────────
 *
 * Both query the table with NO organization predicate unless somebody remembers to add one, which
 * is the exact shape of Filament CVE-2026-48067 — the select query was tenant-scoped and the
 * validation rule for the same field was not. Spelling the scope into the rule string by hand would
 * work and would also be a tenant predicate assembled from route input inside a string, which is
 * the thing that goes wrong. Ownership questions are the policy's and the composite foreign keys';
 * existence questions are the scoped route binding's, which 404s a foreign id BEFORE this request
 * is even constructed.
 *
 * ── `status` IS NOT A FIELD ──────────────────────────────────────────────────────────────────
 *
 * A source is created `Draft` and submitted in the same transaction. Every other move is a
 * transition with its own route, driven by `SourceState::transitionTable()`. A settable `status`
 * would be `{"status":"ready"}` publishing a version nothing verified.
 */
final class StoreSourceRequest extends FormRequest
{
    /**
     * The most files one multipart request may carry.
     *
     * THE CANONICAL VALUE LIVES HERE ONLY UNTIL THE UPLOAD SURFACE LANDS. The console reads
     * `max_bytes`, `allowed_mime` and `max_batch` from an `OrgUploadLimits` endpoint so it can
     * refuse a file before spending the operator's bandwidth on it, and that endpoint belongs with
     * the intake. It must publish THESE constants rather than a second copy of the numbers — two
     * copies of a limit drift, and the drifting copy is the one that ships: a console that believes
     * the batch cap is 20 while the server enforces 10 renders a green upload that 422s.
     */
    public const MAX_FILES = 10;

    /**
     * Per-file ceiling, in KILOBYTES, because that is the unit Laravel's `max:` rule speaks for an
     * uploaded file. 25 MB.
     */
    public const MAX_FILE_KILOBYTES = 25_600;

    /**
     * The pasted-text ceiling, in CHARACTERS.
     *
     * Bounded because a source body is stored, hashed, embedded and billed per token — an unbounded
     * paste is an unbounded provider invoice from one form field. 500,000 characters is roughly a
     * 250-page book, which is past any legitimate paste and short of anything that would strain a
     * request body.
     */
    public const MAX_TEXT_LENGTH = 500_000;

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
            // CLOSED TO THE ENUM'S OWN VALUES, generated from `values()` so the rule and the
            // `knowledge_sources_type_check` constraint cannot drift. Three values and not a list
            // of file formats: the parser decides what to do with bytes from the SNIFFED MIME, and
            // a client-settable file type is the input the upload rules explicitly refuse to trust.
            'type' => ['bail', 'required', 'string', Rule::in(SourceType::values())],

            'name' => ['bail', 'required', 'string', 'max:200'],
            'description' => ['bail', 'sometimes', 'nullable', 'string', 'max:2000'],

            // ── THE CRAWL TARGET ────────────────────────────────────────────────────────────
            //
            // `required_if` and `prohibited_unless` TOGETHER, mirroring
            // `knowledge_sources_origin_url_matches_type`, which is written as an equality between
            // two booleans so BOTH mistakes are refused by one constraint: a crawl source with
            // nothing to crawl, and a file source carrying a URL somebody expects us to fetch.
            // Stating only the first half here would let the second reach the database and surface
            // as a 500 for a request that is plainly a bad one.
            //
            // `url:http,https` REFUSES `file://`, `gopher://` and the rest at the schema level. It
            // is NOT the SSRF check and must never be read as one — that is the crawler's, it
            // resolves DNS, re-checks after every redirect, and nothing a validation rule can
            // express substitutes for it (`kb-security-baseline`).
            'origin_url' => [
                'bail',
                'required_if:type,'.SourceType::Url->value,
                'prohibited_unless:type,'.SourceType::Url->value,
                'string',
                'url:http,https',
                'max:2048',
            ],

            // ── PASTED PROSE ────────────────────────────────────────────────────────────────
            //
            // The same both-directions pairing. A `url` source carrying a paste is a caller who
            // believes they submitted text; silently dropping the field would crawl the URL and
            // never tell them the paste went nowhere.
            'content' => [
                'bail',
                'required_if:type,'.SourceType::Text->value,
                'prohibited_unless:type,'.SourceType::Text->value,
                'string',
                'max:'.self::MAX_TEXT_LENGTH,
            ],

            // ── THE MULTIPART HALF ──────────────────────────────────────────────────────────
            //
            // Declared so the shape is pinned and the dumped rules manifest carries it; the intake
            // itself is refused in the controller with a `TODO(phase-c)`. See the class docblock.
            'files' => [
                'bail',
                'required_if:type,'.SourceType::File->value,
                'prohibited_unless:type,'.SourceType::File->value,
                'array',
                'max:'.self::MAX_FILES,
            ],
            // `file` and a SIZE, and deliberately no `mimes:`/`mimetypes:` rule. Laravel's `mimes:`
            // guesses from the client's filename extension and `mimetypes:` from a guesser that
            // reads the first bytes — neither is the content sniff the security baseline requires,
            // and a rule that LOOKS like a MIME check is worse than none, because the real check
            // then reads as a duplicate somebody may remove. The allow-list is enforced at intake,
            // against libmagic, and its refusal is a `source.upload.rejected` audit row.
            'files.*' => ['bail', 'file', 'max:'.self::MAX_FILE_KILOBYTES],

            // ── LABELS ──────────────────────────────────────────────────────────────────────
            //
            // Bounded at 50 and 64 to match `knowledge_sources_tags_well_formed`, which also
            // refuses a NULL element and the blank string — the first makes `list<string>` a lie on
            // the PHP side, the second is a tag nobody can type and nobody can remove from a filter
            // UI. `distinct` because a set with a repeat is a set the console renders twice.
            'tags' => ['bail', 'sometimes', 'array', 'max:50'],
            'tags.*' => ['bail', 'string', 'min:1', 'max:64', 'distinct'],

            // ── THE RETRIEVAL WINDOW ────────────────────────────────────────────────────────
            //
            // `after:effective_at` mirrors `knowledge_sources_window_ordered`. A window that closes
            // before it opens is a source that is never retrievable and reads in the console as
            // though it were — which is the failure worth a rule rather than a constraint message.
            'effective_at' => ['bail', 'sometimes', 'nullable', 'date'],
            'expires_at' => ['bail', 'sometimes', 'nullable', 'date', 'after:effective_at'],
        ];
    }

    /**
     * The validated body, as a type.
     */
    public function toData(): NewSource
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $tags = $data['tags'] ?? [];

        return new NewSource(
            type: SourceType::from((string) $data['type']),
            name: (string) $data['name'],
            description: $this->nullableString($data, 'description'),
            originUrl: $this->nullableString($data, 'origin_url'),
            content: $this->nullableString($data, 'content'),
            // `array_values` and a string cast per element, so the value handed to
            // `PostgresTextArrayCast` is a `list<string>` and not a map with holes — the cast writes
            // a PostgreSQL `text[]` literal, and a non-sequential array would serialize its KEYS
            // into the column without raising.
            tags: is_array($tags) ? array_values(array_map(strval(...), $tags)) : [],
            effectiveAt: $this->nullableDate($data, 'effective_at'),
            expiresAt: $this->nullableDate($data, 'expires_at'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nullableDate(array $data, string $key): ?CarbonImmutable
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
