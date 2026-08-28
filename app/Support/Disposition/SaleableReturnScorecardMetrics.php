<?php

declare(strict_types=1);

namespace App\Support\Disposition;

use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\EpcisDocument;
use App\Support\Vrs\VerificationScorecardMetrics;

/**
 * Compact scorecard for the saleable return workstation (demo + ops readiness).
 */
final class SaleableReturnScorecardMetrics
{
    /**
     * @return array{
     *     vrs_verified: int,
     *     vrs_blocked: int,
     *     vrs_deferred: int,
     *     returning_authored_today: int,
     *     session_confirmed: int
     * }
     */
    public function handle(int $sessionConfirmed = 0): array
    {
        $vrs = app(VerificationScorecardMetrics::class)->handle();

        $returningToday = EpcisDocument::query()
            ->where('authored_kind', EpcisAuthoredKind::Returning)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return [
            'vrs_verified' => (int) ($vrs['allowed'] ?? 0),
            'vrs_blocked' => (int) ($vrs['blocked'] ?? 0),
            'vrs_deferred' => (int) ($vrs['deferred'] ?? 0),
            'returning_authored_today' => $returningToday,
            'session_confirmed' => max(0, $sessionConfirmed),
        ];
    }
}
