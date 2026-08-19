<?php

namespace Tests\Feature\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Shipping\DetectOpenParentHierarchyOnShip;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetectOpenParentHierarchyOnShipTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    #[Test]
    public function warns_when_confirmed_child_has_open_parent_not_on_ship(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $document = $this->ingestMinimalFixture();
            $this->documentIds[] = (int) $document->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            OutboundShippingScanLine::query()->create([
                'outbound_shipping_session_id' => $session->getKey(),
                'epc_id' => $child->getKey(),
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => self::SGTIN_URI,
                'confirmed_at' => now(),
            ]);

            $detection = app(DetectOpenParentHierarchyOnShip::class)->handle($session->fresh());

            $this->assertTrue($detection['unexpected']);
            $this->assertContains((int) $parent->getKey(), $detection['open_parent_epc_ids']);
            $this->assertContains((int) $child->getKey(), $detection['affected_child_epc_ids']);

            OutboundShippingScanLine::query()->create([
                'outbound_shipping_session_id' => $session->getKey(),
                'epc_id' => $parent->getKey(),
                'line_role' => 'parent',
                'status' => 'confirmed',
                'scan_raw' => self::SSCC_URI,
                'confirmed_at' => now(),
            ]);

            $cleared = app(DetectOpenParentHierarchyOnShip::class)->handle($session->fresh());
            $this->assertFalse($cleared['unexpected']);
            $this->assertSame([], $cleared['open_parent_epc_ids']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function initializeWholesalerTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
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

        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function createShipSite(Tenant $tenant): Site
    {
        $liveTenant = tenant() instanceof Tenant ? tenant() : $tenant;
        $settings = TenantSettings::forTenant($liveTenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }

        $siteGln = $this->uniqueOrgGln('036615');

        $site = Site::query()->create([
            'name' => 'Ship Hierarchy Site '.Str::random(6),
            'gln' => $siteGln,
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings->saveOrganization([
            'gln' => $siteGln,
            'company_prefix' => '036615',
            'default_ship_from_site_id' => (int) $site->getKey(),
            'default_receive_site_id' => (int) $site->getKey(),
        ]);

        return $site;
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

    private function uniqueOrgGln(string $companyPrefix): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $body12 = $companyPrefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $sum = 0;
            $digits = str_split(strrev($body12));
            foreach ($digits as $index => $digit) {
                $sum += ((int) $digit) * ($index % 2 === 0 ? 3 : 1);
            }
            $gln = $body12.(string) ((10 - ($sum % 10)) % 10);

            if (! Site::query()->where('gln', $gln)->exists()) {
                return $gln;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique site GLN for the test.');
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionIds !== []) {
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
            }

            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }

            if ($this->priorDefaultShipFromSiteId !== null) {
                TenantSettings::forTenant($tenant)->saveOrganization([
                    'default_ship_from_site_id' => $this->priorDefaultShipFromSiteId,
                ]);
            }

            tenancy()->end();
        }

        if ($this->priorProfile !== null) {
            Tenant::query()->whereKey(self::DEMO2_TENANT_ID)->update([
                'profile' => $this->priorProfile->value,
            ]);
        }
    }
}
