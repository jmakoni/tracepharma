<?php

namespace Tests\Unit\Models;

use App\Enums\FloorCameraScanPace;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserFloorCameraScanPaceTest extends TestCase
{
    #[Test]
    public function unset_preference_defaults_to_balanced(): void
    {
        $user = new User;
        $user->preferences = null;

        $this->assertSame(FloorCameraScanPace::Balanced, $user->floorCameraScanPace());
        $this->assertSame('balanced', $user->floorCameraScanAlpineConfig()['cameraScanPace']);
    }

    #[Test]
    public function set_and_get_round_trip_without_clobbering_other_preferences(): void
    {
        $user = new User;
        $user->preferences = [
            'dashboard_widgets' => ['orders' => true],
        ];

        $user->setFloorCameraScanPace(FloorCameraScanPace::Rapid);

        $this->assertSame(FloorCameraScanPace::Rapid, $user->floorCameraScanPace());
        $this->assertTrue(data_get($user->preferences, 'dashboard_widgets.orders'));
        $this->assertSame('rapid', data_get($user->preferences, 'floor.camera_scan_pace'));
        $this->assertSame(450, $user->floorCameraScanAlpineConfig()['cooldownMs']);
    }

    #[Test]
    public function invalid_stored_value_falls_back_to_balanced(): void
    {
        $user = new User;
        $user->preferences = [
            'floor' => ['camera_scan_pace' => 'ludicrous'],
        ];

        $this->assertSame(FloorCameraScanPace::Balanced, $user->floorCameraScanPace());
    }
}
