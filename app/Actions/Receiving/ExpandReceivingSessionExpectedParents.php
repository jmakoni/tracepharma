<?php

namespace App\Actions\Receiving;

use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Facades\DB;

/**
 * Add missing expected parent scan lines when a late ASN file joins an open receive session.
 */
final class ExpandReceivingSessionExpectedParents
{
    /**
     * @param  list<int>  $rootParentEpcIds
     */
    public function handle(ReceivingSession $session, array $rootParentEpcIds): ReceivingSession
    {
        $rootParentEpcIds = array_values(array_unique(array_map('intval', $rootParentEpcIds)));
        if ($rootParentEpcIds === []) {
            return $session;
        }

        return DB::transaction(function () use ($session, $rootParentEpcIds): ReceivingSession {
            $session = ReceivingSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'parent')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $existingSet = array_fill_keys($existing, true);
            $toAdd = array_values(array_filter(
                $rootParentEpcIds,
                fn (int $id): bool => ! isset($existingSet[$id]),
            ));

            if ($toAdd === []) {
                $session->forceFill([
                    'expected_parent_count' => max((int) $session->expected_parent_count, count($rootParentEpcIds)),
                ])->save();

                return $session->refresh();
            }

            $now = now();
            $rows = [];
            foreach ($toAdd as $epcId) {
                $rows[] = [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $epcId,
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'expected',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            ReceivingScanLine::query()->insert($rows);

            $parentCount = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'parent')
                ->count();

            $session->forceFill([
                'expected_parent_count' => $parentCount,
            ])->save();

            return $session->refresh();
        });
    }
}
