<?php

namespace Tests\Feature\Shipping;

use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\UnconfirmOutboundShippingScanLine;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnconfirmOutboundShippingScanLineTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const EPC_URI = 'urn:epc:id:sgtin:030116.0200116.90000082007777';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    #[Test]
    public function it_unconfirms_a_confirmed_scan_and_reopens_the_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createShipUser());
            $site = $this->createSite();
            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcIds[] = (int) $epc->getKey();
            $this->receiveAtSite($site, $epc);

            app(ConfirmOutboundShippingScan::class)->handle($session, self::EPC_URI);
            $session->refresh();
            $this->assertSame(1, (int) $session->confirmed_count);
            $this->assertSame('in_progress', $session->status);

            $line = OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->firstOrFail();

            $updated = app(UnconfirmOutboundShippingScanLine::class)->handle($line);

            $this->assertSame('open', $updated->status);
            $this->assertSame(0, (int) $updated->confirmed_count);
            $this->assertSame(0, OutboundShippingScanLine::query()->where('outbound_shipping_session_id', $session->getKey())->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_allows_unconfirm_when_session_needs_shipping_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createShipUser());
            $site = $this->createSite();
            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcIds[] = (int) $epc->getKey();
            $this->receiveAtSite($site, $epc);

            app(ConfirmOutboundShippingScan::class)->handle($session, self::EPC_URI);

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $line = OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->firstOrFail();

            $updated = app(UnconfirmOutboundShippingScanLine::class)->handle($line->fresh());

            $this->assertSame('open', $updated->status);
            $this->assertNull($updated->completed_at);
            $this->assertSame(0, (int) $updated->confirmed_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_blocks_unconfirm_when_shipping_epcis_was_authored(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createShipUser());
            $site = $this->createSite();
            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcIds[] = (int) $epc->getKey();
            $this->receiveAtSite($site, $epc);

            app(ConfirmOutboundShippingScan::class)->handle($session, self::EPC_URI);

            $session->forceFill(['shipping_events_generated_at' => now()])->save();

            $line = OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->firstOrFail();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('shipping EPCIS was already generated');

            app(UnconfirmOutboundShippingScanLine::class)->handle($line->fresh());
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createSite(): Site
    {
        $site = Site::query()->create([
            'name' => 'Ship Unconfirm '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings = TenantSettings::forTenant(tenant());
        $settings->setDefaultShipFromSiteId((int) $site->getKey());
        tenant()->save();

        return $site;
    }

    private function createShipUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'ship-unconfirm-custody.xml',
        ]);
        $this->custodyDocumentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinute(),
            'record_time' => now()->subMinute(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->custodyEventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function uniqueGln(): string
    {
        $prefix = TenantSettings::forTenant(tenant())->companyPrefix() ?: '03';
        $fill = max(1, 12 - strlen($prefix));

        do {
            $body = substr($prefix.str_pad((string) random_int(0, (int) str_repeat('9', $fill)), $fill, '0', STR_PAD_LEFT), 0, 12);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionIds !== []) {
                OutboundShippingScanLine::query()
                    ->whereIn('outbound_shipping_session_id', $this->sessionIds)
                    ->delete();
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
                $this->sessionIds = [];
            }

            if ($this->custodyEventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $this->custodyEventIds)->delete();
                EpcisEvent::query()->whereIn('id', $this->custodyEventIds)->delete();
                $this->custodyEventIds = [];
            }

            if ($this->custodyDocumentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->custodyDocumentIds)->delete();
                $this->custodyDocumentIds = [];
            }

            if ($this->epcIds !== []) {
                Epc::query()->whereIn('id', $this->epcIds)->delete();
                $this->epcIds = [];
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            foreach ($this->userIds as $userId) {
                User::query()->whereKey($userId)->delete();
            }
            $this->userIds = [];

            tenancy()->end();
        }
    }
}
