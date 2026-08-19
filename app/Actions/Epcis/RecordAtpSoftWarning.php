<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Support\MasterData\AtpDisclosure;
use App\Support\MasterData\AtpReadinessGate;
use App\Support\MasterData\TenantReceivingState;

/**
 * Soft ATP gate at ingest/receiving: warn when seller (trading_partner_id) or
 * sold-to (ship_to_partner_id) owning-party sites lack valid licenses for the
 * tenant receiving state. Does not block.
 *
 * Judges what the outbound gate would judge, by the same rule: the site the document
 * names, when it names one, and otherwise the party as a whole, where one ready address
 * clears it. An address that would stop a shipment we author is the same address that
 * warns on a shipment we receive, so the two gates never disagree about a partner.
 */
final class RecordAtpSoftWarning
{
    public const EXCEPTION_TYPE = 'atp_soft_warning';

    public function handle(EpcisDocument $document): ?EpcisException
    {
        if (! config('tracepharma.epcis.enforce_atp_soft_gate', true)) {
            return null;
        }

        $alreadyOpen = EpcisException::query()
            ->where('document_id', $document->getKey())
            ->where('exception_type', self::EXCEPTION_TYPE)
            ->where('status', 'open')
            ->exists();

        if ($alreadyOpen) {
            return null;
        }

        $sellerId = $document->trading_partner_id !== null ? (int) $document->trading_partner_id : null;
        $shipToPartnerId = $document->ship_to_partner_id !== null ? (int) $document->ship_to_partner_id : null;

        if ($sellerId === null && $shipToPartnerId === null) {
            return null;
        }

        // Every party reads as NeedsReceivingState without one, which is not evidence of a
        // license — surface the gap instead of passing the document silently.
        if (TenantReceivingState::resolve() === null) {
            return $this->open(
                $document,
                'ATP licenses were not evaluated for this document — the organization receiving state is not set.',
            );
        }

        $failedParties = [];

        if ($sellerId !== null && $this->partyHasAtpIssue($sellerId, $document->ship_from_site_id)) {
            $failedParties[] = 'seller (source owning party)';
        }

        if (
            $shipToPartnerId !== null
            && $shipToPartnerId !== $sellerId
            && $this->partyHasAtpIssue($shipToPartnerId, $document->ship_to_site_id)
        ) {
            $failedParties[] = 'sold-to (destination owning partner)';
        }

        if ($failedParties === []) {
            return null;
        }

        return $this->open($document, $this->buildDescription($failedParties));
    }

    /**
     * The document's own facility when it names one and it still belongs to the party,
     * otherwise every active address the party has on record — and when it is the latter,
     * one ready address clears the party, exactly as the outbound gate treats a shipment
     * with no named destination.
     */
    private function partyHasAtpIssue(int $tradingPartnerId, mixed $knownSiteId): bool
    {
        return AtpReadinessGate::blocksParty(
            $tradingPartnerId,
            $knownSiteId !== null ? (int) $knownSiteId : null,
        );
    }

    private function open(EpcisDocument $document, string $description): EpcisException
    {
        return EpcisException::query()->create([
            'document_id' => $document->getKey(),
            'exception_type' => self::EXCEPTION_TYPE,
            'severity' => 'warning',
            'description' => $description,
            'status' => 'open',
        ]);
    }

    /**
     * @param  list<string>  $failedParties
     */
    private function buildDescription(array $failedParties): string
    {
        $subject = count($failedParties) === 1
            ? ucfirst($failedParties[0]).' sites'
            : implode(' and ', $failedParties).' sites';

        return $subject.' have expired, missing, or undated ATP licenses on record for the tenant receiving state. '.AtpDisclosure::SHORT;
    }
}
