<?php

namespace App\Actions\Packing;

use App\Models\Packing\PackingScanLine;
use App\Models\Packing\PackingSession;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use Illuminate\Support\Facades\DB;

/**
 * Mark pack session complete and release reservations after EPCIS is authored.
 */
final class CompletePackingSession
{
    public function __construct(
        private readonly EpcExclusiveSessionGate $exclusiveGate,
    ) {}

    public function handle(PackingSession $session, ?int $actorId = null): PackingSession
    {
        return DB::transaction(function () use ($session, $actorId): PackingSession {
            $session = PackingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->packing_events_generated_at !== null) {
                return $session;
            }

            $epcIds = PackingScanLine::query()
                ->where('packing_session_id', $session->getKey())
                ->whereIn('status', ['staged', 'confirmed'])
                ->orderBy('id')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($session->parent_epc_id !== null) {
                $epcIds[] = (int) $session->parent_epc_id;
            }

            $this->exclusiveGate->assertParentsHierarchyFree(
                $epcIds,
                ExclusiveSessionContext::forPacking($session),
            );

            $now = now();

            PackingScanLine::query()
                ->where('packing_session_id', $session->getKey())
                ->where('status', 'staged')
                ->update([
                    'status' => 'confirmed',
                    'confirmed_at' => $now,
                    'confirmed_by' => $actorId ?? auth()->id(),
                ]);

            $confirmedCount = PackingScanLine::query()
                ->where('packing_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->count();

            $session->forceFill([
                'status' => 'completed',
                'staged_count' => 0,
                'confirmed_count' => $confirmedCount,
                'completed_at' => $now,
                'packing_events_generated_at' => $now,
            ])->save();

            return $session->fresh() ?? $session;
        });
    }
}
