<?php

declare(strict_types=1);

namespace App\Support\BuyingGroup;

use App\Models\BuyingGroupMember;

/**
 * Lightweight enrollment stub for Buying Group HQ (Wave F5).
 * Soft = roster row without hard-linked member_tenant_id; hard = linked TracePharma tenant.
 */
final class BuyingGroupEnrollmentAnalytics
{
    /**
     * @return array{
     *     total: int,
     *     soft_linked: int,
     *     hard_linked: int,
     *     with_affiliation_code: int,
     *     affiliation_code_pct: float
     * }
     */
    public function summarize(): array
    {
        $total = (int) BuyingGroupMember::query()->count();
        $hardLinked = (int) BuyingGroupMember::query()
            ->whereNotNull('member_tenant_id')
            ->where('member_tenant_id', '!=', '')
            ->count();
        $withAffiliation = (int) BuyingGroupMember::query()
            ->whereNotNull('affiliation_code')
            ->where('affiliation_code', '!=', '')
            ->count();

        return [
            'total' => $total,
            'soft_linked' => max(0, $total - $hardLinked),
            'hard_linked' => $hardLinked,
            'with_affiliation_code' => $withAffiliation,
            'affiliation_code_pct' => $total === 0
                ? 0.0
                : round(($withAffiliation / $total) * 100, 1),
        ];
    }
}
