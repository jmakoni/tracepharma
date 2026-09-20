<?php

namespace Tests\Unit\Support\Floor;

use App\Enums\FloorCameraScanPace;
use App\Models\User;
use App\Support\Floor\FloorCameraScanAlpine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FloorCameraScanAlpineTest extends TestCase
{
    #[Test]
    public function guest_config_uses_balanced_defaults_and_library_url(): void
    {
        auth()->logout();

        $config = FloorCameraScanAlpine::tpFloorReceiveConfig();

        $this->assertSame('balanced', $config['cameraScanPace']);
        $this->assertSame(1000, $config['cooldownMs']);
        $this->assertSame(24, $config['fps']);
        $this->assertStringContainsString('html5-qrcode', $config['libraryUrl']);
    }

    #[Test]
    public function authenticated_user_config_uses_stored_pace(): void
    {
        $user = new User;
        $user->setFloorCameraScanPace(FloorCameraScanPace::Careful);
        $this->actingAs($user);

        $config = FloorCameraScanAlpine::tpFloorReceiveConfig();

        $this->assertSame('careful', $config['cameraScanPace']);
        $this->assertSame(1800, $config['cooldownMs']);
        $this->assertSame(12, $config['fps']);
    }

    #[Test]
    public function explicit_confirm_method_is_included(): void
    {
        auth()->logout();

        $config = FloorCameraScanAlpine::tpFloorReceiveConfig('stageScan');

        $this->assertSame('stageScan', $config['confirmMethod']);
        $this->assertArrayNotHasKey('confirmMethod', FloorCameraScanAlpine::tpFloorReceiveConfig());
    }
}
