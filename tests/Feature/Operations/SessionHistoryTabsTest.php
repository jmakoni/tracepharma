<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\ListReceivingSessions;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\Tables\ReceivingSessionsTable;
use App\Filament\App\Resources\TransferringSessions\Pages\ListTransferringSessions;
use App\Filament\App\Resources\TransferringSessions\Tables\TransferringSessionsTable;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionHistoryTabsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $transferSessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function transfer_and_receive_lists_default_to_active_tab(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            Livewire::test(ListTransferringSessions::class)
                ->assertSet('activeTab', 'active');

            Livewire::test(ListReceivingSessions::class)
                ->assertSet('activeTab', 'active');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function transfer_history_tab_hides_active_sessions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            [$from, $to] = $this->createOwnedSites();
            $user = $this->createOwnerUser();
            $this->actingAs($user);
            session()->forget(CurrentSite::SESSION_KEY);

            $open = TransferringSession::query()->create([
                'from_site_id' => $from->getKey(),
                'to_site_id' => $to->getKey(),
                'status' => 'open',
                'confirmed_count' => 0,
                'received_count' => 0,
                'opened_at' => now(),
                'opened_by' => $user->getKey(),
            ]);
            $completed = TransferringSession::query()->create([
                'from_site_id' => $from->getKey(),
                'to_site_id' => $to->getKey(),
                'status' => 'completed',
                'confirmed_count' => 1,
                'received_count' => 1,
                'opened_at' => now()->subDay(),
                'shipped_at' => now()->subDay(),
                'received_at' => now()->subHour(),
                'completed_at' => now()->subHour(),
                'opened_by' => $user->getKey(),
            ]);
            $this->transferSessionIds = [(int) $open->getKey(), (int) $completed->getKey()];

            Livewire::test(ListTransferringSessions::class)
                ->set('tableFilters.site.value', null)
                ->assertCanSeeTableRecords([$open])
                ->assertCanNotSeeTableRecords([$completed])
                ->set('activeTab', 'history')
                ->assertCanSeeTableRecords([$completed])
                ->assertCanNotSeeTableRecords([$open]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function receive_history_tab_hides_active_sessions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            [$siteA] = $this->createOwnedSites();
            $user = $this->createOwnerUser();
            $this->actingAs($user);
            session()->forget(CurrentSite::SESSION_KEY);

            $open = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::ScanFirst,
                'site_id' => $siteA->getKey(),
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $completed = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::ScanFirst,
                'site_id' => $siteA->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now()->subDay(),
                'completed_at' => now()->subHour(),
            ]);
            $this->receivingSessionIds = [(int) $open->getKey(), (int) $completed->getKey()];

            Livewire::test(ListReceivingSessions::class)
                ->set('tableFilters.site_id.value', null)
                ->assertCanSeeTableRecords([$open])
                ->assertCanNotSeeTableRecords([$completed])
                ->set('activeTab', 'history')
                ->assertCanSeeTableRecords([$completed])
                ->assertCanNotSeeTableRecords([$open]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function transfer_list_is_visible_from_or_to_site_for_scoped_user(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $siteC = Site::factory()->owned()->create([
                'name' => 'Site C '.Str::random(6),
                'gln' => $this->uniqueGln(),
                'is_active' => true,
                'is_headquarters' => false,
            ]);
            $this->siteIds[] = (int) $siteC->getKey();

            $visible = TransferringSession::query()->create([
                'from_site_id' => $siteA->getKey(),
                'to_site_id' => $siteB->getKey(),
                'status' => 'completed',
                'confirmed_count' => 1,
                'received_count' => 1,
                'opened_at' => now()->subDay(),
                'completed_at' => now(),
            ]);
            $hidden = TransferringSession::query()->create([
                'from_site_id' => $siteB->getKey(),
                'to_site_id' => $siteC->getKey(),
                'status' => 'completed',
                'confirmed_count' => 1,
                'received_count' => 1,
                'opened_at' => now()->subDay(),
                'completed_at' => now(),
            ]);
            $this->transferSessionIds = [(int) $visible->getKey(), (int) $hidden->getKey()];

            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            $ids = TransferringSessionResource::getEloquentQuery()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $this->assertContains((int) $visible->getKey(), $ids);
            $this->assertNotContains((int) $hidden->getKey(), $ids);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function transfer_receive_cross_link_urls_resolve(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            [$from, $to] = $this->createOwnedSites();

            $transfer = TransferringSession::query()->create([
                'from_site_id' => $from->getKey(),
                'to_site_id' => $to->getKey(),
                'status' => 'in_transit',
                'confirmed_count' => 1,
                'received_count' => 0,
                'opened_at' => now(),
                'shipped_at' => now(),
            ]);
            $receive = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::TransferReceive,
                'site_id' => $to->getKey(),
                'transferring_session_id' => $transfer->getKey(),
                'status' => 'open',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->transferSessionIds[] = (int) $transfer->getKey();
            $this->receivingSessionIds[] = (int) $receive->getKey();

            $this->assertStringContainsString(
                'receiving-sessions/'.$receive->getKey(),
                ReceivingSessionResource::getUrl('view', ['record' => $receive], panel: 'app'),
            );
            $this->assertStringContainsString(
                'transferring-sessions/'.$transfer->getKey(),
                TransferringSessionResource::getUrl('view', ['record' => $transfer], panel: 'app'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function tables_expose_history_filters_and_current_site_default(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA] = $this->createOwnedSites();
            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);
            CurrentSite::set((int) $siteA->getKey());

            $transferTable = TransferringSessionsTable::configure(Table::make(new ListTransferringSessions));
            $transferFilters = collect($transferTable->getFilters())->map(fn ($f) => $f->getName())->all();
            $this->assertContains('status', $transferFilters);
            $this->assertContains('site', $transferFilters);
            $this->assertContains('opened_at', $transferFilters);
            $this->assertContains('completed_at', $transferFilters);
            $this->assertSame((int) $siteA->getKey(), $transferTable->getFilters()['site']->getDefaultState());

            $receiveTable = ReceivingSessionsTable::configure(Table::make(new ListReceivingSessions));
            $receiveFilters = collect($receiveTable->getFilters())->map(fn ($f) => $f->getName())->all();
            $this->assertContains('session_kind', $receiveFilters);
            $this->assertContains('status', $receiveFilters);
            $this->assertContains('site_id', $receiveFilters);
            $this->assertSame((int) $siteA->getKey(), $receiveTable->getFilters()['site_id']->getDefaultState());

            $transferColumns = collect($transferTable->getColumns())->map(fn ($c) => $c->getName())->all();
            $this->assertContains('receivingSession.id', $transferColumns);
            $this->assertContains('shipped_at', $transferColumns);
        } finally {
            session()->forget(CurrentSite::SESSION_KEY);
            $this->cleanup();
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'Hist A '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Hist B '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
        ]);
        $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

        return [$siteA, $siteB];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

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

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->receivingSessionIds !== []) {
            ReceivingSession::query()->whereIn('id', $this->receivingSessionIds)->delete();
            $this->receivingSessionIds = [];
        }

        if ($this->transferSessionIds !== []) {
            TransferringSession::query()->whereIn('id', $this->transferSessionIds)->delete();
            $this->transferSessionIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        tenancy()->end();
    }
}
