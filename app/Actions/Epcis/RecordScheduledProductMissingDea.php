<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Fda\DeaRegistration;
use App\Support\Fda\ScheduledProductPresence;

/**
 * Soft warning when inbound document EPCs include DEA-scheduled product and the
 * seller trading partner lacks a DEA registration on the partner or any site.
 */
final class RecordScheduledProductMissingDea
{
    public const EXCEPTION_TYPE = 'SCHEDULED_PRODUCT_MISSING_DEA';

    public function __construct(
        private readonly RecordOperationalEpcisException $recorder,
    ) {}

    /**
     * @return list<EpcisException>
     */
    public function handle(EpcisDocument $document): array
    {
        if ((string) ($document->direction ?? '') !== 'inbound') {
            return [];
        }

        $this->clearOpenSignals($document);

        $gtins = $this->distinctDocumentGtin14s($document);
        if ($gtins === []) {
            return [];
        }

        $presence = ScheduledProductPresence::forGtins($gtins);
        if (! $presence['has_scheduled']) {
            return [];
        }

        $sellerId = $document->trading_partner_id !== null ? (int) $document->trading_partner_id : null;
        if ($this->sellerHasDea($sellerId)) {
            return [];
        }

        $schedule = $presence['highest'] ?? 'scheduled';

        return [
            $this->recorder->handle(
                $document,
                self::EXCEPTION_TYPE,
                sprintf(
                    'This shipment includes DEA schedule %s product but the seller has no DEA registration on file. Add the seller DEA on the trading partner or site, then reprocess.',
                    $schedule,
                ),
            ),
        ];
    }

    private function clearOpenSignals(EpcisDocument $document): void
    {
        EpcisException::query()
            ->where('document_id', $document->getKey())
            ->where('exception_type', self::EXCEPTION_TYPE)
            ->where('status', 'open')
            ->delete();
    }

    /**
     * @return list<string>
     */
    private function distinctDocumentGtin14s(EpcisDocument $document): array
    {
        return $document->epcsQuery()
            ->whereNotNull('gtin14')
            ->where('gtin14', '!=', '')
            ->distinct()
            ->pluck('gtin14')
            ->map(fn ($gtin): string => (string) $gtin)
            ->values()
            ->all();
    }

    private function sellerHasDea(?int $tradingPartnerId): bool
    {
        if ($tradingPartnerId === null) {
            return false;
        }

        $partner = TradingPartner::query()->find($tradingPartnerId);
        if ($partner === null) {
            return false;
        }

        if (DeaRegistration::normalize($partner->dea_number) !== null) {
            return true;
        }

        return Site::query()
            ->where('trading_partner_id', $tradingPartnerId)
            ->whereNotNull('dea_number')
            ->where('dea_number', '!=', '')
            ->get(['dea_number'])
            ->contains(fn (Site $site): bool => DeaRegistration::normalize($site->dea_number) !== null);
    }
}
