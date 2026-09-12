<?php

namespace Tests\Feature\Auth;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Receiving\ResetReceivingSessionScans;
use App\Actions\Receiving\UnconfirmReceivingScanLine;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ResolveOpenReceiveUrl;
use App\Support\TenantSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteAccessAuthorizationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $scanLineIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $transferSessionIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $outboundSessionIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function user_with_only_site_a_cannot_confirm_receive_at_site_b(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            $session = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteB->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200SITE';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $line = ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'expected',
            ]);
            $this->scanLineIds[] = (int) $line->getKey();

            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            $this->expectException(AuthorizationException::class);

            app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                '(01)'.$epc->gtin14.'(21)'.$epc->serial_number,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_with_only_site_a_cannot_complete_cancel_or_reset_receive_at_site_b(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            $session = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteB->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            foreach ([
                fn () => app(CompleteReceivingSession::class)->handle($session->fresh()),
                fn () => app(CancelReceivingSession::class)->handle($session->fresh()),
                fn () => app(ResetReceivingSessionScans::class)->handle($session->fresh()),
            ] as $action) {
                try {
                    $action();
                    $this->fail('Expected AuthorizationException for cross-site receive mutation.');
                } catch (AuthorizationException) {
                    // expected
                }
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_with_only_site_a_cannot_unconfirm_receive_at_site_b(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            $session = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteB->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200UNCF';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $line = ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);
            $this->scanLineIds[] = (int) $line->getKey();

            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            $this->expectException(AuthorizationException::class);

            app(UnconfirmReceivingScanLine::class)->handle($line->fresh());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_with_only_site_a_cannot_update_outbound_references_at_site_b(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $siteB->getKey());
            $this->outboundSessionIds[] = (int) $session->getKey();

            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            $this->expectException(AuthorizationException::class);

            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-CROSS-SITE',
            ]);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_without_sites_access_all_cannot_cancel_or_reset_receive_with_null_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            $session = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::ScanFirst,
                'status' => 'open',
                'site_id' => null,
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionIds[] = (int) $session->getKey();

            [$siteA] = $this->createOwnedSites($tenant);
            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            foreach ([
                fn () => app(CancelReceivingSession::class)->handle($session->fresh()),
                fn () => app(ResetReceivingSessionScans::class)->handle($session->fresh()),
                fn () => app(CompleteReceivingSession::class)->handle($session->fresh()),
            ] as $action) {
                try {
                    $action();
                    $this->fail('Expected AuthorizationException for null-site receive mutation.');
                } catch (AuthorizationException) {
                    // expected
                }
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function resolve_open_receive_url_returns_null_for_other_site_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            $session = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteB->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200URL1';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $line = ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'expected',
            ]);
            $this->scanLineIds[] = (int) $line->getKey();

            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;
            $resolver = app(ResolveOpenReceiveUrl::class);

            $this->assertFalse($resolver->hasContext($barcode));
            $this->assertNull($resolver->previewUrl($barcode));
            $this->assertNull($resolver->handle($barcode, (int) $user->getKey()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_with_only_site_a_cannot_open_receive_at_site_b(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            $this->expectException(AuthorizationException::class);

            app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteB->getKey());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_with_only_site_a_cannot_open_transfer_a_to_b(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            $this->expectException(AuthorizationException::class);

            app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $siteA->getKey(),
                toSiteId: (int) $siteB->getKey(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_with_sites_a_and_b_can_open_transfer_a_to_b(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $user = $this->createUserWithSites([
                (int) $siteA->getKey(),
                (int) $siteB->getKey(),
            ]);
            $this->actingAs($user);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $siteA->getKey(),
                toSiteId: (int) $siteB->getKey(),
            );
            $this->transferSessionIds[] = (int) $session->getKey();

            $this->assertSame('open', $session->status);
            $this->assertSame((int) $siteA->getKey(), (int) $session->from_site_id);
            $this->assertSame((int) $siteB->getKey(), (int) $session->to_site_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function owner_with_access_all_can_act_at_any_org_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            $receiveSession = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteB->getKey());
            $this->sessionIds[] = (int) $receiveSession->getKey();
            $this->assertSame((int) $siteB->getKey(), (int) $receiveSession->site_id);

            $transferSession = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $siteA->getKey(),
                toSiteId: (int) $siteB->getKey(),
            );
            $this->transferSessionIds[] = (int) $transferSession->getKey();
            $this->assertSame('open', $transferSession->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_with_no_sites_has_empty_eligible_options(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->createOwnedSites($tenant);
            $user = $this->createUserWithSites([]);
            $this->actingAs($user);

            $this->assertSame([], EligibleReceiveSites::options($user));
            $this->assertSame(0, EligibleReceiveSites::count($user));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function resolve_open_receive_url_hides_in_transit_transfer_when_user_lacks_to_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            [$barcode, $transferSessionId] = $this->seedInTransitTransferBetween(
                (int) $siteA->getKey(),
                (int) $siteB->getKey(),
            );
            $this->transferSessionIds[] = $transferSessionId;

            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            $resolver = app(ResolveOpenReceiveUrl::class);

            $this->assertFalse($resolver->hasContext($barcode));
            $this->assertNull($resolver->handle($barcode, (int) $user->getKey()));
            $this->assertNull(
                ReceivingSession::query()->where('transferring_session_id', $transferSessionId)->first(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function resolve_open_receive_url_opens_asn_at_ship_to_not_current_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createEligibleReceiveSites($tenant);
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            $ssccUri = 'urn:epc:id:sscc:030116.01001227'.random_int(100, 999);
            $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.1000008200'.random_int(100000, 999999);
            $document = $this->ingestMinimalAsnFixture($ssccUri, $sgtinUri);
            $this->documentIds[] = (int) $document->getKey();
            $document->forceFill(['ship_to_site_id' => (int) $siteB->getKey()])->save();

            $sgtinEpc = Epc::query()->where('epc_uri', $sgtinUri)->firstOrFail();
            $barcode = '(01)'.$sgtinEpc->gtin14.'(21)'.$sgtinEpc->serial_number;

            $user = $this->createUserWithSites([
                (int) $siteA->getKey(),
                (int) $siteB->getKey(),
            ]);
            $this->actingAs($user);
            CurrentSite::set((int) $siteA->getKey());

            $resolver = app(ResolveOpenReceiveUrl::class);
            $this->assertTrue($resolver->hasContext($barcode));

            $url = $resolver->handle($barcode, (int) $user->getKey());
            $this->assertNotNull($url);

            $session = ReceivingSession::query()->where('epcis_document_id', $document->getKey())->first();
            $this->assertNotNull($session);
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertSame('open', $session->status);
            $this->assertSame((int) $siteB->getKey(), (int) $session->site_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function resolve_open_receive_url_allows_asn_when_ship_to_accessible_despite_stale_current_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createEligibleReceiveSites($tenant);
            $owner = $this->createOwnerUser();
            $this->actingAs($owner);

            $ssccUri = 'urn:epc:id:sscc:030116.01001227'.random_int(100, 999);
            $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.1000008200'.random_int(100000, 999999);
            $document = $this->ingestMinimalAsnFixture($ssccUri, $sgtinUri);
            $this->documentIds[] = (int) $document->getKey();
            $document->forceFill(['ship_to_site_id' => (int) $siteB->getKey()])->save();

            $sgtinEpc = Epc::query()->where('epc_uri', $sgtinUri)->firstOrFail();
            $barcode = '(01)'.$sgtinEpc->gtin14.'(21)'.$sgtinEpc->serial_number;

            $user = $this->createUserWithSites([(int) $siteB->getKey()]);
            $user->assignRole(TenantRole::ReceivingTechnician->value);
            $this->actingAs($user);
            session([CurrentSite::SESSION_KEY => (int) $siteA->getKey()]);

            $resolver = app(ResolveOpenReceiveUrl::class);
            $this->assertTrue($resolver->hasContext($barcode));

            $url = $resolver->handle($barcode, (int) $user->getKey());
            $this->assertNotNull($url);

            $session = ReceivingSession::query()->where('epcis_document_id', $document->getKey())->first();
            $this->assertNotNull($session);
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertSame((int) $siteB->getKey(), (int) $session->site_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function receiving_session_list_hides_other_sites(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);

            $sessionA = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::ScanFirst,
                'site_id' => $siteA->getKey(),
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $sessionB = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::ScanFirst,
                'site_id' => $siteB->getKey(),
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionIds = [(int) $sessionA->getKey(), (int) $sessionB->getKey()];

            $user = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($user);

            $visibleIds = ReceivingSessionResource::getEloquentQuery()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $this->assertContains((int) $sessionA->getKey(), $visibleIds);
            $this->assertNotContains((int) $sessionB->getKey(), $visibleIds);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createEligibleReceiveSites(Tenant $tenant): array
    {
        $siteA = Site::query()->create([
            'name' => 'Eligible Receive A '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $siteB = Site::query()->create([
            'name' => 'Eligible Receive B '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $settings->setDefaultShipFromSiteId((int) $siteA->getKey());
        $settings->setDefaultReceiveSiteId((int) $siteB->getKey());
        $tenant->save();

        return [$siteA, $siteB];
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(Tenant $tenant): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'Site A '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Site B '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
        ]);
        $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $settings->setDefaultShipFromSiteId((int) $siteA->getKey());
        $settings->setDefaultReceiveSiteId((int) $siteB->getKey());
        $tenant->save();

        return [$siteA, $siteB];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        $this->seedTenantRoles();

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function createOwnerUser(): User
    {
        $this->seedTenantRoles();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function seedTenantRoles(): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function seedInTransitTransferBetween(int $fromSiteId, int $toSiteId): array
    {
        $suffix = (string) random_int(100000, 999999);
        $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200'.substr($suffix, 0, 6);
        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        $transfer = TransferringSession::query()->create([
            'from_site_id' => $fromSiteId,
            'to_site_id' => $toSiteId,
            'status' => 'in_transit',
            'confirmed_count' => 1,
            'received_count' => 0,
            'opened_at' => now()->subHour(),
            'shipped_at' => now()->subMinute(),
            'transfer_events_generated_at' => now()->subMinute(),
        ]);

        TransferringScanLine::query()->create([
            'transferring_session_id' => $transfer->getKey(),
            'epc_id' => $epc->getKey(),
            'status' => 'confirmed',
            'confirmed_at' => now()->subMinute(),
        ]);

        return [
            '(01)'.$epc->gtin14.'(21)'.$epc->serial_number,
            (int) $transfer->getKey(),
        ];
    }

    private function ingestMinimalAsnFixture(
        string $ssccUri = 'urn:epc:id:sscc:030116.01001227052',
        string $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.10000082001560',
    ): EpcisDocument {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'site_access_asn_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) Str::uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        $xml = str_replace('urn:epc:id:sscc:030116.01001227052', $ssccUri, $xml);
        $xml = str_replace('urn:epc:id:sgtin:030116.0200116.10000082001560', $sgtinUri, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
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

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->scanLineIds !== []) {
                ReceivingScanLine::query()->whereIn('id', $this->scanLineIds)->delete();
                $this->scanLineIds = [];
            }

            if ($this->epcIds !== []) {
                DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
                Epc::query()->whereIn('id', $this->epcIds)->delete();
                $this->epcIds = [];
            }

            if ($this->transferSessionIds !== []) {
                ReceivingSession::query()
                    ->whereIn('transferring_session_id', $this->transferSessionIds)
                    ->delete();
                DB::table('transferring_scan_lines')
                    ->whereIn('transferring_session_id', $this->transferSessionIds)
                    ->delete();
                TransferringSession::query()->whereIn('id', $this->transferSessionIds)->delete();
                $this->transferSessionIds = [];
            }

            if ($this->documentIds !== []) {
                foreach ($this->documentIds as $documentId) {
                    ReceivingSession::query()->where('epcis_document_id', $documentId)->delete();
                    $eventIds = EpcisEvent::query()->where('document_id', $documentId)->pluck('id');
                    if ($eventIds->isNotEmpty()) {
                        DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                        EpcisEvent::query()->whereIn('id', $eventIds)->delete();
                    }
                }
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                $this->documentIds = [];
            }

            if ($this->outboundSessionIds !== []) {
                OutboundShippingSession::query()->whereIn('id', $this->outboundSessionIds)->delete();
                $this->outboundSessionIds = [];
            }

            if ($this->sessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $this->sessionIds)->delete();
                $this->sessionIds = [];
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            if ($this->siteIds !== []) {
                $orphanTransferIds = TransferringSession::query()
                    ->where(function ($query): void {
                        $query->whereIn('from_site_id', $this->siteIds)
                            ->orWhereIn('to_site_id', $this->siteIds);
                    })
                    ->pluck('id')
                    ->all();

                if ($orphanTransferIds !== []) {
                    ReceivingSession::query()
                        ->whereIn('transferring_session_id', $orphanTransferIds)
                        ->delete();
                    DB::table('transferring_scan_lines')
                        ->whereIn('transferring_session_id', $orphanTransferIds)
                        ->delete();
                    TransferringSession::query()->whereIn('id', $orphanTransferIds)->delete();
                }

                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            $settings = TenantSettings::forTenant($tenant);
            $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
            $tenant->save();
            $this->priorDefaultShipFromSiteId = null;
            $this->priorDefaultReceiveSiteId = null;

            tenancy()->end();
        }
    }
}
