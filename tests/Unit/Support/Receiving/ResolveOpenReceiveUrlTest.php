<?php

namespace Tests\Unit\Support\Receiving;

use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\ResolveOpenReceiveUrl;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveOpenReceiveUrlTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function has_context_and_url_when_epc_is_on_open_session_scan_line(): void
    {
        $this->initializeDemo2Tenant();

        $sessionId = null;
        $epcId = null;

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $sessionId = (int) $session->getKey();

            $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200OPEN1';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $epcId = (int) $epc->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'expected',
            ]);

            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;
            $resolver = app(ResolveOpenReceiveUrl::class);

            $this->assertTrue($resolver->hasContext($barcode));

            $url = $resolver->handle($barcode);
            $this->assertNotNull($url);
            $this->assertStringContainsString(
                'receiving-sessions/'.$session->getKey(),
                $url,
            );
            $this->assertStringContainsString('scan=', $url);
        } finally {
            if ($epcId !== null) {
                ReceivingScanLine::query()->where('epc_id', $epcId)->delete();
                Epc::query()->whereKey($epcId)->delete();
            }

            if ($sessionId !== null) {
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            tenancy()->end();
        }
    }

    #[Test]
    public function has_no_context_for_unknown_barcode(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $resolver = app(ResolveOpenReceiveUrl::class);

            $this->assertFalse($resolver->hasContext('UNKNOWN-LABEL-XYZ'));
            $this->assertNull($resolver->handle('UNKNOWN-LABEL-XYZ'));
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function resolved_url_matches_receiving_session_resource_view(): void
    {
        $this->initializeDemo2Tenant();

        $sessionId = null;
        $epcId = null;

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $sessionId = (int) $session->getKey();

            $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200OPEN2';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $epcId = (int) $epc->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            $barcode = (string) $epc->epc_uri;
            $url = app(ResolveOpenReceiveUrl::class)->handle($barcode);

            $expected = ReceivingSessionResource::getUrl('view', [
                'record' => $session,
                'scan' => $barcode,
            ], panel: 'app');

            $this->assertSame($expected, $url);
        } finally {
            if ($epcId !== null) {
                ReceivingScanLine::query()->where('epc_id', $epcId)->delete();
                Epc::query()->whereKey($epcId)->delete();
            }

            if ($sessionId !== null) {
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            tenancy()->end();
        }
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

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
}
