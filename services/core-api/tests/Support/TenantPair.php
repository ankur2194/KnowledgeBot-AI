<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The two-organization fixture, as a value object rather than an array, so a test cannot silently
 * read the wrong organization's actor off a string key.
 *
 * Lives in its own PSR-4 file (not beside tenantPair() in tenancy.php) so `composer dump-autoload
 * -o` can class-map it without a PSR-4 compliance warning. The FUNCTION is global by design —
 * `tenantPair()` is called bare in every isolation test — and a global function cannot live in a
 * namespaced class file.
 *
 * The model-typed properties are declared `object` ONLY while the models do not exist; static
 * analysis runs over tests/. Narrow each one to its real class as it lands. Do not leave them as
 * `object` once the classes are there: the entire point is that $t->botA and $t->botB are not
 * interchangeable.
 */
final readonly class TenantPair
{
    public function __construct(
        public object $a,       // App\Models\Organization
        public object $b,       // App\Models\Organization
        public object $botA,    // App\Models\Bot
        public object $botB,    // App\Models\Bot — the only bot that may see the canary
        public string $canary,  // fresh per test; planted in ORG B's content only
        public object $actorA,  // App\Models\User, admin of A
        public object $actorB,  // App\Models\User, admin of B
    ) {}
}
