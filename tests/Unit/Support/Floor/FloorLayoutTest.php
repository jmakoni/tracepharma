<?php

namespace Tests\Unit\Support\Floor;

use App\Support\Floor\FloorLayout;
use App\Support\Receiving\ReceiveLayout;
use App\Support\Shipping\ShipLayout;
use App\Support\Transferring\TransferLayout;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FloorLayoutTest extends TestCase
{
    #[Test]
    public function shared_cookie_and_breakpoints_are_stable(): void
    {
        $this->assertSame('tp_floor_layout', FloorLayout::COOKIE);
        $this->assertSame(768, FloorLayout::PHONE_MAX_PX);
        $this->assertSame(1024, FloorLayout::DESKTOP_MIN_PX);
        $this->assertSame(FloorLayout::DESKTOP_MIN_PX, FloorLayout::BREAKPOINT_PX);

        $this->assertSame(FloorLayout::COOKIE, ReceiveLayout::COOKIE);
        $this->assertSame(FloorLayout::COOKIE, ShipLayout::COOKIE);
        $this->assertSame(FloorLayout::COOKIE, TransferLayout::COOKIE);
        $this->assertSame(FloorLayout::DESKTOP_MIN_PX, ReceiveLayout::BREAKPOINT_PX);
    }

    #[Test]
    public function cookie_reads_shared_tp_floor_layout_only(): void
    {
        $this->assertNull(FloorLayout::cookie());

        request()->cookies->set(FloorLayout::COOKIE, FloorLayout::DESKTOP);
        $this->assertSame(FloorLayout::DESKTOP, FloorLayout::cookie());
        $this->assertSame(FloorLayout::DESKTOP, ReceiveLayout::cookie());

        request()->cookies->set(FloorLayout::COOKIE, FloorLayout::FLOOR);
        $this->assertSame(FloorLayout::FLOOR, ShipLayout::cookie());
        $this->assertSame(FloorLayout::FLOOR, TransferLayout::cookie());

        request()->cookies->set(FloorLayout::COOKIE, 'bogus');
        $this->assertNull(FloorLayout::cookie());
    }

    #[Test]
    public function layout_switch_partial_encodes_phone_and_desktop_rules(): void
    {
        $html = view('filament.app.partials.floor-layout-switch', [
            'mode' => 'desktop',
            'desktopUrl' => 'https://example.test/desktop',
            'floorUrl' => 'https://example.test/floor',
        ])->render();

        $this->assertStringContainsString('tp_floor_layout', $html);
        $this->assertStringContainsString((string) FloorLayout::PHONE_MAX_PX, $html);
        $this->assertStringContainsString((string) FloorLayout::DESKTOP_MIN_PX, $html);
        $this->assertStringContainsString('Floor view', $html);
        $this->assertStringContainsString('w < phoneMax', $html);
        $this->assertStringContainsString('cookie !== \'desktop\'', $html);
        $this->assertStringContainsString('tp-floor-layout-toggle', $html);

        $floorHtml = view('filament.app.partials.floor-layout-switch', [
            'mode' => 'floor',
            'desktopUrl' => 'https://example.test/desktop',
            'floorUrl' => 'https://example.test/floor',
        ])->render();
        $this->assertStringContainsString('Desktop view', $floorHtml);
    }
}
