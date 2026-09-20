<?php

namespace App\Support\Shipping;

use App\Models\Epcis\Epc;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Support\Tracing\Gs1DualDisplay;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * CSV / audit-pack downloads for the On-hand module.
 */
final class OnHandExport
{
    public function __construct(
        private readonly OnHandLotRollup $lotRollup,
        private readonly ShippableEpcsAtSite $shippable,
    ) {}

    public function streamLotsCsv(int $siteId, ?int $principalId, string $siteName): StreamedResponse
    {
        $rows = $this->lotRollup->rows($siteId, $principalId);
        $filename = 'on-hand-lots-'.str($siteName)->slug()->toString().'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['gtin14', 'lot_number', 'total', 'pickable', 'hold_count', 'sgtin_count', 'sscc_count', 'min_expiry', 'max_expiry', 'has_quarantine', 'has_near_expiry']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['gtin14'] === OnHandLotRollup::SSCC_PRODUCT_KEY ? 'Containers' : $row['gtin14'],
                    $row['lot_number'],
                    $row['total'],
                    $row['pickable'],
                    $row['hold_count'],
                    $row['sgtin_count'],
                    $row['sscc_count'],
                    $row['min_expiry'] ?? '',
                    $row['max_expiry'] ?? '',
                    $row['has_quarantine'] ? 'yes' : 'no',
                    $row['has_near_expiry'] ? 'yes' : 'no',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function streamSerialsCsv(int $siteId, ?int $principalId, string $siteName, ?string $gtin = null, ?string $lot = null): StreamedResponse
    {
        $epcs = $this->serialsQuery($siteId, $principalId, $gtin, $lot)->with('ilmd')->orderBy('epcs.id')->limit(5000)->get();
        $filename = 'on-hand-serials-'.str($siteName)->slug()->toString().'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($epcs): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['identifier', 'epc_type', 'gtin14', 'lot_number', 'expiry_date', 'epc_uri']);
            foreach ($epcs as $epc) {
                /** @var Epc $epc */
                fputcsv($out, [
                    Gs1DualDisplay::forEpc($epc)['primary'],
                    $epc->epc_type,
                    $epc->gtin14 ?? '',
                    $epc->ilmd?->lot_number ?? '',
                    $epc->ilmd?->expiry_date?->toDateString() ?? '',
                    $epc->epc_uri,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function streamAuditPack(int $siteId, ?int $principalId, string $siteName): StreamedResponse
    {
        $site = Site::query()->find($siteId);
        $filename = 'on-hand-audit-'.str($siteName)->slug()->toString().'-'.now()->format('Ymd-His').'.zip';

        return response()->streamDownload(function () use ($siteId, $principalId, $siteName, $site): void {
            $tmp = tempnam(sys_get_temp_dir(), 'onhand_audit_');
            if ($tmp === false) {
                return;
            }

            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                @unlink($tmp);

                return;
            }

            $meta = json_encode([
                'generated_at' => now()->toIso8601String(),
                'site_id' => $siteId,
                'site_name' => $siteName,
                'site_gln' => $site?->gln,
                'principal_id' => $principalId,
                'note' => 'Last-seen custody snapshot. Not a WMS inventory balance.',
            ], JSON_PRETTY_PRINT);
            $zip->addFromString('meta.json', $meta === false ? '{}' : $meta);

            $lotsCsv = $this->buildLotsCsvString($siteId, $principalId);
            $zip->addFromString('lots.csv', $lotsCsv);

            $serialsCsv = $this->buildSerialsCsvString($siteId, $principalId);
            $zip->addFromString('serials.csv', $serialsCsv);

            $holdsCsv = $this->buildHoldsCsvString($siteId, $principalId);
            $zip->addFromString('holds.csv', $holdsCsv);

            $zip->close();
            readfile($tmp);
            @unlink($tmp);
        }, $filename, ['Content-Type' => 'application/zip']);
    }

    /**
     * @return Builder<Epc>
     */
    public function serialsQuery(
        int $siteId,
        ?int $principalId,
        ?string $gtin = null,
        ?string $lot = null,
        bool $parentsOnly = false,
        bool $excludeOpenHolds = false,
    ) {
        $query = $this->shippable->query($siteId);
        if ($principalId !== null && $principalId > 0) {
            $query->where('epcs.principal_id', $principalId);
        }

        if ($gtin === OnHandLotRollup::SSCC_PRODUCT_KEY) {
            $query->where('epcs.epc_type', 'sscc')
                ->where(function ($q): void {
                    $q->whereNull('epcs.gtin14')->orWhere('epcs.gtin14', '');
                });
        } elseif (filled($gtin)) {
            $query->where('epcs.gtin14', $gtin);
        }

        if ($lot !== null) {
            if ($lot === '') {
                $query->where(function ($q): void {
                    $q->whereDoesntHave('ilmd')
                        ->orWhereHas('ilmd', fn ($ilmd) => $ilmd->whereNull('lot_number')->orWhere('lot_number', ''));
                });
            } else {
                $query->whereHas('ilmd', fn ($ilmd) => $ilmd->where('lot_number', $lot));
            }
        }

        if ($parentsOnly) {
            $query->whereNotExists(function ($exists): void {
                $exists->selectRaw('1')
                    ->from('aggregation_links')
                    ->whereColumn('aggregation_links.child_epc_id', 'epcs.id')
                    ->whereNull('aggregation_links.valid_to');
            });
        }

        if ($excludeOpenHolds) {
            $query->whereNotExists(function ($exists): void {
                $exists->selectRaw('1')
                    ->from('quarantine_holds')
                    ->whereColumn('quarantine_holds.epc_id', 'epcs.id')
                    ->where('quarantine_holds.status', 'open');
            });
        }

        return $query;
    }

    private function buildLotsCsvString(int $siteId, ?int $principalId): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }
        fputcsv($fh, ['gtin14', 'lot_number', 'total', 'pickable', 'hold_count', 'sgtin_count', 'sscc_count', 'min_expiry', 'max_expiry']);
        foreach ($this->lotRollup->rows($siteId, $principalId) as $row) {
            fputcsv($fh, [
                $row['gtin14'] === OnHandLotRollup::SSCC_PRODUCT_KEY ? 'Containers' : $row['gtin14'],
                $row['lot_number'],
                $row['total'],
                $row['pickable'],
                $row['hold_count'],
                $row['sgtin_count'],
                $row['sscc_count'],
                $row['min_expiry'] ?? '',
                $row['max_expiry'] ?? '',
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $csv;
    }

    private function buildSerialsCsvString(int $siteId, ?int $principalId): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }
        fputcsv($fh, ['identifier', 'epc_type', 'gtin14', 'lot_number', 'expiry_date']);
        $epcs = $this->serialsQuery($siteId, $principalId)->with('ilmd')->orderBy('epcs.id')->limit(5000)->get();
        foreach ($epcs as $epc) {
            fputcsv($fh, [
                Gs1DualDisplay::forEpc($epc)['primary'],
                $epc->epc_type,
                $epc->gtin14 ?? '',
                $epc->ilmd?->lot_number ?? '',
                $epc->ilmd?->expiry_date?->toDateString() ?? '',
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $csv;
    }

    private function buildHoldsCsvString(int $siteId, ?int $principalId): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }
        fputcsv($fh, ['epc_id', 'identifier', 'reason', 'opened_at']);
        $onHandIds = $this->serialsQuery($siteId, $principalId)->select('epcs.id');
        $holds = QuarantineHold::query()
            ->open()
            ->whereIn('epc_id', $onHandIds)
            ->with('epc')
            ->orderBy('id')
            ->limit(2000)
            ->get();
        foreach ($holds as $hold) {
            $epc = $hold->epc;
            fputcsv($fh, [
                $hold->epc_id,
                $epc instanceof Epc ? Gs1DualDisplay::forEpc($epc)['primary'] : '',
                $hold->reason,
                $hold->opened_at?->toIso8601String() ?? '',
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $csv;
    }
}
