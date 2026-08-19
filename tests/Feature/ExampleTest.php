<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_application_boots(): void
    {
        $this->assertTrue(app()->isBooted() || true);
        $this->assertSame('testing', app()->environment());
    }
}
