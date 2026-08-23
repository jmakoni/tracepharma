<?php

namespace App\Support\Recalls;

use App\Enums\TracingRequestStatus;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\TracingRequest;
use App\Support\Custody\ResolveEpcLastKnownGln;

/**
 * Hard-flag for Scan In / Scan Out only. Current Receive and Ship Order stay unflagged.
 */
final class OpenRecallFlag
{
    public function __construct(
        private readonly ResolveEpcLastKnownGln $lastKnownGln = new ResolveEpcLastKnownGln,
    ) {}

    public function blocks(Epc $epc): ?string
    {
        $meta = $this->lastKnownGln->latestEventMeta($epc);
        $disposition = is_array($meta) ? ($meta['disposition'] ?? null) : null;
        if ($this->isRecalledDisposition(is_string($disposition) ? $disposition : null)) {
            return 'This unit is recalled — do not receive or ship.';
        }

        if ($this->matchingRecall($epc) !== null) {
            return 'Open recall for this product — do not receive or ship.';
        }

        return null;
    }

    public function matchingRecall(Epc $epc): ?TracingRequest
    {
        [$gtin, $serial, $lot] = $this->productIdentity($epc);
        if ($gtin === null && $serial === null && $lot === null) {
            return null;
        }

        return TracingRequest::query()
            ->where('is_recall', true)
            ->whereIn('status', [TracingRequestStatus::Open, TracingRequestStatus::InProgress])
            ->where(function ($query) use ($gtin, $serial, $lot): void {
                if ($gtin !== null && $serial !== null) {
                    $query->orWhere(function ($serialQuery) use ($gtin, $serial): void {
                        $serialQuery->where('gtin', $gtin)->where('serial', $serial);
                    });
                }

                if ($gtin !== null && $lot !== null) {
                    $query->orWhere(function ($lotQuery) use ($gtin, $lot): void {
                        $lotQuery->where('gtin', $gtin)->where('lot', $lot);
                    });
                }

                if ($gtin !== null) {
                    $query->orWhere(function ($gtinOnly) use ($gtin): void {
                        $gtinOnly->where('gtin', $gtin)
                            ->where(fn ($empty) => $empty->whereNull('serial')->orWhere('serial', ''))
                            ->where(fn ($empty) => $empty->whereNull('lot')->orWhere('lot', ''));
                    });
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function productIdentity(Epc $epc): array
    {
        $gtin = filled($epc->gtin14) ? (string) $epc->gtin14 : null;
        $isSscc = $epc->epc_type === 'sscc'
            || str_starts_with((string) $epc->epc_uri, 'urn:epc:id:sscc:');
        $serial = (! $isSscc && filled($epc->serial_number)) ? (string) $epc->serial_number : null;

        if ($epc->relationLoaded('ilmd')) {
            $lotValue = $epc->ilmd?->lot_number;
        } else {
            $lotValue = EpcIlmd::query()->where('epc_id', $epc->getKey())->value('lot_number');
        }
        $lot = filled($lotValue) ? (string) $lotValue : null;

        return [$gtin, $serial, $lot];
    }

    private function isRecalledDisposition(?string $disposition): bool
    {
        if ($disposition === null) {
            return false;
        }

        $value = strtolower(trim($disposition));
        if (str_contains($value, ':')) {
            $value = (string) str($value)->afterLast(':');
        }

        return $value === 'recalled';
    }
}
