<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\InboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Integrations\InboundEpcisReceiver;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class InboundMultiPartnerRoutingFailClosedTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    #[Test]
    public function multi_partner_routing_rejects_unmatched_sender_instead_of_default_partner(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $defaultGln = $this->uniqueGln();
            $unmatchedGln = $this->uniqueGln();

            $default = TradingPartner::query()->create([
                'name' => 'Default partner '.uniqid(),
                'gln' => $defaultGln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $default->id;

            $connection = InboundConnection::query()->create([
                'name' => 'Multi partner fail closed',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'settings' => ['multi_partner_routing' => true],
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $connection->tradingPartners()->attach($default->id, [
                'sender_gln' => $defaultGln,
                'priority' => 1,
                'is_default' => true,
            ]);

            $xml = $this->documentWithSender($unmatchedGln);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is not registered on this multi-partner inbound connection');

            app(InboundEpcisReceiver::class)->receive($connection, $xml, 'unmatched-sender.xml');
        } finally {
            $this->cleanupPartners();
            $this->cleanupTrackedEpcisArtifacts();
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function multi_partner_routing_rejects_missing_sender_gln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $defaultGln = $this->uniqueGln();

            $default = TradingPartner::query()->create([
                'name' => 'Default partner missing '.uniqid(),
                'gln' => $defaultGln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $default->id;

            $connection = InboundConnection::query()->create([
                'name' => 'Multi partner missing sender',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'settings' => ['multi_partner_routing' => true],
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $connection->tradingPartners()->attach($default->id, [
                'sender_gln' => $defaultGln,
                'priority' => 1,
                'is_default' => true,
            ]);

            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1">
  <EPCISBody><EventList/></EPCISBody>
</epcis:EPCISDocument>
XML;

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('requires a valid SBDH sender GLN');

            app(InboundEpcisReceiver::class)->receive($connection, $xml, 'missing-sender.xml');
        } finally {
            $this->cleanupPartners();
            $this->cleanupTrackedEpcisArtifacts();
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    private function uniqueGln(): string
    {
        $base = str_pad((string) random_int(100000000000, 899999999999), 12, '0', STR_PAD_LEFT);

        return $base.Gtin::checkDigit($base);
    }

    private function documentWithSender(string $senderGln): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader>
      <sbdh:Sender><sbdh:Identifier Authority="GLN">{$senderGln}</sbdh:Identifier></sbdh:Sender>
      <sbdh:Receiver><sbdh:Identifier Authority="GLN">0366159000010</sbdh:Identifier></sbdh:Receiver>
    </sbdh:StandardBusinessDocumentHeader>
  </EPCISHeader>
  <EPCISBody><EventList/></EPCISBody>
</epcis:EPCISDocument>
XML;
    }

    private function cleanupPartners(): void
    {
        if ($this->partnerIds === [] || ! tenancy()->initialized) {
            return;
        }

        DB::table('inbound_connection_trading_partner')
            ->whereIn('trading_partner_id', $this->partnerIds)
            ->delete();

        TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
        $this->partnerIds = [];
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
