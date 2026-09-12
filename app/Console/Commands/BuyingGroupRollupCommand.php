<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BuyingGroupMembershipStatus;
use App\Enums\TenantProfile;
use App\Jobs\BuyingGroupMemberRollupJob;
use App\Models\BuyingGroupMembership;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use App\Support\TenantSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BuyingGroupRollupCommand extends Command
{
    protected $signature = 'tracepharma:buying-group-rollup
                            {--buying-group= : Limit to one buying-group tenant id}
                            {--member= : Limit to one member tenant id}
                            {--as-of= : Snapshot date Y-m-d (default today UTC/app)}
                            {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Roll up member pharmacy ATP/exception/connection metrics into buying-group snapshot tables';

    public function handle(): int
    {
        $asOf = Carbon::parse($this->option('as-of') ?: now()->toDateString())->toDateString();
        $sync = (bool) $this->option('sync');
        $buyingGroupFilter = $this->option('buying-group');
        $memberFilter = $this->option('member');

        $dispatched = 0;
        $skipped = 0;

        BuyingGroupMembership::query()
            ->where('status', BuyingGroupMembershipStatus::Active)
            ->when(
                filled($buyingGroupFilter),
                fn ($q) => $q->where('buying_group_tenant_id', $buyingGroupFilter),
            )
            ->when(
                filled($memberFilter),
                fn ($q) => $q->where('member_tenant_id', $memberFilter),
            )
            ->orderBy('id')
            ->chunkById(50, function ($memberships) use ($asOf, $sync, &$dispatched, &$skipped): void {
                $bgIds = $memberships->pluck('buying_group_tenant_id')->unique()->values()->all();
                $memberIds = $memberships->pluck('member_tenant_id')->unique()->values()->all();

                $buyingGroups = Tenant::query()
                    ->whereIn('id', $bgIds)
                    ->where('profile', TenantProfile::BuyingGroup)
                    ->where('status', 'active')
                    ->get()
                    ->keyBy('id');

                $members = Tenant::query()
                    ->whereIn('id', $memberIds)
                    ->where('profile', TenantProfile::Pharmacy)
                    ->where('status', 'active')
                    ->get()
                    ->keyBy('id');

                $enabledByBg = [];

                foreach ($memberships as $membership) {
                    $bg = $buyingGroups->get($membership->buying_group_tenant_id);
                    $member = $members->get($membership->member_tenant_id);

                    if ($bg === null || $member === null) {
                        $skipped++;

                        continue;
                    }

                    $bgKey = (string) $bg->getKey();

                    if (! array_key_exists($bgKey, $enabledByBg)) {
                        $enabledByBg[$bgKey] = TenantRunner::run(
                            $bg,
                            fn (): bool => TenantSettings::forTenant($bg)->buyingGroupMemberRollupsEnabled(),
                        );
                    }

                    if (! $enabledByBg[$bgKey]) {
                        $skipped++;

                        continue;
                    }

                    if ($sync) {
                        BuyingGroupMemberRollupJob::dispatchSync(
                            $bgKey,
                            (string) $member->getKey(),
                            $asOf,
                        );
                    } else {
                        BuyingGroupMemberRollupJob::dispatch(
                            $bgKey,
                            (string) $member->getKey(),
                            $asOf,
                        );
                    }

                    $dispatched++;
                }
            });

        $this->info(sprintf(
            'Buying-group rollup (%s): %d job(s) %s, %d skipped.',
            $asOf,
            $dispatched,
            $sync ? 'ran sync' : 'queued',
            $skipped,
        ));

        return self::SUCCESS;
    }
}
