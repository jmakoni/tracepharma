<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Dscsa\Support\EpcisShipmentReportContext;
use App\Services\Dscsa\TransactionReport\TransactionReportDataBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Non-direct-from-plant (3PL) four-party GS1 DSCSA shipping: manufacturer owning party
 * differs from ship-from location; report manufacturer address must stay on seller GLN.
 */
class Shipping3plFourPartyEnrichTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const MY_PHARMA_GLN = '0361230456891';

    private const MY_SHIPPING_GLN = '0478901112229';

    private const ABC_CORPORATE_GLN = '0087701000003';

    private const ABC_COLUMBUS_GLN = '0716163011226';

    private const MY_PHARMA_ADDRESS = '100 Manufacturer Way';

    private const MY_SHIPPING_ADDRESS = '500 Logistics Park Dr';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function ingest_enriches_four_party_3pl_shipping_and_report_uses_manufacturer_address(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestFixture();
            $this->documentId = (int) $document->getKey();
            $document->load('tradingPartner', 'shipToPartner');

            $this->assertSame('validated', $document->status);
            $this->assertSame(3, $document->event_count);

            // Seller / trading partner = My Pharma (SBDH sender / source owning), not 3PL ship-from.
            $this->assertSame(self::MY_PHARMA_GLN, $document->sender_gln);
            $this->assertNotSame(self::MY_SHIPPING_GLN, $document->sender_gln);
            if ($document->tradingPartner !== null) {
                $this->assertSame(self::MY_PHARMA_GLN, $document->tradingPartner->gln);
                $this->assertNotSame(self::MY_SHIPPING_GLN, $document->tradingPartner->gln);
            }

            // Ship-from site = 3PL location; seller display name = owning party (manufacturer).
            $this->assertSame(self::MY_SHIPPING_GLN, $document->ship_from_gln);
            $this->assertSame('My Pharma LLC', $document->ship_from_name);
            $this->assertSame('My Shipping LLC', $document->ship_from_site_name);

            // Ship-to partner = ABC corporate; ship-to site = Columbus DC.
            $this->assertSame('AmerisourceBergen Corporate', $document->ship_to_name);
            $this->assertSame('AmerisourceBergen Columbus DC', $document->ship_to_site_name);
            $this->assertSame(self::ABC_COLUMBUS_GLN, $document->ship_to_gln);
            if ($document->shipToPartner !== null) {
                $this->assertSame(self::ABC_CORPORATE_GLN, $document->shipToPartner->gln);
            } elseif ($document->ship_to_partner_id !== null) {
                $shipToPartner = TradingPartner::query()->find($document->ship_to_partner_id);
                $this->assertNotNull($shipToPartner);
                $this->assertSame(self::ABC_CORPORATE_GLN, $shipToPartner->gln);
            }

            $this->assertSame('PO-3PL-7174', $document->customer_po);
            $this->assertSame('ASN-3PL-4787', $document->asn_number);

            $context = app(EpcisShipmentReportContext::class);
            $mfrAddress = $context->manufacturerAddress($document->fresh());
            $shipping = $context->resolveShippingContext($document->fresh());

            $this->assertSame(self::MY_PHARMA_ADDRESS, $mfrAddress['address']);
            $this->assertNotSame(self::MY_SHIPPING_ADDRESS, $mfrAddress['address']);
            $this->assertSame('My Pharma LLC', $shipping['seller_name']);
            $this->assertSame(self::MY_PHARMA_GLN, $shipping['seller_gln']);

            $report = app(TransactionReportDataBuilder::class)->build($document->fresh());
            $this->assertNotEmpty($report->pages);
            $page = $report->pages[0];
            $this->assertSame(self::MY_PHARMA_ADDRESS, $page->manufacturerAddress);
            $this->assertNotSame(self::MY_SHIPPING_ADDRESS, $page->manufacturerAddress);
            $this->assertNotEmpty($page->ownershipRows);
            $senderBlock = $page->ownershipRows[0]['sender'];
            $receiverBlock = $page->ownershipRows[0]['receiver'];
            $this->assertStringContainsString('My Pharma LLC', $senderBlock);
            $this->assertStringContainsString('Ship-from: My Shipping LLC', $senderBlock);
            $this->assertStringContainsString(self::MY_SHIPPING_GLN, $senderBlock);
            $this->assertStringContainsString('AmerisourceBergen Corporate', $receiverBlock);
            $this->assertStringContainsString('Ship-to: AmerisourceBergen Columbus DC', $receiverBlock);
            $this->assertStringContainsString(self::ABC_COLUMBUS_GLN, $receiverBlock);
        } finally {
            $this->cleanup();
        }
    }

    private function ingestFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/shipping_3pl_four_party.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_3pl_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('33333333-4444-5555-6666-777777777777', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'shipping_3pl_four_party.xml',
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

        if ($this->documentId !== null) {
            if (Schema::hasTable('receiving_sessions')) {
                ReceivingSession::query()
                    ->where('epcis_document_id', $this->documentId)
                    ->delete();
            }
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        foreach ([
            'urn:epc:id:sgtin:036123.0200116.10000082001560',
            'urn:epc:id:sscc:047890.01001227052',
        ] as $uri) {
            $epc = Epc::query()->where('epc_uri', $uri)->first();
            if ($epc === null) {
                continue;
            }

            if (DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                continue;
            }

            if (Schema::hasTable('epc_ilmd')) {
                DB::table('epc_ilmd')->where('epc_id', $epc->id)->delete();
            }

            $epc->delete();
        }

        tenancy()->end();
    }
}
