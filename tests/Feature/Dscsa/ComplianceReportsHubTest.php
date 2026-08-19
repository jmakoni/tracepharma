<?php

namespace Tests\Feature\Dscsa;

use App\Enums\ComplianceReportType;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\ComplianceReports;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComplianceReportsHubTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

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
    public function generate_report_downloads_pdf_for_parsed_document(): void
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
                    'report_type' => ComplianceReportType::DscsaComplianceReport->value,
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

        return $tenant;
    }
}
