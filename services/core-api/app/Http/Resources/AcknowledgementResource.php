<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * "It happened, and there is nothing else to tell you."
 *
 * WHY A BODY AT ALL, AND WHY NEVER A 204 (decision D8). `DumpOpenApiCommand::operation()` emits
 * `properties => $properties` and `required => array_keys($properties)` unconditionally, so an empty
 * `#[ResponseShape(properties: [])]` json-encodes `properties` as `[]` — a JSON ARRAY where JSON
 * Schema requires an object. The published document would be invalid rather than merely terse, and
 * every generated client would be built from it. Two fixes existed: emit something valid for the
 * empty case, or never return a bodyless success. BOTH HALVES ARE NOW IN PLACE, and the order
 * matters: this resource is the fix at the call site, and `responseShapeFor()` now REFUSES an empty
 * #[ResponseShape] by name rather than publishing the invalid document — so D8 is enforced by
 * executing code instead of by this docblock. The dumper deliberately does not "cope" with the
 * empty case, because coping would let a 204 through and the SPA unwraps `data` unconditionally
 * (D23); see the argument beside that guard.
 *
 * WHY THE `data` ENVELOPE, WHICH THE DESIGN DOCUMENT DID NOT ASK FOR. The rendered body is
 * `{"data": {"acknowledged": true}}`, not `{"acknowledged": true}`. `ResponseShape::$properties` maps
 * a RESPONSE KEY to a resource class, and the dumper composes exactly one object whose properties are
 * those keys — so the only way to publish an unwrapped `{"acknowledged": true}` would be to declare
 * `properties: ['acknowledged' => <something>]`, and a boolean is not a `ProvidesOpenApiSchema`. The
 * choice is therefore between a `data` envelope with a CORRECT document and no envelope with a WRONG
 * one, and it is not close. It also matches every endpoint that already exists here
 * (EmbeddingConfigurationController, ProviderConnectionController), so the SPA has one unwrapping
 * rule rather than two.
 *
 * WHY IT CARRIES A CONSTANT `true` AND NOT AN OUTCOME. A field a client could branch on would invite
 * a client to branch on it, and there is no failure this resource can represent: every failure on
 * these endpoints is the error envelope with an `error_class`. `acknowledged: false` would be a
 * second, undocumented failure channel.
 *
 * ITS OTHER JOB IS TO COLLAPSE FOUR OUTCOMES INTO ONE RESPONSE. `POST /auth/forgot-password` answers
 * with this body whether the address exists, does not exist, or was throttled by the framework's own
 * broker; `POST /auth/email/verify` answers with it whether the address was just verified or was
 * already verified. Those collapses are the account-enumeration defence, and they only work if the
 * bodies are BYTE-IDENTICAL — which a resource with no inputs guarantees by construction.
 */
final class AcknowledgementResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * The resource has no subject, and the constructor says so. `JsonResource::__construct()`
     * requires an argument, so `null` is passed through rather than inventing a value object with
     * one boolean in it.
     *
     * NAMED `ok()` AND NOT `make()`: `JsonResource::make(...$parameters)` is variadic, and PHP
     * enforces signature compatibility on static methods too — a zero-argument override is a fatal
     * error at class-load time, not a warning.
     */
    public static function ok(): self
    {
        return new self(null);
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(Request $request): array
    {
        return ['acknowledged' => true];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'AcknowledgementResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'A success with nothing to report. Always exactly '
                    .'`{"acknowledged": true}` — there is no `false` and no outcome field, because '
                    .'every failure on these endpoints is the error envelope. Several endpoints '
                    .'return this body for SEVERAL DIFFERENT internal outcomes on purpose (an '
                    .'unknown address and a real one both get it from forgot-password); the bodies '
                    .'are byte-identical and that is the account-enumeration defence, so do not '
                    .'treat a difference in latency or length as signal.',
                'required' => ['acknowledged'],
                'properties' => [
                    'acknowledged' => [
                        // `type: boolean` rather than `const: true` / `enum: [true]`. Both are legal
                        // 2020-12, and both would make a generated TypeScript type read `true`
                        // instead of `boolean` — a literal type that becomes a compile error the day
                        // any endpoint here has a reason to say otherwise. The description carries
                        // the invariant; the schema stays the widest thing that is still true.
                        'type' => 'boolean',
                        'description' => 'Always true. Present so the success response has a body '
                            .'at all — a 204 would publish `properties: []`, which is not valid '
                            .'JSON Schema.',
                    ],
                ],
            ],
        ];
    }
}
