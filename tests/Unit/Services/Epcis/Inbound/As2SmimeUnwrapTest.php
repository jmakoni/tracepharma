<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis\Inbound;

use App\Models\InboundConnection;
use App\Services\Epcis\Inbound\As2SmimeUnwrap;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class As2SmimeUnwrapTest extends TestCase
{
    #[Test]
    public function production_refuses_unsigned_xml_even_when_lab_flag_is_enabled(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $connection = new InboundConnection([
                'settings' => ['allow_unsigned_xml' => true],
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('not allowed in production');

            app(As2SmimeUnwrap::class)->unwrap(
                $connection,
                '<?xml version="1.0"?><EPCISDocument/>',
                'application/xml',
            );
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    #[Test]
    public function non_production_allows_unsigned_xml_when_lab_flag_is_enabled(): void
    {
        $connection = new InboundConnection([
            'settings' => ['allow_unsigned_xml' => true],
        ]);

        $xml = '<?xml version="1.0"?><EPCISDocument/>';

        $this->assertSame(
            $xml,
            app(As2SmimeUnwrap::class)->unwrap($connection, $xml, 'application/xml'),
        );
    }
}
