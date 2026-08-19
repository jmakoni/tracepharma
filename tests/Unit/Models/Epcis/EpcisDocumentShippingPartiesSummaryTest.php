<?php

namespace Tests\Unit\Models\Epcis;

use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisDocumentShippingPartiesSummaryTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function outbound_seller_does_not_fall_back_to_customer_trading_partner(): void
    {
        $customer = new TradingPartner([
            'name' => 'Customer Pharmacy Inc',
            'gln' => '1234567890123',
        ]);

        $document = new EpcisDocument([
            'direction' => 'outbound',
            'ship_from_name' => null,
            'sender_gln' => '0361230456891',
            'receiver_gln' => '1234567890123',
        ]);
        $document->setRelation('tradingPartner', $customer);
        $document->setRelation('shipToPartner', null);
        $document->setRelation('shipFromSite', null);
        $document->setRelation('shipToSite', null);

        $summary = $document->shippingPartiesSummary();

        $this->assertNull($summary['seller']['name']);
        $this->assertNotSame('Customer Pharmacy Inc', $summary['seller']['name']);
        $this->assertSame('0361230456891', $summary['seller']['gln']);
        $this->assertNotSame('1234567890123', $summary['seller']['gln']);

        // Sold-to may still resolve via outbound customer tradingPartner.
        $this->assertSame('Customer Pharmacy Inc', $summary['sold_to']['name']);
        $this->assertSame('1234567890123', $summary['sold_to']['gln']);
    }

    #[Test]
    public function outbound_seller_falls_back_to_tenant_name_for_org_facility(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $customer = new TradingPartner([
                'name' => 'Customer Pharmacy Inc',
                'gln' => '1234567890123',
            ]);
            // Org facility: no trading partner on the ship-from site.
            $shipFromSite = new Site([
                'name' => 'DC-1',
                'trading_partner_id' => null,
            ]);
            $shipFromSite->setRelation('tradingPartner', null);

            $document = new EpcisDocument([
                'direction' => 'outbound',
                'ship_from_name' => null,
                'sender_gln' => '0361230456891',
            ]);
            $document->setRelation('tradingPartner', $customer);
            $document->setRelation('shipToPartner', null);
            $document->setRelation('shipFromSite', $shipFromSite);
            $document->setRelation('shipToSite', null);

            $summary = $document->shippingPartiesSummary();

            $this->assertSame((string) $tenant->name, $summary['seller']['name']);
            $this->assertNotSame('Customer Pharmacy Inc', $summary['seller']['name']);
            $this->assertSame('0361230456891', $summary['seller']['gln']);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function outbound_seller_does_not_use_ship_from_site_trading_partner(): void
    {
        $customer = new TradingPartner([
            'name' => 'Customer Pharmacy Inc',
            'gln' => '1234567890123',
        ]);
        $sitePartner = new TradingPartner([
            'name' => 'Misleading Site Org',
            'gln' => '0361230456891',
        ]);
        $shipFromSite = new Site(['name' => 'DC-1']);
        $shipFromSite->setRelation('tradingPartner', $sitePartner);

        $document = new EpcisDocument([
            'direction' => 'outbound',
            'ship_from_name' => null,
            'sender_gln' => '0361230456891',
        ]);
        $document->setRelation('tradingPartner', $customer);
        $document->setRelation('shipToPartner', null);
        $document->setRelation('shipFromSite', $shipFromSite);
        $document->setRelation('shipToSite', null);

        $summary = $document->shippingPartiesSummary();

        $this->assertNull($summary['seller']['name']);
        $this->assertNotSame('Misleading Site Org', $summary['seller']['name']);
        $this->assertNotSame('Customer Pharmacy Inc', $summary['seller']['name']);
        $this->assertSame('0361230456891', $summary['seller']['gln']);
    }

    #[Test]
    public function inbound_seller_still_falls_back_to_trading_partner(): void
    {
        $seller = new TradingPartner([
            'name' => 'Inbound Supplier Co',
            'gln' => '0096295000993',
        ]);

        $document = new EpcisDocument([
            'direction' => 'inbound',
            'ship_from_name' => null,
            'sender_gln' => null,
        ]);
        $document->setRelation('tradingPartner', $seller);
        $document->setRelation('shipToPartner', null);
        $document->setRelation('shipFromSite', null);
        $document->setRelation('shipToSite', null);

        $summary = $document->shippingPartiesSummary();

        $this->assertSame('Inbound Supplier Co', $summary['seller']['name']);
        $this->assertSame('0096295000993', $summary['seller']['gln']);
    }

    #[Test]
    public function inbound_sold_to_does_not_fall_back_to_seller_trading_partner(): void
    {
        $seller = new TradingPartner([
            'name' => 'Inbound Supplier Co',
            'gln' => '0096295000993',
        ]);

        $document = new EpcisDocument([
            'direction' => 'inbound',
            'ship_to_name' => null,
            'receiver_gln' => '0614141123452',
            'sender_gln' => '0096295000993',
        ]);
        $document->setRelation('tradingPartner', $seller);
        $document->setRelation('shipToPartner', null);
        $document->setRelation('shipFromSite', null);
        $document->setRelation('shipToSite', null);

        $summary = $document->shippingPartiesSummary();

        $this->assertNull($summary['sold_to']['name']);
        $this->assertNotSame('Inbound Supplier Co', $summary['sold_to']['name']);
        $this->assertSame('0614141123452', $summary['sold_to']['gln']);
        $this->assertSame('Inbound Supplier Co', $summary['seller']['name']);
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
