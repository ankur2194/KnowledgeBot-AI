<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The application test case.
 *
 * NOTHING GOES IN HERE THAT WEAKENS THE TENANT FILTER. There is no env flag, no `internal=true`
 * fixture, and no helper that disables scoping — not because none has been written yet, but because
 * the existence of one is what makes an isolation suite decoration. If a test is hard to write
 * without a bypass, the production code is wrong (kb-tenancy-isolation NN4, pest-testing NN3).
 *
 * Unit/ deliberately does NOT extend this class: no container, no database, no facades.
 */
abstract class TestCase extends BaseTestCase
{
    //
}
