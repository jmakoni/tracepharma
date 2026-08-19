<?php

declare(strict_types=1);

namespace Tests\Unit\Epcis\Hub;

use App\Services\Epcis\Hub\EpcisHubRouter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EpcisHubRouterTest extends TestCase
{
    #[Test]
    public function probe_does_not_require_tenant(): void
    {
        $resolution = app(EpcisHubRouter::class)->resolve('unitrace', '<root>Connectivity test</root>', 'stage');

        $this->assertTrue($resolution->isProbe());
    }

    #[Test]
    public function rejects_unknown_provider(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported EPCIS hub provider');

        app(EpcisHubRouter::class)->resolve('unknown-provider', '<root>Connectivity test</root>', 'stage');
    }
}
