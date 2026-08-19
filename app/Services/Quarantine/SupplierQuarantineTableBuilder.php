<?php

namespace App\Services\Quarantine;

use App\Enums\ExceptionStatus;
use App\Models\Epcis\Epc;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Product;
use App\Models\Quarantine\QuarantineHold;
use App\Support\Catalog\DisplayName;
use App\Support\Epcis\ShipmentReference;
use App\Support\Exceptions\AssortmentFromCatalog;
use App\Support\Gs1\Gtin;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class SupplierQuarantineTableBuilder
{
    /** @var array<string, ?FdaProductPackaging> */
    private array $packagingByGtinCache = [];

    public function __construct(
        private readonly DocumentAffectedCaseAggregator $documentCases,
    ) {}

    public function isDocumentScoped(ExceptionCase $case): bool
    {
        return $case->isDocumentScoped();
    }

    /**
     * @return Collection<int, array{
     *     po: string,
     *     ndc: string,
     *     product_name: string,
     *     gtin: string,
     *     serial: string,
     *     lot: string,
     *     exp: string,
     *     quantity: int,
     *     status: string,
     *     date_quarantined: ?string,
     *     date_resolved: ?string,
     *     days_held: int,
     * }>
     */
    public function identifierRows(ExceptionCase $case): Collection
    {
        $case->loadMissing([
            'document:id,customer_po,asn_number,ingest_generation',
            'quarantineHolds.epc.product:id,name,ndc,package_ndc,ndc11',
            'quarantineHolds.epc.ilmd',
            'quarantineHolds.document:id,customer_po,asn_number',
            'epcs.product:id,name,ndc,package_ndc,ndc11',
            'epcs.ilmd',
        ]);

        if ($case->isDocumentScoped() && $case->document !== null) {
            return $this->rowsFromDocument($case);
        }

        $holds = $case->quarantineHolds;
        if ($holds->isNotEmpty()) {
            return $holds
                ->sortBy(fn (QuarantineHold $hold): string => (string) ($hold->epc?->serial_number ?? $hold->epc_id))
                ->values()
                ->map(fn (QuarantineHold $hold): array => $this->rowFromHold($case, $hold));
        }

        return $case->epcs
            ->sortBy(fn (Epc $epc): string => (string) ($epc->serial_number ?? $epc->getKey()))
            ->values()
            ->map(fn (Epc $epc): array => $this->rowFromEpc($case, $epc));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $identifierRows
     * @return Collection<int, array{
     *     po: string,
     *     days_held: int,
     *     ndc: string,
     *     product_name: string,
     *     quantity: int,
     * }>
     */
    public function summaryRows(Collection $identifierRows): Collection
    {
        return $identifierRows
            ->groupBy(fn (array $row): string => (string) ($row['ndc'] ?? '—'))
            ->map(function (Collection $group): array {
                $first = $group->first();
                $names = $group->pluck('product_name')->filter(fn ($n) => filled($n) && $n !== '—')->unique()->values();
                $pos = $group->pluck('po')->filter(fn ($p) => filled($p) && $p !== '—')->unique()->values();

                return [
                    'po' => $pos->isNotEmpty() ? $pos->implode(', ') : ($first['po'] ?? '—'),
                    'days_held' => (int) $group->max('days_held'),
                    'ndc' => $first['ndc'],
                    'product_name' => $names->isNotEmpty() ? $names->implode(' / ') : ($first['product_name'] ?? '—'),
                    'quantity' => (int) $group->sum('quantity'),
                ];
            })
            ->sortBy([
                ['ndc', 'asc'],
                ['product_name', 'asc'],
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     po: string,
     *     ndc: string,
     *     product_name: string,
     *     gtin: string,
     *     serial: string,
     *     lot: string,
     *     exp: string,
     *     quantity: int,
     *     status: string,
     *     date_quarantined: ?string,
     *     date_resolved: ?string,
     *     days_held: int,
     * }>
     */
    private function rowsFromDocument(ExceptionCase $case): Collection
    {
        $document = $case->document;
        $po = $this->resolveShipmentPo($document);
        $status = $this->exceptionStatusLabel($case);
        $isOpen = $case->status?->isOpen() === true;
        $daysHeld = $this->daysHeld($case->created_at, $case->resolved_at ?? $case->closed_at, $isOpen);
        $dateQuarantined = $case->created_at?->toDayDateTimeString();
        $dateResolved = $isOpen
            ? null
            : ($case->resolved_at ?? $case->closed_at)?->toDayDateTimeString();

        return $this->documentCases
            ->aggregate($document)
            ->map(function (object $row) use ($po, $status, $daysHeld, $dateQuarantined, $dateResolved): array {
                $ndc = $this->firstFilled($row->package_ndc ?? null, $row->ndc11 ?? null, $row->ndc ?? null);
                $name = $this->usableName($row->product_name ?? null);
                $gtin = filled($row->gtin14 ?? null) ? (string) $row->gtin14 : null;

                if ($ndc === null || $name === null) {
                    $packaging = $this->resolvePackagingByGtin($gtin);
                    if ($packaging !== null) {
                        $ndc ??= $this->ndcForPackaging($packaging);
                        $name ??= $this->usableName($this->packagingName($packaging));
                    }
                }

                $exp = null;
                if (filled($row->expiry_date ?? null)) {
                    $exp = substr((string) $row->expiry_date, 0, 10);
                }

                return [
                    'po' => $po,
                    'ndc' => $this->display($ndc),
                    'product_name' => $this->display($name),
                    'gtin' => $this->display($gtin),
                    'serial' => $this->display($row->serial_number ?? null),
                    'lot' => $this->display($row->lot_number ?? null),
                    'exp' => $this->display($exp),
                    'quantity' => (int) ($row->child_count ?? 0),
                    'status' => $status,
                    'date_quarantined' => $dateQuarantined,
                    'date_resolved' => $dateResolved,
                    'days_held' => $daysHeld,
                ];
            })
            ->values();
    }

    /**
     * @return array{
     *     po: string,
     *     ndc: string,
     *     product_name: string,
     *     gtin: string,
     *     serial: string,
     *     lot: string,
     *     exp: string,
     *     quantity: int,
     *     status: string,
     *     date_quarantined: ?string,
     *     date_resolved: ?string,
     *     days_held: int,
     * }
     */
    private function rowFromHold(ExceptionCase $case, QuarantineHold $hold): array
    {
        $epc = $hold->epc;
        $po = $this->resolveShipmentPo($hold->document ?? $case->document);

        $openedAt = $hold->opened_at;
        $closedAt = $hold->closed_at;

        return array_merge($this->epcColumns($epc), [
            'po' => $po,
            'quantity' => 1,
            'status' => $this->exceptionStatusLabel($case),
            'date_quarantined' => $openedAt?->toDayDateTimeString(),
            'date_resolved' => $closedAt?->toDayDateTimeString(),
            'days_held' => $this->daysHeld($openedAt, $closedAt, $hold->status === 'open'),
        ]);
    }

    /**
     * @return array{
     *     po: string,
     *     ndc: string,
     *     product_name: string,
     *     gtin: string,
     *     serial: string,
     *     lot: string,
     *     exp: string,
     *     quantity: int,
     *     status: string,
     *     date_quarantined: ?string,
     *     date_resolved: ?string,
     *     days_held: int,
     * }
     */
    private function rowFromEpc(ExceptionCase $case, Epc $epc): array
    {
        return array_merge($this->epcColumns($epc), [
            'po' => $this->resolveShipmentPo($case->document),
            'quantity' => 1,
            'status' => $this->exceptionStatusLabel($case),
            'date_quarantined' => null,
            'date_resolved' => null,
            'days_held' => 0,
        ]);
    }

    private function exceptionStatusLabel(ExceptionCase $case): string
    {
        $status = $case->status;

        return $status instanceof ExceptionStatus
            ? $status->label()
            : $this->display(is_string($status) ? $status : null);
    }

    /**
     * @return array{
     *     ndc: string,
     *     product_name: string,
     *     gtin: string,
     *     serial: string,
     *     lot: string,
     *     exp: string,
     * }
     */
    private function epcColumns(?Epc $epc): array
    {
        $product = $epc?->product;
        $ndc = $this->ndcForProduct($product);
        $name = $this->usableName($product?->name);

        if ($epc !== null && ($ndc === null || $name === null)) {
            $packaging = $this->resolvePackagingForEpc($epc);
            if ($packaging !== null) {
                $ndc ??= $this->ndcForPackaging($packaging);
                $name ??= $this->usableName($this->packagingName($packaging));
            }
        }

        return [
            'ndc' => $this->display($ndc),
            'product_name' => $this->display($name),
            'gtin' => $this->display($epc?->gtin14 ?: $epc?->sscc18),
            'serial' => $this->display($epc?->serial_number),
            'lot' => $this->display($epc?->ilmd?->lot_number),
            'exp' => $epc?->ilmd?->expiry_date?->format('Y-m-d') ?? '—',
        ];
    }

    private function resolvePackagingForEpc(Epc $epc): ?FdaProductPackaging
    {
        $candidates = [];
        if (filled($epc->gtin14)) {
            $candidates[] = (string) $epc->gtin14;
        }

        if (filled($epc->company_prefix) && filled($epc->item_reference)) {
            $body13 = '0'.$epc->company_prefix.$epc->item_reference;
            if (strlen($body13) === 13 && ctype_digit($body13)) {
                $candidates[] = $body13.Gtin::checkDigit($body13);
            }
        }

        return $this->firstPackagingHit($candidates);
    }

    private function resolvePackagingByGtin(?string $gtin14): ?FdaProductPackaging
    {
        if (! filled($gtin14) || strlen($gtin14) !== 14 || ! ctype_digit($gtin14)) {
            return null;
        }

        $candidates = [$gtin14];
        $body13 = '0'.substr($gtin14, 1, 12);
        if (ctype_digit($body13)) {
            $candidates[] = $body13.Gtin::checkDigit($body13);
        }

        return $this->firstPackagingHit($candidates);
    }

    /**
     * @param  list<string>  $candidates
     */
    private function firstPackagingHit(array $candidates): ?FdaProductPackaging
    {
        foreach (array_unique($candidates) as $gtin) {
            if (! array_key_exists($gtin, $this->packagingByGtinCache)) {
                $this->packagingByGtinCache[$gtin] = AssortmentFromCatalog::findPackagingByGtin($gtin);
            }

            if ($this->packagingByGtinCache[$gtin] !== null) {
                return $this->packagingByGtinCache[$gtin];
            }
        }

        return null;
    }

    private function ndcForProduct(?Product $product): ?string
    {
        if ($product === null) {
            return null;
        }

        return $this->firstFilled($product->package_ndc, $product->ndc11, $product->ndc);
    }

    private function ndcForPackaging(FdaProductPackaging $packaging): ?string
    {
        return $this->firstFilled($packaging->package_ndc, $packaging->ndc11);
    }

    private function packagingName(FdaProductPackaging $packaging): ?string
    {
        $listing = $packaging->relationLoaded('product') ? $packaging->product : $packaging->product()->first();

        return DisplayName::clean($listing?->name ?: $listing?->brand_name ?: $listing?->generic_name);
    }

    private function usableName(?string $name): ?string
    {
        if (! filled($name)) {
            return null;
        }

        $trimmed = trim((string) $name);

        return strcasecmp($trimmed, 'N/A') === 0 ? null : $trimmed;
    }

    private function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function daysHeld(?CarbonInterface $openedAt, ?CarbonInterface $closedAt, bool $isOpen): int
    {
        if ($openedAt === null) {
            return 0;
        }

        $end = $isOpen ? now() : ($closedAt ?? now());

        return max(0, (int) $openedAt->diffInDays($end));
    }

    private function resolveShipmentPo(?EpcisDocument $document): string
    {
        return ShipmentReference::po($document);
    }

    private function display(?string $value): string
    {
        return filled($value) ? (string) $value : '—';
    }
}
