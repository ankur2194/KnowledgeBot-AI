<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Bot;
use App\Models\KnowledgeSource;
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
 * `$sourceB` LANDED WITH PHASE C1 AND IS NARROWED LIKE THE REST. It is a real, assigned
 * `KnowledgeSource` in Org B — the row `bot_ids` is resolved from — and it is what makes a
 * source-shaped isolation assertion expressible at all: before it, every §22.5 test had a bot to
 * compare and nothing on the knowledge side.
 *
 * ── IT IS DELIBERATELY NOT PAIRED, AND THE ASYMMETRY IS THE ONE EXCEPTION IN THIS FILE ───────
 *
 * Every other model on this fixture comes in a pair, because a test that reads "the" organization
 * has not decided which one it meant. There is no `$sourceA`, and adding one would not be a
 * symmetry improvement: the fixture's negative assertions run AS Org A and assert that Org B's
 * canary is absent, so Org A needs to have no knowledge of its own for the assertion to mean
 * anything. A source in Org A would give a leak somewhere to hide in plain sight — a response body
 * containing a source name would satisfy a reader's eye without anyone checking WHOSE.
 *
 * A test that genuinely needs a source in Org A creates one explicitly, with
 * `KnowledgeSource::factory()->recycle($t->a)`, which is one line and says which tenant it meant.
 *
 * THE CANARY IS STILL IN `$botB->welcome_message` AND DID NOT MOVE ONTO THIS SOURCE. It moves when
 * `KnowledgeSourceFactory::indexed()` lands — see tenancy.php. Putting it on a source whose content
 * never reaches the index would make every isolation test assert against a string no retrieval path
 * could have leaked: a weaker test that reads as a stronger one.
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
        public KnowledgeSource $sourceB, // assigned to $botB; unpaired on purpose, see above
        public string $canary,  // fresh per test; planted in ORG B's content only
        public User $actorA,    // admin of A
        public User $actorB,    // admin of B
    ) {}
}
