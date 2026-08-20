<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Sources\Upload\UploadLimits;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What this deployment will accept as an upload, as data — the shape `apps/web`'s `uploadSchema` is
 * a factory over.
 *
 * ── IT EXISTS BECAUSE A CONSOLE THAT GUESSES THESE NUMBERS IS A CONSOLE THAT GUESSES WRONG ───
 *
 * The console refuses a file BEFORE spending the operator's bandwidth on it, which means it needs
 * the ceiling, the accepted types and the batch cap client-side. There are exactly two ways to get
 * them there: publish them, or write them down twice.
 * `StoreSourceRequest::MAX_FILES`' own docblock rules out the second in the terms of the failure it
 * produces — "two copies of a limit drift, and the drifting copy is the one that ships: a console
 * that believes the batch cap is 20 while the server enforces 10 renders a green upload that 422s."
 *
 * SO NOTHING HERE IS A LITERAL. Every value is read from `UploadLimits`, which reads
 * `StoreSourceRequest`'s constants, and `UploadLimitsEndpointTest` asserts the rendered numbers
 * EQUAL those constants — so a future edit that hardcodes 26214400 into this file fails the suite
 * rather than shipping and drifting.
 *
 * ── `max_bytes` IS BYTES AND THE FORMREQUEST'S CONSTANT IS KILOBYTES ─────────────────────────
 *
 * `StoreSourceRequest::MAX_FILE_KILOBYTES` is 25,600, in the unit Laravel's `max:` rule speaks for
 * an uploaded file — a KiB, because `ValidatesAttributes::getSize()` divides by 1024. The wire
 * carries bytes, because a browser's `File.size` is bytes and a client that had to convert would be
 * the third place the unit is decided. THE CONVERSION HAPPENS IN EXACTLY ONE PLACE,
 * `UploadLimits::maxBytes()`, and this resource does not multiply anything.
 *
 * ── THREE FIELDS, AND `allowed_extensions` IS NOT THE FOURTH ─────────────────────────────────
 *
 * The shape is `{max_bytes, allowed_mime, max_batch}` and it is fixed, because `apps/web`'s schema
 * factory is written against it and `packages/contracts` is another owner's. The extension
 * allow-list is the server's step-2 authority and is deliberately NOT published here — which is a
 * real cost, stated rather than hidden: a drop zone built from `allowed_mime` alone over-accepts,
 * because `.md` and `.csv` are admitted under the sniffed type `text/plain` and a browser will
 * happily offer a `.txt` against it. The operator then gets a server-side `extension` refusal for a
 * file the picker let them choose. Adding `allowed_extensions` is the fix and it is a SHAPE CHANGE
 * that has to be asked for by the owner of the client schema, not taken here.
 *
 * ── WHAT `allowed_mime` MEANS, WHICH IS NOT WHAT A BROWSER MEANS BY IT ───────────────────────
 *
 * These are the types libmagic may read out of the CONTENT. A browser's `File.type` is a guess made
 * from the extension by the operating system, so the two agree often and not always. The server
 * never reads the client's value at all (`kb-security-baseline` §18.7), so this list is safe to use
 * for a client-side hint and is not the check.
 *
 * ── WHAT IS NOT IN HERE ──────────────────────────────────────────────────────────────────────
 *
 * No storage quota, because there is no table behind one yet and a null field would read as
 * "unlimited". No per-organization variation at all: every number here is a platform constant
 * today, and the endpoint is org-scoped anyway so introducing per-plan values later is a change to
 * this resource and not to the route or the client's URL. No credential, no internal host, no
 * bucket name.
 */
final class OrgUploadLimitsResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * A resource over no model. `JsonResource` requires a `$resource`, and the honest value is the
     * empty array: the answer is a property of the deployment rather than of a row, and passing an
     * `Organization` would imply the numbers were read off it.
     */
    public function __construct()
    {
        parent::__construct([]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'max_bytes' => UploadLimits::maxBytes(),
            'allowed_mime' => UploadLimits::allowedMime(),
            'max_batch' => UploadLimits::maxBatch(),
        ];
    }

    /**
     * The published shape, as JSON Schema 2020-12. Asserted against what `toArray()` emits by
     * tests/Contract/OpenApiDocumentTest.php.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'OrgUploadLimitsResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The upload ceilings this deployment enforces. Read them; never '
                    .'hardcode them. They are the same constants the server validates against, so a '
                    .'client that pre-checks against these values and a server that refuses cannot '
                    .'disagree.',
                'required' => ['allowed_mime', 'max_batch', 'max_bytes'],
                'properties' => [
                    'max_bytes' => [
                        'type' => 'integer',
                        'description' => 'The per-file ceiling, in BYTES — directly comparable with '
                            .'a browser `File.size`. The server-side rule is expressed in kibibytes '
                            .'because that is the unit Laravel\'s file `max:` rule speaks; the '
                            .'conversion happens once, on the server, so this number and the '
                            .'enforced one are the same number.',
                    ],
                    'allowed_mime' => [
                        'type' => 'array',
                        'description' => 'Every media type the server may read out of an uploaded '
                            .'file\'s CONTENT, sorted. These are libmagic\'s answers, not the '
                            .'browser\'s: a browser guesses `File.type` from the extension and the '
                            .'server never reads that value at all. Useful as an `accept` hint and '
                            .'never as the check — the server additionally requires the file\'s '
                            .'final extension to be allow-listed and to agree with the sniffed '
                            .'type, and both refusals arrive as a 422 keyed on the part.',
                        'items' => ['type' => 'string'],
                    ],
                    'max_batch' => [
                        'type' => 'integer',
                        'description' => 'The most files one `POST .../sources` may carry, as parts '
                            .'named `files[0]`, `files[1]`, … — indexed even for a single file. A '
                            .'batch with any refused part creates nothing at all.',
                    ],
                ],
            ],
        ];
    }
}
