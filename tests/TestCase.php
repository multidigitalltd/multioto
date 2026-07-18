<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic tests: the real wall-clock day (Shabbat/Yom Tov) must
        // not gate automations under test. Tests that exercise the quiet period
        // re-enable it explicitly.
        config(['billing.shabbat.block_automations' => false]);
    }
}
