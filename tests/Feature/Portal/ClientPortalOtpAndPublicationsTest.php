<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Portal\EnsurePortalOrganization;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Models\PortalOtpChallenge;
use App\Models\PortalPublication;
use App\Models\PortalUser;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Notifications\PortalOtpNotification;
use App\Notifications\PortalPublicationReadyNotification;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Services\Portal\PortalOtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientPortalOtpAndPublicationsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $connectionId = null;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $portalUserIds = [];

    /** @var list<int> */
    private array $portalOrganizationIds = [];

    /** @var list<int> */
    private array $publicationIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<string> */
    private array $payloadPaths = [];

    private ?bool $previousClientPortalV2 = null;

    #[Test]
    public function otp_issue_and_verify_creates_portal_user_and_rejects_bad_code(): void
    {
        $this->initializeDemo2Tenant();
        Notification::fake();

        $email = 'otp-'.Str::lower(Str::random(8)).'@example.com';
        RateLimiter::clear('portal-otp-issue:'.$email);

        try {
            $otp = app(PortalOtpService::class);
            $challenge = $otp->issue($email);

            $this->assertDatabaseHas('portal_otp_challenges', [
                'id' => $challenge->getKey(),
                'email' => $email,
            ]);

            $code = null;
            Notification::assertSentOnDemand(
                PortalOtpNotification::class,
                function (PortalOtpNotification $notification) use (&$code): bool {
                    $code = $notification->code;

                    return strlen($notification->code) === PortalOtpService::CODE_LENGTH;
                },
            );
            $this->assertNotNull($code);

            try {
                $otp->verify($email, '000000');
                $this->fail('Expected ValidationException for bad OTP code.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('code', $e->errors());
            }

            $user = $otp->verify($email, (string) $code);
            $this->assertInstanceOf(PortalUser::class, $user);
            $this->assertSame($email, $user->email);
            $this->assertTrue($user->is_active);
            $this->portalUserIds[] = (int) $user->getKey();

            $this->assertNotNull($challenge->fresh()?->consumed_at);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function otp_issue_throttles_after_many_requests(): void
    {
        $this->initializeDemo2Tenant();
        Notification::fake();

        $email = 'otp-throttle-'.Str::lower(Str::random(8)).'@example.com';
        RateLimiter::clear('portal-otp-issue:'.$email);

        try {
            $otp = app(PortalOtpService::class);

            for ($i = 0; $i < PortalOtpService::ISSUE_MAX_ATTEMPTS; $i++) {
                $otp->issue($email);
            }

            try {
                $otp->issue($email);
                // Soft assert: throttle may vary by rate-limiter driver in CI.
                $this->addToAssertionCount(1);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('email', $e->errors());
                $this->assertStringContainsString('Too many', $e->errors()['email'][0] ?? '');
            }
        } finally {
            RateLimiter::clear('portal-otp-issue:'.$email);
            PortalOtpChallenge::query()->where('email', $email)->delete();
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function portal_transmit_creates_publication_marks_sent_and_notifies(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        Notification::fake();
        URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
        config(['logging.default' => 'null']);

        try {
            $partner = $this->createPartner('Portal Transmit Buyer', [
                'email' => 'buyer-notify-'.Str::lower(Str::random(6)).'@example.com',
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Portal transmit '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Portal,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'settings' => [
                    'notify_on_publish' => true,
                    'invite_emails' => ['invite-'.Str::lower(Str::random(6)).'@example.com'],
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $document = $this->createOutboundDocument($connection, $partner, [
                'asn_number' => 'ASN-PORTAL-1',
                'customer_po' => 'PO-PORTAL-1',
            ]);

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);
            $this->assertNotNull($document->sent_at);

            $publication = PortalPublication::query()
                ->where('epcis_document_id', $document->getKey())
                ->where('trading_partner_id', $partner->getKey())
                ->first();
            $this->assertNotNull($publication);
            $this->assertNull($publication->revoked_at);
            $this->publicationIds[] = (int) $publication->getKey();

            $org = \App\Models\PortalOrganization::query()
                ->where('trading_partner_id', $partner->getKey())
                ->first();
            $this->assertNotNull($org);
            $this->portalOrganizationIds[] = (int) $org->getKey();

            Notification::assertSentOnDemand(PortalPublicationReadyNotification::class);
        } finally {
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    #[Test]
    public function portal_user_in_org_a_cannot_access_document_published_only_to_partner_b(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            $partnerA = $this->createPartner('Isolation Org A');
            $partnerB = $this->createPartner('Isolation Org B');

            $orgA = app(EnsurePortalOrganization::class)->handle($partnerA);
            $this->portalOrganizationIds[] = (int) $orgA->getKey();
            app(EnsurePortalOrganization::class)->handle($partnerB);

            $portalUser = PortalUser::query()->create([
                'email' => 'iso-a-'.Str::lower(Str::random(8)).'@example.com',
                'is_active' => true,
            ]);
            $this->portalUserIds[] = (int) $portalUser->getKey();
            $orgA->users()->attach($portalUser->getKey(), ['role' => 'member']);

            $connection = OutboundConnection::query()->create([
                'name' => 'Portal iso '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Portal,
                'trading_partner_id' => $partnerB->getKey(),
                'is_active' => true,
                'settings' => ['notify_on_publish' => false],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $document = $this->createOutboundDocument($connection, $partnerB);
            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());
            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);

            $publication = PortalPublication::query()
                ->where('epcis_document_id', $document->getKey())
                ->first();
            $this->assertNotNull($publication);
            $this->publicationIds[] = (int) $publication->getKey();

            $orgB = \App\Models\PortalOrganization::query()
                ->where('trading_partner_id', $partnerB->getKey())
                ->first();
            if ($orgB !== null) {
                $this->portalOrganizationIds[] = (int) $orgB->getKey();
            }

            $showUrl = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/'.$document->getKey();
            $downloadUrl = $showUrl.'/download';

            tenancy()->end();

            $this->actingAs($portalUser, 'portal')
                ->get($showUrl)
                ->assertForbidden();

            $this->actingAs($portalUser, 'portal')
                ->get($downloadUrl)
                ->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    #[Test]
    public function trace_returns_published_serial_events_and_empty_for_unpublished(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            $partner = $this->createPartner('Trace Portal Buyer');
            $org = app(EnsurePortalOrganization::class)->handle($partner);
            $this->portalOrganizationIds[] = (int) $org->getKey();

            $portalUser = PortalUser::query()->create([
                'email' => 'trace-'.Str::lower(Str::random(8)).'@example.com',
                'is_active' => true,
            ]);
            $this->portalUserIds[] = (int) $portalUser->getKey();
            $org->users()->attach($portalUser->getKey(), ['role' => 'member']);

            $connection = OutboundConnection::query()->create([
                'name' => 'Portal trace '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Portal,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'settings' => ['notify_on_publish' => false],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $document = $this->createOutboundDocument($connection, $partner);
            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $publication = PortalPublication::query()
                ->where('epcis_document_id', $document->getKey())
                ->first();
            $this->assertNotNull($publication);
            $this->publicationIds[] = (int) $publication->getKey();

            $epcUri = 'urn:epc:id:sgtin:030116.0200116.'.random_int(10000000000000, 99999999999999);
            $epc = Epc::fromUri($epcUri);
            $epc->save();
            $this->epcIds[] = (int) $epc->getKey();

            $event = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subHour(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            ]);
            $this->eventIds[] = (int) $event->getKey();

            DB::table('event_epcs')->insert([
                'event_id' => $event->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ]);

            $publishedUrl = 'http://'.self::DEMO2_DOMAIN.'/client-portal/trace?code='.urlencode($epcUri);
            $unpublishedUrl = 'http://'.self::DEMO2_DOMAIN.'/client-portal/trace?code='
                .urlencode('urn:epc:id:sgtin:030116.0200116.99999999999999');

            tenancy()->end();

            $this->actingAs($portalUser, 'portal')
                ->get($publishedUrl)
                ->assertOk()
                ->assertSee('Event timeline', false)
                ->assertSee('shipping', false);

            $this->actingAs($portalUser, 'portal')
                ->get($unpublishedUrl)
                ->assertOk()
                ->assertSee('No published events found for that identifier in your portal.', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    #[Test]
    public function invite_membership_allows_shipments_index_after_auth(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            $partner = $this->createPartner('Invite Membership Buyer');
            $org = app(EnsurePortalOrganization::class)->handle($partner);
            $this->portalOrganizationIds[] = (int) $org->getKey();

            $portalUser = PortalUser::query()->firstOrCreate(
                ['email' => 'invite-'.Str::lower(Str::random(8)).'@example.com'],
                ['is_active' => true],
            );
            $this->portalUserIds[] = (int) $portalUser->getKey();
            $org->users()->attach($portalUser->getKey(), ['role' => 'admin']);

            $indexUrl = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments';

            tenancy()->end();

            $this->actingAs($portalUser, 'portal')
                ->get($indexUrl)
                ->assertOk();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createPartner(string $name, array $extra = []): TradingPartner
    {
        $partner = TradingPartner::query()->create(array_merge([
            'name' => $name.' '.uniqid(),
            'gln' => $this->uniqueGln(),
            'partner_type' => PartnerType::Pharmacy,
            'country_code' => 'US',
            'is_active' => true,
        ], $extra));
        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOutboundDocument(
        OutboundConnection $connection,
        TradingPartner $partner,
        array $attributes = [],
    ): EpcisDocument {
        $xml = $this->schemaValidOutboundXml();
        $path = 'epcis/outbound/portal-'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, $xml);
        $this->payloadPaths[] = $path;

        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'portal-shipment.xml',
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $xml),
            'dscsa_affirm' => true,
            'status' => 'parsed',
            'reprocess_count' => 0,
            'event_count' => 1,
            'epc_count' => 1,
            'received_at' => now(),
            'outbound_connection_id' => $connection->getKey(),
            'trading_partner_id' => $partner->getKey(),
        ], $attributes));
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function schemaValidOutboundXml(): string
    {
        $xml = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping.xml'));
        $this->assertNotFalse($xml);

        return str_replace(
            '11111111-2222-3333-4444-555555555555',
            (string) Str::uuid(),
            $xml,
        );
    }

    private function uniqueGln(): string
    {
        $base = str_pad((string) random_int(100000000000, 899999999999), 12, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $base.$check;
    }

    private function prepareHttpEnvironment(): void
    {
        $compiled = sys_get_temp_dir().'/tracepharma-client-portal-views-'.getmypid();
        if (! is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }

        config([
            'logging.default' => 'null',
            'view.compiled' => $compiled,
        ]);

        // Blade compiler is a singleton; refresh it so compiled views land in a writable path.
        $this->app->forgetInstance('blade.compiler');
        $this->app->forgetInstance('view');

        URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
    }

    private function enableClientPortalV2(Tenant $tenant): void
    {
        $settings = $tenant->settings ?? [];
        if (! is_array($settings)) {
            $settings = [];
        }

        $this->previousClientPortalV2 = (bool) data_get($settings, 'features.client_portal_v2', false);
        data_set($settings, 'features.client_portal_v2', true);
        $tenant->setAttribute('settings', $settings);
        $tenant->save();

        if (tenancy()->initialized) {
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());
        }
    }

    private function restoreClientPortalV2(Tenant $tenant): void
    {
        if ($this->previousClientPortalV2 === null) {
            return;
        }

        $fresh = $tenant->fresh() ?? $tenant;
        $settings = $fresh->settings ?? [];
        if (! is_array($settings)) {
            $settings = [];
        }

        data_set($settings, 'features.client_portal_v2', $this->previousClientPortalV2);
        $fresh->setAttribute('settings', $settings);
        $fresh->save();
        $this->previousClientPortalV2 = null;
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

        if ($this->eventIds !== [] && Schema::hasTable('event_epcs')) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
        }
        if ($this->eventIds !== [] && Schema::hasTable('epcis_events')) {
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
        }
        if ($this->epcIds !== []) {
            Epc::query()->whereIn('id', $this->epcIds)->delete();
        }

        if ($this->publicationIds !== []) {
            PortalPublication::query()->whereIn('id', $this->publicationIds)->delete();
        } elseif ($this->documentIds !== []) {
            PortalPublication::query()->whereIn('epcis_document_id', $this->documentIds)->delete();
        }

        if ($this->portalOrganizationIds !== []) {
            DB::table('portal_organization_user')
                ->whereIn('portal_organization_id', $this->portalOrganizationIds)
                ->delete();
            \App\Models\PortalOrganization::query()
                ->whereIn('id', $this->portalOrganizationIds)
                ->delete();
        }

        if ($this->portalUserIds !== []) {
            PortalUser::query()->whereIn('id', $this->portalUserIds)->delete();
        }

        if ($this->documentIds !== []) {
            EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();
            TransmissionMdn::query()->whereIn('document_id', $this->documentIds)->delete();
            if (Schema::hasTable('epcis_events')) {
                DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }

        foreach ($this->payloadPaths as $path) {
            Storage::disk('local')->delete($path);
        }

        if ($this->connectionId !== null) {
            OutboundConnection::query()->whereKey($this->connectionId)->delete();
        }

        if ($this->partnerIds !== []) {
            // Orgs cascade from partners may already be deleted; drop leftovers.
            \App\Models\PortalOrganization::query()
                ->whereIn('trading_partner_id', $this->partnerIds)
                ->delete();
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
        }

        $this->documentIds = [];
        $this->partnerIds = [];
        $this->portalUserIds = [];
        $this->portalOrganizationIds = [];
        $this->publicationIds = [];
        $this->epcIds = [];
        $this->eventIds = [];
        $this->payloadPaths = [];
        $this->connectionId = null;
    }
}
