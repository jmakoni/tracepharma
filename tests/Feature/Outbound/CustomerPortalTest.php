<?php

namespace Tests\Feature\Outbound;

use App\Enums\EpcisAuthoredKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\CustomerPortalLinks;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Outbound\CustomerPortalService;
use App\Services\Quarantine\SupplierPortalService;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<string> */
    private array $payloadPaths = [];

    #[Test]
    public function customer_portal_lists_inbound_and_outbound_with_filters(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Portal V2 Buyer');
            $partner->forceFill(['gln' => '0860000000100'])->save();

            $outbound = $this->createOutboundDocument($partner, 'outbound-v2.xml');
            $inboundPath = 'epcis/inbound/portal-v2-'.Str::uuid().'.xml';
            Storage::disk('local')->put($inboundPath, '<?xml version="1.0"?><epcis/>');
            $this->payloadPaths[] = $inboundPath;

            EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'inbound-v2.xml',
                'payload_disk' => 'local',
                'payload_path' => $inboundPath,
                'file_sha256' => hash('sha256', 'inbound'),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'trading_partner_id' => $partner->getKey(),
                'sender_gln' => '0860000000100',
                'customer_po' => 'PO-PORTAL-99',
            ]);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $indexUrl = app(CustomerPortalService::class)->signedCustomerPortalUrl($partner);

            $inboundOnly = app(CustomerPortalService::class)
                ->portalDocumentsQuery($partner, 'inbound')
                ->pluck('original_filename')
                ->all();
            $this->assertContains('inbound-v2.xml', $inboundOnly);
            $this->assertNotContains('outbound-v2.xml', $inboundOnly);

            $poMatches = app(CustomerPortalService::class)
                ->portalDocumentsQuery($partner, po: 'PO-PORTAL-99')
                ->pluck('original_filename')
                ->all();
            $this->assertContains('inbound-v2.xml', $poMatches);

            tenancy()->end();

            $this->get($indexUrl)
                ->assertOk()
                ->assertSee('inbound-v2.xml', false)
                ->assertSee('outbound-v2.xml', false)
                ->assertSee('Records are retained', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_portal_does_not_expose_docs_via_spoofed_sender_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partnerA = $this->createPartner('Portal Buyer A');
            $partnerA->forceFill(['gln' => '0860000000100'])->save();
            $partnerB = $this->createPartner('Portal Buyer B');
            $partnerB->forceFill(['gln' => '0860000000200'])->save();

            $spoofPath = 'epcis/inbound/portal-spoof-'.Str::uuid().'.xml';
            Storage::disk('local')->put($spoofPath, '<?xml version="1.0"?><epcis>secret-for-b</epcis>');
            $this->payloadPaths[] = $spoofPath;

            $spoofed = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'spoofed-sender.xml',
                'payload_disk' => 'local',
                'payload_path' => $spoofPath,
                'file_sha256' => hash('sha256', 'spoof-inbound'),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                // Linked to B, but payload claims A's GLN as sender.
                'trading_partner_id' => $partnerB->getKey(),
                'sender_gln' => '0860000000100',
            ]);
            $this->documentIds[] = (int) $spoofed->getKey();

            $nullPartnerPath = 'epcis/inbound/portal-null-'.Str::uuid().'.xml';
            Storage::disk('local')->put($nullPartnerPath, '<?xml version="1.0"?><epcis>unlinked</epcis>');
            $this->payloadPaths[] = $nullPartnerPath;

            $unlinked = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'unlinked-sender.xml',
                'payload_disk' => 'local',
                'payload_path' => $nullPartnerPath,
                'file_sha256' => hash('sha256', 'unlinked-inbound'),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'trading_partner_id' => null,
                'sender_gln' => '0860000000100',
            ]);
            $this->documentIds[] = (int) $unlinked->getKey();

            $visibleToA = app(CustomerPortalService::class)
                ->inboundDocumentsQuery($partnerA)
                ->pluck('original_filename')
                ->all();

            $this->assertNotContains('spoofed-sender.xml', $visibleToA);
            $this->assertNotContains('unlinked-sender.xml', $visibleToA);

            $visibleToB = app(CustomerPortalService::class)
                ->inboundDocumentsQuery($partnerB)
                ->pluck('original_filename')
                ->all();

            $this->assertContains('spoofed-sender.xml', $visibleToB);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function unsigned_customer_portal_is_forbidden(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Unsigned Buyer');
            app(CustomerPortalService::class)->ensureCustomerPortalLink($partner);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = 'http://'.self::DEMO2_DOMAIN.'/customer-portal/'.$partner->customer_portal_uuid;

            tenancy()->end();

            $this->get($url)->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function buyer_portal_lists_only_shipping_ti_and_does_not_cache(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Shipping TI Buyer');
            $shipping = $this->createOutboundDocument($partner, 'shipping-ti.xml', attributes: [
                'authored_kind' => EpcisAuthoredKind::Shipping,
                'status' => 'generated',
                'notes' => 'Generated outbound shipping EPCIS for ship order session #1.',
            ]);
            $this->createOutboundDocument($partner, 'receiving-attestation.xml', attributes: [
                'authored_kind' => EpcisAuthoredKind::Receiving,
                'status' => 'generated',
                'notes' => 'Generated receiving EPCIS (custody attestation, not TI/TS).',
            ]);
            $this->createOutboundDocument($partner, 'error-shipping.xml', attributes: [
                'authored_kind' => EpcisAuthoredKind::Shipping,
                'status' => 'error',
                'notes' => 'Generated outbound shipping EPCIS for ship order session #2.',
            ]);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $indexUrl = app(CustomerPortalService::class)->signedCustomerPortalUrl($partner);
            $downloadUrl = app(CustomerPortalService::class)->signedDownloadUrl($partner, $shipping);

            tenancy()->end();

            $index = $this->get($indexUrl);
            $index->assertOk();
            $index->assertSee('shipping-ti.xml', false);
            $index->assertDontSee('receiving-attestation.xml', false);
            $index->assertDontSee('error-shipping.xml', false);
            $this->assertStringContainsString('no-store', (string) $index->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $index->headers->get('Cache-Control'));

            $download = $this->get($downloadUrl);
            $download->assertOk();
            $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function partner_a_does_not_see_partner_b_documents(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partnerA = $this->createPartner('Buyer A');
            $partnerB = $this->createPartner('Buyer B');
            $docA = $this->createOutboundDocument($partnerA, 'buyer-a-asn.xml');
            $this->createOutboundDocument($partnerB, 'buyer-b-asn.xml');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(CustomerPortalService::class)->signedCustomerPortalUrl($partnerA);

            tenancy()->end();

            $response = $this->get($url);
            $response->assertOk();
            $response->assertSee('buyer-a-asn.xml', false);
            $response->assertSee((string) $docA->getKey(), false);
            $response->assertDontSee('buyer-b-asn.xml', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function customer_can_download_outbound_xml(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Download Buyer');
            $document = $this->createOutboundDocument($partner, 'download-asn.xml', '<epcis>download-me</epcis>');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $indexUrl = app(CustomerPortalService::class)->signedCustomerPortalUrl($partner);
            $downloadUrl = app(CustomerPortalService::class)->signedDownloadUrl($partner, $document);

            tenancy()->end();

            $this->get($indexUrl)
                ->assertOk()
                ->assertSee('Download EPCIS', false);

            $download = $this->get($downloadUrl);
            $download->assertOk();
            $download->assertDownload('download-asn.xml');
            $this->assertStringContainsString('download-me', $download->streamedContent());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function documents_older_than_retention_are_hidden(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Retention Buyer');
            $recent = $this->createOutboundDocument($partner, 'recent-asn.xml');
            $old = $this->createOutboundDocument($partner, 'old-asn.xml');
            $old->forceFill([
                'created_at' => now()->subYears(7),
            ])->save();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(CustomerPortalService::class)->signedCustomerPortalUrl($partner);

            tenancy()->end();

            $response = $this->get($url);
            $response->assertOk();
            $response->assertSee('recent-asn.xml', false);
            $response->assertDontSee('old-asn.xml', false);
            $response->assertSee((string) $recent->getKey(), false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function customer_portal_uuid_stays_separate_from_supplier_portal(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Dual Portal Buyer');
            $supplier = app(SupplierPortalService::class);
            $customer = app(CustomerPortalService::class);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $supplierUrl = $supplier->signedPartnerExceptionsUrl($partner);
            $customerUrl = $customer->signedCustomerPortalUrl($partner);
            $partner->refresh();

            $this->assertNotNull($partner->portal_share_uuid);
            $this->assertNotNull($partner->customer_portal_uuid);
            $this->assertNotSame($partner->portal_share_uuid, $partner->customer_portal_uuid);
            $this->assertStringContainsString('/supplier-exceptions/'.$partner->portal_share_uuid, $supplierUrl);
            $this->assertStringContainsString('/customer-portal/'.$partner->customer_portal_uuid, $customerUrl);

            $wrongCustomerUrl = 'http://'.self::DEMO2_DOMAIN.'/customer-portal/'.$partner->portal_share_uuid;

            tenancy()->end();

            $this->get($wrongCustomerUrl)->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function ship_to_partner_outbound_documents_are_listed(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Ship To Buyer');
            $this->createOutboundDocument($partner, 'ship-to-asn.xml', attributes: [
                'trading_partner_id' => null,
                'ship_to_partner_id' => $partner->getKey(),
            ]);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(CustomerPortalService::class)->signedCustomerPortalUrl($partner);

            tenancy()->end();

            $this->get($url)
                ->assertOk()
                ->assertSee('ship-to-asn.xml', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function customer_portal_page_issues_a_signed_url_without_changing_partners_resource(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertSame('Trading Partners', TradingPartnerResource::getNavigationLabel());
            $this->assertSame('customer-portal', CustomerPortalLinks::getSlug());

            $partner = $this->createPartner('Issue Link Buyer');

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            $this->assertTrue(CustomerPortalLinks::canAccess());

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);

            Livewire::test(CustomerPortalLinks::class)
                ->assertSuccessful()
                ->assertSee($partner->name)
                ->callAction('issueLink', arguments: ['partner' => $partner->getKey()])
                ->assertSet('issuedPartnerId', (int) $partner->getKey())
                ->assertSee('/customer-portal/', false);

            $partner->refresh();
            $this->assertNotNull($partner->customer_portal_uuid);
            $this->assertNull($partner->portal_share_uuid);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function non_owner_cannot_issue_a_customer_portal_link(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $this->actingAs($user);

            $this->assertFalse(CustomerPortalLinks::canAccess());
            Livewire::test(CustomerPortalLinks::class)->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    private function createPartner(string $name): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => $name.' '.substr((string) Str::uuid(), 0, 8),
            'partner_type' => 'wholesaler',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOutboundDocument(
        TradingPartner $partner,
        string $filename,
        string $xml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>',
        array $attributes = [],
    ): EpcisDocument {
        $path = 'epcis/outbound/customer-portal-'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, $xml);
        $this->payloadPaths[] = $path;

        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Shipping,
            'format' => 'xml',
            'original_filename' => $filename,
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $xml),
            'dscsa_affirm' => false,
            'status' => 'parsed',
            'reprocess_count' => 0,
            'event_count' => 1,
            'epc_count' => 0,
            'received_at' => now(),
            'trading_partner_id' => $partner->getKey(),
        ], $attributes));
        $this->documentIds[] = (int) $document->getKey();

        return $document;
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

        foreach ($this->documentIds as $documentId) {
            EpcisDocument::query()->whereKey($documentId)->delete();
        }
        $this->documentIds = [];

        foreach ($this->payloadPaths as $path) {
            Storage::disk('local')->delete($path);
        }
        $this->payloadPaths = [];

        foreach ($this->partnerIds as $partnerId) {
            TradingPartner::query()->whereKey($partnerId)->update([
                'customer_portal_uuid' => null,
                'portal_share_uuid' => null,
            ]);
            TradingPartner::query()->whereKey($partnerId)->delete();
        }
        $this->partnerIds = [];

        tenancy()->end();
    }
}
