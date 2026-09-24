<?php

namespace App\Support\Receiving;

use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment expected-line claims for parallel ASN receive sessions.
 *
 * A live claim (claimed_receiving_session_id pointing at open/in_progress) means
 * only that session may confirm the EPC. Confirmed lines use confirmed_receiving_session_id.
 */
final class InboundExpectedLineClaims
{
    private static ?bool $schemaReady = null;

    /**
     * @param  list<int>  $parentEpcIds
     * @return list<int> successfully claimed parent epc ids
     */
    public static function claimExpectedParents(ReceivingSession $session, array $parentEpcIds): array
    {
        return self::claimExpectedByRole($session, $parentEpcIds, 'parent');
    }

    /**
     * @param  list<int>  $childEpcIds
     * @return list<int> successfully claimed child epc ids
     */
    public static function claimExpectedChildren(ReceivingSession $session, array $childEpcIds): array
    {
        return self::claimExpectedByRole($session, $childEpcIds, 'child');
    }

    /**
     * Bulk claim expected lines for a role (one lock query + one UPDATE).
     *
     * @param  list<int>  $epcIds
     * @return list<int> successfully claimed epc ids
     */
    private static function claimExpectedByRole(ReceivingSession $session, array $epcIds, string $lineRole): array
    {
        if (! self::enabled($session) || $epcIds === []) {
            return array_values(array_unique(array_map('intval', $epcIds)));
        }

        $shipmentId = (int) $session->inbound_shipment_id;
        $sessionId = (int) $session->getKey();
        $uniqueEpcIds = array_values(array_unique(array_map('intval', $epcIds)));

        $lines = InboundExpectedLine::query()
            ->where('inbound_shipment_id', $shipmentId)
            ->where('line_role', $lineRole)
            ->whereIn('epc_id', $uniqueEpcIds)
            ->lockForUpdate()
            ->get(['id', 'epc_id', 'status', 'claimed_receiving_session_id']);

        $otherClaimers = $lines
            ->pluck('claimed_receiving_session_id')
            ->filter(fn ($id): bool => $id !== null && (int) $id !== $sessionId)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $liveOtherClaimers = $otherClaimers === []
            ? []
            : array_fill_keys(
                ReceivingSession::query()
                    ->whereIn('id', $otherClaimers)
                    ->whereIn('status', ['open', 'in_progress'])
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
                true,
            );

        $claimLineIds = [];
        $claimed = [];

        foreach ($lines as $line) {
            if ($line->status !== 'expected') {
                continue;
            }

            $claimedBy = $line->claimed_receiving_session_id !== null
                ? (int) $line->claimed_receiving_session_id
                : null;

            if ($claimedBy !== null && $claimedBy !== $sessionId && isset($liveOtherClaimers[$claimedBy])) {
                continue;
            }

            $claimLineIds[] = (int) $line->getKey();
            $claimed[] = (int) $line->epc_id;
        }

        if ($claimLineIds !== []) {
            InboundExpectedLine::query()
                ->whereIn('id', $claimLineIds)
                ->update(['claimed_receiving_session_id' => $sessionId]);
        }

        return array_values(array_unique($claimed));
    }

    public static function releaseUnconfirmedForSession(ReceivingSession $session): void
    {
        if (! Schema::hasTable('inbound_expected_lines')
            || ! Schema::hasColumn('inbound_expected_lines', 'claimed_receiving_session_id')) {
            return;
        }

        InboundExpectedLine::query()
            ->where('claimed_receiving_session_id', $session->getKey())
            ->where('status', 'expected')
            ->update(['claimed_receiving_session_id' => null]);
    }

    /**
     * Parent epc ids that are expected and free (or already claimed by $forSessionId).
     *
     * @return list<int>
     */
    public static function availableExpectedParentEpcIds(int $shipmentId, ?int $forSessionId = null): array
    {
        if (! Schema::hasTable('inbound_expected_lines')) {
            return [];
        }

        $lines = InboundExpectedLine::query()
            ->where('inbound_shipment_id', $shipmentId)
            ->where('line_role', 'parent')
            ->where('status', 'expected')
            ->orderBy('epc_id')
            ->get(['epc_id', 'claimed_receiving_session_id', 'status']);

        $ids = [];
        foreach ($lines as $line) {
            if (self::isAvailableToSession($line, $forSessionId)) {
                $ids[] = (int) $line->epc_id;
            }
        }

        return $ids;
    }

    /**
     * True when another live session holds the claim.
     */
    public static function isClaimedByOtherLiveSession(InboundExpectedLine $line, int $sessionId): bool
    {
        $claimedBy = $line->claimed_receiving_session_id !== null
            ? (int) $line->claimed_receiving_session_id
            : null;

        if ($claimedBy === null || $claimedBy === $sessionId) {
            return false;
        }

        return self::sessionIsLive($claimedBy);
    }

    public static function isAvailableToSession(InboundExpectedLine $line, ?int $sessionId): bool
    {
        if ($line->status !== 'expected') {
            return false;
        }

        $claimedBy = $line->claimed_receiving_session_id !== null
            ? (int) $line->claimed_receiving_session_id
            : null;

        if ($claimedBy === null) {
            return true;
        }

        if ($sessionId !== null && $claimedBy === $sessionId) {
            return true;
        }

        return ! self::sessionIsLive($claimedBy);
    }

    private static function sessionIsLive(int $sessionId): bool
    {
        return ReceivingSession::query()
            ->whereKey($sessionId)
            ->whereIn('status', ['open', 'in_progress'])
            ->exists();
    }

    private static function enabled(ReceivingSession $session): bool
    {
        if ($session->inbound_shipment_id === null) {
            return false;
        }

        if (self::$schemaReady === null) {
            self::$schemaReady = Schema::hasTable('inbound_expected_lines')
                && Schema::hasColumn('inbound_expected_lines', 'claimed_receiving_session_id')
                && Schema::hasColumn('receiving_sessions', 'inbound_shipment_id');
        }

        return self::$schemaReady;
    }
}
