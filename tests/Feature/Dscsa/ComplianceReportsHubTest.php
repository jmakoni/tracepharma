<?php

namespace Tests\Feature\Dscsa;

use App\Enums\ComplianceReportType;
use App\Enums\DataExportStatus;
use App\Enums\EpcisReceivedVia;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\ComplianceReports;
use App\Jobs\Exports\ProcessTrackTraceExportJob;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ComplianceReportsHubTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function pharmacy_profile_can_access_compliance_reports_hub(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsComplianceReports());

            $user = User::query()->first() ?? User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ComplianceReports::class)
                ->assertOk()
                ->assertSee('Transaction Report')
                ->assertSee('DSCSA Compliance Report')
                ->assertSee('TI history')
                ->assertSee('Audit package');

            $catalog = (new ComplianceReports)->reportCatalog();
            $types = collect($catalog)->pluck('type')->all();

            $this->assertContains(ComplianceReportType::TransactionReport->value, $types);
            $this->assertContains(ComplianceReportType::DscsaComplianceReport->value, $types);
            $this->assertContains(ComplianceReportType::TiHistory->value, $types);
            $this->assertContains(ComplianceReportType::AuditPackage->value, $types);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function generate_report_requires_document_selection(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ComplianceReports::class)
                ->fillForm([
                    'report_type' => ComplianceReportType::TransactionReport->value,
                    'document_id' => null,
                ])
                ->call('generateReport')
                ->assertHasFormErrors(['document_id']);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function generate_dscsa_compliance_report_queues_async_export(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document] = $this->seedDocumentWithEpcs(1);
            $user = $this->createExportUser();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Queue::fake();

            Livewire::test(ComplianceReports::class)
                ->fillForm([
                    'report_type' => ComplianceReportType::DscsaComplianceReport->value,
                    'document_id' => $document->id,
                ])
                ->call('generateReport')
                ->assertHasNoFormErrors()
                ->assertNotified();

            $this->assertDatabaseHas('data_exports', [
                'requested_by_user_id' => $user->id,
                'status' => DataExportStatus::Pending->value,
            ]);

            Queue::assertPushed(ProcessTrackTraceExportJob::class);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function generate_transaction_report_downloads_pdf_for_parsed_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()
                ->where('direction', 'inbound')
                ->whereIn('status', ['parsed', 'validated'])
                ->latest('id')
                ->first();

            if ($document === null) {
                $this->markTestSkipped('No parsed inbound EPCIS document available in demo2.');
            }

            $user = User::query()->first() ?? User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ComplianceReports::class)
                ->fillForm([
                    'report_type' => ComplianceReportType::TransactionReport->value,
                    'document_id' => $document->id,
                ])
                ->call('generateReport')
                ->assertHasNoFormErrors()
                ->assertFileDownloaded();
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function generate_ti_history_csv_and_audit_zip_for_parsed_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()
                ->where('direction', 'inbound')
                ->whereIn('status', ['parsed', 'validated'])
                ->latest('id')
                ->first();

            if ($document === null) {
                $this->markTestSkipped('No parsed inbound EPCIS document available in demo2.');
            }

            $user = User::query()->first() ?? User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ComplianceReports::class)
                ->fillForm([
                    'report_type' => ComplianceReportType::TiHistory->value,
                    'document_id' => $document->id,
                ])
                ->call('generateReport')
                ->assertHasNoFormErrors()
                ->assertFileDownloaded();

            Livewire::test(ComplianceReports::class)
                ->fillForm([
                    'report_type' => ComplianceReportType::AuditPackage->value,
                    'document_id' => $document->id,
                ])
                ->call('generateReport')
                ->assertHasNoFormErrors()
                ->assertFileDownloaded();
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

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $tenant;
    }

    /**
     * @return array{0: EpcisDocument}
     */
    private function seedDocumentWithEpcs(int $count): array
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'compliance-hub-export-test.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/hub-export-'.str()->uuid().'.xml',
            'dscsa_affirm' => false,
            'status' => 'parsed',
            'received_via' => EpcisReceivedVia::Api,
            'event_count' => 0,
            'epc_count' => $count,
            'received_at' => now(),
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->id;

        for ($i = 0; $i < $count; $i++) {
            $serial = 'hubserial'.$i;
            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.'.$serial,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => $serial,
                'ai_01_21' => '010030116200116221'.$serial,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            EpcIlmd::query()->create([
                'epc_id' => $epc->id,
                'lot_number' => 'LOT-HUB-A',
                'gtin14' => '00301162001162',
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    'document_id' => $document->id,
                    'epc_id' => $epc->id,
                    'ingest_generation' => 1,
                ]);
            }
        }

        return [$document];
    }

    private function createExportUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $user->givePermissionTo(Permissions::SitesAccessAll);

        return $user;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentIds !== []) {
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            }

            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }

        if ($this->epcIds !== []) {
            EpcIlmd::query()->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
        }

        $this->documentIds = [];
        $this->epcIds = [];

        tenancy()->end();
    }
}
