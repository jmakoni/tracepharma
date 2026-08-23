<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\CopyConfirmedReceivingScansToSession;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Actions\Receiving\PropagateScanFirstConfirmsToAsnSession;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ResolveReceiveScanContext;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScanFirstReceivingTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    private ?int $sessionId = null;

    private ?int $asnSessionId = null;

    private ?int $receivingDocumentId = null;

    private ?int $sourceDocumentId = null;

    private ?int $offManifestDocumentId = null;

    private ?int $epcId = null;

    private ?int $transferSessionId = null;

    private ?int $transferDocumentId = null;

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?bool $priorRequireTi = null;

    private ?string $epcUri = null;

    /** @var list<string> */
    private array $uniqueEpcUris = [];

    #[Test]
    public function scan_first_confirm_reconciles_expected_line_on_asn_session_with_null_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $owner->getKey();
            $this->actingAs($owner);

            $document = $this->ingestMinimalFixture();
            $this->sourceDocumentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $asnSession->forceFill(['site_id' => null])->save();
            $this->asnSessionId = (int) $asnSession->getKey();

            $parentEpcId = (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');
            $this->assertGreaterThan(0, $parentEpcId);

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, self::SSCC_URI);
            $this->assertTrue($confirm['ok']);
            $this->assertSame($this->asnSessionId, (int) $confirm['reconciled_asn_session_id']);

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->asnSessionId)
                ->where('epc_id', $parentEpcId)
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('confirmed', $asnLine->status);
            $this->assertNotNull($asnLine->confirmed_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function propagate_includes_completed_scan_first_for_legacy_asn_with_null_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->sourceDocumentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, self::SSCC_URI);
            $this->assertTrue($confirm['ok']);
            $this->assertNull($confirm['reconciled_asn_session_id']);

            app(CompleteReceivingSession::class)->handle($scanFirst->fresh());
            $this->assertSame('completed', $scanFirst->fresh()->status);

            $parentEpcId = (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');
            $this->assertGreaterThan(0, $parentEpcId);

            $asnSession = $this->createLegacyAsnSessionWithNullSite($document, $parentEpcId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $result = app(PropagateScanFirstConfirmsToAsnSession::class)->handle($asnSession->fresh());
            $this->assertGreaterThan(0, $result['copied']);

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->asnSessionId)
                ->where('epc_id', $parentEpcId)
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('confirmed', $asnLine->status);
            $this->assertNotNull($asnLine->confirmed_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function viewing_legacy_asn_backfills_site_and_propagates_completed_scan_first(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->sourceDocumentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            app(ConfirmReceivingScan::class)->handle($scanFirst, self::SSCC_URI);
            app(CompleteReceivingSession::class)->handle($scanFirst->fresh());

            $parentEpcId = (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');

            $asnSession = $this->createLegacyAsnSessionWithNullSite($document, $parentEpcId);
            $this->asnSessionId = (int) $asnSession->getKey();

            Livewire::test(ViewReceivingSession::class, ['record' => $this->asnSessionId])
                ->assertSuccessful();

            $asnSession->refresh();
            $this->assertNotNull($asnSession->site_id);

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->asnSessionId)
                ->where('epc_id', $parentEpcId)
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('confirmed', $asnLine->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_confirm_reconciles_expected_line_on_open_asn_session_at_same_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->sourceDocumentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $parentEpcId = (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');
            $this->assertGreaterThan(0, $parentEpcId);

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, self::SSCC_URI);
            $this->assertTrue($confirm['ok']);
            $this->assertSame($this->asnSessionId, (int) $confirm['reconciled_asn_session_id']);

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->asnSessionId)
                ->where('epc_id', $parentEpcId)
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('confirmed', $asnLine->status);
            $this->assertNotNull($asnLine->confirmed_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function opening_asn_session_backfills_prior_scan_first_confirms_at_same_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->sourceDocumentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, self::SSCC_URI);
            $this->assertTrue($confirm['ok']);
            $this->assertNull($confirm['reconciled_asn_session_id']);

            $parentEpcId = (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->asnSessionId)
                ->where('epc_id', $parentEpcId)
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('confirmed', $asnLine->status);

            $scanFirst->refresh();
            $this->assertSame('in_progress', $scanFirst->status);
            $this->assertNotSame('cancelled', $scanFirst->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_confirm_does_not_reconcile_asn_at_different_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$ssccUri, $document] = $this->ingestUniqueMinimalFixture();
            $this->sourceDocumentId = (int) $document->getKey();

            [$siteA, $siteB] = $this->createReceiveSites($tenant);

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle(
                $document,
                (int) $siteA->getKey(),
            );
            $this->asnSessionId = (int) $asnSession->getKey();

            $parentEpcId = (int) Epc::query()->where('epc_uri', $ssccUri)->value('id');
            $this->epcId = $parentEpcId;

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle((int) $siteB->getKey());
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, $ssccUri);
            $this->assertTrue($confirm['ok']);
            $this->assertNull($confirm['reconciled_asn_session_id']);

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->asnSessionId)
                ->where('epc_id', $parentEpcId)
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('expected', $asnLine->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_confirm_at_site_b_fails_when_epc_on_hand_at_site_a(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$siteA, $siteB] = $this->createReceiveSites($tenant);

            [$epc, $uri] = $this->createEpcWithShippingEvent();
            $this->receiveAtSite($siteA, $epc);

            $this->assertTrue(
                app(ShippableEpcsAtSite::class)->contains(
                    (int) $siteA->getKey(),
                    (int) $epc->getKey(),
                ),
                'Unit should be on hand at site A before the cross-site scan.',
            );

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle((int) $siteB->getKey());
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, $uri);

            $this->assertFalse($confirm['ok']);
            $this->assertSame('not_at_receive_site', $confirm['effect']);
            $this->assertSame(
                'This unit is on hand at another site. Receive it there or transfer it first.',
                $confirm['message'],
            );
            $this->assertNull($confirm['line']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function site_restricted_user_cannot_reconcile_null_site_asn(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            [$siteA] = $this->createReceiveSites($tenant);
            $siteId = (int) $siteA->getKey();

            $user = User::factory()->create();
            $user->syncSites([$siteId]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            [$epc, $uri] = $this->createEpcWithShippingEvent();

            $asnSession = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::InboundAsn,
                'epcis_document_id' => $this->sourceDocumentId,
                'site_id' => null,
                'status' => 'open',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->asnSessionId = (int) $asnSession->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $asnSession->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'expected',
            ]);

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, $uri, (int) $user->getKey());
            $this->assertTrue($confirm['ok']);
            $this->assertNull($confirm['reconciled_asn_session_id']);

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->asnSessionId)
                ->where('epc_id', $epc->getKey())
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('expected', $asnLine->status);
            $this->assertNull($asnLine->confirmed_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_confirm_fails_when_session_site_is_null(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$epc, $uri] = $this->createEpcWithShippingEvent();

            $nullSiteSession = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::ScanFirst,
                'epcis_document_id' => null,
                'transferring_session_id' => null,
                'matched_epcis_document_id' => null,
                'trading_partner_id' => null,
                'site_id' => null,
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionId = (int) $nullSiteSession->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($nullSiteSession, $uri);
            $this->assertFalse($confirm['ok']);
            $this->assertSame('no_receive_site', $confirm['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_off_manifest_confirm_does_not_confirm_unrelated_asn_expected_lines(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->sourceDocumentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $parentEpcId = (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');

            [, $offManifestUri] = $this->createEpcWithShippingEvent();
            $this->offManifestDocumentId = (int) $this->sourceDocumentId;
            $this->sourceDocumentId = (int) $document->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, $offManifestUri);
            $this->assertTrue($confirm['ok']);
            $this->assertNull($confirm['reconciled_asn_session_id']);

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->asnSessionId)
                ->where('epc_id', $parentEpcId)
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('expected', $asnLine->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_confirm_with_shipping_event_then_manual_complete_emits_receiving(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$epc, $uri] = $this->createEpcWithShippingEvent();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $this->assertSame(ReceivingSessionKind::ScanFirst, $session->session_kind);
            $this->assertNull($session->epcis_document_id);

            $confirm = app(ConfirmReceivingScan::class)->handle($session, $uri);
            $this->assertTrue($confirm['ok']);
            $this->assertSame('child_confirmed', $confirm['effect']);
            $this->assertTrue($confirm['has_ti']);
            $this->assertNull($confirm['ti_warning']);

            $session->refresh();
            $this->assertSame('in_progress', $session->status);
            $this->assertSame(1, (int) $session->confirmed_child_count);
            $this->assertNull($session->receiving_events_generated_at);

            $completed = app(CompleteReceivingSession::class)->handle($session->fresh());
            $this->assertSame('completed', $completed->status);
            $this->assertNotNull($completed->receiving_events_generated_at);
            $this->assertNotNull($completed->receiving_epcis_document_id);
            $this->receivingDocumentId = (int) $completed->receiving_epcis_document_id;

            $receivingDoc = EpcisDocument::query()->findOrFail($completed->receiving_epcis_document_id);
            $this->assertSame('outbound', $receivingDoc->direction);

            $event = EpcisEvent::query()
                ->where('document_id', $receivingDoc->getKey())
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                ->firstOrFail();

            $this->assertSame('OBSERVE', $event->action);
            $this->assertSame(1, DB::table('event_epcs')
                ->where('event_id', $event->getKey())
                ->where('epc_id', $epc->getKey())
                ->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_matches_inbound_asn_chip_and_can_open_asn_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [, $uri] = $this->createEpcWithShippingEvent();
            $inboundDocumentId = (int) $this->sourceDocumentId;

            $this->assertSame(0, ReceivingSession::query()
                ->where('epcis_document_id', $inboundDocumentId)
                ->whereIn('status', ['open', 'in_progress'])
                ->count());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $context = app(ResolveReceiveScanContext::class)->handle($uri, $session);
            $this->assertTrue($context['ok']);
            $this->assertSame($inboundDocumentId, (int) $context['matched_inbound_document_id']);
            $this->assertNotNull($context['matched_inbound_document']);

            $confirm = app(ConfirmReceivingScan::class)->handle($session, $uri);
            $this->assertTrue($confirm['ok']);
            $this->assertSame($inboundDocumentId, (int) $confirm['matched_asn_document_id']);

            $session->refresh();
            $this->assertSame($inboundDocumentId, (int) $session->matched_epcis_document_id);
            $this->assertSame(ReceivingSessionKind::ScanFirst, $session->session_kind);
            $this->assertNull($session->epcis_document_id);

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle(
                EpcisDocument::query()->findOrFail($inboundDocumentId),
            );
            $this->asnSessionId = (int) $asnSession->getKey();

            $this->assertSame(ReceivingSessionKind::InboundAsn, $asnSession->session_kind);
            $this->assertSame($inboundDocumentId, (int) $asnSession->epcis_document_id);
            $this->assertNotSame($session->getKey(), $asnSession->getKey());

            // Match ASN cancels the prior scan-first session so Ops Hub sees one active.
            $this->assertTrue($session->refresh()->cancelOpen());
            $this->assertSame('cancelled', $session->fresh()->status);
            $this->assertNotNull($session->fresh()->completed_at);
            $this->assertSame('open', $asnSession->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function matched_asn_chip_shows_asn_number_not_filename(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            [, $uri] = $this->createEpcWithShippingEvent();
            $inboundDocumentId = (int) $this->sourceDocumentId;
            $asnNumber = 'ASN-CHIP-'.random_int(100000, 999999);
            $filename = 'ou_xttrium_prod_dc_long_processed_data.xml';

            EpcisDocument::query()->whereKey($inboundDocumentId)->update([
                'asn_number' => $asnNumber,
                'original_filename' => $filename,
            ]);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($session, $uri);
            $this->assertTrue($confirm['ok']);
            $this->assertSame($inboundDocumentId, (int) $confirm['matched_asn_document_id']);

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSet('chipMatchedAsnDocumentId', $inboundDocumentId)
                ->assertSet('chipMatchedAsnLabel', $asnNumber)
                ->assertSee('Matched ASN: '.$asnNumber)
                ->assertDontSee($filename);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function match_asn_action_cancels_prior_scan_first_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            [, $uri] = $this->createEpcWithShippingEvent();
            $inboundDocumentId = (int) $this->sourceDocumentId;

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($session, $uri);
            $this->assertTrue($confirm['ok']);
            $this->assertSame($inboundDocumentId, (int) $confirm['matched_asn_document_id']);

            $confirmedEpcId = (int) ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->value('epc_id');
            $this->assertGreaterThan(0, $confirmedEpcId);

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->callAction('matchAsn')
                ->assertRedirect();

            $session->refresh();
            $this->assertSame('cancelled', $session->status);
            $this->assertNotNull($session->completed_at);

            $asnSession = ReceivingSession::query()
                ->where('epcis_document_id', $inboundDocumentId)
                ->where('session_kind', ReceivingSessionKind::InboundAsn)
                ->latest('id')
                ->first();

            $this->assertNotNull($asnSession);
            $this->asnSessionId = (int) $asnSession->getKey();
            $this->assertNotSame($session->getKey(), $asnSession->getKey());
            $this->assertContains($asnSession->status, ['open', 'in_progress', 'completed']);
            $this->assertStringContainsString(
                'receiving-sessions/'.$asnSession->getKey(),
                ReceivingSessionResource::getUrl('view', ['record' => $asnSession], panel: 'app'),
            );

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $asnSession->getKey())
                ->where('epc_id', $confirmedEpcId)
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('confirmed', $asnLine->status);
            $this->assertNotNull($asnLine->confirmed_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function soft_ti_warning_is_surfaced_in_confirm_scan_feedback(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->priorRequireTi = TenantSettings::forTenant($tenant)->requireTiForScanFirst();
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SF'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->set('scan', $uri)
                ->callAction('confirmScan');

            $this->assertSame('warn', $component->get('lastScanTone'));
            $this->assertStringContainsString('TI missing', (string) $component->get('lastScanMessage'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_context_ignores_unvalidated_inbound_events_as_ti(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$validEpc] = $this->createEpcWithShippingEvent();
            $validContext = app(ResolveReceiveScanContext::class)->handle((string) $validEpc->epc_uri);
            $this->assertTrue($validContext['has_ti']);
            $this->assertSame($this->sourceDocumentId, $validContext['matched_inbound_document_id']);

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.ER'.$suffix;
            $this->epcUri = $uri;

            $errorDocument = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now()->subHour(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'scan-first-error-ti.xml',
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/scan-first-error-ti-'.Str::uuid().'.xml',
                'dscsa_affirm' => false,
                'status' => 'error',
                'ingest_generation' => 1,
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now()->subHour(),
            ]);
            $this->offManifestDocumentId = (int) $errorDocument->getKey();

            $errorEpc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $errorEpc->getKey();

            $shippingEvent = EpcisEvent::query()->create([
                'document_id' => $errorDocument->getKey(),
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinutes(10),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            ]);

            DB::table('event_epcs')->insert([
                'event_id' => $shippingEvent->getKey(),
                'epc_id' => $errorEpc->getKey(),
                'role' => 'epcList',
            ]);

            $errorContext = app(ResolveReceiveScanContext::class)->handle($uri);
            $this->assertFalse($errorContext['has_ti']);
            $this->assertNull($errorContext['matched_inbound_document_id']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_context_keeps_ti_after_failed_reprocess_of_validated_file(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$epc] = $this->createEpcWithShippingEvent();
            $document = EpcisDocument::query()->findOrFail($this->sourceDocumentId);
            $document->forceFill([
                'ingest_generation' => 1,
                'processed_at' => now()->subHour(),
            ])->save();
            EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->update(['ingest_generation' => 1]);

            $before = app(ResolveReceiveScanContext::class)->handle((string) $epc->epc_uri);
            $this->assertTrue($before['has_ti']);
            $this->assertSame($document->getKey(), $before['matched_inbound_document_id']);

            $document->forceFill([
                'status' => 'error',
                'error_message' => 'Forced reprocess validation failure.',
            ])->save();

            $after = app(ResolveReceiveScanContext::class)->handle((string) $epc->epc_uri);
            $this->assertTrue($after['has_ti'], 'Last-good projection must still count as TI after failed reprocess.');
            $this->assertSame($document->getKey(), $after['matched_inbound_document_id']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function require_ti_hard_blocks_scan_first_when_no_ti(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->priorRequireTi = TenantSettings::forTenant($tenant)->requireTiForScanFirst();
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(true);
            $tenant->save();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SF'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($session, $uri);

            $this->assertFalse($confirm['ok']);
            $this->assertSame('ti_required', $confirm['effect']);
            $this->assertStringContainsString('TI required', $confirm['message']);
            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->count());
            $this->assertSame('open', $session->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function open_transfer_receiving_session_confirm_all_completes_transfer(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer, $uri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->assertSame('in_transit', $shipped->status);
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $receiving = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $receiving->getKey();

            $this->assertSame(ReceivingSessionKind::TransferReceive, $receiving->session_kind);
            $this->assertSame((int) $shipped->getKey(), (int) $receiving->transferring_session_id);
            $this->assertSame(1, (int) $receiving->expected_parent_count);
            $this->assertSame(1, ReceivingScanLine::query()
                ->where('receiving_session_id', $receiving->getKey())
                ->where('status', 'expected')
                ->count());

            $confirm = app(ConfirmReceivingScan::class)->handle($receiving, $uri);
            $this->assertTrue($confirm['ok']);
            $this->assertTrue($confirm['session_completed']);
            $this->assertSame('completed', $confirm['effect']);

            $receiving->refresh();
            $this->assertSame('completed', $receiving->status);
            $this->assertNotNull($receiving->completed_at);

            $transferFresh = $shipped->fresh();
            $this->assertSame('completed', $transferFresh->status);
            $this->assertNotNull($transferFresh->receive_events_generated_at);
            $this->assertSame(1, (int) $transferFresh->received_count);

            $receivingEvent = EpcisEvent::query()
                ->where('document_id', $transferFresh->transfer_epcis_document_id)
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                ->first();
            $this->assertNotNull($receivingEvent);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_dual_write_fails_closed_when_transfer_receive_confirm_fails(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer, $uri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $transferReceive = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->asnSessionId = (int) $transferReceive->getKey();

            // Break in-transit receive so dual-write cannot mark the transferring line received.
            $shipped->forceFill(['status' => 'open'])->save();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle((int) $toSite->getKey());
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, $uri);
            $this->assertFalse($confirm['ok']);
            $this->assertSame('transfer_reconcile_failed', $confirm['effect']);
            $this->assertStringContainsString('in transit', strtolower($confirm['message']));
            $this->assertNull($confirm['reconciled_transfer_receive_session_id'] ?? null);

            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $scanFirst->getKey())
                ->where('status', 'confirmed')
                ->count());

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $this->transferSessionId)
                ->where('epc_id', $this->epcId)
                ->first();

            $this->assertNotNull($transferLine);
            $this->assertSame('confirmed', $transferLine->status);
            $this->assertNull($transferLine->received_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_transfer_receive_completion_error_is_visible_on_result(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer, $uri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $transferReceive = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->asnSessionId = (int) $transferReceive->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle((int) $toSite->getKey());
            $this->sessionId = (int) $scanFirst->getKey();

            $toSite->forceFill(['gln' => null])->save();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, $uri);
            $this->assertTrue($confirm['ok']);
            $this->assertArrayHasKey('completion_error', $confirm);
            $this->assertStringContainsString('completion could not finish', strtolower($confirm['message']));
            $this->assertStringContainsString('receiving epcis could not be authored', strtolower((string) $confirm['completion_error']));

            $transferReceive->refresh();
            $this->assertNotSame('completed', $transferReceive->status);
            $this->assertNull($transferReceive->completed_at);

            $shipped->refresh();
            $this->assertSame('in_transit', $shipped->status);
            $this->assertNull($shipped->receive_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_confirm_reconciles_to_open_transfer_receive_and_sets_received_at(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer, $uri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $transferReceive = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->asnSessionId = (int) $transferReceive->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle((int) $toSite->getKey());
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, $uri);
            $this->assertTrue($confirm['ok']);
            $this->assertSame((int) $transferReceive->getKey(), (int) $confirm['reconciled_transfer_receive_session_id']);

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $this->transferSessionId)
                ->where('epc_id', $this->epcId)
                ->first();

            $this->assertNotNull($transferLine);
            $this->assertSame('received', $transferLine->status);
            $this->assertNotNull($transferLine->received_at);

            $transferFresh = $shipped->fresh();
            $this->assertSame('completed', $transferFresh->status);
            $this->assertNotNull($transferFresh->received_at);
            $this->assertNotNull($transferFresh->completed_at);

            $transferReceive->refresh();
            $this->assertSame('completed', $transferReceive->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function opening_transfer_receive_backfills_prior_scan_first_confirms(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer, $uri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle((int) $toSite->getKey());
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, $uri);
            $this->assertTrue($confirm['ok']);
            $this->assertNull($confirm['reconciled_transfer_receive_session_id'] ?? null);

            $transferReceive = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->asnSessionId = (int) $transferReceive->getKey();

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $this->transferSessionId)
                ->where('epc_id', $this->epcId)
                ->first();

            $this->assertNotNull($transferLine);
            $this->assertSame('received', $transferLine->status);
            $this->assertNotNull($transferLine->received_at);

            $transferFresh = $shipped->fresh();
            $this->assertSame('completed', $transferFresh->status);
            $this->assertNotNull($transferFresh->received_at);

            $transferReceive->refresh();
            $this->assertSame('completed', $transferReceive->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_already_confirmed_repairs_stuck_transfer_receive_dual_write(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer, $uri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $transferReceive = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->asnSessionId = (int) $transferReceive->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle((int) $toSite->getKey());
            $this->sessionId = (int) $scanFirst->getKey();

            // Simulate prior bug: receiving line confirmed, transferring line still confirmed.
            ReceivingScanLine::query()->create([
                'receiving_session_id' => $scanFirst->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'confirmed',
                'scan_raw' => $uri,
                'confirmed_at' => now(),
            ]);
            ReceivingScanLine::query()
                ->where('receiving_session_id', $transferReceive->getKey())
                ->where('epc_id', $epc->getKey())
                ->update([
                    'status' => 'confirmed',
                    'scan_raw' => $uri,
                    'confirmed_at' => now(),
                ]);
            $transferReceive->forceFill([
                'status' => 'in_progress',
                'confirmed_parent_count' => 1,
            ])->save();

            $this->assertSame('confirmed', TransferringScanLine::query()
                ->where('transferring_session_id', $this->transferSessionId)
                ->where('epc_id', $this->epcId)
                ->value('status'));

            $repair = app(ConfirmReceivingScan::class)->handle($scanFirst->fresh(), $uri);
            $this->assertTrue($repair['ok']);
            $this->assertSame('already_confirmed', $repair['effect']);
            $this->assertSame((int) $transferReceive->getKey(), (int) $repair['reconciled_transfer_receive_session_id']);

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $this->transferSessionId)
                ->where('epc_id', $this->epcId)
                ->first();
            $this->assertSame('received', $transferLine->status);
            $this->assertNotNull($transferLine->received_at);
            $this->assertSame('completed', $shipped->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function copy_skips_off_manifest_epcs_on_transfer_receive_without_receiving_only_confirm(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $onManifestUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $this->epcUri = $onManifestUri;

            $onManifestEpc = Epc::query()->create(Epc::materializeAttributesFromUri($onManifestUri));
            $this->epcId = (int) $onManifestEpc->getKey();
            $this->receiveAtSite($fromSite, $onManifestEpc);

            app(ConfirmTransferringScan::class)->handle($transfer, $onManifestUri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $transferReceive = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->asnSessionId = (int) $transferReceive->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle((int) $toSite->getKey());
            $this->sessionId = (int) $scanFirst->getKey();

            app(ConfirmReceivingScan::class)->handle($scanFirst, $onManifestUri);

            [, $offManifestUri] = $this->createEpcWithShippingEvent();
            $this->offManifestDocumentId = (int) $this->sourceDocumentId;
            $offManifestEpcId = (int) Epc::query()->where('epc_uri', $offManifestUri)->value('id');

            app(ConfirmReceivingScan::class)->handle($scanFirst->fresh(), $offManifestUri);

            $copy = app(CopyConfirmedReceivingScansToSession::class)->handle(
                $scanFirst->fresh(),
                $transferReceive->fresh(),
                null,
                strictManifestOnly: false,
            );

            $this->assertGreaterThan(0, $copy['skipped']);
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $transferReceive->getKey())
                    ->where('epc_id', $offManifestEpcId)
                    ->where('status', 'confirmed')
                    ->count(),
            );
            $this->assertNull(
                TransferringScanLine::query()
                    ->where('transferring_session_id', $this->transferSessionId)
                    ->where('epc_id', $offManifestEpcId)
                    ->value('status'),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_reconcile_failure_compensates_transferring_receive_mark(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer, $uri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $transferReceive = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->asnSessionId = (int) $transferReceive->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle((int) $toSite->getKey());
            $this->sessionId = (int) $scanFirst->getKey();

            $transferReceiveSessionId = (int) $transferReceive->getKey();
            $epcId = (int) $this->epcId;

            TransferringScanLine::saved(function (TransferringScanLine $line) use ($transferReceiveSessionId, $epcId): void {
                if ($line->status !== 'received' || (int) $line->epc_id !== $epcId) {
                    return;
                }

                ReceivingScanLine::query()
                    ->where('receiving_session_id', $transferReceiveSessionId)
                    ->where('epc_id', $epcId)
                    ->update(['status' => 'unexpected']);
            });

            try {
                $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, $uri);
            } finally {
                TransferringScanLine::flushEventListeners();
            }
            $this->assertTrue($confirm['ok']);
            $this->assertNull($confirm['reconciled_transfer_receive_session_id'] ?? null);

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $this->transferSessionId)
                ->where('epc_id', $this->epcId)
                ->first();

            $this->assertNotNull($transferLine);
            $this->assertSame('confirmed', $transferLine->status);
            $this->assertNull($transferLine->received_at);
            $this->assertSame('in_transit', $shipped->fresh()->status);
            $this->assertSame(0, (int) $shipped->fresh()->received_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function propagate_scan_first_ignores_null_site_sessions(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer, $uri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $nullSiteScanFirst = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::ScanFirst,
                'epcis_document_id' => null,
                'transferring_session_id' => null,
                'matched_epcis_document_id' => null,
                'trading_partner_id' => null,
                'site_id' => null,
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionId = (int) $nullSiteScanFirst->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $nullSiteScanFirst->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'confirmed',
                'scan_raw' => $uri,
                'confirmed_at' => now(),
            ]);

            $transferReceive = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->asnSessionId = (int) $transferReceive->getKey();

            $this->assertSame('expected', ReceivingScanLine::query()
                ->where('receiving_session_id', $transferReceive->getKey())
                ->where('epc_id', $this->epcId)
                ->value('status'));
            $this->assertSame('confirmed', TransferringScanLine::query()
                ->where('transferring_session_id', $this->transferSessionId)
                ->where('epc_id', $this->epcId)
                ->value('status'));
            $this->assertSame('in_transit', $shipped->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function resolveEligibleReceiveSiteId(): int
    {
        $session = app(OpenScanFirstReceivingSession::class)->handle();
        $siteId = (int) $session->site_id;
        ReceivingSession::query()->whereKey($session->getKey())->delete();

        $this->assertGreaterThan(0, $siteId);

        return $siteId;
    }

    private function createLegacyAsnSessionWithNullSite(EpcisDocument $document, int $parentEpcId): ReceivingSession
    {
        $session = ReceivingSession::query()->create([
            'session_kind' => ReceivingSessionKind::InboundAsn,
            'epcis_document_id' => $document->getKey(),
            'trading_partner_id' => $document->trading_partner_id,
            'site_id' => null,
            'status' => 'open',
            'expected_parent_count' => 1,
            'confirmed_parent_count' => 0,
            'expected_child_count' => 0,
            'confirmed_child_count' => 0,
            'opened_at' => now(),
        ]);

        ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $parentEpcId,
            'parent_epc_id' => null,
            'line_role' => 'parent',
            'status' => 'expected',
        ]);

        return $session->refresh();
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createReceiveSites(Tenant $tenant): array
    {
        $siteA = Site::query()->create([
            'name' => 'ScanFirst Receive A '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $siteA->getKey();

        $siteB = Site::query()->create([
            'name' => 'ScanFirst Receive B '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $siteB->getKey();

        return [$siteA, $siteB];
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) Str::uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
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

    /**
     * @return array{0: string, 1: EpcisDocument}
     */
    private function ingestUniqueMinimalFixture(): array
    {
        do {
            $ssccUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (Epc::query()->where('epc_uri', $ssccUri)->exists());
        do {
            $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.'.(string) random_int(10_000_000_000_000, 99_999_999_999_999);
        } while (Epc::query()->where('epc_uri', $sgtinUri)->exists());

        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace(
            [
                '11111111-2222-3333-4444-555555555555',
                self::SSCC_URI,
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
            ],
            [(string) Str::uuid(), $ssccUri, $sgtinUri],
            $xml,
        );
        file_put_contents($tmp, $xml);

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }

        $this->uniqueEpcUris = [$ssccUri, $sgtinUri];

        return [$ssccUri, $document];
    }

    /**
     * @return array{0: Epc, 1: string}
     */
    private function createEpcWithShippingEvent(): array
    {
        $suffix = (string) random_int(10000000, 99999999);
        $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SF'.$suffix;
        $this->epcUri = $uri;

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now()->subHour(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'scan-first-ti.xml',
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/scan-first-ti-'.Str::uuid().'.xml',
            'dscsa_affirm' => true,
            'status' => 'validated',
            'event_count' => 1,
            'epc_count' => 1,
            'received_at' => now()->subHour(),
        ]);
        $this->sourceDocumentId = (int) $document->getKey();

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcId = (int) $epc->getKey();

        $shippingEvent = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinutes(10),
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            'read_point_gln' => '0301160000009',
            'biz_location_gln' => '0301160000009',
        ]);

        DB::table('event_epcs')->insert([
            'event_id' => $shippingEvent->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]);

        return [$epc, $uri];
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $fromGln = $this->uniqueGln();
        $toGln = $this->uniqueGln();

        $fromSite = Site::query()->create([
            'name' => 'ScanFirst Transfer From '.Str::random(6),
            'gln' => $fromGln,
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'ScanFirst Transfer To '.Str::random(6),
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

        return [$fromSite, $toSite];
    }

    /**
     * Transferring requires on-hand custody at the from site (receiving ObjectEvent).
     */
    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'scan-first-transfer-custody-receipt.xml',
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

        if (EligibleReceiveSites::forOrganization()->count() === 0) {
            $site = Site::factory()->owned()->create([
                'name' => 'Scan-first Receive Test Site',
                'gln' => '0366159000096',
                'is_active' => true,
                'is_headquarters' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();
            TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId((int) $site->getKey());
            $tenant->save();
        }

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionId !== null) {
            $session = ReceivingSession::query()->find($this->sessionId);
            if ($session !== null && $session->receiving_epcis_document_id !== null) {
                $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;
            }
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->asnSessionId !== null) {
            ReceivingSession::query()->whereKey($this->asnSessionId)->delete();
            $this->asnSessionId = null;
        }

        if ($this->receivingDocumentId !== null) {
            $doc = EpcisDocument::query()->find($this->receivingDocumentId);
            if ($doc !== null && filled($doc->payload_path)) {
                Storage::disk($doc->payload_disk)->delete($doc->payload_path);
            }
            EpcisDocument::query()->whereKey($this->receivingDocumentId)->delete();
            $this->receivingDocumentId = null;
        }

        if ($this->transferDocumentId !== null) {
            $doc = EpcisDocument::query()->find($this->transferDocumentId);
            if ($doc !== null && filled($doc->payload_path)) {
                Storage::disk($doc->payload_disk)->delete($doc->payload_path);
            }
            EpcisDocument::query()->whereKey($this->transferDocumentId)->delete();
            $this->transferDocumentId = null;
        }

        if ($this->transferSessionId !== null) {
            TransferringSession::query()->whereKey($this->transferSessionId)->delete();
            $this->transferSessionId = null;
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

        if ($this->offManifestDocumentId !== null) {
            EpcisDocument::query()->whereKey($this->offManifestDocumentId)->delete();
            $this->offManifestDocumentId = null;
        }

        if ($this->sourceDocumentId !== null) {
            $sessions = ReceivingSession::query()->where('epcis_document_id', $this->sourceDocumentId)->get();
            foreach ($sessions as $session) {
                if ($session->receiving_epcis_document_id !== null) {
                    EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
                }
            }
            ReceivingSession::query()->where('epcis_document_id', $this->sourceDocumentId)->delete();
            EpcisDocument::query()->whereKey($this->sourceDocumentId)->delete();
            $this->sourceDocumentId = null;
        }

        $epcIds = [];
        if ($this->uniqueEpcUris !== []) {
            $epcIds = Epc::query()->whereIn('epc_uri', $this->uniqueEpcUris)->pluck('id')->all();
        }
        if ($this->epcId !== null) {
            $epcIds[] = $this->epcId;
        }
        $epcIds = array_values(array_unique(array_map('intval', $epcIds)));

        if ($epcIds !== []) {
            DB::table('aggregation_links')
                ->where(function ($query) use ($epcIds): void {
                    $query->whereIn('parent_epc_id', $epcIds)
                        ->orWhereIn('child_epc_id', $epcIds);
                })
                ->delete();
            if (DB::getSchemaBuilder()->hasTable('epc_ilmd')) {
                DB::table('epc_ilmd')->whereIn('epc_id', $epcIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('event_epc_ilmd')) {
                DB::table('event_epc_ilmd')->whereIn('epc_id', $epcIds)->delete();
            }
            DB::table('document_epcs')->whereIn('epc_id', $epcIds)->delete();
            DB::table('event_epcs')->whereIn('epc_id', $epcIds)->delete();
            ReceivingScanLine::query()->whereIn('epc_id', $epcIds)->delete();
            Epc::query()->whereIn('id', $epcIds)->delete();
        }
        $this->epcId = null;
        $this->uniqueEpcUris = [];

        if ($this->epcUri !== null) {
            $epc = Epc::query()->where('epc_uri', $this->epcUri)->first();
            if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                $epc->delete();
            }
            $this->epcUri = null;
        }

        foreach ([self::SSCC_URI] as $uri) {
            $epc = Epc::query()->where('epc_uri', $uri)->first();
            if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                $epc->delete();
            }
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorDefaultShipFromSiteId !== null || $this->priorDefaultReceiveSiteId !== null) {
            $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
            $this->priorDefaultShipFromSiteId = null;
            $this->priorDefaultReceiveSiteId = null;
        }

        if ($this->priorRequireTi !== null) {
            $settings->setRequireTiForScanFirst($this->priorRequireTi);
            $this->priorRequireTi = null;
        }

        $tenant->save();

        tenancy()->end();
    }
}
