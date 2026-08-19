<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\CreateReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\Pages\ListReceivingSessions;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\RelationManagers\ScanLinesRelationManager;
use App\Filament\App\Resources\ReceivingSessions\Tables\ReceivingSessionsTable;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceivingSessionResourceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $sessionId = null;

    #[Test]
    public function pharmacy_can_access_receiving_session_resource(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsReceiving());
            $this->assertTrue(ReceivingSessionResource::canAccess());
            $this->assertTrue(ReceivingSessionResource::canCreate());
            $this->assertSame('Receive', ReceivingSessionResource::getNavigationLabel());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function resource_pages_slug_and_relations_are_registered(): void
    {
        $pages = ReceivingSessionResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('create', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertArrayHasKey('floor', $pages);
        $this->assertSame('receiving-sessions', ReceivingSessionResource::getSlug());
        $this->assertSame(['index', 'create', 'view', 'floor'], array_keys($pages));
        $this->assertContains(ScanLinesRelationManager::class, ReceivingSessionResource::getRelations());
    }

    #[Test]
    public function list_and_view_urls_resolve_for_open_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('receiving_sessions'));

            $document = $this->demo2ReceivableDocument();
            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            $table = ReceivingSessionsTable::configure(Table::make(new ListReceivingSessions));
            $columnNames = collect($table->getColumns())->map(fn ($column) => $column->getName())->all();
            $this->assertContains('session_kind', $columnNames);
            $this->assertContains('site.name', $columnNames);
            $this->assertContains('status', $columnNames);
            $this->assertContains('openedByUser.name', $columnNames);
            $this->assertContains('source', $columnNames);
            $this->assertContains('opened_at', $columnNames);
            $filterNames = collect($table->getFilters())->map(fn ($filter) => $filter->getName())->all();
            $this->assertContains('session_kind', $filterNames);
            $this->assertContains('status', $filterNames);
            $this->assertContains('site_id', $filterNames);
            $this->assertContains('completed_at', $columnNames);

            Filament::setCurrentPanel(Filament::getPanel('app'));

            $indexUrl = ReceivingSessionResource::getUrl('index', panel: 'app');
            $this->assertStringContainsString('receiving-sessions', $indexUrl);

            $viewUrl = ReceivingSessionResource::getUrl('view', ['record' => $session], panel: 'app');
            $this->assertStringContainsString('receiving-sessions/'.$session->getKey(), $viewUrl);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_session_view_renders_scan_form_without_duplicate_status_badges(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $document = $this->demo2ReceivableDocument();
            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()]);

            $component->assertSee('Scan barcode');
            $component->assertSee('ADD');
            $component->assertDontSee('Camera');
            $component->assertDontSee('Sealed tote/case');
            $component->assertDontSee('Receiving complete');
            $this->assertTrue($component->instance()->autoConfirmChildren);

            // Status is shown once in the HUD badge; the duplicate subheading badges
            // and the infolist's own status/pallets/units entries are gone.
            $this->assertNull($component->instance()->getSubheading());

            $desktopBlade = \Illuminate\Support\Facades\File::get(resource_path(
                'views/filament/app/resources/receiving-sessions/pages/view-receiving-session.blade.php',
            ));
            $this->assertStringContainsString('scan-field', $desktopBlade);
            $this->assertStringContainsString('show-camera="false"', $desktopBlade);
            $this->assertStringContainsString("submit-action=\"confirmScan\"", $desktopBlade);
            $this->assertStringNotContainsString('stageScan', $desktopBlade);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function completed_session_view_shows_summary_and_hides_scan_form(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $document = $this->demo2ReceivableDocument();
            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $component = Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()]);

            $component->assertSee('Receiving complete');
            $component->assertDontSee('Scan barcode');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function create_scan_first_page_opens_session_and_redirects_to_view(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $site = EligibleReceiveSites::forOrganization()->orderBy('id')->first();

            if ($site === null) {
                $site = Site::query()->create([
                    'name' => 'Scan-first UI Test Site',
                    'gln' => '0366159000096',
                    'is_active' => true,
                    'is_headquarters' => true,
                    'is_organization_facility' => true,
                    'trading_partner_id' => null,
                ]);
            }

            $beforeIds = ReceivingSession::query()->pluck('id')->all();

            Livewire::test(CreateReceivingSession::class)
                ->fillForm([
                    'site_id' => $site->getKey(),
                    'notes' => 'Dock notes',
                ])
                ->call('create')
                ->assertHasNoFormErrors()
                ->assertRedirect();

            $session = ReceivingSession::query()
                ->whereNotIn('id', $beforeIds)
                ->latest('id')
                ->first();

            $this->assertNotNull($session);
            $this->sessionId = (int) $session->getKey();
            $this->assertSame(ReceivingSessionKind::ScanFirst, $session->session_kind);
            $this->assertSame((int) $site->getKey(), (int) $session->site_id);
            $this->assertNull($session->epcis_document_id);

            $this->assertStringContainsString(
                'receiving-sessions/'.$session->getKey(),
                ReceivingSessionResource::getUrl('view', ['record' => $session], panel: 'app'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function scan_first_view_shows_kind_hud_without_asn_only_copy(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSee('Scan-first')
                ->assertSee('ADD')
                ->assertDontSee('Not on this ASN — do not put away')
                ->assertSee('no ASN required');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function confirm_scan_action_is_not_password_gated_when_gate_enabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => true]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $document = $this->demo2ReceivableDocument();
            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()]);
            $action = $component->instance()->confirmScanAction();

            $this->assertFalse(
                $action->isConfirmationRequired(),
                'Receiving Confirm scan must not require regulatory password confirmation.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function create_is_ungated_while_complete_receive_requires_confirmation_when_gate_enabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => true]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $createPage = Livewire::test(CreateReceivingSession::class);
            $createAction = (new \ReflectionMethod(CreateReceivingSession::class, 'getCreateFormAction'))
                ->invoke($createPage->instance());

            $this->assertFalse(
                $createAction->isConfirmationRequired(),
                'Opening a scan-first receive session must not require regulatory password confirmation.',
            );

            $session = app(OpenScanFirstReceivingSession::class)->handle(
                openedBy: (int) $user->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $view = Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()]);
            $complete = $view->instance()->getAction('completeReceiving');

            $this->assertTrue(
                $complete->isConfirmationRequired(),
                'Complete receive must require confirmation when submitting scanned data.',
            );
        } finally {
            $this->cleanup();
        }
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

    private function demo2ReceivableDocument(): EpcisDocument
    {
        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $statuses = $requireValidated ? ['validated'] : ['parsed', 'validated'];

        $document = EpcisDocument::query()
            ->whereIn('status', $statuses)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull(
            $document,
            $requireValidated
                ? 'Demo2 needs a validated inbound EPCIS document.'
                : 'Demo2 needs a parsed/validated inbound EPCIS document.',
        );

        return $document;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionId !== null) {
                ReceivingSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            tenancy()->end();
        }
    }
}
