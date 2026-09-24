<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;

/**
 * Stamp an existing verify/quarantine case onto an open receive session
 * when the EPC already sits on that session. Does not author a receive type.
 */
final class LinkVerifyCaseToOpenReceive
{
    /** @var list<string> */
    private const LINE_STATUSES = ['expected', 'staged', 'confirmed', 'unexpected'];

    /**
     * @param  list<int>  $epcIds
     * @return array{receiving_session_id: int, epc_id: int}|null
     */
    public function stamp(ExceptionCase $case, array $epcIds, ?User $actor = null): ?array
    {
        $meta = $this->sessionMetaForEpcs($epcIds);
        if ($meta === null) {
            return null;
        }

        $already = $case->activities()
            ->where(function ($query) use ($meta): void {
                $query->where('meta->receiving_session_id', $meta['receiving_session_id'])
                    ->orWhere('meta->receiving_session_id', (string) $meta['receiving_session_id']);
            })
            ->exists();

        if (! $already) {
            $case->logActivity(
                ExceptionActivityKind::System,
                $actor,
                'Linked to open receiving session #'.$meta['receiving_session_id'].'.',
                ExceptionActivityVisibility::Internal,
                $meta,
            );
        }

        return $meta;
    }

    /**
     * @param  list<int>  $epcIds
     * @return array{receiving_session_id: int, epc_id: int}|null
     */
    public function sessionMetaForEpcs(array $epcIds): ?array
    {
        foreach (array_values(array_unique(array_filter($epcIds))) as $epcId) {
            $session = $this->openSessionForEpcId((int) $epcId);
            if ($session === null) {
                continue;
            }

            return [
                'receiving_session_id' => (int) $session->getKey(),
                'epc_id' => (int) $epcId,
            ];
        }

        return null;
    }

    public function openSessionForEpc(Epc $epc): ?ReceivingSession
    {
        return $this->openSessionForEpcId((int) $epc->getKey());
    }

    private function openSessionForEpcId(int $epcId): ?ReceivingSession
    {
        if ($epcId <= 0) {
            return null;
        }

        $line = ReceivingScanLine::query()
            ->where('epc_id', $epcId)
            ->whereIn('status', self::LINE_STATUSES)
            ->whereHas('session', function ($query): void {
                $query->whereIn('status', ['open', 'in_progress']);
            })
            ->with(['session' => fn ($query) => $query->select(['id', 'status'])])
            ->orderByDesc('id')
            ->first();

        return $line?->session;
    }
}
