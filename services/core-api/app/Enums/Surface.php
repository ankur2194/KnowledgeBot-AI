<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four route surfaces of config/kb.php, as a type.
 *
 * Bound per route group and constructor-injected into OrgScopedPolicy, which reads ONLY isPublic()
 * — to choose between a 403 that admits the record exists and a 404 that does not. The error_class
 * is `authorization` in both cases and nothing branches on the rendered status
 * (kb-error-taxonomy footnote 1).
 */
enum Surface: string
{
    case Admin = 'admin';
    case PublicRuntime = 'public_runtime';
    case Sdk = 'sdk';
    case Internal = 'internal';

    /**
     * Enumeration-sensitive surfaces deny as 404.
     *
     * `Internal` is not public: it is HMAC-authenticated service-to-service traffic where a 403 is
     * the honest answer and there is no attacker to enumerate for.
     */
    public function isPublic(): bool
    {
        return $this === self::PublicRuntime || $this === self::Sdk;
    }
}
