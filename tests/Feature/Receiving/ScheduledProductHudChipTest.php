<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Fda\FdaProduct;
use App\Models\Product;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduledProductHudChipTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const CII_GTIN = '88884000001001';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $fdaProductIds = [];

    /** @var list<int> */
    private array $tenantProductIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function receiving_view_shows_cii_chip_when_session_gtins_are_fda_linked_cii(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $this->seedScheduledCiiProduct();
            $partner = TradingPartner::factory()->create([
                'dea_number' => null,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $document = $this->makeInboundDocumentWithScheduledEpc($partner);
            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionIds[] = (int) $session->getKey();

            $component = Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()]);

            $component->assertSee('CII');
            $component->assertSee('No DEA on seller');
            $this->assertSame('CII', $component->instance()->chipDeaSchedule);
            $this->assertTrue($component->instance()->chipDeaMissingParty);
            $this->assertSame('danger', $component->instance()->chipDeaColor);
        } finally {
            $this->cleanupTenant();
        }
    }

    private function seedScheduledCiiProduct(): void
    {
        $listing = FdaProduct::query()->create([
            'product_id' => 'SSOR-HUD-CII-DEA',
            'product_ndc' => '88884-504',
            'name' => 'SSOR HUD Scheduled CII',
            'dea_schedule' => '2',
            'is_active' => true,
        ]);
        $this->fdaProductIds[] = (int) $listing->id;

        $product = Product::query()->create([
            'name' => 'SSOR HUD Scheduled CII SKU',
            'gtin' => self::CII_GTIN,
            'fda_product_id' => $listing->id,
            'is_active' => true,
        ]);
        $this->tenantProductIds[] = (int) $product->id;
    }

    private function makeInboundDocumentWithScheduledEpc(TradingPartner $partner): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'status' => 'validated',
            'format' => 'xml',
            'original_filename' => 'scheduled-dea-hud-test.xml',
            'dscsa_affirm' => true,
            'trading_partner_id' => $partner->getKey(),
            'creation_date' => now(),
            'received_at' => now(),
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $serial = str_replace('-', '', (string) str()->uuid());
        $epc = Epc::query()->create([
            'epc_uri' => 'urn:epc:id:sgtin:888840.0000100.'.$serial,
            'epc_type' => 'sgtin',
            'company_prefix' => '888840',
            'indicator_digit' => 0,
            'gtin14' => self::CII_GTIN,
            'serial_number' => $serial,
            'product_id' => null,
            'first_seen_at' => now(),
        ]);
        $this->epcIds[] = (int) $epc->getKey();

        if (Schema::hasTable('document_epcs')) {
            DB::table('document_epcs')->insert([
                'document_id' => $document->getKey(),
                'epc_id' => $epc->getKey(),
                'ingest_generation' => (int) ($document->ingest_generation ?? 1),
            ]);
        }

        return $document->fresh();
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        return $user;
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

    private function cleanupTenant(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->sessionIds as $sessionId) {
            ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
            ReceivingSession::query()->whereKey($sessionId)->delete();
        }
        $this->sessionIds = [];

        foreach ($this->documentIds as $documentId) {
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('document_id', $documentId)->delete();
            }
            EpcisDocument::query()->whereKey($documentId)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $epcId) {
            Epc::query()->whereKey($epcId)->delete();
        }
        $this->epcIds = [];

        foreach ($this->partnerIds as $partnerId) {
            TradingPartner::query()->whereKey($partnerId)->delete();
        }
        $this->partnerIds = [];

        if ($this->tenantProductIds !== []) {
            Product::query()->whereIn('id', $this->tenantProductIds)->delete();
        }
        $this->tenantProductIds = [];

        tenancy()->end();
    }

    private function cleanup(): void
    {
        $this->cleanupTenant();

        if ($this->fdaProductIds !== []) {
            FdaProduct::query()->whereIn('id', $this->fdaProductIds)->delete();
        }
        $this->fdaProductIds = [];
    }
}
