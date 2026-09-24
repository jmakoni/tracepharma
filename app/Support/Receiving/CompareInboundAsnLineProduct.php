<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Facades\Schema;

/**
 * Compare a scanned 2D GTIN/lot to the ASN / expected-line product identity
 * (order line), not inbound EPCIS PI.
 */
final class CompareInboundAsnLineProduct
{
    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, array{scan: string, line: string}>|null
     */
    public function mismatch(ReceivingSession $session, Epc $epc, array $identity): ?array
    {
        $lineEpc = $this->expectedLineEpc($session, $epc);
        if ($lineEpc === null) {
            return null;
        }

        $mismatch = [];

        $scanGtin = $this->digits((string) ($identity['gtin14'] ?? ''));
        $lineGtin = $this->digits((string) ($lineEpc->gtin14 ?? ''));
        if ($scanGtin !== '' && $lineGtin !== '' && $scanGtin !== $lineGtin) {
            $mismatch['gtin'] = ['scan' => $scanGtin, 'line' => $lineGtin];
        }

        $scanLot = trim((string) ($identity['lot_number'] ?? ''));
        $lineLot = trim((string) ($lineEpc->ilmd?->lot_number ?? ''));
        if ($scanLot !== '' && $lineLot !== '' && $scanLot !== $lineLot) {
            $mismatch['lot'] = ['scan' => $scanLot, 'line' => $lineLot];
        }

        return $mismatch === [] ? null : $mismatch;
    }

    private function expectedLineEpc(ReceivingSession $session, Epc $epc): ?Epc
    {
        $epcId = (int) $epc->getKey();

        $onSession = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('epc_id', $epcId)
            ->whereIn('status', ['expected', 'confirmed'])
            ->exists();

        if ($onSession) {
            return $epc;
        }

        if (
            Schema::hasTable('inbound_expected_lines')
            && $session->inbound_shipment_id !== null
            && InboundExpectedLine::query()
                ->where('inbound_shipment_id', $session->inbound_shipment_id)
                ->where('epc_id', $epcId)
                ->whereIn('status', ['expected', 'confirmed'])
                ->exists()
        ) {
            return $epc;
        }

        return null;
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
