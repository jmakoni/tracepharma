<?php

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\EpcisDocuments\Pages\ListEpcisDocuments;
use App\Filament\App\Resources\EpcisDocuments\Tables\EpcisDocumentsTable;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisDocumentResourceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function pharmacy_can_access_inbound_epcis_resource(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsInboundIntegrations());
            $this->assertTrue(EpcisDocumentResource::canAccess());
            $this->assertFalse(EpcisDocumentResource::canCreate());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function buying_group_cannot_access_inbound_epcis_resource(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $tenant = tenant();
            $original = $tenant->profile;
            $tenant->setAttribute('profile', TenantProfile::BuyingGroup);

            $this->assertFalse(TenantFeatures::forTenant(tenant())->supportsInboundIntegrations());
            $this->assertFalse(EpcisDocumentResource::canAccess());

            $tenant->setAttribute('profile', $original);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function resource_pages_and_slug_are_registered(): void
    {
        $pages = EpcisDocumentResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertSame('inbound-epcis', EpcisDocumentResource::getSlug());
        $this->assertSame(['index', 'view'], array_keys($pages));
    }

    #[Test]
    public function list_table_sees_ingested_xttrium_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()->first();
            $this->assertNotNull($document);
            $this->assertSame(2305, (int) $document->event_count);
            $this->assertSame(29502, (int) $document->epc_count);
            $this->assertStringContainsString('xttrium', strtolower((string) $document->original_filename));

            $row = EpcisDocument::query()
                ->with('tradingPartner')
                ->whereKey($document->getKey())
                ->first();
            $this->assertNotNull($row);
            $this->assertSame($document->document_uuid, $row->document_uuid);
            $this->assertTrue($row->relationLoaded('tradingPartner'));

            $table = EpcisDocumentsTable::configure(Table::make(new ListEpcisDocuments));
            $columnNames = collect($table->getColumns())->map(fn ($column) => $column->getName())->all();
            $this->assertContains('document_uuid', $columnNames);
            $this->assertContains('original_filename', $columnNames);
            $this->assertContains('event_count', $columnNames);
            $this->assertContains('epc_count', $columnNames);

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $url = EpcisDocumentResource::getUrl('index', panel: 'app');
            $this->assertStringContainsString('inbound-epcis', $url);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function operations_hub_lists_inbound_epcis_when_inbound_integrations_enabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $hub = new OperationsHub;
            $labels = collect($hub->directories())->pluck('label')->all();

            $this->assertContains('Inbound EPCIS', $labels);
            $this->assertContains('Find / Recall', $labels);

            $card = collect($hub->directories())->firstWhere('label', 'Inbound EPCIS');
            $this->assertNotNull($card);
            $this->assertStringContainsString('inbound-epcis', (string) $card['url']);

            $findRecall = collect($hub->directories())->firstWhere('label', 'Find / Recall');
            $this->assertNotNull($findRecall);
            $this->assertStringContainsString('inbound-epcis', (string) $findRecall['url']);
            $this->assertStringContainsString('findRecall=1', (string) $findRecall['url']);
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
