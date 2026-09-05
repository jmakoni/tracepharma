<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Receiving\ReceivingSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared overlap filter for scan-first → ASN / transfer_receive propagate.
 * Only sessions with confirmed EPCs that also appear on the target session.
 */
final class ScanFirstSessionsForPropagate
{
    /**
     * @param  Builder<ReceivingSession>  $query
     * @return Builder<ReceivingSession>
     */
    public static function constrainToOverlappingTargetEpcs(Builder $query, ReceivingSession $target): Builder
    {
        $targetKey = $target->getKey();

        return $query->whereExists(function ($exists) use ($targetKey): void {
            $exists->selectRaw('1')
                ->from('receiving_scan_lines as sfl')
                ->whereColumn('sfl.receiving_session_id', 'receiving_sessions.id')
                ->where('sfl.status', 'confirmed')
                ->whereNotNull('sfl.epc_id')
                ->whereIn('sfl.epc_id', function ($sub) use ($targetKey): void {
                    $sub->select('epc_id')
                        ->from('receiving_scan_lines')
                        ->where('receiving_session_id', $targetKey)
                        ->whereNotNull('epc_id');
                });
        });
    }
}
