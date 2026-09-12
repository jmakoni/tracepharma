<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantRunnerTest extends TestCase
{
    #[Test]
    public function it_ends_tenancy_when_callback_throws(): void
    {
        $tenant = Tenant::query()->first();

        if ($tenant === null) {
            $this->markTestSkipped('No tenant available.');
        }

        try {
            TenantRunner::run($tenant, function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(tenancy()->initialized);
    }
}
