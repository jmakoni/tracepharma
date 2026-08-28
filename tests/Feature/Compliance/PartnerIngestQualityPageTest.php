<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\PartnerIngestQuality;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Integrations\PartnerIngestQualityMetrics;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PartnerIngestQualityPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $exceptionIds = [];

    protected function tearDown(): void
    {
        $this->cleanupTenantRows();
        parent::tearDown();
    }

    #[Test]
    public function pharmacy_page_shows_partner_exception_counts(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsInboundIntegrations());
            $this->assertTrue(PartnerIngestQuality::canAccess());

            $partner = TradingPartner::factory()->create([
                'name' => 'AAA Partner Ingest Quality Co',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'direction' => 'inbound',
                'status' => 'error',
                'trading_partner_id' => $partner->getKey(),
                'original_filename' => 'partner-ingest-quality-fixture.xml',
                'file_sha256' => hash('sha256', 'partner-ingest-quality-'.uniqid()),
                'creation_date' => now(),
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $recent = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'INGESTION_PARSE_ERROR',
                'severity' => 'error',
                'description' => 'Parse failed for partner ingest quality test.',
                'status' => 'open',
            ]);
            $this->exceptionIds[] = (int) $recent->getKey();
            $recent->forceFill(['created_at' => now()->subDays(2)])->save();

            $older = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'INTERNAL_VALIDATION_FAILED',
                'severity' => 'error',
                'description' => 'Validation failed outside 7d window.',
                'status' => 'open',
            ]);
            $this->exceptionIds[] = (int) $older->getKey();
            $older->forceFill(['created_at' => now()->subDays(14)])->save();

            $rows = app(PartnerIngestQualityMetrics::class)->rows();
            $row = $rows->firstWhere('trading_partner_id', $partner->getKey());

            $this->assertNotNull($row);
            $this->assertSame('AAA Partner Ingest Quality Co', $row['partner_name']);
            $this->assertSame(1, (int) $row['exceptions_7d']);
            $this->assertSame(2, (int) $row['exceptions_30d']);

            Livewire::test(PartnerIngestQuality::class)
                ->assertSuccessful()
                ->assertSee('Partner data quality')
                ->assertSee('AAA Partner Ingest Quality Co')
                ->assertSee('not clean-data certified')
                ->assertSee('not TraceReady');
        } finally {
            $this->cleanupTenantRows();
            tenancy()->end();
        }
    }

    #[Test]
    public function buying_group_cannot_access_partner_ingest_quality(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $tenant = tenant();
            $original = $tenant->profile;
            $tenant->setAttribute('profile', TenantProfile::BuyingGroup);

            $this->assertFalse(TenantFeatures::forTenant(tenant())->supportsInboundIntegrations());
            $this->assertFalse(PartnerIngestQuality::canAccess());

            $tenant->setAttribute('profile', $original);
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

    private function cleanupTenantRows(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->exceptionIds !== []) {
            EpcisException::query()->whereIn('id', $this->exceptionIds)->delete();
            $this->exceptionIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            $this->partnerIds = [];
        }
    }
}
