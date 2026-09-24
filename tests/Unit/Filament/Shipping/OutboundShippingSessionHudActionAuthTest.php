<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Shipping;

use App\Filament\App\Resources\OutboundShippingSessions\Concerns\InteractsWithOutboundShippingSessionHud;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class OutboundShippingSessionHudActionAuthTest extends TestCase
{
    #[Test]
    public function confirm_scan_authorizes_update_on_session(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(InteractsWithOutboundShippingSessionHud::class))->getFileName() ?: '',
        );

        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString("Action::make('confirmScan')", $source);
        $this->assertStringContainsString("\$this->authorize('update', \$session);", $source);
    }
}
