<?php

namespace App\Actions\Packing;

use App\Models\Packing\PackingScanLine;
use App\Models\Packing\PackingSession;
use Illuminate\Support\Facades\DB;

/**
 * Mark pack session complete and release reservations after EPCIS is authored.
 */
final class CompletePackingSession
{
    public function handle(PackingSession $session, ?int $actorId = null): PackingSession
    {
        return DB::transaction(function () use ($session, $actorId): PackingSession {
            $session = PackingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->packing_events_generated_at !== null) {
                return $session;
            }

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
