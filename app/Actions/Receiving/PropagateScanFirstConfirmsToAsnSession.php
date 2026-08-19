<?php

namespace App\Actions\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Receiving\ReceivingSession;
use DomainException;

final class PropagateScanFirstConfirmsToAsnSession
{
    public function __construct(
        private readonly CopyConfirmedReceivingScansToSession $copyConfirmedReceivingScansToSession,
    ) {}

    /**
     * @return array{
     *     copied: int,
     *     already_confirmed: int,
     *     skipped: int,
     *     notes: list<string>
     * }
     */
    public function handle(ReceivingSession $asnSession, ?int $userId = null): array
    {
        if (! $asnSession->isInboundAsn()) {
            throw new DomainException('Propagate scan-first confirms requires an inbound ASN receiving session.');
        }

        $asnSession = $asnSession->fresh() ?? $asnSession;
        $siteId = $asnSession->site_id;

        $scanFirstQuery = ReceivingSession::query()
            ->where('session_kind', ReceivingSessionKind::ScanFirst)
            ->whereIn('status', ['open', 'in_progress', 'completed']);

        if ($siteId !== null) {
            $scanFirstQuery
                ->where(function ($q) use ($siteId): void {
                    $q->where('site_id', $siteId)
                        ->orWhereNull('site_id');
                })
                ->orderByRaw('CASE WHEN site_id IS NULL THEN 1 ELSE 0 END');
        }

        $scanFirstSessions = $scanFirstQuery
            ->orderBy('id')
            ->get();

        $copied = 0;
        $alreadyConfirmed = 0;
        $skipped = 0;
        $notes = [];

        foreach ($scanFirstSessions as $from) {
            $result = $this->copyConfirmedReceivingScansToSession->handle(
                $from,
                $asnSession,
                $userId,
                strictManifestOnly: true,
            );

            $copied += $result['copied'];
            $alreadyConfirmed += $result['already_confirmed'];
            $skipped += $result['skipped'];
            $notes = array_merge($notes, $result['notes']);
        }

        return [
            'copied' => $copied,
            'already_confirmed' => $alreadyConfirmed,
            'skipped' => $skipped,
            'notes' => $notes,
        ];
    }
}
