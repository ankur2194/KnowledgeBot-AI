<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Enums\Provider;
use SensitiveParameter;

/**
 * The validated input to a connection save, as a type rather than an array.
 *
 * `$credential` is `#[SensitiveParameter]`, so PHP renders it as
 * `Object(SensitiveParameterValue)` in every stack trace below this constructor. That is not
 * decoration: a `RuntimeException` from the vault, from the database driver, or from anything the
 * service calls would otherwise carry the plaintext key into the log pipeline and into a
 * debug-mode error envelope.
 *
 * `organization_id` is deliberately NOT a member. It is never validated, never posted and never
 * carried in a DTO; it comes from the authenticated context at the call site
 * (laravel-rbac-policies NN5).
 */
final readonly class NewProviderConnection
{
    /**
     * @param  list<NewProviderModel>  $models
     */
    public function __construct(
        public Provider $provider,
        public string $label,
        #[SensitiveParameter] public string $credential,
        public array $models,
    ) {}
}
