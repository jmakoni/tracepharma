<?php

namespace App\Support\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Support\Gs1\ElementString;
use DomainException;

/**
 * Scan In only: GS1 GTIN (01) + lot (10) without serial maps to one expected ASN line.
 * Default Receive HUD never calls this.
 */
final class ResolveLotLevelReceiveScan
{
    public function handle(ReceivingSession $session, string $scan): string
    {
        if (ElementString::sgtinIdentity($scan) !== null || str_starts_with($scan, 'urn:epc:')) {
            return $scan;
        }

        $ais = ElementString::parse($scan);
        $gtin14 = $ais['01'] ?? null;
        $lot = $ais['10'] ?? null;

        if (! filled($gtin14) || ! filled($lot)) {
            return $scan;
        }

        if (! $session->isInboundAsn()) {
            throw new DomainException('Lot-level scan needs a matching ASN line, or scan the 2D serial.');
        }

        $matches = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('status', 'expected')
            ->whereHas('epc', function ($query) use ($gtin14, $lot): void {
                $query->where('gtin14', $gtin14)
                    ->whereHas('ilmd', fn ($ilmd) => $ilmd->where('lot_number', $lot));
            })
            ->with('epc')
            ->get();

        if ($matches->count() > 1) {
            throw new DomainException('Scan the 2D serial. More than one expected ASN line matches that GTIN and lot.');
        }

        $epc = $matches->first()?->epc;
        if (! $epc instanceof Epc || blank($epc->epc_uri)) {
            throw new DomainException('No expected ASN line matches that GTIN and lot. Scan the 2D serial.');
        }

        return (string) $epc->epc_uri;
    }
}
