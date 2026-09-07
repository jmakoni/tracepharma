<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Integrations;

use App\Enums\OutboundConnectionKind;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\OutboundNetworkProfile;
use App\Support\Integrations\OutboundSendPreset;
use Database\Seeders\OutboundNetworkProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundNetworkProfileSeedTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function seed_includes_all_sixteen_network_slugs(): void
    {
        (new OutboundNetworkProfileSeeder)->run();

        $slugs = OutboundNetworkProfile::query()
            ->distinct()
            ->orderBy('network_slug')
            ->pluck('network_slug')
            ->all();

        $this->assertCount(16, $slugs);
        $this->assertEqualsCanonicalizing(OutboundNetworkProfile::networkSlugs(), $slugs);
    }

    #[Test]
    public function systech_and_unitrace_prod_use_shared_hub_url_with_query_string(): void
    {
        (new OutboundNetworkProfileSeeder)->run();

        foreach (['systech', 'unitrace'] as $slug) {
            $profile = OutboundNetworkProfile::query()
                ->where('network_slug', $slug)
                ->where('environment', 'prod')
                ->firstOrFail();

            $this->assertSame(
                'https://hub-prod.systechcloud.com/jobs/api/inboundjob/?message-type=epcis&format=xml',
                $profile->endpoint_url,
            );
        }
    }

    #[Test]
    public function sap_ich_prod_url_matches_export(): void
    {
        (new OutboundNetworkProfileSeeder)->run();

        $profile = OutboundNetworkProfile::query()
            ->where('network_slug', 'sap_ich')
            ->where('environment', 'prod')
            ->firstOrFail();

        $this->assertSame(
            'https://ich4ls.net.sap/cxf/ICH_DataExchange_SOAP_ASYNC_v4',
            $profile->endpoint_url,
        );
    }

    #[Test]
    public function tracepharma_profiles_match_hub_path_pattern(): void
    {
        (new OutboundNetworkProfileSeeder)->run();

        $expected = [
            'demo' => 'https://admin2.internal.vatengi.com/api/webhooks/epcis/hub/tracepharma',
            'stage' => 'https://stage.tracepharma.io/api/webhooks/epcis/hub/tracepharma',
            'prod' => 'https://prod.tracepharma.io/api/webhooks/epcis/hub/tracepharma',
        ];

        foreach ($expected as $environment => $url) {
            $profile = OutboundNetworkProfile::query()
                ->where('network_slug', 'tracepharma')
                ->where('environment', $environment)
                ->firstOrFail();

            $this->assertSame($url, $profile->endpoint_url);
        }
    }

    #[Test]
    public function tracelink_prod_seeds_as2_to_and_subject(): void
    {
        (new OutboundNetworkProfileSeeder)->run();

        $profile = OutboundNetworkProfile::query()
            ->where('network_slug', 'tracelink')
            ->where('environment', 'prod')
            ->firstOrFail();

        $this->assertSame('TRACELINKPROD', $profile->as2_to);
        $this->assertSame('PT_SOMINT_SALES_SHIPMENT_IB', $profile->as2_subject);
    }

    #[Test]
    public function email_and_portal_are_never_network_transports(): void
    {
        foreach (SerializationProvider::networkProfileProviders() as $provider) {
            $allowed = OutboundSendPreset::allowedTransportsFor(
                OutboundConnectionKind::ProviderHub,
                $provider,
            );

            $this->assertNotContains(OutboundTransport::Email, $allowed, $provider->value);
            $this->assertNotContains(OutboundTransport::Portal, $allowed, $provider->value);
        }
    }

    #[Test]
    public function network_allowed_transports_match_seed_matrix(): void
    {
        $this->assertSame(
            [OutboundTransport::Https],
            OutboundSendPreset::allowedTransportsFor(
                OutboundConnectionKind::ProviderHub,
                SerializationProvider::TracePharma,
            ),
        );

        $this->assertSame(
            [OutboundTransport::As2, OutboundTransport::Https],
            OutboundSendPreset::allowedTransportsFor(
                OutboundConnectionKind::ProviderHub,
                SerializationProvider::TraceLink,
            ),
        );

        $this->assertSame(
            OutboundTransport::As2,
            SerializationProvider::TraceLink->defaultOutboundTransport(),
        );
    }
}
