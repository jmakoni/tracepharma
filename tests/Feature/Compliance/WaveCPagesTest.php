<?php

namespace Tests\Feature\Compliance;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Filament\App\Pages\AssetTracking;
use App\Filament\App\Pages\ComplianceReports;
use App\Filament\App\Pages\ExpiryWorklist;
use App\Filament\App\Pages\InspectionPack;
use App\Filament\App\Pages\OnHandList;
use App\Filament\App\Pages\Quarantine;
use App\Filament\App\Pages\SiteRecallReconciliation;
use App\Filament\App\Pages\SopLibrary;
use App\Models\AtpLicense;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Models\User;
use App\Services\Dscsa\InspectionPackZipGenerator;
use App\Support\Auth\TenantRoleSeeder;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PlacesEpcOnHandAtSite;
use Tests\TestCase;

class WaveCPagesTest extends TestCase
{
    use PlacesEpcOnHandAtSite;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $recallIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $holdIds = [];

    /** @var list<int> */
    private array $licenseIds = [];

    #[Test]
    public function new_pages_sit_beside_frozen_compliance_and_asset_pages(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(ExpiryWorklist::canAccess());
            $this->assertSame('expiry-worklist', ExpiryWorklist::getSlug());
            $this->assertTrue(OnHandList::canAccess());
            $this->assertSame('on-hand', OnHandList::getSlug());
            $this->assertTrue(SiteRecallReconciliation::canAccess());
            $this->assertSame('site-recall', SiteRecallReconciliation::getSlug());
            $this->assertTrue(SopLibrary::canAccess());
            $this->assertSame('sop-library', SopLibrary::getSlug());
            $this->assertTrue(InspectionPack::canAccess());
            $this->assertSame('inspection-pack', InspectionPack::getSlug());

            $this->assertSame('Compliance reports', ComplianceReports::getNavigationLabel());
            $this->assertSame('Quarantine', Quarantine::getNavigationLabel());
            $this->assertSame('Asset Tracking', AssetTracking::getNavigationLabel());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function expiry_and_on_hand_list_serials_at_the_selected_site(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();
            $near = $this->makeOnHandSgtin($site, now()->addDays(12)->toDateString(), 'NEARLOT');
            $far = $this->makeOnHandSgtin($site, now()->addDays(120)->toDateString(), 'FARLOT');

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            Livewire::test(ExpiryWorklist::class)
                ->set('siteId', $site->getKey())
                ->set('windowDays', 90)
                ->assertSuccessful()
                ->assertSee($near['serial'], false)
                ->assertDontSee($far['serial'], false);

            Livewire::test(OnHandList::class)
                ->set('siteId', $site->getKey())
                ->assertSuccessful()
                ->assertSee($near['serial'], false)
                ->assertSee($far['serial'], false);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function expiry_worklist_excludes_already_expired_and_orders_by_expiry(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();
            $expired = $this->makeOnHandSgtin($site, now()->subDays(3)->toDateString(), 'PASTLOT');
            $soon = $this->makeOnHandSgtin($site, now()->addDays(12)->toDateString(), 'SOONLOT');
            $later = $this->makeOnHandSgtin($site, now()->addDays(40)->toDateString(), 'LATERLOT');

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            $component = Livewire::test(ExpiryWorklist::class)
                ->set('siteId', $site->getKey())
                ->set('windowDays', 90)
                ->assertSuccessful()
                ->assertDontSee($expired['serial'], false)
                ->assertSee($soon['serial'], false)
                ->assertSee($later['serial'], false);

            $serials = $component->instance()->rows()->map(fn ($epc) => $epc->serial_number)->values()->all();
            $this->assertContains($soon['serial'], $serials);
            $this->assertContains($later['serial'], $serials);
            $this->assertNotContains($expired['serial'], $serials);
            $this->assertLessThan(
                array_search($later['serial'], $serials, true),
                array_search($soon['serial'], $serials, true),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function site_recall_lists_open_recall_hits_and_can_mark_accounted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();
            $hit = $this->makeOnHandSgtin($site, now()->addYear()->toDateString(), 'RECALLOT');

            $recall = TracingRequest::query()->create([
                'title' => 'Wave C site recall',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'is_recall' => true,
                'gtin' => $hit['epc']->gtin14,
                'lot' => 'RECALLOT',
                'requested_at' => now(),
            ]);
            $this->recallIds[] = (int) $recall->getKey();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            Livewire::test(SiteRecallReconciliation::class)
                ->set('siteId', $site->getKey())
                ->assertSuccessful()
                ->assertSee($hit['serial'], false)
                ->callAction('markAccounted', arguments: ['epc' => $hit['epc']->getKey()])
                ->assertDontSee($hit['serial'], false);

            $otherLot = $this->makeOnHandSgtin($site, now()->addYear()->toDateString(), 'OTHERRECALLOT');
            $otherRecall = TracingRequest::query()->create([
                'title' => 'Unrelated open recall',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'is_recall' => true,
                'gtin' => $otherLot['epc']->gtin14,
                'lot' => 'OTHERRECALLOT',
                'requested_at' => now(),
            ]);
            $this->recallIds[] = (int) $otherRecall->getKey();
            $recall->refresh();
            $this->assertSame(
                [(int) $hit['epc']->getKey()],
                $recall->response_metadata['reconciled']['site_'.$site->getKey()] ?? [],
            );
            $this->assertArrayNotHasKey('reconciled', is_array($otherRecall->fresh()->response_metadata) ? $otherRecall->fresh()->response_metadata : []);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sop_library_renders_seeded_checklists(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user] = $this->loginOwnerWithSite();
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            Livewire::test(SopLibrary::class)
                ->assertSuccessful()
                ->assertSee('Suspect product isolation', false)
                ->assertSee('Site recall sweep', false)
                ->assertSee('FDA 3911 within 24 hours', false);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inspection_pack_zip_includes_atp_and_open_exceptions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();

            $license = AtpLicense::factory()->create([
                'site_id' => $site->getKey(),
                'license_number' => 'ATP-WAVC-'.random_int(1000, 9999),
                'is_active' => true,
            ]);
            $this->licenseIds[] = (int) $license->getKey();

            $type = ExceptionType::query()->where('code', 'SUSPECT_PRODUCT')->first();
            if ($type === null) {
                (new ExceptionTypeSeeder)->run();
                $type = ExceptionType::query()->where('code', 'SUSPECT_PRODUCT')->firstOrFail();
            }

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Wave C open exception',
                'status' => ExceptionStatus::New,
                'severity' => ExceptionSeverity::High,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            Livewire::test(InspectionPack::class)
                ->set('siteId', $site->getKey())
                ->assertSuccessful()
                ->assertSee('Download pack', false);

            Livewire::test(InspectionPack::class)
                ->set('siteId', null)
                ->callAction('downloadPack')
                ->assertNotified('Select a site you can access.');

            try {
                app(InspectionPackZipGenerator::class)->generate(null);
                $this->fail('Inspection pack must require a site.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Select a site', $e->getMessage());
            }

            $otherSite = Site::query()->create([
                'name' => 'Other insp site '.substr((string) str()->uuid(), 0, 8),
                'gln' => '03'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT),
                'is_active' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $otherSite->getKey();
            $otherCase = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Other site hold case',
                'status' => ExceptionStatus::New,
                'severity' => ExceptionSeverity::High,
                'site_id' => $otherSite->getKey(),
            ]);
            $this->caseIds[] = (int) $otherCase->getKey();
            $otherHold = QuarantineHold::query()->create([
                'exception_id' => $otherCase->getKey(),
                'epc_id' => $this->makeOnHandSgtin($otherSite, now()->addYear()->toDateString(), 'OTHERHOLD')['epc']->getKey(),
                'status' => 'open',
                'reason' => 'other site hold',
                'opened_at' => now(),
            ]);
            $this->holdIds[] = (int) $otherHold->getKey();

            $pack = app(InspectionPackZipGenerator::class)->generate((int) $site->getKey());
            $this->assertNotSame('', $pack['binary']);

            $tmp = tempnam(sys_get_temp_dir(), 'insp');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $pack['binary']);
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($tmp) === true);
            $this->assertStringContainsString($license->license_number, (string) $zip->getFromName('atp-licenses.csv'));
            $this->assertStringContainsString('Wave C open exception', (string) $zip->getFromName('exceptions-open.csv'));
            $this->assertStringNotContainsString((string) $otherHold->getKey(), (string) $zip->getFromName('quarantine-holds-open.csv'));
            $zip->close();
            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: User, 1: Site}
     */
    private function loginOwnerWithSite(): array
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        $gln = '03'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
        $site = Site::query()->create([
            'name' => 'Wave C Site '.substr((string) str()->uuid(), 0, 8),
            'gln' => $gln,
            'is_active' => true,
            'is_headquarters' => false,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return [$user, $site];
    }

    /**
     * @return array{epc: Epc, serial: string}
     */
    private function makeOnHandSgtin(Site $site, string $expiry, string $lot): array
    {
        $suffix = (string) random_int(10_000_000, 99_999_999);
        $itemRef = substr($suffix, 0, 6);
        $serial = 'WC'.$suffix;
        $epc = Epc::fromUri("urn:epc:id:sgtin:030116.3{$itemRef}.{$serial}");
        $epc->first_seen_at = now();
        $epc->save();
        $this->epcIds[] = (int) $epc->getKey();

        EpcIlmd::query()->create([
            'epc_id' => $epc->getKey(),
            'gtin14' => $epc->gtin14,
            'lot_number' => $lot,
            'expiry_date' => $expiry,
        ]);

        $placed = $this->placeEpcOnHandAtSite($site, $epc);
        $this->documentIds[] = (int) $placed['document']->getKey();
        $this->eventIds[] = (int) $placed['event']->getKey();

        return ['epc' => $epc->fresh(), 'serial' => $serial];
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

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->holdIds as $id) {
            QuarantineHold::query()->whereKey($id)->delete();
        }
        $this->holdIds = [];

        foreach ($this->caseIds as $id) {
            ExceptionCase::query()->whereKey($id)->delete();
        }
        $this->caseIds = [];

        foreach ($this->recallIds as $id) {
            TracingRequest::query()->whereKey($id)->delete();
        }
        $this->recallIds = [];

        foreach ($this->licenseIds as $id) {
            AtpLicense::query()->whereKey($id)->delete();
        }
        $this->licenseIds = [];

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereKey($this->eventIds)->delete();
            $this->eventIds = [];
        }

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $id) {
            EpcIlmd::query()->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        foreach ($this->siteIds as $id) {
            Site::query()->whereKey($id)->delete();
        }
        $this->siteIds = [];

        tenancy()->end();
    }
}
