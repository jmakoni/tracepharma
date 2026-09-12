<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Epcis\Epc;
use App\Models\Principal;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Best-effort stamp of epcs.principal_id from unambiguous receive/ship session tags.
 * Report-only by default; pass --apply to write.
 */
final class BackfillEpcPrincipalOwnership extends Command
{
    protected $signature = 'tracepharma:backfill-epc-principals
                            {--tenants=* : Tenant ids (default: all)}
                            {--apply : Persist stamps (default is dry-run)}';

    protected $description = 'Stamp EPC principal_id from unambiguous receive/ship session principals';

    public function handle(): int
    {
        $tenantIds = $this->option('tenants');
        $apply = (bool) $this->option('apply');

        $tenants = Tenant::query()
            ->when($tenantIds !== [], fn ($q) => $q->whereIn('id', $tenantIds))
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants matched.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->line('Tenant '.$tenant->getTenantKey().' ('.$tenant->name.')');

            tenancy()->initialize($tenant);

            try {
                $this->backfillTenant($apply);
            } finally {
                tenancy()->end();
            }
        }

        return self::SUCCESS;
    }

    private function backfillTenant(bool $apply): void
    {
        if (! Principal::query()->exists()) {
            $this->line('  skip — no principals');

            return;
        }

        $fromReceive = $this->unambiguousReceiveStamps();
        $fromShip = $this->unambiguousShipStamps();

        $merged = [];
        foreach ([$fromReceive, $fromShip] as $batch) {
            foreach ($batch as $epcId => $principalId) {
                if (isset($merged[$epcId]) && $merged[$epcId] !== $principalId) {
                    unset($merged[$epcId]);

                    continue;
                }
                $merged[$epcId] = $principalId;
            }
        }

        $already = Epc::query()
            ->whereNotNull('principal_id')
            ->pluck('principal_id', 'id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $toStamp = [];
        $conflicts = 0;
        foreach ($merged as $epcId => $principalId) {
            if (isset($already[$epcId]) && $already[$epcId] !== $principalId) {
                $conflicts++;

                continue;
            }
            if (isset($already[$epcId])) {
                continue;
            }
            $toStamp[$epcId] = $principalId;
        }

        $unstamped = Epc::query()->whereNull('principal_id')->count();

        $this->line('  candidates: '.count($toStamp).'; conflicts with existing: '.$conflicts.'; still null after: '.max(0, $unstamped - count($toStamp)));

        if (! $apply || $toStamp === []) {
            return;
        }

        DB::transaction(function () use ($toStamp): void {
            foreach (collect($toStamp)->groupBy(fn (int $principalId): int => $principalId) as $principalId => $epcIds) {
                Epc::query()
                    ->whereIn('id', $epcIds->keys()->all())
                    ->whereNull('principal_id')
                    ->update(['principal_id' => (int) $principalId]);
            }
        });

        $this->info('  applied '.count($toStamp).' stamps');
    }

    /**
     * @return array<int, int> epc_id => principal_id
     */
    private function unambiguousReceiveStamps(): array
    {
        $rows = ReceivingScanLine::query()
            ->select([
                'receiving_scan_lines.epc_id',
                'receiving_sessions.principal_id',
            ])
            ->join('receiving_sessions', 'receiving_sessions.id', '=', 'receiving_scan_lines.receiving_session_id')
            ->where('receiving_scan_lines.status', 'confirmed')
            ->where('receiving_sessions.status', 'completed')
            ->whereNotNull('receiving_sessions.principal_id')
            ->whereNotNull('receiving_scan_lines.epc_id')
            ->get();

        return $this->collapseUnambiguous($rows);
    }

    /**
     * @return array<int, int> epc_id => principal_id
     */
    private function unambiguousShipStamps(): array
    {
        $rows = OutboundShippingScanLine::query()
            ->select([
                'outbound_shipping_scan_lines.epc_id',
                'outbound_shipping_sessions.principal_id',
            ])
            ->join(
                'outbound_shipping_sessions',
                'outbound_shipping_sessions.id',
                '=',
                'outbound_shipping_scan_lines.outbound_shipping_session_id',
            )
            ->where('outbound_shipping_scan_lines.status', 'confirmed')
            ->where('outbound_shipping_sessions.status', 'completed')
            ->whereNotNull('outbound_shipping_sessions.principal_id')
            ->whereNotNull('outbound_shipping_scan_lines.epc_id')
            ->get();

        return $this->collapseUnambiguous($rows);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, int>
     */
    private function collapseUnambiguous($rows): array
    {
        /** @var array<int, int> $out */
        $out = [];
        /** @var array<int, true> $ambiguous */
        $ambiguous = [];

        foreach ($rows as $row) {
            $epcId = (int) $row->epc_id;
            $principalId = (int) $row->principal_id;

            if ($epcId <= 0 || $principalId <= 0 || isset($ambiguous[$epcId])) {
                continue;
            }

            if (isset($out[$epcId]) && $out[$epcId] !== $principalId) {
                unset($out[$epcId]);
                $ambiguous[$epcId] = true;

                continue;
            }

            $out[$epcId] = $principalId;
        }

        return $out;
    }
}
