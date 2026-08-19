<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScanSoundsTest extends TestCase
{
    #[Test]
    public function app_panel_registers_scan_sounds_script(): void
    {
        $this->assertFileExists(public_path('js/tp-scan-sounds.js'));

        $provider = file_get_contents(app_path('Providers/Filament/AppPanelProvider.php'));

        $this->assertIsString($provider);
        $this->assertStringContainsString("Js::make('tp-scan-sounds')", $provider);
        $this->assertStringContainsString('js/tp-scan-sounds.js', $provider);
        $this->assertStringContainsString('versionedPublicJs', $provider);
    }
}
