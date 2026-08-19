<?php

namespace Tests\Unit\Support\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShippableEpcsAtSiteTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    private ?int $siteId = null;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?int $receivingDocumentId = null;

    #[Test]
    public function returns_epc_after_receiving_event_at_site(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $siteGln = '0366159000034';

            $site = Site::query()->create([
                'name' => 'Shippable Site '.Str::random(6),
                'gln' => $siteGln,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteId = (int) $site->getKey();

            TenantSettings::forTenant(tenant())->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
                'default_receive_site_id' => $this->siteId,
            ]);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            $session->forceFill(['site_id' => $this->siteId])->save();

            app(ConfirmReceivingScan::class)->handle($session->fresh(), self::SSCC_URI, userId: null, autoConfirmChildren: true);

            $session = $session->fresh();
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;

            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();

            $ids = app(ShippableEpcsAtSite::class)->epcIds($this->siteId);

            $this->assertContains((int) $epc->getKey(), $ids);
        } finally {
            $this->cleanup();
            tenancy()->end();
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
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => basename($fixture),
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

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->receivingDocumentId !== null) {
            EpcisDocument::query()->whereKey($this->receivingDocumentId)->delete();
            $this->receivingDocumentId = null;
        }

        if ($this->sessionId !== null) {
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->documentId !== null) {
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if ($this->siteId !== null) {
            Site::query()->whereKey($this->siteId)->delete();
            $this->siteId = null;
        }
    }
}
