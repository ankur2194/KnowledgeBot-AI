<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SourceType;
use App\Services\Sources\NewSource;
use App\Services\Sources\Upload\UploadLimits;
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
 * ── THE RULES BELOW ARE THE SHAPE; THE INTAKE GATE IS THE CONTENT ───────────────────────────
 *
 * `files` and `files.*` pin the multipart shape and the per-file size. Everything else about an
 * uploaded file — the extension allow-list on the NFKC-normalized name, the MIME sniffed from
 * content by libmagic, the extension/MIME cross-check, the OPC macro and embedded-object refusal,
 * the decompression caps, the SHA-256 — is `App\Services\Sources\Upload\UploadIntake`, in one
 * method, in one order, because THE ORDER IS THE SECURITY PROPERTY and a gate split between a
 * FormRequest and a service is a gate whose order nobody can read.
 *
 * A rule that LOOKED like a MIME check here would be worse than none: `mimes:` guesses from the
 * client's filename and `mimetypes:` from a guesser that reads the first bytes, and neither is the
 * content sniff §8.10 requires — so the real check would then read as a duplicate somebody may
 * remove. See the comment on `files.*` below, which has said so since before the intake existed.
 *
 * Omitting `files` from `rules()` altogether would be worse still, in the specific way an absent
 * rule always is here: `validated()` SILENTLY DISCARDS an undeclared field, so a console posting
 * files against this endpoint would receive a 201 for a source with no content and no version, and
 * would discover it only when the bot could not answer.
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
     * IT IS THE SAME VALUE `UploadLimits::MAX_BATCH` HOLDS, BECAUSE IT IS THAT CONSTANT. The
     * console reads `max_bytes`, `allowed_mime` and `max_batch` from
     * `GET .../sources/upload-limits` so it can refuse a file before spending the operator's
     * bandwidth on it, and that endpoint renders `OrgUploadLimitsResource` out of
     * `App\Services\Sources\Upload\UploadLimits`. Nothing on that path holds a second copy of a
     * number — two copies of a limit drift, and the drifting copy is the one that ships: a console
     * that believes the batch cap is 20 while the server enforces 10 renders a green upload that
     * 422s. `UploadLimitsEndpointTest` asserts the rendered values against THESE constants, so a
     * hardcoded copy on either side fails the suite instead of shipping.
     *
     * THE DEFINITION SITE IS THE SERVICE AND NOT THIS FILE, and that is `arch()->preset()->
     * laravel()`'s doing rather than a preference: a FormRequest may not be used outside
     * `App\Http`, so `UploadLimits` reading a constant from here fails the arch suite while this
     * reading one from there does not. The name stays `MAX_FILES` because that is what the rule
     * counting the array calls it.
     */
    public const MAX_FILES = UploadLimits::MAX_BATCH;

    /**
     * Per-file ceiling, in KILOBYTES, because that is the unit Laravel's `max:` rule speaks for an
     * uploaded file. 25 MB, and the same constant `UploadLimits::MAX_FILE_KILOBYTES` holds.
     *
     * KIBIBYTES, PRECISELY: `ValidatesAttributes::getSize()` divides `UploadedFile::getSize()` by
     * **1024**, so this is 26,214,400 bytes and not 25,600,000. The wire publishes bytes, because a
     * browser's `File.size` is bytes; the conversion happens in exactly one place,
     * `UploadLimits::maxBytes()`, and nothing else on this path multiplies or divides by 1024.
     */
    public const MAX_FILE_KILOBYTES = UploadLimits::MAX_FILE_KILOBYTES;

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
     * `knowledge_sources_origin_url_scheme` AND `source_items_url_scheme`, AS A PCRE.
     *
     * THE TWO HALVES HAVE TO AGREE OR THE DIFFERENCE IS A 500. Anything this pattern admits and
     * the CHECK refuses reaches PostgreSQL as an `IntegrityError` nothing in the taxonomy converts;
     * anything the CHECK admits and this refuses is a URL a caller cannot submit and cannot find
     * out why. The pattern is therefore transcribed rather than approximated:
     *
     *   ^https?://              the two schemes
     *   [^@/?#\s]+              the AUTHORITY, with NO `@` — no `user:password@host`, which we
     *                           would send to a host we do not control and log on the way
     *   ([/?#]\S*)?             optionally path/query/fragment, where `@` is ordinary (`/@handle`)
     *   $
     *
     * `\s` for POSIX `[:space:]` and `https?` for `https{0,1}`; the two engines spell the same
     * two things differently and mean exactly the same set. `D` so `$` cannot match before a
     * trailing newline — PCRE's default would otherwise admit `"https://host\n"`, which POSIX
     * `[:space:]` refuses, and a newline in a URL is a log-forging shape as well as a parse error.
     */
    public const URL_AUTHORITY_PATTERN = '/^https?:\/\/[^@\/?#\s]+([\/?#]\S*)?$/D';

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
            //
            // AND THE `regex` BESIDE IT IS NOT REDUNDANT. `url:http,https` permits `@` in the
            // userinfo group, so `https://user:password@host/` validates — and
            // `knowledge_sources_origin_url_scheme` refuses it, as an unconverted `QueryException`
            // and a 500 on a route whose documented failure shape is a per-field 422. The pattern
            // here is the CHECK's, character for character (see the
            // `narrow_url_checks_to_the_authority` migration), so the refusal happens where it can
            // name the field. Credentials in a crawl target are refused because we would send them
            // to a host we do not control and log them on the way; `@` in the PATH is ordinary and
            // is allowed by both halves.
            'origin_url' => [
                'bail',
                'required_if:type,'.SourceType::Url->value,
                'prohibited_unless:type,'.SourceType::Url->value,
                'string',
                'url:http,https',
                'regex:'.self::URL_AUTHORITY_PATTERN,
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
            // The BATCH cap. `MAX_FILES` and not a literal, so the endpoint that publishes it to
            // the console and the rule that enforces it are one value. See the class docblock.
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
            // against libmagic, and its refusal is a `source.upload.rejected` audit row carrying a
            // closed reason token naming which step said no.
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
