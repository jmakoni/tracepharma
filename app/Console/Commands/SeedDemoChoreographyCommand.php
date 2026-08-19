<?php

namespace App\Console\Commands;

use App\Actions\Demo\SeedMasterData;
use App\Actions\Demo\SeedOperationalChoreography;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SeedDemoChoreographyCommand extends Command
{
    protected $signature = 'tracepharma:seed-demo-choreography
                            {--tenant= : Tenant id or primary demo domain (defaults to first DEMO_DOMAINS entry)}
                            {--receive-only : Seed completed receive only; leave Ship Order for a live demo click}
                            {--transfer : Also seed a second SSCC through receive→transfer→destination receive (primary ship path unchanged)}
                            {--unpack : Also seed a third SSCC through receive→unpack (primary ship path unchanged)}
                            {--pack : Also seed hierarchy receive→unpack→pack (implies --unpack)}
                            {--return : Also seed hierarchy receive→return EPCIS (uses child after unpack, else sealed SSCC)}';

    protected $description = 'Seed idempotent receive→ship demo choreography (custody + optional completed Ship Order + optional inter-site transfer + optional unpack/pack/return)';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();

        if ($tenant === null) {
            $this->error('Demo tenant not found. Run tracepharma:setup-demo first or pass --tenant=');

            return self::FAILURE;
        }

        $completeShip = ! (bool) $this->option('receive-only');
        $includeTransfer = (bool) $this->option('transfer');
        $includeUnpack = (bool) $this->option('unpack') || (bool) $this->option('pack');
        $includePack = (bool) $this->option('pack');
        $includeReturn = (bool) $this->option('return');

        $result = $tenant->run(function () use (
            $completeShip,
            $includeTransfer,
            $includeUnpack,
            $includePack,
            $includeReturn,
        ): array {
            app(SeedMasterData::class)->handle();

            return app(SeedOperationalChoreography::class)->handle(
                completeShip: $completeShip,
                includeTransfer: $includeTransfer,
                includeUnpack: $includeUnpack,
                includePack: $includePack,
                includeReturn: $includeReturn,
            );
        });

        $this->info('Demo choreography seeded for '.$tenant->name.'.');
        $this->line('Receive session #'.$result['receive_session_id']
            .($result['receive_created'] ? ' (created/updated)' : ' (already satisfied)'));

        if ($result['ship_session_id'] !== null) {
            $this->line('Ship order session #'.$result['ship_session_id']
                .($result['ship_created'] ? ' (created/updated)' : '')
                .($result['ship_completed'] ? ' — completed with EPCIS' : ' — open/in progress'));
        }

        if ($result['ship_deferred'] && filled($result['ship_deferred_reason'])) {
            $this->warn($result['ship_deferred_reason']);
        }

        if ($result['transfer_session_id'] !== null) {
            $this->line('Transfer session #'.$result['transfer_session_id']
                .($result['transfer_created'] ? ' (created/updated)' : '')
                .($result['transfer_completed'] ? ' — completed with EPCIS' : ' — in transit or open'));
        }

        if ($result['transfer_deferred'] && filled($result['transfer_deferred_reason'])) {
            $this->warn($result['transfer_deferred_reason']);
        }

        if ($result['hierarchy_receive_session_id'] !== null) {
            $this->line('Hierarchy receive session #'.$result['hierarchy_receive_session_id']
                .($result['hierarchy_receive_created'] ? ' (created/updated)' : ' (already satisfied)'));
        }

        if ($result['unpack_completed'] || $result['unpack_deferred']) {
            $this->line($result['unpack_completed']
                ? 'Unpack — completed with EPCIS'
                : 'Unpack — skipped');
        }

        if ($result['unpack_deferred'] && filled($result['unpack_deferred_reason'])) {
            $this->warn($result['unpack_deferred_reason']);
        }

        if ($result['pack_batch_id'] !== null) {
            $this->line('Pack batch #'.$result['pack_batch_id']
                .($result['pack_created'] ? ' (created/updated)' : '')
                .($result['pack_completed'] ? ' — commissioned with EPCIS' : ' — incomplete'));
        }

        if ($result['pack_deferred'] && filled($result['pack_deferred_reason'])) {
            $this->warn($result['pack_deferred_reason']);
        }

        if ($result['return_document_id'] !== null) {
            $this->line('Return document #'.$result['return_document_id']
                .($result['return_created'] ? ' (created/updated)' : '')
                .($result['return_completed'] ? ' — returning EPCIS authored' : ' — incomplete'));
        }

        if ($result['return_deferred'] && filled($result['return_deferred_reason'])) {
            $this->warn($result['return_deferred_reason']);
        }

        return self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        $selector = $this->option('tenant');

        if (filled($selector)) {
            $tenant = Tenant::query()->find($selector);

            if ($tenant !== null) {
                return $tenant;
            }

            return Tenant::query()
                ->whereHas('domains', fn ($query) => $query->where('domain', $selector))
                ->first();
        }

        $domains = config('tracepharma.demo_domains', []);

        if ($domains === []) {
            return null;
        }

        return Tenant::query()
            ->whereHas('domains', fn ($query) => $query->where('domain', $domains[0]))
            ->first();
    }
}
