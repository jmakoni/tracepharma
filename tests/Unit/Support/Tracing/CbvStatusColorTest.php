<?php

namespace Tests\Unit\Support\Tracing;

use App\Support\Tracing\CbvStatusColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CbvStatusColorTest extends TestCase
{
    #[Test]
    public function disposition_accepts_urn_and_local_name(): void
    {
        $this->assertSame('primary', CbvStatusColor::disposition('urn:epcglobal:cbv:disp:in_transit'));
        $this->assertSame('primary', CbvStatusColor::disposition('in_transit'));
    }

    #[Test]
    #[DataProvider('dispositionColors')]
    public function disposition_maps_expected_colors(?string $value, string $expected): void
    {
        $this->assertSame($expected, CbvStatusColor::disposition($value));
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function dispositionColors(): array
    {
        return [
            'active' => ['active', 'success'],
            'retail_sold' => ['retail_sold', 'success'],
            'in_progress' => ['in_progress', 'info'],
            'in_transit' => ['in_transit', 'primary'],
            'encoded' => ['encoded', 'gray'],
            'reserved' => ['reserved', 'gray'],
            'returned' => ['returned', 'warning'],
            'expired' => ['expired', 'danger'],
            'recalled' => ['recalled', 'danger'],
            'destroyed' => ['destroyed', 'danger'],
            'decommissioned' => ['decommissioned', 'danger'],
            'unknown' => ['custom_state', 'gray'],
            'empty' => [null, 'gray'],
        ];
    }

    #[Test]
    #[DataProvider('businessStepColors')]
    public function business_step_maps_expected_colors(?string $value, string $expected): void
    {
        $this->assertSame($expected, CbvStatusColor::businessStep($value));
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function businessStepColors(): array
    {
        return [
            'commissioning urn' => ['urn:epcglobal:cbv:bizstep:commissioning', 'info'],
            'shipping' => ['shipping', 'primary'],
            'departing' => ['departing', 'primary'],
            'receiving' => ['receiving', 'success'],
            'arriving' => ['arriving', 'success'],
            'accepting' => ['accepting', 'success'],
            'packing' => ['packing', 'gray'],
            'storing' => ['storing', 'info'],
            'holding' => ['holding', 'warning'],
            'inspecting' => ['inspecting', 'warning'],
            'void_shipping' => ['void_shipping', 'warning'],
            'decommissioning' => ['decommissioning', 'danger'],
            'unknown' => ['mystery_step', 'gray'],
            'empty' => ['', 'gray'],
        ];
    }

    #[Test]
    public function action_maps_delete_to_warning(): void
    {
        $this->assertSame('warning', CbvStatusColor::action('DELETE'));
        $this->assertSame('gray', CbvStatusColor::action('OBSERVE'));
        $this->assertSame('gray', CbvStatusColor::action('ADD'));
        $this->assertSame('gray', CbvStatusColor::action(null));
    }

    #[Test]
    public function daisy_badge_class_maps_filament_colors(): void
    {
        $this->assertSame('badge-primary', CbvStatusColor::daisyBadgeClass('primary'));
        $this->assertSame('badge-error', CbvStatusColor::daisyBadgeClass('danger'));
        $this->assertSame('badge-ghost', CbvStatusColor::daisyBadgeClass('gray'));
    }
}
