<?php

namespace App\Support\Vrs;

use App\Models\Verification;
use Illuminate\Support\Carbon;

/**
 * Dispense / verify scorecard counts for the last 24 hours.
 */
final class VerificationScorecardMetrics
{
    /**
     * @return array{
     *     allowed: int,
     *     blocked: int,
     *     deferred: int,
     *     unavailable: int,
     *     since: string
     * }
     */
    public function handle(?Carbon $since = null): array
    {
        $since ??= now()->subDay();

        $base = Verification::query()->where('created_at', '>=', $since);

        $allowed = (clone $base)->where('status', 'verified')->count();
        $blocked = (clone $base)->whereIn('status', ['failed', 'suspect'])->count();
        $deferred = (clone $base)->where('status', 'deferred')->count();
        $unavailable = (clone $base)->where('status', 'unavailable')->count();

        return [
            'allowed' => $allowed,
            'blocked' => $blocked,
            'deferred' => $deferred,
            'unavailable' => $unavailable,
            'since' => $since->toIso8601String(),
        ];
    }
}
