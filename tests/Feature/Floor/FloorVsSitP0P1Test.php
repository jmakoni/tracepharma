<?php

namespace Tests\Feature\Floor;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\FloorFind;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Floor\FloorRouteMap;
use App\Support\Floor\FloorTaskMenu;
use App\Support\Receiving\OutstandingReceiveTargets;
use App\Support\Receiving\ReceivingSessionProgress;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

/**
 * Floor-vs-SIT P0/P1 closures: G-LAT-1, G-HUD-1, G-FIND, G3.
 * G-LAT-2 cover behavior covered by ReceivingEdgeModeParityTest::case_only_covering_*.
 */
class FloorVsSitP0P1Test extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function g_lat_1_sealed_pallet_parent_scan_sets_expected_child_count_without_child_roster(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);

            $result = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                userId: null,
                autoConfirmChildren: true,
            );

            $this->assertTrue($result['ok'], (string) ($result['message'] ?? ''));
            $this->assertSame('parent_confirmed', $result['effect']);
            $this->assertSame(1, $result['parent_expected_children']);
            $this->assertSame(1, $result['parent_confirmed_children']);
            $this->assertNotEmpty($result['parent_child_uom']);
            $this->assertStringContainsString(
                sprintf('1 of 1 %s', $result['parent_child_uom']),
                (string) $result['message'],
            );

            $session->refresh();
            $this->assertSame(1, $session->expected_child_count);
            $this->assertSame(1, $session->confirmed_child_count);

            $outstanding = app(OutstandingReceiveTargets::class)->forSession($session);
            foreach ($outstanding['rows'] as $row) {
                $this->assertSame('SSCC', $row['type']);
            }
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('line_role', 'child')
                    ->where('status', 'expected')
                    ->count(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function g_hud_1_progress_chip_uses_parent_local_n_of_m_after_confirm(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $result = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                userId: null,
                autoConfirmChildren: true,
            );

            $this->assertTrue($result['ok'], (string) ($result['message'] ?? ''));

            $progress = ReceivingSessionProgress::for(
                $session->fresh(),
                focusChildConfirmed: (int) $result['parent_confirmed_children'],
                focusChildExpected: (int) $result['parent_expected_children'],
                focusChildUom: (string) $result['parent_child_uom'],
            );

            $this->assertSame('1/1', $progress->childProgressQuantity());
            $this->assertSame('1/1 '.$result['parent_child_uom'], $progress->childProgressChipLabel());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function g_find_floor_find_uses_floor_shell_layout(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $this->assertTrue(FloorFind::canAccess());
            $this->assertSame('find', FloorFind::getSlug());
            $this->assertTrue(FloorRouteMap::isFloorPath('/find'));
            $this->assertSame('layouts.floor-shell', (new FloorFind)->getLayout());

            $url = FloorFind::getUrl(panel: 'app');
            $this->assertStringContainsString('/find', parse_url($url, PHP_URL_PATH) ?? '');

            Livewire::test(FloorFind::class)
                ->assertSuccessful()
                ->assertSee('Find')
                ->assertSeeHtml('tp-floor-find')
                ->assertSeeHtml('dock dock-md');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function g3_launcher_tiles_omit_returns_destroy_commission(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $keys = array_column(FloorTaskMenu::launcherTiles(), 'key');
            $this->assertNotContains('returns', $keys);
            $this->assertNotContains('destroy', $keys);
            $this->assertNotContains('commission', $keys);
        } finally {
            $this->cleanup();
        }
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'floor-vs-sit-'.uniqid('', true).'@example.test',
        ]);
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

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentId !== null) {
            $sessionIds = ReceivingSession::query()
                ->where('epcis_document_id', $this->documentId)
                ->pluck('id');
            if ($sessionIds->isNotEmpty()) {
                ReceivingScanLine::query()->whereIn('receiving_session_id', $sessionIds)->delete();
                ReceivingSession::query()->whereIn('id', $sessionIds)->delete();
            }
            DB::table('epcis_events')->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
