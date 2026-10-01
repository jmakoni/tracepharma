<?php

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\ScanInWorkstation;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\EpcisDocuments\Pages\ListEpcisDocuments;
use App\Filament\App\Resources\EpcisDocuments\Tables\EpcisDocumentsTable;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Zvizvi\FilamentColumnFilters\FilamentColumnFilters;
use Zvizvi\FilamentColumnFilters\Filters\SearchColumnFilter;
use Zvizvi\FilamentColumnFilters\Filters\SelectColumnFilter;

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
    public function manufacturer_plant_can_open_inbound_epcis_and_receive(): void
    {
        $this->initializeDemo2Tenant();

        $tenant = tenant();
        $priorJobRoles = TenantSettings::forTenant($tenant)->jobRolesEnabled();
        $userId = null;

        try {
            $tenant->forceFill(['profile' => TenantProfile::Manufacturer])->save();

            $features = TenantFeatures::forTenant($tenant->fresh());
            $this->assertTrue($features->supportsInboundIntegrations());
            $this->assertTrue($features->supportsReceiving());
            $this->assertFalse($features->supportsVrs());

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();
            $tenant->refresh();

            $user = User::factory()->create([
                'email' => 'mfr-inbound-'.uniqid().'@example.test',
            ]);
            $userId = (int) $user->getKey();
            $user->assignRole(TenantRole::PackagingLineOperator->value);
            $this->actingAs($user);

            $this->assertTrue($user->can(Permissions::NavReceive));
            $this->assertTrue(EpcisDocumentResource::canAccess());
            $this->assertTrue(ScanInWorkstation::canAccess());
            $this->assertTrue(ReceivingSessionResource::canAccess());
        } finally {
            if (tenancy()->initialized) {
                if ($userId !== null) {
                    User::query()->whereKey($userId)->delete();
                }
                TenantSettings::forTenant($tenant)->setJobRolesEnabled($priorJobRoles);
                $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function drug_wholesaler_can_access_inbound_epcis_resource(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $tenant = tenant();
            $original = $tenant->profile;
            $tenant->setAttribute('profile', TenantProfile::DrugWholesaler);

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsInboundIntegrations());
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsReceiving());
            $this->assertTrue(EpcisDocumentResource::canAccess());

            $tenant->setAttribute('profile', $original);
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
    public function status_filter_includes_floor_receive_and_ingest_options(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $table = EpcisDocumentsTable::configure(Table::make(new ListEpcisDocuments));
        $filter = $table->getFilter('status');
        $this->assertNotNull($filter);

        $options = $filter->getOptions();
        $this->assertSame('Received', $options['Floor receive']['floor_received'] ?? null);
        $this->assertSame('Partially Received', $options['Floor receive']['floor_partially_received'] ?? null);
        $this->assertSame('Receive Blocked', $options['Floor receive']['floor_receive_blocked'] ?? null);
        $this->assertSame('Validated', $options['Ingest']['validated'] ?? null);
        $this->assertSame('Uploaded', $options['Ingest']['received'] ?? null);
    }

    #[Test]
    public function inbound_column_filters_use_select_not_search(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $table = EpcisDocumentsTable::configure(Table::make(new ListEpcisDocuments));

        foreach ([
            'seller_display',
            'ship_from_display',
            'sold_to_display',
            'ship_to_site_display',
            'asn_number',
            'customer_po',
            'document_uuid',
            'original_filename',
            'status',
            'schema_version',
        ] as $columnName) {
            $column = $table->getColumn($columnName);
            $this->assertNotNull($column, $columnName);
            $filter = FilamentColumnFilters::getColumnFilter($column);
            $this->assertInstanceOf(SelectColumnFilter::class, $filter, $columnName);
            $this->assertNotInstanceOf(SearchColumnFilter::class, $filter, $columnName);
        }
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
            $this->assertStringContainsString('action=findRecall', (string) $findRecall['url']);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function find_recall_query_param_sets_default_action_for_wire_init(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $owner = User::query()->where('email', 'owner@demo.test')->first()
                ?? User::factory()->create(['email' => 'owner@demo.test']);

            $this->actingAs($owner, 'web');

            Livewire::withQueryParams(['findRecall' => '1'])
                ->test(ListEpcisDocuments::class)
                ->assertSet('defaultAction', 'findRecall');
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function refresh_action_requires_exceptions_or_integrations_job_role(): void
    {
        $tenant = Tenant::query()->findOrFail(self::DEMO2_TENANT_ID);
        $originalProfile = $tenant->profile;
        if ($tenant->profile !== TenantProfile::DrugWholesaler) {
            $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
        }
        tenancy()->initialize($tenant);

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
        TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
        $tenant->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $receiveOnly = null;
        $exceptionsUser = null;

        try {
            $table = EpcisDocumentsTable::configure(Table::make(new ListEpcisDocuments));
            $actions = collect($table->getRecordActions())
                ->flatMap(fn ($action) => method_exists($action, 'getActions') ? $action->getActions() : [$action]);

            $refresh = $actions->first(fn ($action): bool => $action->getName() === 'refresh');
            $reprocess = $actions->first(fn ($action): bool => $action->getName() === 'reprocess');
            $this->assertNotNull($refresh);
            $this->assertNotNull($reprocess);

            $visibleProp = new \ReflectionProperty($refresh, 'isVisible');
            $visibleProp->setAccessible(true);
            $refreshVisible = $visibleProp->getValue($refresh);
            $this->assertInstanceOf(\Closure::class, $refreshVisible);

            $receiveOnly = User::factory()->create([
                'email' => 'refresh-receive-'.uniqid().'@example.test',
            ]);
            $receiveOnly->assignRole(TenantRole::ReceivingTechnician->value);
            $this->actingAs($receiveOnly);
            $this->assertFalse((bool) $refreshVisible());

            $exceptionsUser = User::factory()->create([
                'email' => 'refresh-exc-'.uniqid().'@example.test',
            ]);
            $exceptionsUser->assignRole(TenantRole::InboundExceptionCoordinator->value);
            $this->actingAs($exceptionsUser);
            $this->assertTrue((bool) $refreshVisible());
        } finally {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->forceFill(['profile' => $originalProfile])->save();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $receiveOnly?->delete();
            $exceptionsUser?->delete();
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
