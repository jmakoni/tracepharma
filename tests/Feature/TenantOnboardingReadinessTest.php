<?php

namespace Tests\Feature;

use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\OutboundConnection;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Actions\MasterData\AssignMissingDefaultSites;
use App\Support\TenantOnboarding;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantOnboardingReadinessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SITE_GLN = '0366159000034';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?int $shippingDocumentId = null;

    private ?int $shippingSessionId = null;

    private mixed $priorOutboundDeferredAt = null;

    #[Test]
    public function wholesaler_is_critical_complete_with_org_gln_and_default_sites(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
            ]);
            $tenant->save();

            $site = Site::query()->create([
                'name' => 'Onboarding HQ '.Str::random(6),
                'gln' => self::SITE_GLN,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
                'default_receive_site_id' => (int) $site->getKey(),
                'default_ship_from_site_id' => (int) $site->getKey(),
            ]);

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $byId = $this->itemsById($onboarding->items());

            $this->assertTrue($onboarding->isCriticalComplete());
            $this->assertTrue($onboarding->isComplete());
            $this->assertSame(100, $onboarding->criticalScore());
            $this->assertTrue($byId['org_gln']['done']);
            $this->assertTrue($byId['default_receive_site']['done']);
            $this->assertTrue($byId['default_ship_from_site']['done']);
            $this->assertContains('downstream_partner', array_keys($byId));
            $this->assertContains('outbound_configured', array_keys($byId));
            $this->assertContains('receive_proven', array_keys($byId));
            $this->assertContains('atp_ready', array_keys($byId));
            $this->assertSame(
                'Upstream partner ATP ready for receiving state',
                $byId['atp_ready']['label'],
            );
            // Org facilities never carry catalog ATP — without an upstream partner site Ready,
            // the ATP checklist item stays open even when critical GLN/sites are done.
            $this->assertFalse($byId['atp_ready']['done']);
            $this->assertFalse($onboarding->isUpstreamAtpSatisfied());
            $this->assertGreaterThan(0, $onboarding->score());
            $this->assertLessThan(100, $onboarding->score());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pharmacy_checklist_is_thinner_and_critical_with_receive_site_only(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
            ]);
            $tenant->save();

            $site = Site::query()->create([
                'name' => 'Pharmacy Receive '.Str::random(6),
                'gln' => self::SITE_GLN,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
                'default_receive_site_id' => (int) $site->getKey(),
                'default_ship_from_site_id' => null,
            ]);

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $ids = array_column($onboarding->items(), 'id');
            $byId = $this->itemsById($onboarding->items());

            $this->assertContains('org_gln', $ids);
            $this->assertContains('default_receive_site', $ids);
            $this->assertContains('receive_proven', $ids);
            $this->assertNotContains('default_ship_from_site', $ids);
            $this->assertNotContains('downstream_partner', $ids);
            $this->assertNotContains('outbound_configured', $ids);
            $this->assertTrue($onboarding->isCriticalComplete());
            $this->assertTrue($onboarding->isComplete());
            $this->assertSame(100, $onboarding->criticalScore());
            $this->assertTrue($byId['org_gln']['done']);
            $this->assertTrue($byId['default_receive_site']['done']);
            // Recommended % may already be 100 on seeded demo tenants; critical is the go-live gate.
            $this->assertGreaterThanOrEqual(0, $onboarding->score());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function receive_proven_is_done_when_completed_session_has_site_id(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
            ]);
            $tenant->save();

            $site = Site::query()->create([
                'name' => 'Receive Proven Site '.Str::random(6),
                'gln' => self::SITE_GLN,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'validated',
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'site_id' => $site->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $byId = $this->itemsById($onboarding->items());

            $this->assertTrue($byId['receive_proven']['done']);
            $this->assertNotEmpty($byId['receive_proven']['href'] ?? null);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_proven_is_incomplete_without_a_completed_shipping_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $byId = $this->itemsById($onboarding->items());

            $this->assertContains('ship_proven', array_keys($byId));
            $this->assertFalse($byId['ship_proven']['done']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_proven_is_done_when_completed_session_has_epcis_document(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            $site = Site::query()->create([
                'name' => 'Ship Proven Site '.Str::random(6),
                'gln' => self::SITE_GLN,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'status' => 'validated',
                'received_at' => now(),
            ]);
            $this->shippingDocumentId = (int) $document->getKey();

            $session = OutboundShippingSession::query()->create([
                'site_id' => $site->getKey(),
                'epcis_document_id' => $document->getKey(),
                'status' => 'completed',
                'expected_count' => 0,
                'confirmed_count' => 0,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->shippingSessionId = (int) $session->getKey();

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $byId = $this->itemsById($onboarding->items());

            $this->assertTrue($byId['ship_proven']['done']);
            $this->assertNotEmpty($byId['ship_proven']['href'] ?? null);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function outbound_configured_soft_completes_when_choreography_deferred(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            $settings = TenantSettings::forTenant($tenant);
            $settings->acknowledgeOutboundDeferred();
            $tenant->save();

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $byId = $this->itemsById($onboarding->items());

            $this->assertContains('outbound_configured', array_keys($byId));
            $this->assertTrue($byId['outbound_configured']['done']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function outbound_configured_is_done_when_active_outbound_connection_exists(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            OutboundConnection::query()->create([
                'name' => 'Test Outbound',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://example.test/epcis'],
            ]);

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $byId = $this->itemsById($onboarding->items());

            $this->assertTrue($byId['outbound_configured']['done']);
            $this->assertNotEmpty($byId['outbound_configured']['href'] ?? null);
        } finally {
            if (tenancy()->initialized) {
                OutboundConnection::query()->where('name', 'Test Outbound')->delete();
            }
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function creating_org_facility_with_gln_assigns_missing_default_sites(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DentalMedicalSupply);

            $live = tenant();
            TenantSettings::forTenant($live)
                ->setGln('0366159000010')
                ->setCompanyPrefix('0366159')
                ->setDefaultReceiveSiteId(null)
                ->setDefaultShipFromSiteId(null);
            $live->save();
            tenancy()->initialize($live->fresh());

            $site = Site::withoutEvents(fn () => Site::query()->create([
                'name' => 'Onboarding HQ '.Str::random(6),
                'gln' => '0366159'.str_pad((string) random_int(100000, 999999), 6, '0'),
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => true,
            ]));
            $this->siteIds[] = (int) $site->getKey();

            $this->assertNull(TenantSettings::forTenant(tenant())->defaultReceiveSiteId());

            app(AssignMissingDefaultSites::class)->handle($site);

            $settings = TenantSettings::forTenant(tenant());
            $onboarding = TenantOnboarding::forTenant(tenant());
            $byId = $this->itemsById($onboarding->items());

            $this->assertSame((int) $site->getKey(), $settings->defaultReceiveSiteId());
            $this->assertSame((int) $site->getKey(), $settings->defaultShipFromSiteId());
            $this->assertTrue($byId['default_receive_site']['done']);
            $this->assertTrue($byId['default_ship_from_site']['done']);

            TenantSettings::forTenant(tenant())
                ->setDefaultReceiveSiteId(null)
                ->setDefaultShipFromSiteId(null);
            tenant()->save();
            tenancy()->initialize(tenant()->fresh());

            app(AssignMissingDefaultSites::class)->healFromExistingSites();

            $this->assertNotNull(TenantSettings::forTenant(tenant())->defaultReceiveSiteId());
            $this->assertNotNull(TenantSettings::forTenant(tenant())->defaultShipFromSiteId());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function critical_incomplete_when_org_gln_set_but_default_sites_missing(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
                'default_receive_site_id' => null,
                'default_ship_from_site_id' => null,
            ]);

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $byId = $this->itemsById($onboarding->items());

            $this->assertFalse($onboarding->isCriticalComplete());
            $this->assertTrue($byId['org_gln']['done']);
            $this->assertFalse($byId['default_receive_site']['done']);
            $this->assertFalse($byId['default_ship_from_site']['done']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @param  list<array{id: string, label: string, done: bool, href?: string}>  $items
     * @return array<string, array{id: string, label: string, done: bool, href?: string}>
     */
    private function itemsById(array $items): array
    {
        $byId = [];

        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }

        return $byId;
    }

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        $tenant->forceFill(['profile' => $profile])->save();
        tenancy()->end();
        tenancy()->initialize($tenant->fresh());
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

        $settings = TenantSettings::forTenant($tenant);
        $this->priorProfile = $tenant->profile;
        $this->priorGln = $settings->gln();
        $this->priorCompanyPrefix = $settings->companyPrefix();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorOutboundDeferredAt = $settings->outboundChoreographyDeferredAt();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionId !== null) {
                ReceivingSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            if ($this->shippingSessionId !== null) {
                OutboundShippingSession::query()->whereKey($this->shippingSessionId)->delete();
                $this->shippingSessionId = null;
            }

            if ($this->shippingDocumentId !== null) {
                EpcisDocument::query()->whereKey($this->shippingDocumentId)->delete();
                $this->shippingDocumentId = null;
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            $restored = $tenant->fresh() ?? $tenant;
            if ($this->priorProfile !== null) {
                $restored->forceFill(['profile' => $this->priorProfile])->save();
            }

            $settings = TenantSettings::forTenant($restored);
            $settings->saveOrganization([
                'gln' => $this->priorGln,
                'company_prefix' => $this->priorCompanyPrefix,
                'default_receive_site_id' => $this->priorDefaultReceiveSiteId,
                'default_ship_from_site_id' => $this->priorDefaultShipFromSiteId,
            ]);
            $settings->setOutboundChoreographyDeferredAt($this->priorOutboundDeferredAt);
            $restored->save();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorProfile = null;
        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
        $this->priorDefaultReceiveSiteId = null;
        $this->priorDefaultShipFromSiteId = null;
        $this->priorOutboundDeferredAt = null;
    }
}
