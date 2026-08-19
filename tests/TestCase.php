<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep the broader suite green; enable per-test when asserting the gate.
        config(['tracepharma.regulatory_compliance.password_gate' => false]);
    }
}
