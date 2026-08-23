<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Vrs\RunProductVerification;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\Analytics;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\HqRollup;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Services\Vrs\Contracts\VrsClient;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Dashboard\HqRollupMetrics;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PlacesEpcOnHandAtSite;
use Tests\TestCase;

class HqRollupPageTest extends TestCase
{
    use PlacesEpcOnHandAtSite;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function page_sits_beside_frozen_dashboard_and_analytics(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertTrue(HqRollup::canAccess());
            $this->assertSame('hq-rollup', HqRollup::getSlug());
            $this->assertSame('Dashboard', Dashboard::getNavigationLabel());
            $this->assertSame('Analytics', Analytics::getNavigationLabel());

            Livewire::test(HqRollup::class)
                ->assertSuccessful()
                ->assertSee('Receive fill', false);
        } finally {
            tenancy()->end();
        }
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
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    #[Test]
    public function vrs_fail_rate_counts_verified_and_failed(): void
    {
        $this->initializeDemo2Tenant();
        $siteIds = [];
        $caseIds = [];
        $verificationIds = [];
        $epcIds = [];
        $documentIds = [];
        $eventIds = [];

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $site = Site::query()->create([
                'name' => 'HQ VRS site '.substr((string) str()->uuid(), 0, 8),
                'gln' => '03'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT),
                'is_active' => true,
                'is_organization_facility' => true,
            ]);
            $siteIds[] = (int) $site->getKey();

            $suffix = (string) random_int(10_000_000, 99_999_999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.HQ'.$suffix;
            $epc = Epc::fromUri($uri);
            $epc->first_seen_at = now();
            $epc->save();
            $epcIds[] = (int) $epc->getKey();
            $placed = $this->placeEpcOnHandAtSite($site, $epc);
            $documentIds[] = (int) $placed['document']->getKey();
            $eventIds[] = (int) $placed['event']->getKey();

            $this->app->bind(VrsClient::class, fn (): VrsClient => new class implements VrsClient
            {
                public function verify(
                    string $gtin14,
                    string $serial,
                    ?string $lot = null,
                    ?string $expiryYymmdd = null,
                ): array {
                    return [
                        'gtin14' => $gtin14,
                        'serial' => $serial,
                        'lot' => $lot,
                        'expiry_yymmdd' => $expiryYymmdd,
                        'status' => 'verified',
                        'message' => 'Verified',
                    ];
                }
            });

            $result = app(RunProductVerification::class)->handle(
                '(01)'.$epc->gtin14.'(21)'.$epc->serial_number,
                $user,
            );
            $passed = $result['verification'];
            $verificationIds[] = (int) $passed->getKey();

            $this->assertNull($passed->exception_id);
            $this->assertSame((int) $site->getKey(), $passed->request_payload['site_id'] ?? null);

            $type = ExceptionType::query()->where('code', 'SUSPECT_PRODUCT')->first();
            if ($type === null) {
                (new ExceptionTypeSeeder)->run();
                $type = ExceptionType::query()->where('code', 'SUSPECT_PRODUCT')->firstOrFail();
            }

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'HQ VRS fail case',
                'status' => ExceptionStatus::New,
                'severity' => ExceptionSeverity::High,
                'site_id' => $site->getKey(),
            ]);
            $caseIds[] = (int) $case->getKey();

            $failed = Verification::query()->create([
                'gtin14' => '00301163000014',
                'serial' => 'HQFAIL'.random_int(1000, 9999),
                'status' => 'failed',
                'exception_id' => $case->getKey(),
                'verified_by' => $user->getKey(),
            ]);
            $verificationIds[] = (int) $failed->getKey();

            $row = collect(app(HqRollupMetrics::class)->bySite())
                ->firstWhere('site_id', (int) $site->getKey());

            $this->assertNotNull($row);
            $this->assertSame(2, $row['vrs_total']);
            $this->assertSame(1, $row['vrs_blocked']);
            $this->assertSame(50.0, $row['vrs_fail_pct']);
        } finally {
            foreach ($verificationIds as $id) {
                Verification::query()->whereKey($id)->delete();
            }
            foreach ($caseIds as $id) {
                ExceptionCase::query()->whereKey($id)->delete();
            }
            foreach ($eventIds as $id) {
                DB::table('event_epcs')->where('event_id', $id)->delete();
                EpcisEvent::query()->whereKey($id)->delete();
            }
            foreach ($documentIds as $id) {
                EpcisDocument::query()->whereKey($id)->delete();
            }
            foreach ($epcIds as $id) {
                Epc::query()->whereKey($id)->delete();
            }
            foreach ($siteIds as $id) {
                Site::query()->whereKey($id)->delete();
            }
            tenancy()->end();
        }
    }
}
