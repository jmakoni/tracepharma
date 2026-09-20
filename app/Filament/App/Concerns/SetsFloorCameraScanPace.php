<?php

declare(strict_types=1);

namespace App\Filament\App\Concerns;

use App\Enums\FloorCameraScanPace;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Persist per-user floor camera scan pace from mobile HUD overlays.
 */
trait SetsFloorCameraScanPace
{
    /**
     * @return array{cameraScanPace: string, cooldownMs: int, fps: int, frameRateIdeal: int, frameRateMax: int}
     */
    public function setFloorCameraScanPace(string $pace): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'pace' => 'You must be signed in to change camera scan pace.',
            ]);
        }

        $resolved = FloorCameraScanPace::tryFromUserValue($pace);
        $user->setFloorCameraScanPace($resolved);
        $user->save();

        return $resolved->toAlpineConfig();
    }
}
