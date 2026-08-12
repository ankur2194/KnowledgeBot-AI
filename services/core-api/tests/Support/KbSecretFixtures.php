<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Per-test fixture state for tests/Unit/KbSecretsTest.php.
 *
 * STATIC RATHER THAN ON $this, and that is not a style choice: phpunit.xml sets
 * failOnDeprecation="true", and assigning a dynamic property to the test case is a PHP 8.2
 * deprecation, so the obvious `$this->dir = ...` fails the suite rather than the assertion.
 *
 * IT LIVES IN ITS OWN PSR-4 FILE, beside TenantPair, for the same reason that one does. `Tests\` is
 * mapped PSR-4 to ./tests in composer.json's autoload-dev, so a class declared inside
 * tests/Unit/KbSecretsTest.php is a PSR-4 violation: composer printed
 *
 *     Class KbSecretFixtures located in ./tests/Unit/KbSecretsTest.php does not comply with psr-4
 *     autoloading standard (rule: Tests\ => ./tests). Skipping.
 *
 * on EVERY `composer install` and every `dump-autoload -o`. The class still worked, because the test
 * file that declared it is the only file that used it — which is precisely what made the warning
 * permanent and therefore invisible. A install log that always carries one benign warning is an
 * install log nobody reads the day it carries two.
 *
 * Being autoloaded rather than declared inline also puts it inside the `Tests` namespace that
 * tests/Arch/DoctrineTest.php asserts is never reachable from App — a global `\KbSecretFixtures`
 * was outside that rule's reach entirely.
 */
final class KbSecretFixtures
{
    /** Absolute path to the per-test temporary directory holding secret files. */
    public static string $dir = '';

    /**
     * Every environment variable name the test wrote, so afterEach can unset exactly those and
     * leave the rest of the superglobals alone.
     *
     * @var list<string>
     */
    public static array $names = [];
}
