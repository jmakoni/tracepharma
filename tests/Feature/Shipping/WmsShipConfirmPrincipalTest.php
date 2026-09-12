<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Shipping\ProcessWmsShipConfirm;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Principal;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WmsShipConfirmPrincipalTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    private ?bool $priorEnforced = null;

    /** @var list<int> */
    private array $principalIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function resolve_by_principal_external_ref_opens_session_with_that_principal_id(): void
    {
        $this->initializeLogistics3plTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);
            TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced(false);
            tenant()->save();

            $principal = $this->createPrincipal(
                'WMS ExtRef Client',
                externalRef: 'WMS-CLIENT-'.Str::random(8),
            );
            $site = $this->createShipSite(principalId: null);
            $this->makeEpcShippableAtSite($site);

            $result = app(ProcessWmsShipConfirm::class)->handle([
                'site_id' => (int) $site->getKey(),
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'principal_external_ref' => $principal->external_ref,
            ]);

            $this->sessionIds[] = (int) $result['session_id'];

            $session = OutboundShippingSession::query()->find($result['session_id']);
            $this->assertNotNull($session);
            $this->assertSame((int) $principal->getKey(), (int) $session->principal_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function conflicting_principal_id_and_external_ref_throws_domain_exception(): void
    {
        $this->initializeLogistics3plTenant();

        try {
            TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced(false);
            tenant()->save();

            $principalA = $this->createPrincipal('Conflict A', externalRef: 'EXT-A-'.Str::random(6));
            $principalB = $this->createPrincipal('Conflict B', externalRef: 'EXT-B-'.Str::random(6));
            $site = $this->createShipSite(principalId: null);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Conflicting principal identifiers');

            app(ProcessWmsShipConfirm::class)->handle([
                'site_id' => (int) $site->getKey(),
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'principal_id' => (int) $principalA->getKey(),
                'principal_external_ref' => $principalB->external_ref,
            ]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inactive_principal_id_throws_domain_exception(): void
    {
        $this->initializeLogistics3plTenant();

        try {
            TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced(false);
            tenant()->save();

            $principal = $this->createPrincipal('Inactive Id', externalRef: 'EXT-INACTIVE-'.Str::random(6));
            $principal->forceFill(['is_active' => false])->save();
            $site = $this->createShipSite(principalId: null);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Unknown or inactive principal_id');

            app(ProcessWmsShipConfirm::class)->handle([
                'site_id' => (int) $site->getKey(),
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'principal_id' => (int) $principal->getKey(),
            ]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function enforced_mode_rejects_when_no_principal_and_no_site_default(): void
    {
        $this->initializeLogistics3plTenant();

        try {
            TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced(true);
            tenant()->save();

            $site = $this->createShipSite(principalId: null);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Principal custody is enforced');

            app(ProcessWmsShipConfirm::class)->handle([
                'site_id' => (int) $site->getKey(),
                'scans' => [self::SSCC_URI],
                'complete' => false,
            ]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function soft_mode_omitting_principal_opens_session_with_null_or_site_default(): void
    {
        $this->initializeLogistics3plTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);
            TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced(false);
            tenant()->save();

            $defaultPrincipal = $this->createPrincipal('Site Default Soft');
            $siteWithDefault = $this->createShipSite(principalId: (int) $defaultPrincipal->getKey());
            $this->makeEpcShippableAtSite($siteWithDefault);

            $withDefault = app(ProcessWmsShipConfirm::class)->handle([
                'site_id' => (int) $siteWithDefault->getKey(),
                'scans' => [self::SSCC_URI],
                'complete' => false,
            ]);
            $this->sessionIds[] = (int) $withDefault['session_id'];

            $sessionWithDefault = OutboundShippingSession::query()->find($withDefault['session_id']);
            $this->assertNotNull($sessionWithDefault);
            $this->assertSame((int) $defaultPrincipal->getKey(), (int) $sessionWithDefault->principal_id);

            $siteWithoutDefault = $this->createShipSite(principalId: null, name: 'WMS Soft Null Site');
            $secondSscc = 'urn:epc:id:sscc:030116.01001227061';
            $this->makeEpcShippableAtSite($siteWithoutDefault, $secondSscc);

            $withoutDefault = app(ProcessWmsShipConfirm::class)->handle([
                'site_id' => (int) $siteWithoutDefault->getKey(),
                'scans' => [$secondSscc],
                'complete' => false,
            ]);
            $this->sessionIds[] = (int) $withoutDefault['session_id'];

            $sessionWithoutDefault = OutboundShippingSession::query()->find($withoutDefault['session_id']);
            $this->assertNotNull($sessionWithoutDefault);
            $this->assertNull($sessionWithoutDefault->principal_id);
        } finally {
            $this->cleanup();
        }
    }

    private function createPrincipal(string $name, ?string $externalRef = null, ?string $gln = null): Principal
    {
        $principal = Principal::query()->create([
            'name' => $name.' '.Str::random(4),
            'external_ref' => $externalRef,
            'gln' => $gln,
            'is_active' => true,
        ]);
        $this->principalIds[] = (int) $principal->getKey();

        return $principal;
    }

    private function createShipSite(?int $principalId = null, string $name = 'WMS Principal Ship Site'): Site
    {
        $siteGln = '0366159000'.random_int(100, 999);

        $site = Site::query()->create([
            'name' => $name.' '.Str::random(4),
            'gln' => $siteGln,
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'principal_id' => $principalId,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        TenantSettings::forTenant(tenant())->saveOrganization([
            'gln' => $siteGln,
            'company_prefix' => '036615',
            'default_ship_from_site_id' => (int) $site->getKey(),
            'default_receive_site_id' => (int) $site->getKey(),
        ]);

        return $site;
    }

    private function makeEpcShippableAtSite(Site $site, string $ssccUri = self::SSCC_URI): void
    {
        $document = $this->ingestMinimalFixture($ssccUri);
        $this->documentIds[] = (int) $document->getKey();

        $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
        $this->receivingSessionIds[] = (int) $session->getKey();
        $session->forceFill(['site_id' => (int) $site->getKey()])->save();

        app(ConfirmReceivingScan::class)->handle(
            $session->fresh(),
            $ssccUri,
            userId: null,
            autoConfirmChildren: true,
        );

        $session = $session->fresh();
        $session->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        if ($session->receiving_epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->receiving_epcis_document_id;
        }
    }

    private function ingestMinimalFixture(string $ssccUri = self::SSCC_URI): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        $xml = str_replace(self::SSCC_URI, $ssccUri, $xml);
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

    private function initializeLogistics3plTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo 3PL',
                'profile' => TenantProfile::Logistics3pl,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => TenantProfile::Logistics3pl])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        $this->priorEnforced = TenantSettings::forTenant(tenant())->principalCustodyEnforced();

        OutboundShippingSession::query()->whereIn('status', ['open', 'in_progress'])->delete();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionIds !== []) {
                OutboundShippingSession::query()->whereKey($this->sessionIds)->delete();
                $this->sessionIds = [];
            }

            if ($this->receivingSessionIds !== []) {
                ReceivingSession::query()->whereKey($this->receivingSessionIds)->delete();
                $this->receivingSessionIds = [];
            }

            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereKey($this->documentIds)->delete();
                $this->documentIds = [];
            }

            if ($this->siteIds !== []) {
                OutboundShippingSession::query()->whereIn('site_id', $this->siteIds)->delete();
                Site::query()->whereKey($this->siteIds)->update(['principal_id' => null]);
                Site::query()->whereKey($this->siteIds)->delete();
                $this->siteIds = [];
            }

            if ($this->principalIds !== []) {
                Principal::query()->whereKey($this->principalIds)->delete();
                $this->principalIds = [];
            }

            if ($this->priorEnforced !== null) {
                TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced($this->priorEnforced);
                tenant()->save();
            }
        }

        if ($this->priorProfile !== null) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                $tenant->forceFill(['profile' => $this->priorProfile])->save();
            }
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorProfile = null;
        $this->priorEnforced = null;
    }
}
