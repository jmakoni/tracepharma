<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Verification;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Export recent verification rows as an honest VRS Verify readiness evidence pack.
 * This is internal go-live evidence — not Gateway Certified / TraceReady branding.
 */
class ExportVrsReadinessLogCommand extends Command
{
    private const CERTIFICATION_CLAIM = 'none — not Gateway Certified';

    private const HONESTY = 'Internal VRS Verify readiness log only. TracePharma does not claim Gateway Certified, TraceReady, or GS1 Trustmark certification.';

    protected $signature = 'vrs:export-readiness-log
                            {--tenant= : Limit to a single tenant id}
                            {--limit=100 : Max verification rows per tenant}
                            {--output= : Output JSON path (default storage/app/evidence/vrs-readiness.json)}';

    protected $description = 'Export recent verification rows as a VRS Verify readiness evidence JSON (not Gateway Certified)';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $limit = max(1, (int) $this->option('limit'));
        $output = (string) ($this->option('output') ?: storage_path('app/evidence/vrs-readiness.json'));

        $tenantsPayload = [];
        $totalRows = 0;
        $failed = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use ($limit, &$tenantsPayload, &$totalRows, &$failed): void {
            try {
                TenantRunner::run($tenant, function () use ($tenant, $limit, &$tenantsPayload, &$totalRows): void {
                    $rows = Verification::query()
                        ->orderByDesc('id')
                        ->limit($limit)
                        ->get([
                            'id',
                            'gtin14',
                            'serial',
                            'lot',
                            'status',
                            'message',
                            'verified_at',
                            'created_at',
                        ])
                        ->map(static function (Verification $verification): array {
                            return [
                                'id' => $verification->id,
                                'gtin14' => $verification->gtin14,
                                'serial' => $verification->serial,
                                'lot' => $verification->lot,
                                'status' => $verification->status,
                                'message' => $verification->message,
                                'verified_at' => optional($verification->verified_at)?->toIso8601String(),
                                'created_at' => optional($verification->created_at)?->toIso8601String(),
                            ];
                        })
                        ->all();

                    $totalRows += count($rows);

                    $tenantsPayload[] = [
                        'tenant_id' => $tenant->id,
                        'name' => $tenant->name,
                        'verifications' => $rows,
                    ];
                });
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        });

        $payload = [
            'certification_claim' => self::CERTIFICATION_CLAIM,
            'honesty' => self::HONESTY,
            'generated_at' => now()->toIso8601String(),
            'tenants' => $tenantsPayload,
        ];

        File::ensureDirectoryExists(dirname($output));
        File::put(
            $output,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        if ($totalRows === 0) {
            $this->warn('No verification rows exported (empty readiness pack).');
        }

        $this->info(sprintf(
            'Wrote VRS readiness log to %s (tenants=%d rows=%d). certification_claim=%s',
            $output,
            count($tenantsPayload),
            $totalRows,
            self::CERTIFICATION_CLAIM,
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
