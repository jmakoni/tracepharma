<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TenantProfile;
use App\Models\BuyingGroupMemberMetric;
use App\Models\BuyingGroupMembership;
use App\Models\BuyingGroupPartnerFact;
use App\Models\Tenant;
use App\Support\BuyingGroup\BuyingGroupMemberMetricsCollector;
use App\Support\Tenancy\TenantRunner;
use App\Support\TenantSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collect member pharmacy aggregates and upsert snapshots into the BG tenant DB.
 * Never writes into the member tenant database.
 */
class BuyingGroupMemberRollupJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(
        public string $buyingGroupTenantId,
        public string $memberTenantId,
        public ?string $asOf = null,
    ) {}

    public function uniqueId(): string
    {
        $asOf = $this->asOf ?? now()->toDateString();

        return 'bg-rollup:'.$this->buyingGroupTenantId.':'.$this->memberTenantId.':'.$asOf;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(BuyingGroupMemberMetricsCollector $collector): void
    {
        $asOf = Carbon::parse($this->asOf ?? now()->toDateString())->toDateString();

        $buyingGroup = Tenant::query()->find($this->buyingGroupTenantId);
        $member = Tenant::query()->find($this->memberTenantId);

        if ($buyingGroup === null || $member === null) {
            Log::warning('Buying group rollup skipped: tenant missing', [
                'buying_group_tenant_id' => $this->buyingGroupTenantId,
                'member_tenant_id' => $this->memberTenantId,
            ]);

            return;
        }

        if ($buyingGroup->profile !== TenantProfile::BuyingGroup) {
            return;
        }

        if ($member->profile !== TenantProfile::Pharmacy) {
            return;
        }

        $membershipActive = BuyingGroupMembership::query()
            ->where('buying_group_tenant_id', $this->buyingGroupTenantId)
            ->where('member_tenant_id', $this->memberTenantId)
            ->active()
            ->exists();

        if (! $membershipActive) {
            Log::warning('Buying group rollup skipped: membership not active', [
                'buying_group_tenant_id' => $this->buyingGroupTenantId,
                'member_tenant_id' => $this->memberTenantId,
            ]);

            return;
        }

        $rollupsEnabled = TenantRunner::run(
            $buyingGroup,
            fn (): bool => TenantSettings::forTenant($buyingGroup)->buyingGroupMemberRollupsEnabled(),
        );

        if (! $rollupsEnabled) {
            return;
        }

        try {
            $snapshot = TenantRunner::run(
                $member,
                fn (): array => $collector->collect(),
            );
        } catch (Throwable $exception) {
            Log::error('Buying group rollup failed while reading member', [
                'buying_group_tenant_id' => $this->buyingGroupTenantId,
                'member_tenant_id' => $this->memberTenantId,
                'exception' => $exception,
            ]);

            throw $exception;
        }

        TenantRunner::run($buyingGroup, function () use ($snapshot, $asOf): void {
            $this->writeSnapshots($snapshot, $asOf);
        });
    }

    /**
     * @param  array{
     *     atp_gap_count: int,
     *     exceptions_open: int,
     *     exceptions_aging_7d: int,
     *     connection_unhealthy: bool,
     *     last_epcis_success_at: ?string,
     *     health_score: ?float,
     *     risk_score: ?float,
     *     partners: list<array{
     *         partner_key: string,
     *         partner_name: string,
     *         partner_gln: ?string,
     *         license_status: string,
     *         expires_at: ?string
     *     }>
     * }  $snapshot
     */
    private function writeSnapshots(array $snapshot, string $asOf): void
    {
        BuyingGroupMemberMetric::query()->updateOrCreate(
            [
                'member_tenant_id' => $this->memberTenantId,
                'as_of' => $asOf,
            ],
            [
                'atp_gap_count' => $snapshot['atp_gap_count'],
                'exceptions_open' => $snapshot['exceptions_open'],
                'exceptions_aging_7d' => $snapshot['exceptions_aging_7d'],
                'connection_unhealthy' => $snapshot['connection_unhealthy'],
                'last_epcis_success_at' => $snapshot['last_epcis_success_at'],
                'health_score' => $snapshot['health_score'],
                'risk_score' => $snapshot['risk_score'],
            ],
        );

        BuyingGroupPartnerFact::query()
            ->where('member_tenant_id', $this->memberTenantId)
            ->whereDate('as_of', $asOf)
            ->delete();

        $rows = [];
        $now = now();

        foreach ($snapshot['partners'] as $partner) {
            $rows[] = [
                'member_tenant_id' => $this->memberTenantId,
                'partner_key' => $partner['partner_key'],
                'partner_name' => $partner['partner_name'],
                'partner_gln' => $partner['partner_gln'],
                'license_status' => $partner['license_status'],
                'expires_at' => $partner['expires_at'],
                'as_of' => $asOf,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            BuyingGroupPartnerFact::query()->insert($chunk);
        }
    }
}
