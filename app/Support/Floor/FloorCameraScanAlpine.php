<?php

declare(strict_types=1);

namespace App\Support\Floor;

use App\Enums\FloorCameraScanPace;
use App\Models\User;

/**
 * Alpine bootstrap payload for floor camera overlays.
 */
final class FloorCameraScanAlpine
{
    /**
     * @return array{
     *     libraryUrl: string,
     *     cameraScanPace: string,
     *     cooldownMs: int,
     *     fps: int,
     *     frameRateIdeal: int,
     *     frameRateMax: int,
     *     confirmMethod?: string
     * }
     */
    public static function tpFloorReceiveConfig(?string $confirmMethod = null): array
    {
        $user = auth()->user();
        $paceConfig = $user instanceof User
            ? $user->floorCameraScanAlpineConfig()
            : FloorCameraScanPace::default()->toAlpineConfig();

        $config = array_merge([
            'libraryUrl' => asset('vendor/html5-qrcode/html5-qrcode.min.js'),
        ], $paceConfig);

        if (filled($confirmMethod)) {
            $config['confirmMethod'] = $confirmMethod;
        }

        return $config;
    }
}
