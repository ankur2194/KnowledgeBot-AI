<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Bot;
use App\Models\Organization;
use App\Models\User;

/**
 * The two-organization fixture, as a value object rather than an array, so a test cannot silently
 * read the wrong organization's actor off a string key.
 *
 * Lives in its own PSR-4 file (not beside tenantPair() in tenancy.php) so `composer dump-autoload
 * -o` can class-map it without a PSR-4 compliance warning. The FUNCTION is global by design —
 * `tenantPair()` is called bare in every isolation test — and a global function cannot live in a
 * namespaced class file.
 *
 * ── EVERY PROPERTY IS NARROWED, AND THE TYPES ARE PART OF THE SECURITY CONTROL ────────────────
 *
 * All six model properties were declared `object` while the models did not exist. They exist now,
 * and leaving them as `object` is not a style question: `$t->botA` and `$t->botB` are the pair the
 * whole fixture exists to keep distinct, and `object` lets a test hand either one to anything.
 * Static analysis runs over tests/ at level 8 and cannot object to a property typed `object`, so
 * the mistake that matters most — passing Org B's record into an assertion written for Org A, which
 * makes a negative assertion run as the organization that PLANTED the canary and pass while proving
 * the opposite of what it claims — would type-check silently.
 *
 * `App\Models\KnowledgeSource` is the one that has not landed, and it has no property here yet:
 * tenancy.php carries the Phase C TODO for it, and the canary moves into its content when it does.
 *
 * FLAG for whoever enables the commented-out arch rule in tests/Arch/DoctrineTest.php — "a
 * controller cannot touch a model", `expect('App\Models')->toOnlyBeUsedIn([...])`. These three
 * imports are `Tests\Support -> App\Models` edges, and that rule's allow-list will have to carry
 * `Tests\Support` or this file becomes its first violation. It is named here rather than left to be
 * discovered, because the wrong fix — widening the properties back to `object` — reads as a small
 * concession and removes the property above.
 */
final readonly class TenantPair
{
    public function __construct(
        public Organization $a,
        public Organization $b,
        public Bot $botA,
        public Bot $botB,       // the only bot that may see the canary
        public string $canary,  // fresh per test; planted in ORG B's content only
        public User $actorA,    // admin of A
        public User $actorB,    // admin of B
    ) {}
}
