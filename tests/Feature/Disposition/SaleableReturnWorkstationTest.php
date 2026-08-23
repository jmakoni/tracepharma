<?php

namespace Tests\Feature\Disposition;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Filament\App\Pages\ReturnWorkstation;
use App\Filament\App\Pages\SaleableReturnWorkstation;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Models\User;
use App\Models\Verification;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Receiving\ReceivingGate;
use App\Services\Vrs\Contracts\VrsClient;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PlacesEpcOnHandAtSite;
use Tests\TestCase;

class SaleableReturnWorkstationTest extends TestCase
{
    use PlacesEpcOnHandAtSite;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function page_is_visible_beside_return_workstation(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsReturning());
            $this->assertTrue(SaleableReturnWorkstation::canAccess());
            $this->assertTrue(SaleableReturnWorkstation::shouldRegisterNavigation());
            $this->assertSame('Saleable return', SaleableReturnWorkstation::getNavigationLabel());
            $this->assertSame('saleable-return', SaleableReturnWorkstation::getSlug());
            $this->assertSame(19, SaleableReturnWorkstation::getNavigationSort());
            $this->assertSame('Return', ReturnWorkstation::getNavigationLabel());

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            Livewire::test(SaleableReturnWorkstation::class)
                ->assertSuccessful()
                ->assertSee('Saleable return')
                ->assertSee('VRS must pass');
        } finally {
            tenancy()->end();
        }
    }

    private function initializeDemo2Tenant(): void
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
    }

    #[Test]
    public function unavailable_vrs_does_not_add_the_epc_to_the_return_list(): void
    {
        $this->initializeDemo2Tenant();
        $siteIds = [];
        $epcIds = [];
        $documentIds = [];
        $eventIds = [];

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $gln = '03'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
            $site = Site::query()->create([
                'name' => 'Saleable VRS site '.substr((string) str()->uuid(), 0, 8),
                'gln' => $gln,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $siteIds[] = (int) $site->getKey();
            CurrentSite::set((int) $site->getKey());

            $suffix = (string) random_int(10_000_000, 99_999_999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SR'.$suffix;
            $epc = Epc::fromUri($uri);
            $epc->first_seen_at = now();
            $epc->save();
            $epcIds[] = (int) $epc->getKey();
            EpcIlmd::query()->create([
                'epc_id' => $epc->getKey(),
                'gtin14' => $epc->gtin14,
                'lot_number' => 'SRLOT',
            ]);
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
                        'status' => 'unavailable',
                        'message' => 'VRS unavailable — rescan',
                    ];
                }
            });

            $scan = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;
            $component = Livewire::test(SaleableReturnWorkstation::class);
            CurrentSite::set((int) $site->getKey());
            $page = $component->instance();
            $page->scan = $scan;
            $page->processScan(
                app(ResolveEpcFromScan::class),
                app(ReceivingGate::class),
                app(EpcCustodyGate::class),
                app(ShippableEpcsAtSite::class),
                app(EpcOnAnotherOpenReceivingSession::class),
            );

            Verification::query()
                ->where('gtin14', $epc->gtin14)
                ->where('serial', $epc->serial_number)
                ->delete();

            $this->assertStringContainsString('unavailable', strtolower((string) $page->lastMessage));
            $this->assertSame([], $page->confirmed);
        } finally {
            foreach ($eventIds as $eventId) {
                DB::table('event_epcs')->where('event_id', $eventId)->delete();
                EpcisEvent::query()->whereKey($eventId)->delete();
            }
            foreach ($documentIds as $documentId) {
                EpcisDocument::query()->whereKey($documentId)->delete();
            }
            foreach ($epcIds as $epcId) {
                EpcIlmd::query()->where('epc_id', $epcId)->delete();
                if (! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                    Epc::query()->whereKey($epcId)->delete();
                }
            }
            foreach ($siteIds as $siteId) {
                Site::query()->whereKey($siteId)->delete();
            }
            tenancy()->end();
        }
    }

    #[Test]
    public function complete_is_blocked_when_a_lot_recall_opens_after_confirm(): void
    {
        $this->initializeDemo2Tenant();
        $siteIds = [];
        $epcIds = [];
        $documentIds = [];
        $eventIds = [];
        $requestIds = [];
        $verificationIds = [];

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $gln = '03'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
            $site = Site::query()->create([
                'name' => 'Saleable recall site '.substr((string) str()->uuid(), 0, 8),
                'gln' => $gln,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $siteIds[] = (int) $site->getKey();
            CurrentSite::set((int) $site->getKey());

            $suffix = (string) random_int(10_000_000, 99_999_999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.RC'.$suffix;
            $epc = Epc::fromUri($uri);
            $epc->first_seen_at = now();
            $epc->save();
            $epcIds[] = (int) $epc->getKey();
            EpcIlmd::query()->create([
                'epc_id' => $epc->getKey(),
                'gtin14' => $epc->gtin14,
                'lot_number' => 'SRRECALL',
            ]);
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

            $scan = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;
            $component = Livewire::test(SaleableReturnWorkstation::class);
            CurrentSite::set((int) $site->getKey());
            $component->set('scan', $scan)->call('processScan');
            $page = $component->instance();

            $this->assertNotSame([], $page->confirmed);
            $verificationIds = Verification::query()
                ->where('gtin14', $epc->gtin14)
                ->where('serial', $epc->serial_number)
                ->pluck('id')
                ->all();

            $recall = TracingRequest::query()->create([
                'title' => 'Lot recall after confirm',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'is_recall' => true,
                'gtin' => $epc->gtin14,
                'lot' => 'SRRECALL',
                'requested_at' => now(),
            ]);
            $requestIds[] = (int) $recall->getKey();

            $component->callAction('confirmReturn')
                ->assertNotified('Return failed');

            $page = $component->instance();
            $this->assertStringContainsString('recall', strtolower((string) $page->lastMessage));
            $this->assertSame([(int) $epc->getKey()], array_map(
                fn (array $row): int => (int) $row['epc_id'],
                $page->confirmed,
            ));
        } finally {
            foreach ($verificationIds as $id) {
                Verification::query()->whereKey($id)->delete();
            }
            foreach ($requestIds as $id) {
                TracingRequest::query()->whereKey($id)->delete();
            }
            foreach ($eventIds as $eventId) {
                DB::table('event_epcs')->where('event_id', $eventId)->delete();
                EpcisEvent::query()->whereKey($eventId)->delete();
            }
            foreach ($documentIds as $documentId) {
                EpcisDocument::query()->whereKey($documentId)->delete();
            }
            foreach ($epcIds as $epcId) {
                EpcIlmd::query()->where('epc_id', $epcId)->delete();
                if (! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                    Epc::query()->whereKey($epcId)->delete();
                }
            }
            foreach ($siteIds as $siteId) {
                Site::query()->whereKey($siteId)->delete();
            }
            tenancy()->end();
        }
    }
}
