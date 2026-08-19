<?php

namespace Tests\Unit\Support\Tracing;

use App\Filament\App\Pages\AssetTracking;
use App\Models\Epcis\Epc;
use App\Support\Tracing\AssetTrackingUrl;
use App\Support\Ui\CopyableIdentifier;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetTrackingUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    #[Test]
    public function scan_for_epc_prefers_human_readable_over_urn(): void
    {
        $epc = new Epc([
            'epc_type' => 'sscc',
            'epc_uri' => 'urn:epc:id:sscc:030116.01001108185',
            'sscc18' => '003011610011081850',
            'ai_00' => '00003011610011081850',
        ]);

        $this->assertSame('(00)003011610011081850', AssetTrackingUrl::scanForEpc($epc));
    }

    #[Test]
    public function scan_for_epc_builds_sscc_from_sscc18(): void
    {
        $epc = new Epc([
            'epc_type' => 'sscc',
            'sscc18' => '003011610011081850',
        ]);

        $this->assertSame('(00)003011610011081850', AssetTrackingUrl::scanForEpc($epc));
    }

    #[Test]
    public function scan_for_epc_falls_back_to_urn_when_no_human_readable(): void
    {
        $epc = new Epc([
            'epc_type' => 'other',
            'epc_uri' => 'urn:epc:id:gdti:0614141.12345.400',
        ]);

        $this->assertSame('urn:epc:id:gdti:0614141.12345.400', AssetTrackingUrl::scanForEpc($epc));
    }

    #[Test]
    public function scan_for_epc_builds_sgtin_from_gtin_and_serial(): void
    {
        $epc = new Epc([
            'epc_type' => 'sgtin',
            'gtin14' => '30301164005087',
            'serial_number' => '90000082008888',
        ]);

        $this->assertSame('01303011640050872190000082008888', AssetTrackingUrl::scanForEpc($epc));
    }

    #[Test]
    public function scan_for_epc_returns_null_for_bare_gtin_without_serial(): void
    {
        $epc = new Epc([
            'epc_type' => 'sgtin',
            'gtin14' => '30301164005087',
        ]);

        $this->assertNull(AssetTrackingUrl::scanForEpc($epc));
        $this->assertNull(AssetTrackingUrl::forEpc($epc));
        $this->assertNull(AssetTrackingUrl::scanForGtinSerial('30301164005087', null));
    }

    #[Test]
    public function url_points_at_asset_tracking_with_scan_query(): void
    {
        $url = AssetTrackingUrl::url('urn:epc:id:sscc:030116.01001108185');

        $this->assertNotNull($url);
        $this->assertStringContainsString('asset-tracking', $url);
        $this->assertStringContainsString('scan=', $url);
        $this->assertSame(
            AssetTracking::getUrl(['scan' => 'urn:epc:id:sscc:030116.01001108185'], panel: 'app'),
            $url,
        );
    }

    #[Test]
    public function link_epc_column_is_plain_trace_link_with_hover_copy_control(): void
    {
        $plain = AssetTrackingUrl::linkEpcColumn(
            TextColumn::make('sscc18'),
            fn (): null => null,
        );
        $copyable = AssetTrackingUrl::linkEpcColumn(
            TextColumn::make('sscc18'),
            fn (): null => null,
            copyable: true,
        );

        $this->assertNull($plain->getColor('003011610011081850'));
        $this->assertNull($copyable->getColor('003011610011081850'));
        $this->assertFalse($copyable->isCopyable('003011610011081850'));
        $this->assertTrue($copyable->hasDynamicExtraCellAttributes());

        $button = CopyableIdentifier::outlineButtonHtml('003011610011081850');
        $this->assertNotNull($button);
        $html = $button->toHtml();
        $this->assertStringContainsString('tp-copy-btn', $html);
        $this->assertStringContainsString('fi-icon', $html);
        $this->assertStringContainsString('stroke=', $html);
        $this->assertStringContainsString('group-hover:opacity-100', $html);
        $this->assertStringNotContainsString('border-', $html);
        $this->assertStringContainsString('opacity-0', $html);
    }
}
