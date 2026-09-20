<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringScanLine;
use App\Support\Copy\OperatorNouns;

/**
 * Session-finished banner copy that never claims the document is done
 * while shipment/transfer expected lines remain.
 *
 * Operator-facing quantities follow ATTP practice: count top containers the
 * operator scanned (parents). Auto-confirmed AggregationLink children are not
 * summed into that number — eaches stay on the expected-order strip.
 *
 * @phpstan-type CompleteCopy array{
 *     title: string,
 *     body: string,
 *     document_complete: bool,
 *     remaining_parents: int,
 *     session_confirmed_parents: int
 * }
 */
final class ReceivingSessionCompleteCopy
{
    /**
     * @return CompleteCopy
     */
    public static function for(ReceivingSession $session, ?ReceivingPolicy $policy = null): array
    {
        $policy ??= ReceivingPolicy::forTenant(tenant());
        $progress = ReceivingSessionProgress::for($session, $policy);
        $parentLabel = strtolower($progress->parentTypeLabel());
        $childLabel = strtolower($progress->childTypeLabel());

        if ($session->isScanFirst()) {
            return [
                'title' => 'Session complete',
                'body' => self::operatorScannedBody(
                    (int) $session->confirmed_parent_count,
                    (int) $session->confirmed_child_count,
                    $parentLabel,
                    $childLabel,
                ),
                'document_complete' => true,
                'remaining_parents' => 0,
                'session_confirmed_parents' => (int) $session->confirmed_parent_count,
            ];
        }

        if ($session->isTransferReceive()) {
            return self::forTransferReceive($session, $parentLabel, $childLabel);
        }

        return self::forInboundAsn($session, $policy, $parentLabel, $childLabel);
    }

    /**
     * Primary complete/HUD quantity: top containers scanned, else leaf scans.
     * Never parent + child as one blended "items" count.
     */
    public static function operatorScannedCount(ReceivingSession $session): int
    {
        $parents = (int) $session->confirmed_parent_count;
        if ($parents > 0) {
            return $parents;
        }

        return (int) $session->confirmed_child_count;
    }

    /**
     * @return CompleteCopy
     */
    private static function forInboundAsn(
        ReceivingSession $session,
        ReceivingPolicy $policy,
        string $parentLabel,
        string $childLabel,
    ): array {
        $shipment = ExpectedInboundOrderHeader::shipmentFor($session);
        $sessionConfirmedParents = (int) $session->confirmed_parent_count;

        if ($shipment === null) {
            return [
                'title' => 'Session complete',
                'body' => self::operatorScannedBody(
                    $sessionConfirmedParents,
                    (int) $session->confirmed_child_count,
                    $parentLabel,
                    $childLabel,
                ),
                'document_complete' => true,
                'remaining_parents' => 0,
                'session_confirmed_parents' => $sessionConfirmedParents,
            ];
        }

        if (
            (int) $shipment->expected_parent_count === 0
            && (int) $shipment->expected_each_count === 0
            && $shipment->expectedLines()->exists()
        ) {
            $shipment->refreshRollups();
        }

        $remainingParents = (int) $shipment->remainingExpectedLines()
            ->where('line_role', 'parent')
            ->count();
        $hasRemaining = $shipment->hasRemainingExpected();
        $shipmentExpectedParents = max(
            (int) $shipment->expected_parent_count,
            (int) $shipment->confirmed_parent_count + $remainingParents,
        );

        if ($hasRemaining) {
            if ($sessionConfirmedParents > 0) {
                $expectedTotal = max($shipmentExpectedParents, $sessionConfirmedParents);
                $still = $remainingParents > 0 ? $remainingParents : self::remainingAnyCount($shipment);
                $stillLabel = $remainingParents > 0
                    ? self::quantityNoun($still, $parentLabel)
                    : ($still === 1 ? 'line' : 'lines');

                $body = sprintf(
                    '%d of %d %s received this session. %d %s still expected on this ASN.',
                    $sessionConfirmedParents,
                    $expectedTotal,
                    self::quantityNoun($expectedTotal, $parentLabel),
                    $still,
                    $stillLabel,
                );
            } else {
                $body = self::operatorScannedBody(
                    0,
                    (int) $session->confirmed_child_count,
                    $parentLabel,
                    $childLabel,
                );
                $remaining = $remainingParents > 0 ? $remainingParents : self::remainingAnyCount($shipment);
                $body .= sprintf(
                    ' %d %s still expected on this ASN.',
                    $remaining,
                    $remainingParents > 0 ? $parentLabel : 'lines',
                );
            }

            return [
                'title' => 'Session complete',
                'body' => $body,
                'document_complete' => false,
                'remaining_parents' => $remainingParents,
                'session_confirmed_parents' => $sessionConfirmedParents,
            ];
        }

        return [
            'title' => OperatorNouns::ASN_COMPLETE,
            'body' => sprintf(
                'All expected %s on this ASN are confirmed.',
                $parentLabel,
            ),
            'document_complete' => true,
            'remaining_parents' => 0,
            'session_confirmed_parents' => $sessionConfirmedParents,
        ];
    }

    /**
     * @return CompleteCopy
     */
    private static function forTransferReceive(
        ReceivingSession $session,
        string $parentLabel,
        string $childLabel,
    ): array {
        $transferId = $session->transferring_session_id;
        $remaining = 0;

        if ($transferId !== null) {
            $remaining = (int) TransferringScanLine::query()
                ->where('transferring_session_id', $transferId)
                ->where('status', 'expected')
                ->count();
        }

        $parents = (int) $session->confirmed_parent_count;
        $children = (int) $session->confirmed_child_count;

        if ($remaining > 0) {
            $count = self::operatorScannedCount($session);
            $label = $parents > 0
                ? self::quantityNoun($count, $parentLabel)
                : self::quantityNoun($count, $childLabel);

            return [
                'title' => 'Session complete',
                'body' => sprintf(
                    '%d %s received this session. %d transfer line%s still expected.',
                    $count,
                    $label,
                    $remaining,
                    $remaining === 1 ? '' : 's',
                ),
                'document_complete' => false,
                'remaining_parents' => $remaining,
                'session_confirmed_parents' => $parents,
            ];
        }

        return [
            'title' => 'Transfer complete',
            'body' => 'All expected lines on this transfer are confirmed. Destination receiving EPCIS is on the transfer document.',
            'document_complete' => true,
            'remaining_parents' => 0,
            'session_confirmed_parents' => $parents,
        ];
    }

    /**
     * Top-container-first body. Never blends parents and children into one count.
     */
    private static function operatorScannedBody(
        int $parents,
        int $children,
        string $parentLabel,
        string $childLabel,
    ): string {
        if ($parents > 0) {
            return sprintf(
                '%d %s received this session.',
                $parents,
                self::quantityNoun($parents, $parentLabel),
            );
        }

        if ($children > 0) {
            return sprintf(
                '%d %s received this session.',
                $children,
                self::quantityNoun($children, $childLabel),
            );
        }

        return 'This receive session is complete.';
    }

    /**
     * Lowercase type labels from ReceivingSessionProgress are plural ("cases").
     * Use singular when count is 1 for operator-facing complete copy.
     */
    private static function quantityNoun(int $count, string $pluralLabel): string
    {
        $label = strtolower(trim($pluralLabel));
        if ($count === 1 && str_ends_with($label, 's') && ! str_ends_with($label, 'ss')) {
            return substr($label, 0, -1);
        }

        return $label;
    }

    private static function remainingAnyCount(InboundShipment $shipment): int
    {
        return (int) $shipment->remainingExpectedLines()->count();
    }
}
