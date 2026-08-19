<?php

namespace App\Actions\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use DomainException;

/**
 * Backfill transfer_receive expected lines from prior scan-first confirms at the same site.
 * Uses ConfirmReceivingScan so transferring_scan_lines.received_at and session completion stay in sync.
 */
final class PropagateScanFirstConfirmsToTransferReceiveSession
{
    public function __construct(
        private readonly CopyConfirmedReceivingScansToSession $copyConfirmedReceivingScansToSession,
        private readonly CompleteReceivingSession $completeReceivingSession,
    ) {}

    /**
     * @return array{
     *     copied: int,
     *     already_confirmed: int,
     *     skipped: int,
     *     notes: list<string>,
     *     session_completed: bool
     * }
     */
    public function handle(ReceivingSession $transferReceive, ?int $userId = null): array
    {
        if (! $transferReceive->isTransferReceive()) {
            throw new DomainException('Propagate scan-first confirms requires a transfer_receive session.');
        }

        $transferReceive = $transferReceive->fresh() ?? $transferReceive;
        $siteId = $transferReceive->site_id;

        $scanFirstQuery = ReceivingSession::query()
            ->where('session_kind', ReceivingSessionKind::ScanFirst)
            ->whereIn('status', ['open', 'in_progress', 'completed']);

        if ($siteId === null) {
            throw new DomainException('Transfer receive session has no site_id — cannot propagate scan-first confirms.');
        }

        $scanFirstQuery->where('site_id', $siteId);

        $scanFirstSessions = $scanFirstQuery
            ->orderBy('id')
            ->get();

        $copied = 0;
        $alreadyConfirmed = 0;
        $skipped = 0;
        $notes = [];

        foreach ($scanFirstSessions as $from) {
            // strictManifestOnly false → ConfirmReceivingScan on transfer_receive (dual-write + complete).
            $result = $this->copyConfirmedReceivingScansToSession->handle(
                $from,
                $transferReceive,
                $userId,
                strictManifestOnly: false,
            );

            $copied += $result['copied'];
            $alreadyConfirmed += $result['already_confirmed'];
            $skipped += $result['skipped'];
            $notes = array_merge($notes, $result['notes']);
        }

        $transferReceive = $transferReceive->fresh() ?? $transferReceive;
        $sessionCompleted = $transferReceive->status === 'completed';

        if (! $sessionCompleted) {
            $remainingExpected = ReceivingScanLine::query()
                ->where('receiving_session_id', $transferReceive->getKey())
                ->where('status', 'expected')
                ->count();

            if ($remainingExpected === 0 && $copied + $alreadyConfirmed > 0) {
                try {
                    $this->completeReceivingSession->handle($transferReceive, $userId);
                    $sessionCompleted = true;
                } catch (\Throwable $e) {
                    $notes[] = 'Copied confirms, but transfer receive could not be completed: '.$e->getMessage();
                }
            }
        }

        return [
            'copied' => $copied,
            'already_confirmed' => $alreadyConfirmed,
            'skipped' => $skipped,
            'notes' => $notes,
            'session_completed' => $sessionCompleted,
        ];
    }
}
