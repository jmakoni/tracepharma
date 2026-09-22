<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EventEpcIlmd;
use App\Models\Receiving\ReceivingSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compare a scanned 2D identity to inbound EPCIS PI on the preferred inbound document only.
 */
final class CompareInboundDocumentPi
{
    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, array{scan: string, file: string}>|null
     */
    public function mismatch(
        ReceivingSession $session,
        Epc $epc,
        array $identity,
        ?int $preferredDocumentId = null,
    ): ?array {
        $documentId = $preferredDocumentId
            ?? $session->epcis_document_id
            ?? $session->matched_epcis_document_id;
        if ($documentId === null) {
            return null;
        }

        $document = EpcisDocument::query()->find($documentId);
        if ($document === null || (string) ($document->direction ?? '') !== 'inbound') {
            return null;
        }

        if (! $this->epcOnDocument((int) $document->getKey(), (int) $epc->getKey())) {
            return null;
        }

        $file = $this->filePi($document, $epc);
        $mismatch = [];

        $scanGtin = $this->digits((string) ($identity['gtin14'] ?? ''));
        $fileGtin = $this->digits((string) ($file['gtin'] ?? $epc->gtin14 ?? ''));
        if ($scanGtin !== '' && $fileGtin !== '' && $scanGtin !== $fileGtin) {
            $mismatch['gtin'] = ['scan' => $scanGtin, 'file' => $fileGtin];
        }

        $scanSerial = trim((string) ($identity['serial'] ?? $identity['serial_number'] ?? ''));
        $fileSerial = trim((string) ($epc->serial_number ?? ''));
        if ($scanSerial !== '' && $fileSerial !== '' && $scanSerial !== $fileSerial) {
            $mismatch['serial'] = ['scan' => $scanSerial, 'file' => $fileSerial];
        }

        $scanLot = trim((string) ($identity['lot_number'] ?? ''));
        $fileLot = trim((string) ($file['lot'] ?? ''));
        if ($scanLot !== '' && $fileLot !== '' && $scanLot !== $fileLot) {
            $mismatch['lot'] = ['scan' => $scanLot, 'file' => $fileLot];
        }

        $scanExpiry = $this->scanExpiry($identity);
        $fileExpiry = trim((string) ($file['expiry'] ?? ''));
        if ($scanExpiry !== null && $fileExpiry !== '' && $scanExpiry !== $fileExpiry) {
            $mismatch['expiry'] = ['scan' => $scanExpiry, 'file' => $fileExpiry];
        }

        return $mismatch === [] ? null : $mismatch;
    }

    /**
     * @return array{gtin: ?string, lot: ?string, expiry: ?string}
     */
    private function filePi(EpcisDocument $document, Epc $epc): array
    {
        $row = null;
        if (Schema::hasTable('event_epc_ilmd') && Schema::hasTable('epcis_events')) {
            $row = EventEpcIlmd::query()
                ->where('epc_id', $epc->getKey())
                ->whereHas('event', function ($events) use ($document): void {
                    $events->where('document_id', $document->getKey());
                    if (Schema::hasColumn('epcis_events', 'ingest_generation')
                        && Schema::hasColumn('epcis_documents', 'ingest_generation')
                    ) {
                        $events->where('ingest_generation', $document->ingest_generation);
                    }
                })
                ->orderByDesc('event_id')
                ->first();
        }

        $lot = $row?->lot_number;
        $expiry = $row?->expiry_date?->toDateString();

        if ($lot === null && $expiry === null && $epc->relationLoaded('ilmd') === false) {
            $epc->loadMissing('ilmd');
        }

        return [
            'gtin' => $epc->gtin14,
            'lot' => filled($lot) ? (string) $lot : ($epc->ilmd?->lot_number !== null ? (string) $epc->ilmd->lot_number : null),
            'expiry' => filled($expiry)
                ? $expiry
                : ($epc->ilmd?->expiry_date?->toDateString()),
        ];
    }

    private function epcOnDocument(int $documentId, int $epcId): bool
    {
        if (! Schema::hasTable('event_epcs') || ! Schema::hasTable('epcis_events')) {
            return false;
        }

        return DB::table('event_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->where('epcis_events.document_id', $documentId)
            ->where('event_epcs.epc_id', $epcId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function scanExpiry(array $identity): ?string
    {
        $yymmdd = trim((string) ($identity['expiry_yymmdd'] ?? ''));
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }

        $year = 2000 + (int) substr($yymmdd, 0, 2);
        $month = (int) substr($yymmdd, 2, 2);
        $day = (int) substr($yymmdd, 4, 2);
        if ($month < 1 || $month > 12) {
            return null;
        }

        try {
            if ($day === 0) {
                return Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
            }

            return Carbon::create($year, $month, $day)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
