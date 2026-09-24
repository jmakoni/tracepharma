<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Receiving;

use App\Filament\App\Resources\ReceivingSessions\Concerns\InteractsWithReceivingSessionHud;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class ReceivingSessionHudActionAuthTest extends TestCase
{
    #[Test]
    public function mutate_actions_authorize_update_on_session(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(InteractsWithReceivingSessionHud::class))->getFileName() ?: '',
        );

        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertGreaterThanOrEqual(
            12,
            substr_count($source, "authorize('update'"),
            'Receive HUD mutate paths must authorize update against ReceivingSessionPolicy',
        );

        foreach ([
            'confirmScan',
            'acceptRemaining',
            'completeReceiving',
            'receiveAllExpected',
            'closeOpenTote',
            'resetScans',
            'cancelReceiving',
        ] as $action) {
            $this->assertStringContainsString("Action::make('{$action}')", $source);
        }

        $this->assertStringContainsString(
            "\$this->authorize('update', \$session);",
            $source,
            'confirmScanInput must authorize before mutating',
        );
    }
}
