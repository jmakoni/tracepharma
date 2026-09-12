<?php

declare(strict_types=1);

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\ScanInWorkstation;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Sgln;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ManufacturerCmoReceiveTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const RECEIVE_SITE_GLN = '0366159000088';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?int $receivingDocumentId = null;

    private ?int $siteId = null;

    private ?int $userId = null;

    #[Test]
    public function manufacturer_can_access_scan_in_and_receive_but_not_verify_product(): void
    {
        $this->initializeDemo2Tenant();

        $tenant = tenant();

        try {
            $tenant->forceFill(['profile' => TenantProfile::Manufacturer])->save();

            $features = TenantFeatures::forTenant($tenant->fresh());
            $this->assertTrue($features->supportsReceiving());
            $this->assertTrue($features->supportsTransferring());
            $this->assertFalse($features->supportsVrs());
            $this->assertFalse($features->supportsPharmacyOutboundDesk());

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);

            $user = User::factory()->create([
                'email' => 'mfr-cmo-access-'.uniqid().'@example.test',
            ]);
            $this->userId = (int) $user->getKey();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertTrue(ScanInWorkstation::canAccess());
            $this->assertTrue(ReceivingSessionResource::canAccess());
            $this->assertTrue(TransferringSessionResource::canAccess());
            $this->assertFalse(VerifyProduct::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function buying_group_cannot_access_receive_or_scan_in(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $tenant = tenant();
            $tenant->setAttribute('profile', TenantProfile::BuyingGroup);

            $this->assertFalse(TenantFeatures::forTenant(tenant())->supportsReceiving());
            $this->assertFalse(ReceivingSessionResource::canAccess());
            $this->assertFalse(ScanInWorkstation::canAccess());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function wholesaler_receive_access_unchanged(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $tenant = tenant();
            $tenant->setAttribute('profile', TenantProfile::DrugWholesaler);

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsReceiving());
            $this->assertTrue(ReceivingSessionResource::canAccess());
            $this->assertTrue(ScanInWorkstation::canAccess());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function manufacturer_partner_inbound_receive_puts_serials_on_hand_at_site(): void
    {
        $this->initializeDemo2Tenant();

        $tenant = tenant();

        try {
            $tenant->forceFill(['profile' => TenantProfile::Manufacturer])->save();
            $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);

            $user = User::factory()->create([
                'email' => 'mfr-cmo-recv-'.uniqid().'@example.test',
            ]);
            $this->userId = (int) $user->getKey();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $site = Site::query()->create([
                'name' => 'Manufacturer CMO Dock',
                'gln' => self::RECEIVE_SITE_GLN,
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => true,
            ]);
            $this->siteId = (int) $site->getKey();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle(
                $document,
                siteId: $this->siteId,
            );
            $this->sessionId = (int) $session->getKey();
            $this->assertSame($this->siteId, $session->site_id);

            app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                userId: null,
                autoConfirmChildren: true,
            );

            $session->refresh();
            if ($session->status !== 'completed') {
                $session = app(CompleteReceivingSession::class)->handle($session);
            }

            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;

            $event = EpcisEvent::query()
                ->where('document_id', $session->receiving_epcis_document_id)
                ->where('event_type', 'ObjectEvent')
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                ->firstOrFail();

            $expectedGln = Sgln::normalizeGln(self::RECEIVE_SITE_GLN);
            $this->assertSame($expectedGln, $event->biz_location_gln);

            $epc = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $this->assertTrue(
                app(ShippableEpcsAtSite::class)->contains(
                    $this->siteId,
                    (int) $epc->getKey(),
                ),
                'Partner/CMO inbound receive must leave the serial on-hand at the to-site.',
            );
        } finally {
            $this->cleanup($tenant);
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
        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionId !== null) {
                $this->deleteReceivingSessionForIsolation($this->sessionId);
                $this->sessionId = null;
            }

            if ($this->receivingDocumentId !== null) {
                EpcisEvent::query()->where('document_id', $this->receivingDocumentId)->delete();
                EpcisDocument::query()->whereKey($this->receivingDocumentId)->delete();
                $this->receivingDocumentId = null;
            }

            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            if ($this->siteId !== null) {
                Site::query()->whereKey($this->siteId)->delete();
                $this->siteId = null;
            }

            if ($this->userId !== null) {
                User::query()->whereKey($this->userId)->delete();
                $this->userId = null;
            }

            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }
}
