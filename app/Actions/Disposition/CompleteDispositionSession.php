<?php

namespace App\Actions\Disposition;

use App\Models\Disposition\DispositionScanLine;
use App\Models\Disposition\DispositionSession;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use Illuminate\Support\Facades\DB;

/**
 * Mark disposition session complete after EPCIS is authored.
 */
final class CompleteDispositionSession
{
    public function __construct(
        private readonly EpcExclusiveSessionGate $exclusiveGate,
    ) {}

    public function handle(DispositionSession $session, ?int $actorId = null): DispositionSession
    {
        return DB::transaction(function () use ($session, $actorId): DispositionSession {
            $session = DispositionSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->disposition_events_generated_at !== null) {
                return $session;
            }

            $epcIds = DispositionScanLine::query()
                ->where('disposition_session_id', $session->getKey())
                ->whereIn('status', ['staged', 'confirmed'])
                ->orderBy('id')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $this->exclusiveGate->assertParentsHierarchyFree(
                $epcIds,
                ExclusiveSessionContext::forDisposition($session),
            );

            $now = now();

            DispositionScanLine::query()
                ->where('disposition_session_id', $session->getKey())
                ->where('status', 'staged')
                ->update([
                    'status' => 'confirmed',
                    'confirmed_at' => $now,
                    'confirmed_by' => $actorId ?? auth()->id(),
                ]);

            $confirmedCount = DispositionScanLine::query()
                ->where('disposition_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->count();

            $session->forceFill([
                'status' => 'completed',
                'staged_count' => 0,
                'confirmed_count' => $confirmedCount,
                'completed_at' => $now,
                'disposition_events_generated_at' => $now,
            ])->save();

            return $session->fresh() ?? $session;
        });
    }
}
