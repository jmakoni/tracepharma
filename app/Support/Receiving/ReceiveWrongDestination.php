<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EventParty;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Support\Gs1\Sgln;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound destinationList / ship-to SGLN versus this receive site.
 */
final class ReceiveWrongDestination
{
    /**
     * @return array{file: string, site: string}|null
     */
    public function mismatch(ReceivingSession $session, ?int $preferredDocumentId = null): ?array
    {
        $documentId = $preferredDocumentId
            ?? $session->epcis_document_id
            ?? $session->matched_epcis_document_id;
        if ($documentId === null || $session->site_id === null) {
            return null;
        }

        $document = EpcisDocument::query()->find($documentId);
        if ($document === null || (string) ($document->direction ?? '') !== 'inbound') {
            return null;
        }

        $fileGln = $this->destinationListGln($document);
        $siteGln = Sgln::normalizeGln(Site::query()->whereKey($session->site_id)->value('gln'));

        if ($fileGln === null || $siteGln === null || $fileGln === $siteGln) {
            return null;
        }

        return [
            'file' => $fileGln,
            'site' => $siteGln,
        ];
    }

    private function destinationListGln(EpcisDocument $document): ?string
    {
        if (! Schema::hasTable('event_parties') || ! Schema::hasTable('epcis_events')) {
            return null;
        }

        $parties = EventParty::query()
            ->where('party_role', 'destination')
            ->whereIn(
                'event_id',
                $document->events()->select('id'),
            )
            ->get();

        $location = null;
        $owning = null;
        foreach ($parties as $party) {
            $gln = Sgln::normalizeGln($party->gln);
            if ($gln === null) {
                continue;
            }

            $type = strtolower((string) (($party->extra_json ?? [])['source_dest_type'] ?? ''));
            if ($type === 'location') {
                $location ??= $gln;
            } elseif ($type === 'owning_party') {
                $owning ??= $gln;
            } else {
                $location ??= $gln;
            }
        }

        return $location ?? $owning;
    }
}
