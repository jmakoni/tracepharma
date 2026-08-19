<?php

namespace Tests\Feature\Transferring;

use App\Actions\Transferring\CancelTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Actions\Transferring\UnconfirmTransferringScanLine;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CancelAndUnconfirmTransferringSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const EPC_URI = 'urn:epc:id:sgtin:030116.0200116.90000082006666';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function it_cancels_an_open_transfer_before_shipping_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $this->actingAs($this->createShipUser([(int) $fromSite->getKey()]));

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $this->assertTrue($session->canCancel());

            $cancelled = app(CancelTransferringSession::class)->handle($session);

            $this->assertSame('cancelled', $cancelled->status);
            $this->assertNotNull($cancelled->completed_at);
            $this->assertFalse($cancelled->canCancel());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_blocks_cancel_when_transfer_is_in_transit_or_authored(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $this->actingAs($this->createShipUser([(int) $fromSite->getKey()]));

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $session->forceFill(['status' => 'in_transit', 'shipped_at' => now()])->save();

            try {
                app(CancelTransferringSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException for in_transit session.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('in_transit', $e->getMessage());
            }

            $session->forceFill([
                'status' => 'open',
                'shipped_at' => null,
                'transfer_events_generated_at' => now(),
            ])->save();

            try {
                app(CancelTransferringSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException for authored transferring EPCIS.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('authored transferring EPCIS', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_unconfirms_a_confirmed_transfer_scan_on_open_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $this->actingAs($this->createShipUser([(int) $fromSite->getKey()]));

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcIds[] = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);
            $this->assertSame(1, (int) $session->fresh()->confirmed_count);

            $line = TransferringScanLine::query()
                ->where('transferring_session_id', $session->getKey())
                ->firstOrFail();

            $updated = app(UnconfirmTransferringScanLine::class)->handle($line);

            $this->assertSame(0, (int) $updated->confirmed_count);
            $this->assertSame(0, TransferringScanLine::query()->where('transferring_session_id', $session->getKey())->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_blocks_unconfirm_when_transfer_is_no_longer_open(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $this->actingAs($this->createShipUser([(int) $fromSite->getKey()]));

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcIds[] = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);

            $line = TransferringScanLine::query()
                ->where('transferring_session_id', $session->getKey())
                ->firstOrFail();

            $session->forceFill(['status' => 'in_transit', 'shipped_at' => now()])->save();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('not editable');

            app(UnconfirmTransferringScanLine::class)->handle($line->fresh());
        } finally {
            $this->cleanup($tenant);
        }
    }

    /** @return array{0: Site, 1: Site} */
    private function createTransferSites(Tenant $tenant): array
    {
        $fromSite = Site::query()->create([
            'name' => 'Cancel Transfer From '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Cancel Transfer To '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $toSite->getKey();

        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $settings->setDefaultShipFromSiteId((int) $fromSite->getKey());
        $settings->setDefaultReceiveSiteId((int) $toSite->getKey());
        $tenant->save();

        return [$fromSite, $toSite];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createShipUser(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->syncSites($siteIds);
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
            'original_filename' => 'cancel-transfer-custody.xml',
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
                TransferringScanLine::query()
                    ->whereIn('transferring_session_id', $this->sessionIds)
                    ->delete();
                TransferringSession::query()->whereIn('id', $this->sessionIds)->delete();
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

            if ($this->priorDefaultShipFromSiteId !== null || $this->priorDefaultReceiveSiteId !== null) {
                $settings = TenantSettings::forTenant($tenant);
                $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
                $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
                $tenant->save();
                $this->priorDefaultShipFromSiteId = null;
                $this->priorDefaultReceiveSiteId = null;
            }

            tenancy()->end();
        }
    }
}
