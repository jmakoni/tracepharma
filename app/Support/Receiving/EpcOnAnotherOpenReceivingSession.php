<?php

namespace App\Support\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;

/**
 * Whether an EPC is already confirmed/unexpected on a different open receive session.
 */
final class EpcOnAnotherOpenReceivingSession
{
    public function exists(Epc $epc, ReceivingSession $current): bool
    {
        return $this->otherSession($epc, $current) !== null;
    }

    public function existsOnAnyExclusiveSession(Epc $epc): bool
    {
        return ReceivingScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->whereIn('status', ['confirmed', 'unexpected'])
            ->whereHas('session', fn ($query) => $this->applyExclusiveSessionScope($query))
            ->exists();
    }

    public function otherSession(Epc $epc, ReceivingSession $current): ?ReceivingSession
    {
        $line = ReceivingScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->whereIn('status', ['confirmed', 'unexpected'])
            ->whereHas('session', function ($query) use ($current): void {
                $this->applyExclusiveSessionScope($query);
                $query->whereKeyNot($current->getKey());
            })
            ->with(['session' => fn ($q) => $q->select(['id', 'status', 'session_kind', 'opened_at'])])
            ->orderByDesc('id')
            ->first();

        return $line?->session;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ReceivingSession>  $query
     */
    private function applyExclusiveSessionScope($query): void
    {
        $query->where(function ($exclusive): void {
            $exclusive
                ->whereIn('status', ['open', 'in_progress'])
                ->orWhere(function ($pendingGenerate): void {
                    // Completed but receiving EPCIS not authored yet — still
                    // exclusive so a second session cannot confirm the same EPC
                    // in the gap between Complete and GenerateReceivingEpcisEvents.
                    $pendingGenerate
                        ->where('status', 'completed')
                        ->whereNull('receiving_events_generated_at');
                });
        });
    }
}
