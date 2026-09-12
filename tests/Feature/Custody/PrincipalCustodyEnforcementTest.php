<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Enums\EpcisAuthoredKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Principal;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Custody\PrincipalCustody;
use App\Support\Gs1\Gtin;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class PrincipalCustodyEnforcementTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    private ?bool $priorEnforced = null;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $principalIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receiveSessionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?int $priorSitePrincipalId = null;

    private ?int $touchedSiteId = null;

    #[Test]
    public function flag_off_allows_ship_open_and_cross_principal_serials(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            $this->actingAs($this->createOwner());
            TenantSettings::forTenant($tenant)->setPrincipalCustodyEnforced(false);
            $tenant->save();

            $this->assertFalse(PrincipalCustody::forTenant()->isEnforced());

            [$site] = $this->createSites($tenant);
            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertNull($session->principal_id);

            $epc = $this->createEpc();
            $this->authorReceivingEvent($site, $epc);
            $epc->forceFill(['principal_id' => $this->createPrincipal('A')->getKey()])->save();

            app(EpcCustodyGate::class)->assertInCustody($epc->fresh(), 'shipping');
            $this->assertTrue(app(ShippableEpcsAtSite::class)->contains((int) $site->getKey(), (int) $epc->getKey()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function flag_on_requires_principal_to_open_ship_and_receive(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            $this->actingAs($this->createOwner());
            TenantSettings::forTenant($tenant)->setPrincipalCustodyEnforced(true);
            $tenant->save();

            [$site] = $this->createSites($tenant, withPrincipal: false);

            try {
                app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
                $this->fail('Expected ship open to require a principal when custody is enforced.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Principal custody is enforced', $e->getMessage());
            }

            try {
                app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $site->getKey());
                $this->fail('Expected receive open to require a principal when custody is enforced.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Principal custody is enforced', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function flag_on_stamps_epcs_on_receive_generate_and_denies_cross_principal_ship(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            $this->actingAs($this->createOwner());
            $this->ensureDemo2OrgPrefixMatchesReceiveSites();
            TenantSettings::forTenant($tenant)->setPrincipalCustodyEnforced(true);
            $tenant->save();

            $principalA = $this->createPrincipal('Client A');
            $principalB = $this->createPrincipal('Client B');

            $site = EligibleReceiveSites::forOrganization()->orderBy('id')->first();
            $this->assertNotNull($site);
            $this->priorSitePrincipalId = $site->principal_id !== null ? (int) $site->principal_id : null;
            $this->touchedSiteId = (int) $site->getKey();
            $site->forceFill(['principal_id' => $principalA->getKey()])->save();

            $settings = TenantSettings::forTenant($tenant);
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
            $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
            $settings->setDefaultShipFromSiteId((int) $site->getKey());
            $settings->setDefaultReceiveSiteId((int) $site->getKey());
            $tenant->save();

            $receive = app(OpenScanFirstReceivingSession::class)->handle(
                siteId: (int) $site->getKey(),
                openedBy: auth()->id(),
            );
            $this->receiveSessionIds[] = (int) $receive->getKey();
            $this->assertSame((int) $principalA->getKey(), (int) $receive->principal_id);

            $epc = $this->createEpc();
            $scan = app(ConfirmReceivingScan::class)->handle($receive, (string) $epc->epc_uri);
            $this->assertTrue($scan['ok'], $scan['message'] ?? 'scan failed');

            $completed = app(CompleteReceivingSession::class)->handle($receive->fresh());
            $this->assertNotNull($completed->receiving_events_generated_at);
            $this->assertSame((int) $principalA->getKey(), (int) $epc->fresh()->principal_id);

            $shipWrong = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                principalId: (int) $principalB->getKey(),
            );
            $this->sessionIds[] = (int) $shipWrong->getKey();

            $denied = app(ConfirmOutboundShippingScan::class)->handle($shipWrong, (string) $epc->epc_uri);
            $this->assertFalse($denied['ok']);
            $this->assertStringContainsString('another principal', $denied['message']);

            $shipRight = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                principalId: (int) $principalA->getKey(),
            );
            $this->sessionIds[] = (int) $shipRight->getKey();

            $allowed = app(ConfirmOutboundShippingScan::class)->handle($shipRight, (string) $epc->epc_uri);
            $this->assertTrue($allowed['ok'], $allowed['message'] ?? 'expected ship confirm');
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Logistics3pl);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function createPrincipal(string $name): Principal
    {
        $principal = Principal::query()->create([
            'name' => $name.' '.Str::random(4),
            'gln' => null,
            'is_active' => true,
        ]);
        $this->principalIds[] = (int) $principal->getKey();

        return $principal;
    }

    /**
     * @return array{0: Site}
     */
    private function createSites(Tenant $tenant, bool $withPrincipal = false, ?Principal $principal = null): array
    {
        $site = Site::query()->create([
            'name' => 'Principal Custody Site '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
            'principal_id' => $withPrincipal && $principal !== null ? $principal->getKey() : null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $settings->setDefaultShipFromSiteId((int) $site->getKey());
        $settings->setDefaultReceiveSiteId((int) $site->getKey());
        $tenant->save();

        return [$site];
    }

    private function createEpc(): Epc
    {
        $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(10000000, 99999999), 0, 6)
            .'.PC'.random_int(10000000, 99999999);

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function authorReceivingEvent(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Receiving,
            'status' => 'parsed',
            'original_filename' => 'principal-custody-'.Str::random(6).'.xml',
            'notes' => 'Receiving EPCIS for principal custody test.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinutes(5),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo 2',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $this->priorProfile = $tenant->profile;
            $tenant->forceFill(['profile' => $profile])->save();
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
        $this->priorEnforced = TenantSettings::forTenant($tenant)->principalCustodyEnforced();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            try {
                $settings = TenantSettings::forTenant($tenant);
                if ($this->priorEnforced !== null) {
                    $settings->setPrincipalCustodyEnforced($this->priorEnforced);
                }
                if ($this->priorDefaultShipFromSiteId !== null) {
                    $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
                }
                if ($this->priorDefaultReceiveSiteId !== null) {
                    $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
                }
                if ($this->touchedSiteId !== null) {
                    Site::query()->whereKey($this->touchedSiteId)->update([
                        'principal_id' => $this->priorSitePrincipalId,
                    ]);
                    $this->touchedSiteId = null;
                    $this->priorSitePrincipalId = null;
                }
                $tenant->save();

                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->each(function (OutboundShippingSession $session): void {
                    $session->scanLines()->delete();
                    $session->delete();
                });
                $this->sessionIds = [];

                foreach ($this->receiveSessionIds as $id) {
                    $this->deleteReceivingSessionForIsolation($id);
                }
                $this->receiveSessionIds = [];

                if ($this->eventIds !== []) {
                    DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
                    EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
                    $this->eventIds = [];
                }

                if ($this->documentIds !== []) {
                    EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                    $this->documentIds = [];
                }

                if ($this->epcIds !== []) {
                    DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
                    DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
                    Epc::query()->whereIn('id', $this->epcIds)->delete();
                    $this->epcIds = [];
                }

                if ($this->siteIds !== []) {
                    Site::query()->whereIn('id', $this->siteIds)->update(['principal_id' => null]);
                    Site::query()->whereIn('id', $this->siteIds)->delete();
                    $this->siteIds = [];
                }

                if ($this->principalIds !== []) {
                    Principal::query()->whereIn('id', $this->principalIds)->delete();
                    $this->principalIds = [];
                }

                if ($this->userIds !== []) {
                    User::query()->whereIn('id', $this->userIds)->delete();
                    $this->userIds = [];
                }
            } finally {
                $this->priorEnforced = null;
                $this->priorDefaultShipFromSiteId = null;
                $this->priorDefaultReceiveSiteId = null;
            }
        }

        tenancy()->end();

        if ($this->priorProfile !== null) {
            Tenant::query()->whereKey(self::DEMO2_TENANT_ID)->update([
                'profile' => $this->priorProfile,
            ]);
            $this->priorProfile = null;
        }
    }
}
