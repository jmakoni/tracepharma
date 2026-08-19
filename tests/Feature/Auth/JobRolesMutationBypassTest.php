<?php

namespace Tests\Feature\Auth;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Quarantine\OpenQuarantineHold;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\FlagManualReceivingException;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Actions\Receiving\UnconfirmReceivingScanLine;
use App\Actions\Vrs\RunProductVerification;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\AssetTracking;
use App\Filament\App\Pages\OperationsHub;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Transferring\TransferringSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\Gtin;
use App\Support\Receiving\ResolveOpenReceiveUrl;
use Database\Seeders\ExceptionCaseSeeder;
use App\Support\TenantSettings;
use App\Support\Tracing\EpcContextLinks;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class JobRolesMutationBypassTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    private ?int $sessionId = null;

    private ?int $transferSessionId = null;

    private ?int $transferDocumentId = null;

    private ?int $epcId = null;

    private ?int $custodyDocumentId = null;

    private ?int $custodyEventId = null;

    private ?int $asnDocumentId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?int $outboundSessionId = null;

    private ?bool $priorJobRolesEnabled = null;

    #[Test]
    public function receive_only_user_cannot_unconfirm_receiving_scan_line(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $owner = $this->createOwnerActor();
            $this->actingAs($owner);
            $this->pauseOpenReceivingSessions();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200UNCF1';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $line = ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'unexpected',
            ]);

            $user = $this->createUserWithRole(TenantRole::OutboundPickAndPackLead);
            $this->actingAs($user);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Receiving is not authorized for your job role.');

            app(UnconfirmReceivingScanLine::class)->handle($line);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_only_user_cannot_update_outbound_shipping_party_or_references(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $shipSite = Site::query()->create([
                'name' => 'Secondary Ship Gate Site',
                'gln' => $this->uniqueGlnUnderCompanyPrefix(),
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $shipSite->getKey();
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $shipSite->getKey());
            $tenant->save();

            $owner = $this->createOwnerActor();
            $this->actingAs($owner);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $shipSite->getKey());
            $this->outboundSessionId = (int) $session->getKey();

            $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);
            $this->actingAs($user);

            foreach ([
                fn () => app(UpdateOutboundShippingParty::class)->handle($session->fresh(), []),
                fn () => app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), []),
            ] as $action) {
                try {
                    $action();
                    $this->fail('Expected DomainException for ship-only secondary action gate.');
                } catch (DomainException $e) {
                    $this->assertSame('Shipping is not authorized for your job role.', $e->getMessage());
                }
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function verify_only_user_ops_hub_scan_skips_receive_and_routes_to_asset_tracking(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $receiveSite = Site::query()->create([
                'name' => 'Verify Only Receive Site',
                'gln' => $this->uniqueGlnUnderCompanyPrefix(),
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $receiveSite->getKey();
            TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId((int) $receiveSite->getKey());
            $tenant->save();

            $owner = $this->createOwnerActor();
            $this->actingAs($owner);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->pauseOpenReceivingSessions();

            $only = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $receiveSite->getKey());
            $this->sessionId = (int) $only->getKey();

            $user = $this->createUserWithRole(TenantRole::VrsAnalyst);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertTrue(AssetTracking::canAccess());

            $barcode = '(01)30301164005162(21)VERIFYONLY1';
            $normalized = ElementString::normalize($barcode);

            Livewire::test(OperationsHub::class)
                ->set('hubScan', $barcode)
                ->call('routeHubScan')
                ->assertRedirect(AssetTracking::getUrl(['scan' => $normalized]));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function verify_only_user_epc_context_links_omits_open_receive_for_in_transit_transfer(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = $this->createUserWithRole(TenantRole::VrsAnalyst);
            [$epc, , $scan] = $this->seedInTransitTransferData($tenant);

            $this->actingAs($user);

            $links = collect(app(EpcContextLinks::class)->forEpc($epc, $scan, (int) $user->getKey()))
                ->keyBy('key');

            $this->assertFalse($links->has('open_receive'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function integrations_user_without_receive_omits_open_receive_from_epc_context_links(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Logistics3pl);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = $this->createUserWithRole(TenantRole::WmsIntegrationSpecialist);
            [$epc, , $scan] = $this->seedInTransitTransferData($tenant);

            $this->actingAs($user);

            $links = collect(app(EpcContextLinks::class)->forEpc($epc, $scan, (int) $user->getKey()))
                ->keyBy('key');

            $this->assertFalse($links->has('open_receive'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function integrations_user_without_receive_hides_asset_tracking_open_receive_action(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Logistics3pl);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            [, $transfer, $scan] = $this->seedInTransitTransfer($tenant);

            $user = $this->createUserWithRole(TenantRole::WmsIntegrationSpecialist);
            $user->sites()->attach((int) $transfer->from_site_id);

            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(AssetTracking::class)
                ->set('scan', $scan)
                ->call('runTrace')
                ->assertSet('trace.found', true)
                ->assertActionHidden('open_receive');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_only_user_cannot_open_scan_first_receiving_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $receiveSite = Site::query()->create([
                'name' => 'Ship Only Blocked Receive Site',
                'gln' => $this->uniqueGlnUnderCompanyPrefix(),
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $receiveSite->getKey();

            $user = $this->createUserWithRole(TenantRole::OutboundPickAndPackLead);
            $this->actingAs($user);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Receiving is not authorized for your job role.');

            app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $receiveSite->getKey());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_only_user_cannot_confirm_receiving_scan(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $receiveSite = Site::query()->create([
                'name' => 'Ship Only Blocked Confirm Site',
                'gln' => $this->uniqueGlnUnderCompanyPrefix(),
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $receiveSite->getKey();

            $owner = $this->createOwnerActor();
            $this->actingAs($owner);
            $this->pauseOpenReceivingSessions();

            $session = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $receiveSite->getKey());
            $this->sessionId = (int) $session->getKey();

            $user = $this->createUserWithRole(TenantRole::OutboundPickAndPackLead);
            $this->actingAs($user);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Receiving is not authorized for your job role.');

            app(ConfirmReceivingScan::class)->handle($session->fresh(), '(01)30301164005162(21)SHIPONLYBLK1');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function receive_only_user_cannot_open_outbound_shipping_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $shipSite = Site::query()->create([
                'name' => 'Receive Only Blocked Ship Site',
                'gln' => $this->uniqueGlnUnderCompanyPrefix(),
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $shipSite->getKey();
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $shipSite->getKey());
            $tenant->save();

            $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);
            $this->actingAs($user);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Shipping is not authorized for your job role.');

            app(OpenOutboundShippingSession::class)->handle((int) $shipSite->getKey());
        } finally {
            $this->cleanup($tenant);
        }
    }

    private ?int $exceptionCaseId = null;

    private ?int $quarantineHoldId = null;

    #[Test]
    public function receive_only_user_can_open_quarantine_hold_via_receiving_exception_wrapper(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->seed(ExceptionCaseSeeder::class);

            $owner = $this->createOwnerActor();
            $this->actingAs($owner);

            $session = $this->seedCompletedReceivingSessionWithOverage($owner);
            $overageEpcId = (int) ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'unexpected')
                ->value('epc_id');

            $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);
            $user->sites()->attach((int) $session->site_id);
            $this->actingAs($user);

            $case = app(FlagManualReceivingException::class)->execute(
                $session->fresh(),
                'overage',
                ['notes' => 'Receive-only overage quarantine test'],
                $user,
            );
            $this->exceptionCaseId = (int) $case->getKey();

            $hold = QuarantineHold::query()
                ->open()
                ->where('exception_id', $case->getKey())
                ->where('epc_id', $overageEpcId)
                ->first();

            $this->assertNotNull($hold);
            $this->quarantineHoldId = (int) $hold->getKey();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function verify_only_user_can_open_quarantine_hold_via_vrs_wrapper(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->seed(ExceptionCaseSeeder::class);

            $suffix = substr((string) str()->uuid(), 0, 8);
            $serial = "FAIL-JR{$suffix}";
            $uri = "urn:epc:id:sgtin:030116.0200116.{$serial}";
            $epc = Epc::query()->create(array_merge(
                Epc::materializeAttributesFromUri($uri),
                ['first_seen_at' => now()],
            ));
            $this->epcId = (int) $epc->getKey();

            $user = $this->createUserWithRole(TenantRole::VrsAnalyst);
            $this->actingAs($user);

            $result = app(RunProductVerification::class)->handle(
                '(01)'.$epc->gtin14.'(21)'.$serial,
                $user,
            );

            $this->assertSame('failed', $result['verification']->status);
            $this->assertNotNull($result['exception_id']);
            $this->exceptionCaseId = (int) $result['exception_id'];

            $this->assertTrue(
                QuarantineHold::query()
                    ->open()
                    ->where('exception_id', $result['exception_id'])
                    ->where('epc_id', $epc->getKey())
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function receive_only_user_can_open_quarantine_hold_when_exception_case_supplied(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->seed(ExceptionCaseSeeder::class);

            $suffix = substr((string) str()->uuid(), 0, 8);
            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.r{$suffix}",
                'gtin14' => '00301162001162',
                'serial_number' => "r{$suffix}",
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcId = (int) $epc->getKey();

            $typeId = \App\Models\Exceptions\ExceptionType::query()
                ->where('code', 'SUSPECT_PRODUCT')
                ->value('id');
            $case = ExceptionCase::query()->create([
                'exception_type_id' => $typeId,
                'title' => 'Receive-only hold bind test',
                'description' => 'Case supplied for receive-only hold',
                'severity' => 'critical',
                'status' => 'new',
            ]);
            $this->exceptionCaseId = (int) $case->getKey();

            $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);
            $this->actingAs($user);

            $hold = app(OpenQuarantineHold::class)->handle(
                reason: 'Receive-only with case',
                epc: $epc,
                exception: $case,
            );
            $this->quarantineHoldId = (int) $hold->getKey();

            $this->assertTrue($hold->wasRecentlyCreated);
            $this->assertSame((int) $case->getKey(), (int) $hold->exception_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function non_exceptions_user_cannot_open_quarantine_hold(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);
            $this->actingAs($user);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Exceptions are not authorized for your job role.');

            app(OpenQuarantineHold::class)->handle(reason: 'Unauthorized hold attempt');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_only_user_cannot_open_transfer_receiving_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            [, $transfer] = $this->seedInTransitTransfer($tenant);
            $user = $this->createUserWithRole(TenantRole::OutboundPickAndPackLead);
            $this->actingAs($user);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Receiving is not authorized for your job role.');

            app(OpenTransferReceivingSession::class)->handle($transfer->fresh());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_only_user_cannot_open_receiving_session_from_document(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $owner = $this->createOwnerActor();
            $this->actingAs($owner);

            $document = $this->ingestAsnFixture(
                'urn:epc:id:sscc:030116.01001227099',
                'urn:epc:id:sgtin:030116.0200116.10000082009992',
            );
            $this->asnDocumentId = (int) $document->getKey();

            $user = $this->createUserWithRole(TenantRole::OutboundPickAndPackLead);
            $this->actingAs($user);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Receiving is not authorized for your job role.');

            app(OpenReceivingSessionFromDocument::class)->handle($document->fresh());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function verify_only_user_resolve_open_receive_url_does_not_open_asn_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $owner = $this->createOwnerActor();
            $this->actingAs($owner);

            $document = $this->ingestAsnFixture(
                'urn:epc:id:sscc:030116.01001227098',
                'urn:epc:id:sgtin:030116.0200116.10000082009993',
            );
            $this->asnDocumentId = (int) $document->getKey();

            $sgtinEpc = Epc::query()->where('epc_uri', 'urn:epc:id:sgtin:030116.0200116.10000082009993')->firstOrFail();
            $barcode = '(01)'.$sgtinEpc->gtin14.'(21)'.$sgtinEpc->serial_number;

            $user = $this->createUserWithRole(TenantRole::VrsAnalyst);
            $this->actingAs($user);

            $resolver = app(ResolveOpenReceiveUrl::class);
            $this->assertFalse($resolver->hasContext($barcode));
            $this->assertNull($resolver->handle($barcode, (int) $user->getKey()));
            $this->assertNull(
                ReceivingSession::query()->where('epcis_document_id', $document->getKey())->first(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: Epc, 1: TransferringSession, 2: string}
     */
    private function seedInTransitTransfer(Tenant $tenant): array
    {
        [$epc, , $scan] = $this->seedInTransitTransferData($tenant);

        return [$epc, TransferringSession::query()->findOrFail($this->transferSessionId), $scan];
    }

    /**
     * @return array{0: Epc, 1: string, 2: string}
     */
    private function seedInTransitTransferData(Tenant $tenant): array
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $owner = $this->createOwnerActor();
        $this->actingAs($owner);

        $fromGln = $this->uniqueGlnUnderCompanyPrefix();
        $toGln = $this->uniqueGlnUnderCompanyPrefix();

        $fromSite = Site::query()->create([
            'name' => 'Bypass Transfer From '.Str::random(6),
            'gln' => $fromGln,
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Bypass Transfer To '.Str::random(6),
            'gln' => $toGln,
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

        $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200BYP1';
        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcId = (int) $epc->getKey();

        $custodyDocument = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'bypass-transfer-custody.xml',
        ]);
        $this->custodyDocumentId = (int) $custodyDocument->getKey();

        $custodyEvent = EpcisEvent::query()->create([
            'document_id' => $custodyDocument->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinute(),
            'record_time' => now()->subMinute(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => $fromGln,
            'biz_location_gln' => $fromGln,
        ]);
        $this->custodyEventId = (int) $custodyEvent->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $custodyEvent->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);

        $transfer = app(OpenTransferringSession::class)->handle(
            fromSiteId: (int) $fromSite->getKey(),
            toSiteId: (int) $toSite->getKey(),
            openedBy: (int) $owner->getKey(),
        );
        $this->transferSessionId = (int) $transfer->getKey();

        app(ConfirmTransferringScan::class)->handle($transfer, $uri, (int) $owner->getKey());
        $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
        $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

        $scan = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;

        return [$epc->fresh(), $uri, $scan];
    }

    private function ingestAsnFixture(string $ssccUri, string $sgtinUri): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        $xml = str_replace('urn:epc:id:sscc:030116.01001227052', $ssccUri, $xml);
        $xml = str_replace('urn:epc:id:sgtin:030116.0200116.10000082001560', $sgtinUri, $xml);

        $tmp = tempnam(sys_get_temp_dir(), 'job_roles_asn_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'job_roles_asn.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function enableJobRoles(Tenant $tenant): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
        $tenant->save();
    }

    private function createUserWithRole(TenantRole $role): User
    {
        $user = User::factory()->create([
            'email' => 'jobroles-'.Str::lower(Str::random(12)).'@example.test',
        ]);
        $user->syncRoles([$role->value]);
        $user->refresh();

        return $user;
    }

    private function createOwnerActor(): User
    {
        $user = User::factory()->create([
            'email' => 'jobroles-owner-'.Str::lower(Str::random(12)).'@example.test',
        ]);
        $user->syncRoles([TenantRole::Owner->value]);
        $user->refresh();

        return $user;
    }

    /** @var array<int, string> */
    private array $pausedSessionStatuses = [];

    private function pauseOpenReceivingSessions(): void
    {
        $open = ReceivingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->get(['id', 'status']);

        foreach ($open as $session) {
            $this->pausedSessionStatuses[(int) $session->getKey()] = (string) $session->status;
            $session->forceFill(['status' => 'cancelled'])->save();
        }
    }

    private function uniqueGlnUnderCompanyPrefix(): string
    {
        $prefix = TenantSettings::forTenant(tenant())->companyPrefix() ?? '0399991';
        $prefix = preg_replace('/\D+/', '', $prefix) ?: '0399991';
        $locationLen = 12 - strlen($prefix);

        do {
            $location = str_pad((string) random_int(0, (10 ** $locationLen) - 1), $locationLen, '0', STR_PAD_LEFT);
            $body = $prefix.$location;
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
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->priorJobRolesEnabled = TenantSettings::forTenant($tenant)->jobRolesEnabled();

        return $tenant;
    }

    private function seedCompletedReceivingSessionWithOverage(User $owner): ReceivingSession
    {
        $site = Site::query()->create([
            'name' => 'Job Roles Receive Site '.Str::random(6),
            'gln' => $this->uniqueGlnUnderCompanyPrefix(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'inbound',
            'status' => 'validated',
            'original_filename' => 'job-roles-receive-overage.xml',
        ]);
        $this->asnDocumentId = (int) $document->getKey();

        $session = ReceivingSession::query()->create([
            'session_kind' => ReceivingSessionKind::InboundAsn,
            'epcis_document_id' => $document->getKey(),
            'trading_partner_id' => $document->trading_partner_id,
            'site_id' => $site->getKey(),
            'status' => 'completed',
            'expected_parent_count' => 1,
            'confirmed_parent_count' => 0,
            'expected_child_count' => 0,
            'confirmed_child_count' => 0,
            'opened_by' => $owner->getKey(),
            'opened_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
        $this->sessionId = (int) $session->getKey();

        $suffix = substr((string) str()->uuid(), 0, 8);
        $overageEpc = Epc::query()->create([
            'epc_type' => 'sscc',
            'epc_uri' => 'urn:epc:id:sscc:030116.8'.$suffix,
            'sscc18' => '003011680'.substr(preg_replace('/\D/', '', $suffix) ?: '1', 0, 9),
            'company_prefix' => '030116',
            'first_seen_at' => now(),
        ]);
        $this->epcId = (int) $overageEpc->getKey();

        ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $overageEpc->getKey(),
            'line_role' => 'parent',
            'status' => 'unexpected',
            'confirmed_at' => now(),
            'confirmed_by' => $owner->getKey(),
            'scan_raw' => $overageEpc->sscc18,
        ]);

        return $session->fresh() ?? $session;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->exceptionCaseId !== null) {
            $case = ExceptionCase::query()->find($this->exceptionCaseId);
            if ($case !== null) {
                $case->activities()->delete();
                QuarantineHold::query()->where('exception_id', $this->exceptionCaseId)->delete();
                $case->epcs()->detach();
                $case->delete();
            }
            $this->exceptionCaseId = null;
        }

        if ($this->quarantineHoldId !== null) {
            QuarantineHold::query()->whereKey($this->quarantineHoldId)->delete();
            $this->quarantineHoldId = null;
        }

        if ($this->outboundSessionId !== null) {
            OutboundShippingSession::query()->whereKey($this->outboundSessionId)->delete();
            $this->outboundSessionId = null;
        }

        if ($this->sessionId !== null) {
            ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        foreach ($this->pausedSessionStatuses as $id => $status) {
            ReceivingSession::query()->whereKey($id)->update(['status' => $status]);
        }
        $this->pausedSessionStatuses = [];

        if ($this->transferDocumentId !== null) {
            EpcisDocument::query()->whereKey($this->transferDocumentId)->delete();
            $this->transferDocumentId = null;
        }

        if ($this->transferSessionId !== null) {
            ReceivingSession::query()
                ->where('transferring_session_id', $this->transferSessionId)
                ->delete();
            TransferringSession::query()->whereKey($this->transferSessionId)->delete();
            $this->transferSessionId = null;
        }

        if ($this->epcId !== null) {
            QuarantineHold::query()->where('epc_id', $this->epcId)->delete();
            DB::table('event_epcs')->where('epc_id', $this->epcId)->delete();
            Epc::query()->whereKey($this->epcId)->delete();
            $this->epcId = null;
        }

        if ($this->custodyEventId !== null) {
            EpcisEvent::query()->whereKey($this->custodyEventId)->delete();
            $this->custodyEventId = null;
        }

        if ($this->custodyDocumentId !== null) {
            EpcisDocument::query()->whereKey($this->custodyDocumentId)->delete();
            $this->custodyDocumentId = null;
        }

        if ($this->asnDocumentId !== null) {
            ReceivingSession::query()->where('epcis_document_id', $this->asnDocumentId)->delete();
            EpcisDocument::query()->whereKey($this->asnDocumentId)->delete();
            $this->asnDocumentId = null;
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        $settings = TenantSettings::forTenant($tenant);
        $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
        $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);

        if ($this->priorJobRolesEnabled !== null) {
            $settings->setJobRolesEnabled($this->priorJobRolesEnabled);
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $tenant->save();
        tenancy()->end();

        $this->priorDefaultShipFromSiteId = null;
        $this->priorDefaultReceiveSiteId = null;
        $this->priorJobRolesEnabled = null;
    }
}
