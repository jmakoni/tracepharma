<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Models\Epcis\EpcisException;
use App\Models\TradingPartner;
use Illuminate\Support\Collection;

/**
 * Read-only partner ingest exception rollup (7d / 30d).
 *
 * Counts all EpcisException rows on inbound documents linked to a trading partner
 * within each window. Not clean-data certified / not TraceReady.
 */
final class PartnerIngestQualityMetrics
{
    /**
     * @return Collection<int, array{trading_partner_id: int, partner_name: string, exceptions_7d: int, exceptions_30d: int}>
     */
    public function rows(): Collection
    {
        $since30d = now()->subDays(30);
        $since7d = now()->subDays(7);

        $counts = EpcisException::query()
            ->selectRaw(
                'epcis_documents.trading_partner_id,
                 SUM(CASE WHEN epcis_exceptions.created_at >= ? THEN 1 ELSE 0 END) as exceptions_7d,
                 COUNT(*) as exceptions_30d',
                [$since7d],
            )
            ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_exceptions.document_id')
            ->where('epcis_documents.direction', 'inbound')
            ->whereNotNull('epcis_documents.trading_partner_id')
            ->where('epcis_exceptions.created_at', '>=', $since30d)
            ->groupBy('epcis_documents.trading_partner_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->trading_partner_id);

        if ($counts->isEmpty()) {
            return collect();
        }

        $partners = TradingPartner::query()
            ->whereIn('id', $counts->keys()->all())
            ->pluck('name', 'id');

        return $counts
            ->map(function (object $row) use ($partners): array {
                $partnerId = (int) $row->trading_partner_id;

                return [
                    'trading_partner_id' => $partnerId,
                    'partner_name' => (string) ($partners[$partnerId] ?? 'Unknown partner'),
                    'exceptions_7d' => (int) $row->exceptions_7d,
                    'exceptions_30d' => (int) $row->exceptions_30d,
                ];
            })
            ->sortByDesc('exceptions_30d')
            ->values();
    }
}
