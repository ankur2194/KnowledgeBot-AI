<?php

declare(strict_types=1);

namespace App\Services\Providers;

use SensitiveParameter;

/**
 * The validated input to a credential rotation: the replacement key, and nothing else.
 *
 * `$credential` is `#[SensitiveParameter]`, so PHP renders it as `Object(SensitiveParameterValue)`
 * in every stack trace below this constructor — which is what keeps a `RuntimeException` from the
 * vault, from the PDO driver, or from anything the service calls from carrying the plaintext key
 * into the log pipeline and into a debug-mode error envelope. Same construction, same reason, as
 * NewProviderConnection.
 *
 * ── THE RE-AUTHENTICATION PASSWORD IS NOT A MEMBER, DELIBERATELY ───────────────────────────────
 *
 * `current_password` is validated by the FormRequest and dies there. Carrying it one layer further
 * would put a second live secret inside an object that is passed to a service, closed over by an
 * audit callback, and alive for the length of a database transaction — for no benefit at all,
 * since nothing below the FormRequest has any use for it. The rule already answered the only
 * question anyone downstream could ask.
 *
 * `organization_id` is not a member either, for the usual reason: it comes from the authenticated
 * context at the call site, never from a validated body (laravel-rbac-policies NN5).
 */
final readonly class ProviderCredentialRotation
{
    public function __construct(
        #[SensitiveParameter] public string $credential,
    ) {}
}
