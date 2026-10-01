<?php

namespace Tests\Feature\TableViews;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\EpcisDocuments\Pages\ListEpcisDocuments;
use App\Filament\App\Resources\Exceptions\Pages\ListExceptions;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tracepharma\FilamentTableViews\Models\TableView;

class TableViewsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $viewIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    protected function tearDown(): void
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            if ($this->viewIds !== [] && TableView::tableExists()) {
                TableView::query()->whereIn('id', $this->viewIds)->delete();
            }
            if ($this->sessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $this->sessionIds)->delete();
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            User::query()->whereIn('id', $this->userIds)->delete();
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function save_reload_and_apply_restores_filters_sort_and_columns(): void
    {
        $this->bootDemo2();
        $owner = $this->createOwnerUser();
        $this->actingAs($owner);
        $this->seedExceptionType();

        $component = Livewire::test(ListExceptions::class)
            ->call('applyTableView', 'preset:all_open')
            ->set('tableSearch', 'view-search-'.Str::uuid())
            ->set('tableSort', 'title:asc')
            ->set('tableFilters', [
                'status' => ['value' => ExceptionStatus::New->value],
            ]);

        $columns = $component->get('tableColumns');
        $this->assertIsArray($columns);
        $this->assertNotEmpty($columns);
        $component->set('tableColumns', $columns);

        $name = 'Saved snapshot '.Str::uuid();
        $component
            ->set('tableViewForm.name', $name)
            ->set('tableViewForm.is_favorite', true)
            ->call('saveAsTableView');

        $view = TableView::query()->where('name', $name)->first();
        $this->assertNotNull($view);
        $this->trackView($view);

        $this->assertSame($component->get('tableSearch'), $view->state['search'] ?? null);
        $this->assertSame('title:asc', $view->state['sort'] ?? null);
        $this->assertSame(ExceptionStatus::New->value, data_get($view->state, 'filters.status.value'));
        $this->assertSame(
            collect($columns)->pluck('name')->all(),
            collect($view->state['columns'] ?? [])->pluck('name')->all(),
        );

        $reloaded = Livewire::test(ListExceptions::class)
            ->call('applyTableView', 'saved:'.$view->getKey());

        $this->assertSame($view->state['search'], $reloaded->get('tableSearch'));
        $this->assertSame('title:asc', $reloaded->get('tableSort'));
        $this->assertSame(ExceptionStatus::New->value, data_get($reloaded->get('tableFilters'), 'status.value'));
        $this->assertSame(
            collect($view->state['columns'] ?? [])->pluck('name')->all(),
            collect($reloaded->get('tableColumns'))->pluck('name')->all(),
        );
    }

    #[Test]
    public function quick_update_overwrites_state_without_duplicating_the_row(): void
    {
        $this->bootDemo2();
        $this->actingAs($this->createOwnerUser());

        $name = 'Quick update '.Str::uuid();
        $component = Livewire::test(ListExceptions::class)
            ->set('tableSearch', 'first-pass')
            ->set('tableViewForm.name', $name)
            ->set('tableViewForm.is_favorite', true)
            ->call('saveAsTableView');

        $view = TableView::query()->where('name', $name)->first();
        $this->assertNotNull($view);
        $this->trackView($view);

        $component
            ->set('tableSearch', 'second-pass')
            ->call('quickSaveTableView');

        $this->assertSame(1, TableView::query()->where('name', $name)->count());
        $this->assertSame('second-pass', $view->fresh()->state['search'] ?? null);
    }

    #[Test]
    public function manage_lists_a_remove_control_and_deletes_the_saved_view(): void
    {
        $this->bootDemo2();
        $this->actingAs($this->createOwnerUser());

        $name = 'Remove me '.Str::uuid();
        $component = Livewire::test(ListExceptions::class)
            ->set('tableViewForm.name', $name)
            ->set('tableViewForm.is_favorite', true)
            ->call('saveAsTableView');

        $view = TableView::query()->where('name', $name)->first();
        $this->assertNotNull($view);
        $this->trackView($view);

        $html = $component
            ->call('openTableViewManager')
            ->instance()
            ->renderTableViewsBar()
            ->render();

        $this->assertStringContainsString('deleteTableView('.$view->getKey().')', $html);
        $this->assertStringContainsString('Remove', $html);

        $component->call('deleteTableView', $view->getKey());

        $this->assertNull(TableView::query()->find($view->getKey()));
        $labels = collect($component->instance()->getTableViewBarItems())->pluck('label');
        $this->assertFalse($labels->contains($name));
    }

    #[Test]
    public function inventory_user_cannot_delete_another_users_public_view(): void
    {
        $this->bootDemo2();
        $owner = $this->createOwnerUser();
        $peer = $this->createInventoryUser();
        $this->actingAs($owner);

        $name = 'Owner public '.Str::uuid();
        Livewire::test(ListExceptions::class)
            ->set('tableViewForm.name', $name)
            ->set('tableViewForm.is_favorite', true)
            ->set('tableViewForm.is_public', true)
            ->call('saveAsTableView');

        $view = TableView::query()->where('name', $name)->first();
        $this->assertNotNull($view);
        $this->trackView($view);

        $this->actingAs($peer);
        Livewire::test(ListExceptions::class)
            ->call('deleteTableView', $view->getKey());

        $this->assertNotNull(TableView::query()->find($view->getKey()));
    }

    #[Test]
    public function favorite_appears_on_the_bar_and_unfavorite_does_not(): void
    {
        $this->bootDemo2();
        $this->actingAs($this->createOwnerUser());

        $name = 'Bar favorite '.Str::uuid();
        $component = Livewire::test(ListExceptions::class)
            ->set('tableViewForm.name', $name)
            ->set('tableViewForm.is_favorite', true)
            ->call('saveAsTableView');

        $view = TableView::query()->where('name', $name)->first();
        $this->assertNotNull($view);
        $this->trackView($view);

        $labels = collect($component->instance()->getTableViewBarItems())->pluck('label');
        $this->assertTrue($labels->contains($name));

        $component->call('toggleTableViewFavorite', $view->getKey());

        $labels = collect($component->instance()->getTableViewBarItems())->pluck('label');
        $this->assertFalse($labels->contains($name));
        $this->assertFalse($view->fresh()->is_favorite);
    }

    #[Test]
    public function public_view_is_visible_to_a_second_user_private_is_not(): void
    {
        $this->bootDemo2();
        $owner = $this->createOwnerUser();
        $peer = $this->createInventoryUser();
        $this->actingAs($owner);

        $publicName = 'Public view '.Str::uuid();
        $privateName = 'Private view '.Str::uuid();

        Livewire::test(ListExceptions::class)
            ->set('tableViewForm.name', $publicName)
            ->set('tableViewForm.is_favorite', true)
            ->set('tableViewForm.is_public', true)
            ->call('saveAsTableView');

        Livewire::test(ListExceptions::class)
            ->set('tableViewForm.name', $privateName)
            ->set('tableViewForm.is_favorite', true)
            ->set('tableViewForm.is_public', false)
            ->call('saveAsTableView');

        $public = TableView::query()->where('name', $publicName)->first();
        $private = TableView::query()->where('name', $privateName)->first();
        $this->assertNotNull($public);
        $this->assertNotNull($private);
        $this->trackView($public);
        $this->trackView($private);

        $visibleToPeer = TableView::query()
            ->forTable(ListExceptions::class)
            ->visibleTo($peer)
            ->pluck('name');

        $this->assertTrue($visibleToPeer->contains($publicName));
        $this->assertFalse($visibleToPeer->contains($privateName));

        $this->actingAs($peer);
        $peerBar = collect(Livewire::test(ListExceptions::class)->instance()->getTableViewBarItems())->pluck('label');
        $this->assertTrue($peerBar->contains($publicName));
        $this->assertFalse($peerBar->contains($privateName));
    }

    #[Test]
    public function global_favorite_is_visible_to_all_users(): void
    {
        $this->bootDemo2();
        $owner = $this->createOwnerUser();
        $peer = $this->createInventoryUser();
        $this->actingAs($owner);

        $name = 'Global favorite '.Str::uuid();
        Livewire::test(ListExceptions::class)
            ->set('tableViewForm.name', $name)
            ->set('tableViewForm.is_favorite', true)
            ->set('tableViewForm.is_global', true)
            ->call('saveAsTableView');

        $view = TableView::query()->where('name', $name)->first();
        $this->assertNotNull($view);
        $this->trackView($view);
        $this->assertTrue($view->is_global);
        $this->assertNull($view->user_id);

        $this->actingAs($peer);
        $peerBar = collect(Livewire::test(ListExceptions::class)->instance()->getTableViewBarItems())->pluck('label');
        $this->assertTrue($peerBar->contains($name));
    }

    #[Test]
    public function exception_presets_constrain_open_versus_cleared_resolved(): void
    {
        $this->bootDemo2();
        $this->actingAs($this->createOwnerUser());
        $this->seedExceptionType();

        $token = 'preset-token-'.Str::uuid();
        $open = $this->createCase($token.' open', ExceptionStatus::New);
        $cleared = $this->createCase($token.' cleared', ExceptionStatus::Cleared);

        $this->assertTrue(ExceptionCase::query()->open()->whereKey($open->getKey())->exists());
        $this->assertFalse(ExceptionCase::query()->open()->whereKey($cleared->getKey())->exists());

        $openPage = Livewire::test(ListExceptions::class)
            ->call('applyTableView', 'preset:all_open');
        $this->assertSame('preset:all_open', $openPage->get('activeTableView'));

        $openQuery = ExceptionCase::query();
        $openPreset = $openPage->instance()->getPresetViews()['all_open'];
        $this->assertNotNull($openPreset->getQuery());
        $openQuery = ($openPreset->getQuery())($openQuery);
        $this->assertTrue($openQuery->clone()->whereKey($open->getKey())->exists());
        $this->assertFalse($openQuery->clone()->whereKey($cleared->getKey())->exists());

        $clearedPage = Livewire::test(ListExceptions::class)
            ->call('applyTableView', 'preset:cleared_resolved');
        $this->assertSame('preset:cleared_resolved', $clearedPage->get('activeTableView'));

        $clearedQuery = ExceptionCase::query();
        $clearedPreset = $clearedPage->instance()->getPresetViews()['cleared_resolved'];
        $this->assertNotNull($clearedPreset->getQuery());
        $clearedQuery = ($clearedPreset->getQuery())($clearedQuery);
        $this->assertTrue($clearedQuery->clone()->whereKey($cleared->getKey())->exists());
        $this->assertFalse($clearedQuery->clone()->whereKey($open->getKey())->exists());

        $this->assertFalse(
            ($openPreset->getQuery())(ExceptionCase::query())->whereKey($cleared->getKey())->exists(),
        );
    }

    #[Test]
    public function inbound_epcis_list_renders_table_views_without_toolbar_collision(): void
    {
        $this->bootDemo2();
        $this->actingAs($this->createOwnerUser());

        $component = Livewire::test(ListEpcisDocuments::class)
            ->assertSuccessful();

        $labels = collect($component->instance()->getTableViewBarItems())->pluck('label');
        $this->assertTrue($labels->contains('Needs attention'));
        $this->assertTrue($labels->contains('Received'));
        $this->assertTrue($labels->contains('Ready to Receive'));
        $this->assertFalse($labels->contains('Validated'));
        $this->assertStringContainsString('Quick Save', $component->instance()->renderTableViewsBar()->render());

        $component
            ->call('applyTableView', 'preset:needs_attention')
            ->assertSet('activeTableView', 'preset:needs_attention')
            ->assertSuccessful();
    }

    #[Test]
    public function inbound_epcis_ready_to_receive_preset_keeps_validated_documents_not_yet_fully_received(): void
    {
        $this->bootDemo2();
        $this->actingAs($this->createOwnerUser());

        $ready = $this->createInboundDocument('validated');
        $partial = $this->createInboundDocument('validated');
        $fullyReceived = $this->createInboundDocument('validated');
        $uploaded = $this->createInboundDocument('received');

        $this->sessionIds[] = (int) ReceivingSession::query()->create([
            'epcis_document_id' => $partial->getKey(),
            'status' => 'in_progress',
            'expected_parent_count' => 2,
            'confirmed_parent_count' => 1,
            'expected_child_count' => 10,
            'confirmed_child_count' => 3,
            'opened_at' => now(),
        ])->getKey();

        $this->sessionIds[] = (int) ReceivingSession::query()->create([
            'epcis_document_id' => $fullyReceived->getKey(),
            'status' => 'completed',
            'expected_parent_count' => 2,
            'confirmed_parent_count' => 2,
            'expected_child_count' => 10,
            'confirmed_child_count' => 10,
            'opened_at' => now(),
            'completed_at' => now(),
        ])->getKey();

        $page = Livewire::test(ListEpcisDocuments::class)
            ->call('applyTableView', 'preset:ready_to_receive');

        $this->assertSame('preset:ready_to_receive', $page->get('activeTableView'));

        $preset = $page->instance()->getPresetViews()['ready_to_receive'];
        $this->assertSame('Ready to Receive', $preset->getName());
        $this->assertNotNull($preset->getQuery());
        $query = ($preset->getQuery())(EpcisDocument::query());

        $this->assertTrue($query->clone()->whereKey($ready->getKey())->exists());
        $this->assertTrue($query->clone()->whereKey($partial->getKey())->exists());
        $this->assertFalse($query->clone()->whereKey($fullyReceived->getKey())->exists());
        $this->assertFalse($query->clone()->whereKey($uploaded->getKey())->exists());
    }

    #[Test]
    public function inbound_epcis_list_renders_when_table_views_is_missing(): void
    {
        $this->bootDemo2();
        $this->actingAs($this->createOwnerUser());

        Schema::dropIfExists('table_views');
        TableView::forgetTableExistsCache();

        try {
            Livewire::test(ListEpcisDocuments::class)->assertSuccessful();
        } finally {
            $migration = require database_path('migrations/tenant/2026_09_26_140000_create_table_views_table.php');
            $migration->up();
            TableView::forgetTableExistsCache();
        }
    }

    #[Test]
    public function inventory_user_cannot_create_public_or_global_views(): void
    {
        $this->bootDemo2();
        $this->actingAs($this->createInventoryUser());

        $name = 'Blocked share '.Str::uuid();
        Livewire::test(ListExceptions::class)
            ->set('tableViewForm.name', $name)
            ->set('tableViewForm.is_public', true)
            ->call('saveAsTableView');

        $this->assertNull(TableView::query()->where('name', $name)->first());
    }

    private function bootDemo2(): void
    {
        $this->initializeDemo2Tenant();
        session()->forget('table-views.'.ListExceptions::class);
        session()->forget('table-views.'.ListEpcisDocuments::class);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedExceptionType(): void
    {
        app(ExceptionTypeSeeder::class)->run();
    }

    private function createCase(string $title, ExceptionStatus $status): ExceptionCase
    {
        $typeId = ExceptionType::query()->value('id');
        $this->assertNotNull($typeId);

        $case = ExceptionCase::query()->create([
            'exception_type_id' => $typeId,
            'title' => $title,
            'severity' => ExceptionSeverity::High->value,
            'status' => $status->value,
            'site_id' => Site::query()->value('id'),
        ]);
        $this->caseIds[] = (int) $case->getKey();

        return $case;
    }

    private function createOwnerUser(): User
    {
        $user = User::factory()->create([
            'email' => 'table-views-owner-'.Str::uuid().'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $user->givePermissionTo(Permissions::SitesAccessAll, Permissions::UsersManage);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function createInventoryUser(): User
    {
        $user = User::factory()->create([
            'email' => 'table-views-inv-'.Str::uuid().'@example.test',
        ]);
        $user->assignRole(TenantRole::PharmacyInventoryManager->value);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function trackView(TableView $view): void
    {
        $this->viewIds[] = (int) $view->getKey();
    }

    private function createInboundDocument(string $status): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'creation_date' => now(),
            'received_at' => now(),
            'received_via' => 'filament_upload',
            'status' => $status,
            'dscsa_affirm' => true,
            'event_count' => 0,
            'epc_count' => 0,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
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
