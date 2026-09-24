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
        $this->assertStringContainsString('js/tp-scan-sounds.js', $provider);
        $this->assertStringContainsString('versionedPublicJs', $provider);
    }

    #[Test]
    public function scan_sounds_script_uses_piezo_handheld_timbres(): void
    {
        $js = file_get_contents(public_path('js/tp-scan-sounds.js'));

        $this->assertIsString($js);
        $this->assertStringContainsString('playGoodRead', $js);
        $this->assertStringContainsString('playError', $js);
        $this->assertStringContainsString('playWarning', $js);
        $this->assertStringContainsString('playPowerUp', $js);
        $this->assertStringContainsString('tpScannerTones', $js);
        $this->assertStringContainsString("osc.type = 'square'", $js);
        $this->assertStringContainsString('scheduleBeep(2700, 100', $js);
        $this->assertStringContainsString('scheduleBeep(250, 80', $js);
        $this->assertStringContainsString('scheduleBeep(1800, 70', $js);
        $this->assertStringContainsString('scheduleBeep(1200, 70', $js);
        $this->assertStringContainsString('scheduleBeep(2400, 70', $js);
        $this->assertStringNotContainsString("'sine'", $js);
        $this->assertStringNotContainsString('880', $js);
    }
}
