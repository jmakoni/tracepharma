<?php

namespace Tests\Feature\Labeling;

use App\Actions\Labeling\GenerateSsccLabelBatch;
use App\Enums\PartnerType;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccNumberRangeScope;
use App\Enums\SsccNumberRangeStatus;
use App\Enums\TenantProfile;
use App\Exceptions\SsccNumberRangeCapacityException;
use App\Filament\App\Resources\Sites\RelationManagers\SsccNumberRangesRelationManager;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\App\Resources\SsccNumberRanges\SsccNumberRangeResource;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccNumberRange;
use App\Models\SsccSerialPool;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Labeling\ResolveSsccNumberRange;
use App\Services\Labeling\SsccNumberRangeMonitorService;
use App\Support\Labeling\SsccNumberRangeValidator;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SsccNumberRangeAllocationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $rangeIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $batchIds = [];

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function resolve_prefers_site_then_partner_then_tenant(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->ensureOrgPrefix($tenant);

            SsccNumberRange::query()->delete();

            $site = $this->createOrgSite();
            $partner = TradingPartner::query()->create([
                'name' => 'Range Partner '.Str::lower(Str::random(4)),
                'gln' => '039999200001'.((string) random_int(0, 9)),
                'partner_type' => PartnerType::Wholesaler->value,
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $prefix = (string) TenantSettings::forTenant($tenant)->companyPrefix();
            $base = $this->uniqueSerialBase();

            $tenantRange = $this->createRange([
                'name' => 'TenantRange'.$base,
                'scope' => SsccNumberRangeScope::Tenant->value,
                'company_prefix' => $prefix,
                'extension_digit' => 0,
                'start_number' => $base,
                'current_number' => $base,
                'range_size' => 50,
            ]);
            $partnerRange = $this->createRange([
                'name' => 'PartnerRange'.$base,
                'scope' => SsccNumberRangeScope::Partner->value,
                'trading_partner_id' => $partner->getKey(),
                'company_prefix' => $prefix,
                'extension_digit' => 0,
                'start_number' => $base + 1000,
                'current_number' => $base + 1000,
                'range_size' => 50,
            ]);
            $siteRange = $this->createRange([
                'name' => 'SiteRange'.$base,
                'scope' => SsccNumberRangeScope::Site->value,
                'site_id' => $site->getKey(),
                'company_prefix' => $prefix,
                'extension_digit' => 0,
                'start_number' => $base + 2000,
                'current_number' => $base + 2000,
                'range_size' => 50,
            ]);

            $this->assertSame(SsccNumberRangeScope::Site, $siteRange->fresh()->scope);
            $this->assertSame((int) $site->getKey(), (int) $siteRange->fresh()->site_id);
            $this->assertTrue($siteRange->fresh()->isSelectable());

            $resolver = app(ResolveSsccNumberRange::class);

            $this->assertSame(
                $siteRange->getKey(),
                $resolver->resolve($prefix, 0, (int) $site->getKey(), (int) $partner->getKey())?->getKey(),
            );
            $this->assertSame(
                $partnerRange->getKey(),
                $resolver->resolve($prefix, 0, null, (int) $partner->getKey())?->getKey(),
            );
            $this->assertSame(
                $tenantRange->getKey(),
                $resolver->resolve($prefix, 0)?->getKey(),
            );

            // Site range with insufficient remaining falls through to partner, then tenant.
            $siteRange->forceFill([
                'current_number' => $base + 2000 + 49,
                'remaining' => 1,
            ])->save();
            $this->assertSame(
                $partnerRange->getKey(),
                $resolver->resolve($prefix, 0, (int) $site->getKey(), (int) $partner->getKey(), 5)?->getKey(),
            );

            $partnerRange->forceFill([
                'current_number' => $base + 1000 + 49,
                'remaining' => 1,
            ])->save();
            $this->assertSame(
                $tenantRange->getKey(),
                $resolver->resolve($prefix, 0, (int) $site->getKey(), (int) $partner->getKey(), 5)?->getKey(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function off_grid_current_is_rejected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->ensureOrgPrefix($tenant);
            $prefix = (string) TenantSettings::forTenant($tenant)->companyPrefix();
            $base = $this->uniqueSerialBase();

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('align');

            $this->createRange([
                'name' => 'OffGrid'.$base,
                'scope' => SsccNumberRangeScope::Tenant->value,
                'company_prefix' => $prefix,
                'extension_digit' => 0,
                'start_number' => $base,
                'current_number' => $base + 1,
                'increment_by' => 2,
                'range_size' => 10,
            ]);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function inactive_never_used_range_allows_overlapping_replenishment(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->ensureOrgPrefix($tenant);
            SsccNumberRange::query()->delete();
            $prefix = (string) TenantSettings::forTenant($tenant)->companyPrefix();
            $base = $this->uniqueSerialBase();

            $inactive = $this->createRange([
                'name' => 'InactiveA'.$base,
                'scope' => SsccNumberRangeScope::Tenant->value,
                'company_prefix' => $prefix,
                'start_number' => $base,
                'current_number' => $base,
                'range_size' => 10,
            ]);
            $inactive->forceFill(['status' => SsccNumberRangeStatus::Inactive])->save();

            $replacement = $this->createRange([
                'name' => 'ActiveB'.$base,
                'scope' => SsccNumberRangeScope::Tenant->value,
                'company_prefix' => $prefix,
                'start_number' => $base,
                'current_number' => $base,
                'range_size' => 10,
            ]);

            $this->assertSame(SsccNumberRangeStatus::Active, $replacement->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function depleted_range_is_included_in_threshold_alerts(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->ensureOrgPrefix($tenant);
            $prefix = (string) TenantSettings::forTenant($tenant)->companyPrefix();
            $base = $this->uniqueSerialBase();

            $range = $this->createRange([
                'name' => 'DepleteAlert'.$base,
                'scope' => SsccNumberRangeScope::Tenant->value,
                'company_prefix' => $prefix,
                'start_number' => $base,
                'current_number' => $base + 10,
                'range_size' => 10,
                'threshold_percentage' => 80,
            ]);
            $range->syncRemainingAndStatus();
            $range->save();
            $this->assertSame(SsccNumberRangeStatus::Depleted, $range->fresh()->status);

            $monitor = app(SsccNumberRangeMonitorService::class);
            $alerts = $monitor->rangesNeedingAlert();
            $this->assertNotEmpty($alerts);
            $this->assertSame($range->getKey(), $alerts[0]['range_id']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function sequential_batch_consumes_number_range(): void
    {
        Storage::fake('local');
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->ensureOrgPrefix($tenant);
            SsccNumberRange::query()->delete();

            $site = $this->createOrgSite();
            $user = User::factory()->create();
            if (method_exists($user, 'syncSites')) {
                $user->syncSites([(int) $site->id], (int) $site->id);
            }
            $this->actingAs($user);

            $prefix = (string) TenantSettings::forTenant($tenant)->companyPrefix();
            $base = $this->uniqueSerialBase();

            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $site->id);
            $tenant->save();

            // Clear any leftover labels in this band from prior polluted runs.
            SsccLabel::query()
                ->where('company_prefix', $prefix)
                ->where('extension_digit', '0')
                ->whereBetween('serial_reference_int', [$base, $base + 20])
                ->delete();

            SsccSerialPool::query()->updateOrCreate(
                ['company_prefix' => $prefix, 'extension_digit' => '0'],
                [
                    'last_serial_reference_int' => max(0, $base - 1),
                    'default_allocation_mode' => SsccAllocationMode::Sequential,
                ],
            );

            $range = $this->createRange([
                'name' => 'MintRange'.$base,
                'scope' => SsccNumberRangeScope::Tenant->value,
                'company_prefix' => $prefix,
                'extension_digit' => 0,
                'start_number' => $base,
                'current_number' => $base,
                'range_size' => 10,
                'increment_by' => 1,
            ]);

            $batch = app(GenerateSsccLabelBatch::class)->execute([
                'label_count' => 3,
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'site_id' => $site->getKey(),
                'send_to_printer' => false,
                'emit_epcis' => false,
            ]);
            $this->batchIds[] = (int) $batch->getKey();

            $range->refresh();
            $this->assertSame($base + 3, (int) $range->current_number);
            $this->assertSame(7, (int) $range->remaining);
            $this->assertSame(SsccNumberRangeStatus::Active, $range->status);
            $this->assertSame($range->getKey(), $batch->allocation_config['sscc_number_range_id'] ?? null);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function threshold_monitor_alerts_once(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->ensureOrgPrefix($tenant);
            $prefix = (string) TenantSettings::forTenant($tenant)->companyPrefix();
            $base = $this->uniqueSerialBase();

            $range = $this->createRange([
                'name' => 'AlertRange'.$base,
                'scope' => SsccNumberRangeScope::Tenant,
                'company_prefix' => $prefix,
                'start_number' => $base,
                'current_number' => $base + 8,
                'range_size' => 10,
                'threshold_percentage' => 80,
            ]);

            $monitor = app(SsccNumberRangeMonitorService::class);
            $first = $monitor->rangesNeedingAlert();
            $this->assertNotEmpty($first);
            $this->assertSame($range->getKey(), $first[0]['range_id']);

            $monitor->markNotified([(int) $range->getKey()]);
            $this->assertSame([], $monitor->rangesNeedingAlert());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function resource_is_gated_by_sscc_labeling(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->assertTrue(SsccNumberRangeResource::canAccess());

            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->assertFalse(SsccNumberRangeResource::canAccess());

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->assertContains(
                SsccNumberRangesRelationManager::class,
                SiteResource::getRelations(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function capacity_failure_persists_range_self_heal_after_outer_rollback(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->ensureOrgPrefix($tenant);
            $prefix = (string) TenantSettings::forTenant($tenant)->companyPrefix();
            $base = $this->uniqueSerialBase();

            $range = $this->createRange([
                'name' => 'HealRange'.$base,
                'scope' => SsccNumberRangeScope::Tenant->value,
                'company_prefix' => $prefix,
                'extension_digit' => 0,
                'start_number' => $base,
                'current_number' => $base,
                'range_size' => 5,
                'increment_by' => 1,
            ]);

            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => $prefix,
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 5,
                'copies_per_label' => 1,
                'status' => SsccLabelBatchStatus::Completed,
                'completed_at' => now(),
            ]);
            $this->batchIds[] = (int) $batch->getKey();

            foreach (range($base, $base + 4) as $serial) {
                $sscc18 = str_pad((string) $serial, 18, '0', STR_PAD_LEFT);
                DB::table('sscc_labels')->insert([
                    'batch_id' => $batch->getKey(),
                    'company_prefix' => $prefix,
                    'extension_digit' => '0',
                    'serial_reference' => (string) $serial,
                    'serial_reference_int' => $serial,
                    'sscc_18' => $sscc18,
                    'sscc_urn' => 'urn:epc:id:sscc:'.$prefix.'.'.$serial,
                    'element_string' => '00'.$sscc18,
                    'hrt' => '00'.$sscc18,
                    'label_disk' => 'local',
                    'label_path' => 'sscc/heal-test-'.$serial.'.pdf',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            try {
                DB::transaction(function () use ($range): void {
                    app(ResolveSsccNumberRange::class)->issue($range, 1);
                });
                $this->fail('Expected capacity exception');
            } catch (SsccNumberRangeCapacityException $exception) {
                $this->assertStringContainsString('does not have enough remaining serials', $exception->getMessage());

                // issue() never persists a heal itself (would self-deadlock on MySQL while the
                // outer transaction above still held the row lock). The heal must be flushed by
                // the caller only after that outer transaction has ended — exactly as
                // GenerateSsccLabelBatch does via ResolveSsccNumberRange::flushPendingHeals().
                $exception->persistHeal();
            }

            $range->refresh();
            $this->assertSame(SsccNumberRangeStatus::Depleted, $range->status);
            $this->assertSame(0, (int) $range->remaining);
            $this->assertGreaterThan($base + 4, (int) $range->current_number);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRange(array $overrides): SsccNumberRange
    {
        $data = SsccNumberRangeValidator::normalizeAndValidate(array_merge([
            'name' => 'Range'.Str::random(6),
            'scope' => SsccNumberRangeScope::Tenant->value,
            'extension_digit' => 0,
            'increment_by' => 1,
            'range_size' => 100,
            'start_number' => 1,
            'current_number' => 1,
            'threshold_percentage' => 80,
            'company_prefix' => TenantSettings::forTenant(tenant())->companyPrefix(),
        ], $overrides));

        $range = SsccNumberRange::query()->create($data);
        $this->rangeIds[] = (int) $range->getKey();

        return $range;
    }

    private function createOrgSite(): Site
    {
        $site = Site::query()->create([
            'name' => 'SSCC Range Site '.Str::upper(Str::random(4)),
            'code' => 'SRS-'.Str::upper(Str::random(4)),
            'gln' => '0399991'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'is_active' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return $site;
    }

    private function ensureOrgPrefix(Tenant $tenant): void
    {
        TenantSettings::forTenant($tenant)->saveOrganization([
            'gln' => '0399991000008',
            'company_prefix' => '0399991',
        ]);
    }

    private function uniqueSerialBase(): int
    {
        // Must fit within max serial for a 7-digit company prefix (9-digit serial reference).
        return random_int(100_000_000, 899_999_999);
    }

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        if ($this->priorProfile === null) {
            $this->priorProfile = $tenant->profile instanceof TenantProfile
                ? $tenant->profile
                : TenantProfile::tryFrom((string) $tenant->profile);
        }

        $tenant->forceFill(['profile' => $profile])->save();
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            tenancy()->initialize($tenant);
            self::$demo2TenantReady = true;
        } else {
            tenancy()->initialize($tenant);
        }

        $this->assertTrue(Schema::hasTable('sscc_number_ranges'));

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->batchIds !== []) {
                SsccLabel::query()->whereIn('batch_id', $this->batchIds)->delete();
                SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
                $this->batchIds = [];
            }

            if ($this->rangeIds !== []) {
                SsccNumberRange::query()->whereIn('id', $this->rangeIds)->delete();
                $this->rangeIds = [];
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
                $this->partnerIds = [];
            }
        }

        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
            $this->priorProfile = null;
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
