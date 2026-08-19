<?php

namespace Tests\Feature\Compliance;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\ComplianceReports;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComplianceSiteAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if ($this->caseIds !== []) {
                ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function exception_list_is_scoped_to_direct_site_id_or_document_ship_to(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $typeId = ExceptionType::query()->value('id');
            if ($typeId === null) {
                $typeId = ExceptionType::query()->create([
                    'code' => 'ssor_site_direct_'.Str::lower(Str::random(4)),
                    'name' => 'Direct site access type',
                    'is_active' => true,
                ])->id;
            }

            $docB = $this->createInboundDocument((int) $siteB->id, 'parsed');

            $caseViaSiteId = ExceptionCase::query()->create([
                'exception_type_id' => $typeId,
                'site_id' => $siteA->id,
                'title' => 'Manual receiving site A',
                'description' => 'A',
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::New,
            ]);
            $caseViaDocument = ExceptionCase::query()->create([
                'exception_type_id' => $typeId,
                'document_id' => $docB->id,
                'title' => 'Document ship-to site B only',
                'description' => 'B',
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::New,
            ]);
            $caseOtherSite = ExceptionCase::query()->create([
                'exception_type_id' => $typeId,
                'site_id' => $siteB->id,
                'title' => 'Site B only',
                'description' => 'C',
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds = [
                (int) $caseViaSiteId->id,
                (int) $caseViaDocument->id,
                (int) $caseOtherSite->id,
            ];

            $user = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $visible = ExceptionResource::getEloquentQuery()->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $this->assertContains((int) $caseViaSiteId->id, $visible);
            $this->assertNotContains((int) $caseViaDocument->id, $visible);
            $this->assertNotContains((int) $caseOtherSite->id, $visible);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function exception_list_is_scoped_to_document_ship_to_site(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $typeId = ExceptionType::query()->value('id');
            if ($typeId === null) {
                $typeId = ExceptionType::query()->create([
                    'code' => 'ssor_site_'.Str::lower(Str::random(4)),
                    'name' => 'Site access type',
                    'is_active' => true,
                ])->id;
            }

            $docA = $this->createInboundDocument((int) $siteA->id, 'parsed');
            $docB = $this->createInboundDocument((int) $siteB->id, 'parsed');

            $caseA = ExceptionCase::query()->create([
                'exception_type_id' => $typeId,
                'document_id' => $docA->id,
                'title' => 'SSOR site A exception',
                'description' => 'A',
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::New,
            ]);
            $caseB = ExceptionCase::query()->create([
                'exception_type_id' => $typeId,
                'document_id' => $docB->id,
                'title' => 'SSOR site B exception',
                'description' => 'B',
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds = [(int) $caseA->id, (int) $caseB->id];

            $user = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $visible = ExceptionResource::getEloquentQuery()->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $this->assertContains((int) $caseA->id, $visible);
            $this->assertNotContains((int) $caseB->id, $visible);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function compliance_reports_document_options_exclude_other_sites(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $docA = $this->createInboundDocument((int) $siteA->id, 'validated');
            $docB = $this->createInboundDocument((int) $siteB->id, 'validated');

            $user = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $component = Livewire::test(ComplianceReports::class);

            $method = new \ReflectionMethod(ComplianceReports::class, 'accessibleDocumentsQuery');
            $visibleIds = $method->invoke($component->instance())
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $docA->id, $visibleIds);
            $this->assertNotContains((int) $docB->id, $visibleIds);

            $component
                ->fillForm([
                    'report_type' => \App\Enums\ComplianceReportType::TransactionReport->value,
                    'document_id' => $docB->id,
                ])
                ->call('generateReport')
                ->assertNoFileDownloaded();
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'Compliance Site A '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Compliance Site B '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $this->siteIds = [(int) $siteA->id, (int) $siteB->id];

        return [$siteA, $siteB];
    }

    private function createInboundDocument(int $shipToSiteId, string $status): EpcisDocument
    {
        $document = new EpcisDocument;
        $document->forceFill([
            'document_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'status' => $status,
            'format' => 'xml',
            'creation_date' => now(),
            'received_at' => now(),
            'ship_to_site_id' => $shipToSiteId,
            'original_filename' => 'ssor-compliance-'.Str::random(6).'.xml',
            'payload_disk' => 'local',
            'payload_path' => 'tests/ssor-'.Str::random(6).'.xml',
            'file_sha256' => hash('sha256', Str::random(32)),
            'ingest_generation' => 1,
        ]);
        $document->save();
        $this->documentIds[] = (int) $document->id;

        return $document;
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $this->userIds[] = (int) $user->id;

        return $user;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }
}
