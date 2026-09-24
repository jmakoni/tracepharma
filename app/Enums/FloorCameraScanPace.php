<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-user floor camera scan pace (cool-down + decode fps/frameRate).
 * Balanced matches the pre-preference hardcoded html5-qrcode defaults.
 */
enum FloorCameraScanPace: string
{
    case Careful = 'careful';
    case Balanced = 'balanced';
    case Rapid = 'rapid';

    public static function default(): self
    {
        return self::Balanced;
    }

    public static function tryFromUserValue(mixed $value): self
    {
        if (! is_string($value) || $value === '') {
            return self::default();
        }

        return self::tryFrom($value) ?? self::default();
    }

    public function label(): string
    {
        return match ($this) {
            self::Careful => 'Careful',
            self::Balanced => 'Balanced',
            self::Rapid => 'Rapid',
        };
    }

    /**
     * Filament / Heroicon name for floor camera overlay chips.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Careful => 'heroicon-o-shield-check',
            self::Balanced => 'heroicon-o-scale',
            self::Rapid => 'heroicon-o-bolt',
        };
    }

    public function cooldownMs(): int
    {
        return match ($this) {
            self::Careful => 1800,
            self::Balanced => 1000,
            self::Rapid => 450,
        };
    }

    public function fps(): int
    {
        return match ($this) {
            self::Careful => 12,
            self::Balanced => 24,
            self::Rapid => 30,
        };
    }

    public function frameRateIdeal(): int
    {
        return match ($this) {
            self::Careful => 12,
            self::Balanced => 24,
            self::Rapid => 30,
        };
    }

    public function frameRateMax(): int
    {
        return match ($this) {
            self::Careful => 15,
            self::Balanced => 30,
            self::Rapid => 30,
        };
    }

    /**
     * @return array{cameraScanPace: string, cooldownMs: int, fps: int, frameRateIdeal: int, frameRateMax: int}
     */
    public function toAlpineConfig(): array
    {
        return [
            'cameraScanPace' => $this->value,
            'cooldownMs' => $this->cooldownMs(),
            'fps' => $this->fps(),
            'frameRateIdeal' => $this->frameRateIdeal(),
            'frameRateMax' => $this->frameRateMax(),
        ];
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Careful, self::Balanced, self::Rapid];
    }
}
