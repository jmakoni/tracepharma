<?php

namespace Tests\Unit\Enums;

use App\Enums\FloorCameraScanPace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FloorCameraScanPaceTest extends TestCase
{
    #[Test]
    public function balanced_matches_legacy_hardcoded_camera_defaults(): void
    {
        $pace = FloorCameraScanPace::Balanced;

        $this->assertSame(1000, $pace->cooldownMs());
        $this->assertSame(24, $pace->fps());
        $this->assertSame(24, $pace->frameRateIdeal());
        $this->assertSame(30, $pace->frameRateMax());
    }

    #[Test]
    #[DataProvider('presetProvider')]
    public function presets_resolve_expected_knobs(
        FloorCameraScanPace $pace,
        int $cooldownMs,
        int $fps,
        int $frameRateIdeal,
        int $frameRateMax,
    ): void {
        $this->assertSame($cooldownMs, $pace->cooldownMs());
        $this->assertSame($fps, $pace->fps());
        $this->assertSame($frameRateIdeal, $pace->frameRateIdeal());
        $this->assertSame($frameRateMax, $pace->frameRateMax());

        $config = $pace->toAlpineConfig();
        $this->assertSame($pace->value, $config['cameraScanPace']);
        $this->assertSame($cooldownMs, $config['cooldownMs']);
        $this->assertSame($fps, $config['fps']);
        $this->assertSame($frameRateIdeal, $config['frameRateIdeal']);
        $this->assertSame($frameRateMax, $config['frameRateMax']);
    }

    /**
     * @return array<string, array{0: FloorCameraScanPace, 1: int, 2: int, 3: int, 4: int}>
     */
    public static function presetProvider(): array
    {
        return [
            'careful' => [FloorCameraScanPace::Careful, 1800, 12, 12, 15],
            'balanced' => [FloorCameraScanPace::Balanced, 1000, 24, 24, 30],
            'rapid' => [FloorCameraScanPace::Rapid, 450, 30, 30, 30],
        ];
    }

    #[Test]
    public function try_from_user_value_falls_back_to_balanced(): void
    {
        $this->assertSame(FloorCameraScanPace::Balanced, FloorCameraScanPace::tryFromUserValue(null));
        $this->assertSame(FloorCameraScanPace::Balanced, FloorCameraScanPace::tryFromUserValue(''));
        $this->assertSame(FloorCameraScanPace::Balanced, FloorCameraScanPace::tryFromUserValue('turbo'));
        $this->assertSame(FloorCameraScanPace::Rapid, FloorCameraScanPace::tryFromUserValue('rapid'));
    }

    #[Test]
    public function paces_expose_distinct_icons(): void
    {
        $icons = array_map(
            fn (FloorCameraScanPace $pace): string => $pace->icon(),
            FloorCameraScanPace::ordered(),
        );

        $this->assertSame([
            'heroicon-o-shield-check',
            'heroicon-o-scale',
            'heroicon-o-bolt',
        ], $icons);
        $this->assertCount(3, array_unique($icons));
    }
}
