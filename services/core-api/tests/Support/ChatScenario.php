<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * One fully wired chat surface for one test: the fixture, a live session token, and an open
 * conversation.
 *
 * ── IT IS A VALUE OBJECT AND NOT `beforeEach` STATE, AND THAT IS A TYPING DECISION ────────────
 *
 * Pest binds `$this` inside a test closure to the test case, but STATIC ANALYSIS resolves it to
 * `Pest\PendingCalls\TestCall` — so `$this->fixture` set in a `beforeEach` is an undefined property
 * at level 8 and `$this->postJson()` is an undefined method. The house convention already answers
 * the second half with `currentTest()` (declared in `tests/Pest.php` precisely so no call site needs
 * an annotation); this answers the first.
 *
 * The by-product is worth more than the typing: every test states what it set up, in the test, so a
 * reader never has to scroll to a `beforeEach` to find out which bot they are talking to.
 */
final readonly class ChatScenario
{
    public function __construct(
        public ChatFixture $fixture,
        public string $token,
        public string $conversation,
    ) {}
}
