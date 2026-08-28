<?php

namespace Tests\Feature\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Shipping\AddOutboundShippingEpcsFromReceivingSession;
use App\Actions\Shipping\CompleteOutboundShippingSession;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\GenerateShippingEpcisEvents;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Actions\Shipping\ValidateOutboundShippingSend;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\EpcisAuthoredKind;
use App\Enums\FacilityType;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\CreateOutboundShippingSession;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\ListOutboundShippingSessions;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\MobileViewOutboundShippingSession;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\ViewOutboundShippingSession;
use App\Models\AtpLicense;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\OutboundConnection;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Epcis\Validation\EpcisValidationFinding;
use App\Support\Epcis\Validation\EpcisXsdValidator;
use App\Support\Gs1\Sgln;
use App\Support\Shipping\AtpGateBypass;
use App\Support\Shipping\SearchShipToCustomers;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantSettings;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class OutboundShippingSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    /** The fixture's only item, packed under {@see SSCC_URI}. */
    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    /**
     * Company prefix with no trading-partner GLN under it, so organization identity
     * assertions do not trip on demo partner data.
     */
    private const CORRECTIVE_COMPANY_PREFIX = '093117';

    private const DEMO_PARTNER_GLN = '0614141000005';

    private const DEMO_PARTNER_SGLN = 'urn:epc:id:sgln:0614141.00000.0';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $connectionIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $transferSessionIds = [];

    /** @var list<int> */
    private array $ssccLabelIds = [];

    /** EPCs minted by a test (not fixture rows shared with other cases). @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $atpLicenseIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?string $priorReceivingState = null;

    private bool $receivingStateCaptured = false;

    #[Test]
    public function open_and_confirm_shippable_epc_succeeds(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $this->assertSame('open', $session->status);

            $result = app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            $this->assertTrue($result['ok']);
            $this->assertSame('confirmed', $result['effect']);

            $session->refresh();
            $this->assertSame('in_progress', $session->status);
            $this->assertSame(1, (int) $session->confirmed_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_rejects_epc_on_open_receive_session(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $site = $this->createShipSite($tenant);

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SH'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $receiveSession = app(OpenScanFirstReceivingSession::class)->handle((int) $site->getKey());
            $this->receivingSessionIds[] = (int) $receiveSession->getKey();

            $received = app(ConfirmReceivingScan::class)->handle($receiveSession, $uri);
            $this->assertTrue($received['ok'], $received['message']);

            $shipSession = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $shipSession->getKey();

            $blocked = app(ConfirmOutboundShippingScan::class)->handle($shipSession, $uri);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('on_open_receive', $blocked['effect']);
            $this->assertSame('Already confirmed on an open receive session.', $blocked['message']);
            $this->assertSame(0, OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $shipSession->getKey())
                ->where('epc_id', $epc->getKey())
                ->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_rejects_non_shippable_quarantined_and_double_ship(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->prepareFixtureReceivingState();

            $site = $this->createShipSite($tenant);
            $otherSite = Site::query()->create([
                'name' => 'Other Site '.Str::random(6),
                'gln' => $this->uniqueOrgGln('036615'),
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds[] = (int) $otherSite->getKey();

            $epc = Epc::query()->firstOrCreate(
                ['epc_uri' => self::SSCC_URI],
                Epc::materializeAttributesFromUri(self::SSCC_URI),
            );

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $notShippable = app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            $this->assertFalse($notShippable['ok']);
            $this->assertSame('not_shippable', $notShippable['effect']);

            $this->makeEpcShippableAtSite($site);

            $otherSession = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $otherSession->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($otherSession, self::SSCC_URI);

            $doubleShip = app(ConfirmOutboundShippingScan::class)->handle($session->fresh(), self::SSCC_URI);
            $this->assertFalse($doubleShip['ok']);
            $this->assertSame('double_ship', $doubleShip['effect']);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $site->getKey(),
                toSiteId: (int) $otherSite->getKey(),
            );
            $this->transferSessionIds[] = (int) $transfer->getKey();
            app(ConfirmTransferringScan::class)->handle($transfer, self::SSCC_URI);

            $sessionThree = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $sessionThree->getKey();
            $onTransfer = app(ConfirmOutboundShippingScan::class)->handle($sessionThree, self::SSCC_URI);
            $this->assertFalse($onTransfer['ok']);
            $this->assertSame('double_ship', $onTransfer['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_validation_blocks_when_confirmed_count_without_scan_lines(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Empty Lines Customer');
            $shipTo = $this->createCustomerSite($customer, '037014');

            $session = $this->readyToSendSession(
                $site,
                $customer,
                'ASN-EMPTY-'.Str::random(4),
                'PO-'.Str::random(4),
                $shipTo,
            );

            OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->delete();
            $session->forceFill(['confirmed_count' => 1])->save();

            $blockers = app(ValidateOutboundShippingSend::class)->handle($session->fresh());
            $this->assertNotEmpty($blockers);
            $this->assertStringContainsString(
                'Confirm at least one unit',
                implode(' ', $blockers),
            );

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected empty confirmed lines to block the send.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Confirm at least one unit', $e->getMessage());
            }

            $this->assertNotSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_blocks_child_with_open_parent_not_on_ship_order(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);

            $this->assertTrue(
                AggregationLink::query()
                    ->whereHas('parentEpc', fn ($q) => $q->where('epc_uri', self::SSCC_URI))
                    ->whereHas('childEpc', fn ($q) => $q->where('epc_uri', self::SGTIN_URI))
                    ->whereNull('valid_to')
                    ->exists(),
            );

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $result = app(ConfirmOutboundShippingScan::class)->handle($session, self::SGTIN_URI);

            $this->assertFalse($result['ok']);
            $this->assertSame('open_parent_hierarchy', $result['effect']);
            $this->assertStringContainsString('outermost SSCC', $result['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function complete_blocks_open_parent_hierarchy_on_confirmed_lines(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, 'TX');

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Open Parent Ship Customer');
            $shipTo = $this->createCustomerSite($customer, '037015');

            $license = AtpLicense::query()->create([
                'site_id' => (int) $shipTo->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            OutboundShippingScanLine::query()->create([
                'outbound_shipping_session_id' => $session->getKey(),
                'epc_id' => $child->getKey(),
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => self::SGTIN_URI,
                'confirmed_at' => now(),
            ]);
            $session->forceFill([
                'status' => 'in_progress',
                'confirmed_count' => 1,
            ])->save();

            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $customer->getKey(),
                'ship_to_site_id' => (int) $shipTo->getKey(),
                'ship_to_gln' => (string) $shipTo->gln,
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-OPEN-PARENT-'.Str::random(4),
                'customer_po' => 'PO-'.Str::random(4),
                'dscsa_affirm' => true,
            ]);

            $blockers = app(ValidateOutboundShippingSend::class)->handle($session->fresh());
            $this->assertNotEmpty(array_filter(
                $blockers,
                fn (string $blocker): bool => str_contains($blocker, 'open aggregation parent'),
            ));

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected open parent hierarchy to block the send.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('open aggregation parent', $e->getMessage());
            }

            $this->assertNotSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_rejects_epc_on_completed_session_before_shipping_epcis_is_generated(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);

            $sessionA = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $sessionA->getKey();
            $confirmed = app(ConfirmOutboundShippingScan::class)->handle($sessionA, self::SSCC_URI);
            $this->assertTrue($confirmed['ok'], $confirmed['message']);

            $sessionA->fresh()->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();
            $this->assertNull($sessionA->fresh()->shipping_events_generated_at);

            $sessionB = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $sessionB->getKey();
            $blocked = app(ConfirmOutboundShippingScan::class)->handle($sessionB, self::SSCC_URI);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('double_ship', $blocked['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_rejects_epc_after_shipping_epcis_is_generated_because_custody_is_gone(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $sessionA = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $sessionA->getKey();
            $confirmed = app(ConfirmOutboundShippingScan::class)->handle($sessionA, self::SSCC_URI);
            $this->assertTrue($confirmed['ok'], $confirmed['message']);

            app(UpdateOutboundShippingParty::class)->handle($sessionA->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($sessionA->fresh(), [
                'asn_number' => 'ASN-BH4-GEN-001',
                'customer_po' => 'PO-BH4-GEN-001',
                'dscsa_affirm' => true,
            ]);

            $sessionA->fresh()->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $generated = app(GenerateShippingEpcisEvents::class)->handle($sessionA->fresh());
            $this->assertTrue($generated['generated']);
            $this->assertNotNull($generated['document']);
            $this->documentIds[] = (int) $generated['document']->getKey();
            $this->assertNotNull($sessionA->fresh()->shipping_events_generated_at);

            $sessionB = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $sessionB->getKey();
            $blocked = app(ConfirmOutboundShippingScan::class)->handle($sessionB, self::SSCC_URI);
            $this->assertFalse($blocked['ok']);
            $this->assertContains($blocked['effect'], ['not_shippable', 'not_in_custody']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function corrective_ship_requires_a_reason(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);

            try {
                app(OpenOutboundShippingSession::class)->handle(
                    siteId: (int) $site->getKey(),
                    isCorrective: true,
                    correctiveReason: '   ',
                );
                $this->fail('Expected DomainException when opening a corrective order without a reason.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('reason is required', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function corrective_ship_needs_prior_ship_evidence_instead_of_on_hand_inventory(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $corrective = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Partner rejected the original ASN.',
            );
            $this->sessionIds[] = (int) $corrective->getKey();
            $this->assertTrue($corrective->is_corrective);

            // Never shipped: shippable-at-site inventory is not enough for a correction.
            $blocked = app(ConfirmOutboundShippingScan::class)->handle($corrective, self::SSCC_URI);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('not_correctable', $blocked['effect']);

            $shipped = $this->completeShipOrderFor($site);
            $this->assertSame('completed', $shipped->status);

            $allowed = app(ConfirmOutboundShippingScan::class)->handle($corrective->fresh(), self::SSCC_URI);
            $this->assertTrue($allowed['ok'], $allowed['message']);
            $this->assertSame('confirmed', $allowed['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function corrective_ship_actions_open_a_reasoned_session_from_the_ui(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createShippingUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $user->syncSites([(int) $site->getKey()], (int) $site->getKey());
            $this->makeEpcShippableAtSite($site);

            Livewire::test(ListOutboundShippingSessions::class)
                ->callAction('startCorrectiveShip', [
                    'site_id' => (int) $site->getKey(),
                    'corrective_reason' => 'Partner rejected the original ASN.',
                ]);

            $opened = OutboundShippingSession::query()
                ->where('is_corrective', true)
                ->orderByDesc('id')
                ->firstOrFail();
            $this->sessionIds[] = (int) $opened->getKey();

            $this->assertSame((int) $site->getKey(), (int) $opened->site_id);
            $this->assertSame('Partner rejected the original ASN.', $opened->corrective_reason);
            $this->assertNull($opened->corrects_epcis_document_id);

            $original = $this->completeShipOrderFor($site);
            $originalDocumentId = (int) $original->epcis_document_id;

            Livewire::test(ViewOutboundShippingSession::class, ['record' => $original->getKey()])
                ->callAction('correctiveShipFromOrder', [
                    'corrective_reason' => 'Reissue against the shipped document.',
                ]);

            $fromOrder = OutboundShippingSession::query()
                ->where('is_corrective', true)
                ->whereKeyNot($opened->getKey())
                ->orderByDesc('id')
                ->firstOrFail();
            $this->sessionIds[] = (int) $fromOrder->getKey();

            $this->assertSame($originalDocumentId, (int) $fromOrder->corrects_epcis_document_id);
            $this->assertSame('Reissue against the shipped document.', $fromOrder->corrective_reason);

            config(['tracepharma.regulatory_compliance.password_gate' => true]);

            $gated = Livewire::test(ListOutboundShippingSessions::class)
                ->instance()
                ->getAction('startCorrectiveShip');

            $this->assertFalse(
                $gated->isConfirmationRequired(),
                'The site/reason form must open without a password prompt.',
            );

            $submit = $gated->getModalSubmitAction();
            $this->assertNotNull($submit);
            $this->assertTrue(
                $submit->isConfirmationRequired(),
                'Submitting a corrective ship order must pass the regulatory compliance gate.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function corrective_session_authors_shipping_epcis_with_correction_notes(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $original = $this->completeShipOrderFor($site);
            $originalDocumentId = (int) $original->epcis_document_id;
            $this->assertGreaterThan(0, $originalDocumentId);

            $corrective = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Reissued with corrected invoice number.',
                correctsEpcisDocumentId: $originalDocumentId,
            );
            $this->sessionIds[] = (int) $corrective->getKey();

            $confirmed = app(ConfirmOutboundShippingScan::class)->handle($corrective, self::SSCC_URI);
            $this->assertTrue($confirmed['ok'], $confirmed['message']);

            $partner = $this->ensureDemoPartner();
            app(UpdateOutboundShippingParty::class)->handle($corrective->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($corrective->fresh(), [
                'asn_number' => 'ASN-CORR-001',
                'invoice_number' => 'INV-CORR-001',
                'dscsa_affirm' => true,
            ]);

            // Authored document_uuid is derived from event time to the second; keep the
            // corrective shipment out of the original's second.
            sleep(1);

            $completed = app(CompleteOutboundShippingSession::class)->handle($corrective->fresh());
            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame(EpcisAuthoredKind::Shipping->value, (string) $document->authored_kind?->value);
            $this->assertSame($originalDocumentId, (int) $document->corrects_epcis_document_id);
            $this->assertStringContainsString('Corrective shipment.', (string) $document->notes);
            $this->assertStringContainsString('Corrects EPCIS document #'.$originalDocumentId, (string) $document->notes);
            $this->assertStringContainsString('Reissued with corrected invoice number.', (string) $document->notes);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function shipping_hands_custody_over_and_clears_shippable_inventory(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $epcId = (int) $epc->getKey();
            $gate = app(EpcCustodyGate::class);

            // We printed and commissioned this SSCC ourselves, and it is sitting at our dock.
            $this->commissionSsccLabelFor($epc);
            $this->assertTrue($gate->isInCustody($epc));
            $this->assertContains($epcId, app(ShippableEpcsAtSite::class)->epcIds((int) $site->getKey()));

            $partner = $this->ensureDemoPartner();
            $completed = $this->completeShipOrderFor($site);
            $this->assertSame('completed', $completed->status);

            // The shipping event reads at our dock but comes to rest at the customer.
            $shipping = EpcisEvent::query()
                ->where('document_id', $completed->epcis_document_id)
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                ->firstOrFail();

            $this->assertSame($site->gln, $shipping->read_point_gln);
            $this->assertSame($partner->gln, $shipping->biz_location_gln);

            $authoredLocations = DB::table('event_locations')
                ->where('event_id', $shipping->getKey())
                ->get();
            $this->assertCount(1, $authoredLocations);
            $this->assertSame('readPoint', $authoredLocations[0]->location_type);
            $this->assertSame($site->gln, $authoredLocations[0]->gln);
            $this->assertSame((int) $site->getKey(), (int) $authoredLocations[0]->site_id);
            $this->assertNotEmpty($authoredLocations[0]->gln_uri);
            $this->assertSame(
                0,
                DB::table('event_locations')
                    ->where('event_id', $shipping->getKey())
                    ->where('location_type', 'bizLocation')
                    ->count(),
            );

            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            $xml = Storage::disk($document->payload_disk)->get($document->payload_path);
            $shipToSgln = (string) $partner->sgln;
            $shipFromSgln = Sgln::toUrn((string) $site->gln, strlen(self::CORRECTIVE_COMPANY_PREFIX));
            $this->assertSame(self::DEMO_PARTNER_SGLN, $shipToSgln);
            $this->assertStringContainsString("<readPoint>\n          <id>{$shipFromSgln}</id>", (string) $xml);

            // The shipping event omits bizLocation per the GS1 US IG; the customer is
            // named on destinationList instead.
            $this->assertStringNotContainsString('<bizLocation>', (string) $xml);
            $this->assertStringContainsString(
                '<destination type="urn:epcglobal:cbv:sdt:location">'.$shipToSgln.'</destination>',
                (string) $xml,
            );

            // Shipped stock is gone: neither our own commissioning nor our GLN on the
            // readPoint may keep it in custody or back in the shippable list.
            $epc->refresh();
            $this->assertFalse($gate->isInCustody($epc));
            $this->assertTrue($gate->isTenantCommissioned($epc));
            $this->assertSame([], $gate->epcIdsInCustody([$epcId]));
            $this->assertNotContains($epcId, app(ShippableEpcsAtSite::class)->epcIds((int) $site->getKey()));

            try {
                $gate->assertInCustody($epc, 'shipping');
                $this->fail('Expected shipped stock to fail the custody assertion.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('already shipped and in transit', $e->getMessage());
            }

            // Re-scanning it onto a fresh (non-corrective) ship order must be refused.
            $again = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $again->getKey();
            $rescanned = app(ConfirmOutboundShippingScan::class)->handle($again, self::SSCC_URI);
            $this->assertFalse($rescanned['ok']);
            $this->assertSame('not_shippable', $rescanned['effect']);

            // An id that does not resolve must fail the assertion, not skip it.
            try {
                $gate->assertInCustody([$epcId, PHP_INT_MAX], 'shipping');
                $this->fail('Expected an unknown EPC id to fail closed.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Unknown EPC id', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * Stand in for a label batch we printed and commissioned for this SSCC.
     */
    private function commissionSsccLabelFor(Epc $epc): SsccLabel
    {
        $sscc18 = (string) $epc->sscc18;
        $serialReference = (string) $epc->serial_reference;

        $label = SsccLabel::query()->create([
            'sscc_18' => $sscc18,
            'sscc_urn' => (string) $epc->epc_uri,
            'extension_digit' => substr($sscc18, 0, 1),
            'company_prefix' => (string) $epc->company_prefix,
            'serial_reference' => $serialReference,
            'serial_reference_int' => (int) $serialReference,
            'element_string' => '(00)'.$sscc18,
            'hrt' => 'SSCC '.$sscc18,
            'label_disk' => 'local',
            'label_path' => 'labels/test-'.$sscc18.'.pdf',
            'commissioned_at' => now(),
        ]);

        $this->ssccLabelIds[] = (int) $label->getKey();

        return $label;
    }

    #[Test]
    public function shipping_an_sscc_takes_its_packed_children_out_of_custody(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $siteId = (int) $site->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $childId = (int) $child->getKey();

            $gate = app(EpcCustodyGate::class);
            $shippable = app(ShippableEpcsAtSite::class);

            // The item is packed under the SSCC and, on its own events, sits at our dock.
            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childId)
                    ->whereNull('valid_to')
                    ->exists(),
                'Fixture must leave the SGTIN packed under the SSCC.',
            );
            $this->assertTrue($gate->isInCustody($child));
            $this->assertContains($childId, $shippable->epcIds($siteId));

            $completed = $this->completeShipOrderFor($site);
            $this->assertSame('completed', $completed->status);

            // Only the scanned SSCC is on the authored epcList — the item leaves inside it.
            $shipping = EpcisEvent::query()
                ->where('document_id', $completed->epcis_document_id)
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                ->firstOrFail();

            $this->assertFalse(
                DB::table('event_epcs')
                    ->where('event_id', $shipping->getKey())
                    ->where('epc_id', $childId)
                    ->exists(),
                'Shipping authors the outermost unit only; the child rides on its container.',
            );

            $child->refresh();
            $this->assertFalse($gate->isInCustody($child));
            $this->assertSame([], $gate->epcIdsInCustody([$childId]));
            $this->assertNotContains($childId, $shippable->epcIds($siteId));
            $this->assertFalse($shippable->contains($siteId, $childId));

            try {
                $gate->assertInCustody($child, 'shipping');
                $this->fail('Expected a packed child of a shipped SSCC to fail the custody assertion.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('packed inside', $e->getMessage());
                $this->assertStringContainsString((string) $parent->sscc18, $e->getMessage());
            }

            // Re-scanning the item onto a fresh (non-corrective) ship order must be refused.
            $again = app(OpenOutboundShippingSession::class)->handle($siteId);
            $this->sessionIds[] = (int) $again->getKey();
            $rescanned = app(ConfirmOutboundShippingScan::class)->handle($again, self::SGTIN_URI);
            $this->assertFalse($rescanned['ok']);
            $this->assertSame('not_shippable', $rescanned['effect']);

            // The refusal points at corrective shipping, so that door must stay open.
            $corrective = app(OpenOutboundShippingSession::class)->handle(
                siteId: $siteId,
                isCorrective: true,
                correctiveReason: 'Amend the item that left inside the pallet.',
                correctsEpcisDocumentId: (int) $completed->epcis_document_id,
            );
            $this->sessionIds[] = (int) $corrective->getKey();

            $amended = app(ConfirmOutboundShippingScan::class)->handle($corrective, self::SGTIN_URI);
            $this->assertTrue($amended['ok'], $amended['message']);

            // Unpacking gives the item its own voice back: it is on hand at the dock again.
            AggregationLink::query()
                ->where('child_epc_id', $childId)
                ->whereNull('valid_to')
                ->update(['valid_to' => now()]);

            $this->assertTrue($gate->isInCustody($child->refresh()));
            $this->assertContains($childId, $shippable->epcIds($siteId));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function corrective_ship_is_scoped_to_the_document_it_corrects(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $original = $this->completeShipOrderFor($site);
            $shippedDocumentId = (int) $original->epcis_document_id;
            $this->assertGreaterThan(0, $shippedDocumentId);

            $unrelated = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => EpcisAuthoredKind::Shipping,
                'format' => 'xml',
                'status' => 'generated',
                'received_at' => now(),
                'ship_from_site_id' => (int) $site->getKey(),
            ]);
            $this->documentIds[] = (int) $unrelated->getKey();

            $offScope = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Amend a shipment this unit never left on.',
                correctsEpcisDocumentId: (int) $unrelated->getKey(),
            );
            $this->sessionIds[] = (int) $offScope->getKey();

            // Prior ship evidence somewhere in the org is not enough once the order names
            // the document it corrects.
            $blocked = app(ConfirmOutboundShippingScan::class)->handle($offScope, self::SSCC_URI);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('not_correctable', $blocked['effect']);
            $this->assertStringContainsString('#'.$unrelated->getKey(), $blocked['message']);

            $inScope = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Reissue against the document it shipped on.',
                correctsEpcisDocumentId: $shippedDocumentId,
            );
            $this->sessionIds[] = (int) $inScope->getKey();

            $allowed = app(ConfirmOutboundShippingScan::class)->handle($inScope, self::SSCC_URI);
            $this->assertTrue($allowed['ok'], $allowed['message']);

            // The SSCC carried its contents, so the packed SGTIN is in scope as well even
            // though only the SSCC was on the shipped epcList.
            $child = app(ConfirmOutboundShippingScan::class)->handle($inScope->fresh(), self::SGTIN_URI);
            $this->assertTrue($child['ok'], $child['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function corrective_ship_rejects_a_child_unpacked_before_the_shipment_left(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            // Taken off the pallet at our dock, well before the pallet went out: it never
            // left, so the shipment it did not travel on cannot be corrected against it.
            AggregationLink::query()
                ->where('child_epc_id', $child->getKey())
                ->whereNull('valid_to')
                ->update(['valid_to' => now()->subMinutes(5)]);

            $shipped = $this->completeShipOrderFor($site);
            $shippedDocumentId = (int) $shipped->epcis_document_id;
            $this->assertGreaterThan(0, $shippedDocumentId);

            $corrective = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Amend an item that stayed behind on the dock.',
                correctsEpcisDocumentId: $shippedDocumentId,
            );
            $this->sessionIds[] = (int) $corrective->getKey();

            $blocked = app(ConfirmOutboundShippingScan::class)->handle($corrective, self::SGTIN_URI);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('not_correctable', $blocked['effect']);
            $this->assertStringContainsString('Not part of the shipment being corrected', $blocked['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function corrective_ship_accepts_a_grandchild_under_the_shipped_pallet(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            // pallet (SSCC) → case (fixture SGTIN) → item: only the pallet is scanned onto
            // the shipment, so the item is two levels below anything on the epcList.
            $case = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $item = $this->packNewItemUnder($case);

            $shipped = $this->completeShipOrderFor($site);
            $shippedDocumentId = (int) $shipped->epcis_document_id;

            $shipping = EpcisEvent::query()
                ->where('document_id', $shippedDocumentId)
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                ->firstOrFail();

            $this->assertFalse(
                DB::table('event_epcs')
                    ->where('event_id', $shipping->getKey())
                    ->whereIn('epc_id', [(int) $case->getKey(), (int) $item->getKey()])
                    ->exists(),
                'Only the outermost pallet belongs on the shipped epcList.',
            );

            $corrective = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Amend the item that left inside a case inside the pallet.',
                correctsEpcisDocumentId: $shippedDocumentId,
            );
            $this->sessionIds[] = (int) $corrective->getKey();

            $amended = app(ConfirmOutboundShippingScan::class)->handle($corrective, (string) $item->epc_uri);
            $this->assertTrue($amended['ok'], $amended['message']);
            $this->assertSame('confirmed', $amended['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function corrective_ship_refuses_stock_that_has_been_received_back(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $shipped = $this->completeShipOrderFor($site);
            $shippedDocumentId = (int) $shipped->epcis_document_id;

            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $gate = app(EpcCustodyGate::class);
            $this->assertFalse($gate->isInCustody($epc->refresh()));

            // The customer refused delivery and the pallet came back over our dock.
            $this->makeEpcShippableAtSite($site);
            $this->assertTrue($gate->isInCustody($epc->refresh()));

            $corrective = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Partner returned the pallet.',
                correctsEpcisDocumentId: $shippedDocumentId,
            );
            $this->sessionIds[] = (int) $corrective->getKey();

            $blocked = app(ConfirmOutboundShippingScan::class)->handle($corrective, self::SSCC_URI);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('not_correctable', $blocked['effect']);
            $this->assertStringContainsString('in tenant custody', $blocked['message']);
            $this->assertStringContainsString('normal ship order', $blocked['message']);

            // Same answer for a correction opened cold, where prior ship evidence alone
            // would otherwise authorize the amendment.
            $cold = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Reissue for the returned pallet.',
            );
            $this->sessionIds[] = (int) $cold->getKey();

            $coldBlocked = app(ConfirmOutboundShippingScan::class)->handle($cold, self::SSCC_URI);
            $this->assertFalse($coldBlocked['ok']);
            $this->assertSame('not_correctable', $coldBlocked['effect']);
            $this->assertStringContainsString('in tenant custody', $coldBlocked['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function corrective_send_fails_closed_when_a_confirmed_line_has_no_epc_on_record(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $original = $this->completeShipOrderFor($site);
            $originalDocumentId = (int) $original->epcis_document_id;

            $corrective = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Reissue with a corrected PO.',
                correctsEpcisDocumentId: $originalDocumentId,
            );
            $this->sessionIds[] = (int) $corrective->getKey();

            $confirmed = app(ConfirmOutboundShippingScan::class)->handle($corrective, self::SSCC_URI);
            $this->assertTrue($confirmed['ok'], $confirmed['message']);

            app(UpdateOutboundShippingParty::class)->handle($corrective->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($corrective->fresh(), [
                'asn_number' => 'ASN-CORR-MISSING',
                'customer_po' => 'PO-CORR-MISSING',
                'dscsa_affirm' => true,
            ]);

            // A confirmed line whose EPC is no longer on record must stop the send rather
            // than be skipped, which would author a shipment nobody rechecked.
            Schema::withoutForeignKeyConstraints(function () use ($corrective): void {
                DB::table('outbound_shipping_scan_lines')->insert([
                    'outbound_shipping_session_id' => (int) $corrective->getKey(),
                    'epc_id' => PHP_INT_MAX,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            try {
                app(CompleteOutboundShippingSession::class)->handle($corrective->fresh());
                $this->fail('Expected a confirmed line with an unknown EPC to fail the send.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Unknown EPC id', $e->getMessage());
            }

            $corrective = $corrective->fresh();
            $this->assertNotSame('completed', $corrective->status);
            $this->assertNull($corrective->epcis_document_id);
            $this->assertNull($corrective->shipping_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_corrective_document_is_not_prior_evidence_for_another_correction(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $partner = $this->ensureDemoPartner();

            $epc = $this->createUnpackedItem();
            $original = $this->createAuthoredShippingDocument($site, corrects: null);
            $correction = $this->createAuthoredShippingDocument($site, corrects: $original);
            $this->attachShippingEvent($correction, $epc, $site, $partner);

            $gate = app(EpcCustodyGate::class);

            $this->assertFalse(
                $gate->hasPriorTenantShipEvidence($epc, (int) $site->getKey()),
                'A correction must not authorize the next correction.',
            );

            // The same event on a document that is not a correction is real evidence.
            $correction->forceFill([
                'corrects_epcis_document_id' => null,
                'notes' => 'Generated outbound shipping EPCIS for ship order session #0.',
            ])->save();

            $this->assertTrue($gate->hasPriorTenantShipEvidence($epc, (int) $site->getKey()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function failed_first_ingest_outbound_shipping_is_not_prior_ship_evidence(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $partner = $this->ensureDemoPartner();
            $epc = $this->createUnpackedItem();

            $document = $this->createAuthoredShippingDocument($site, corrects: null);
            $document->forceFill([
                'status' => 'error',
                'ingest_generation' => 1,
                'processed_at' => null,
            ])->save();
            $this->attachShippingEvent($document, $epc, $site, $partner);

            $this->assertFalse(
                app(EpcCustodyGate::class)->hasPriorTenantShipEvidence($epc, (int) $site->getKey()),
                'Never-validated outbound author events must not authorize corrective ship.',
            );

            $document->forceFill(['processed_at' => now()->subHour()])->save();
            $this->assertTrue(
                app(EpcCustodyGate::class)->hasPriorTenantShipEvidence($epc, (int) $site->getKey()),
                'Last-good projection after failed reprocess must still count as prior ship evidence.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * Pack a fresh item under $parent on the packing event the fixture already has,
     * so the pallet is three levels deep before it ships.
     */
    private function packNewItemUnder(Epc $parent): Epc
    {
        $packing = AggregationLink::query()
            ->where('child_epc_id', $parent->getKey())
            ->orderByDesc('id')
            ->firstOrFail();

        $item = $this->createUnpackedItem();

        AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $item->getKey(),
            'established_by_event_id' => $packing->established_by_event_id,
            'link_type' => 'aggregation',
            'valid_from' => now()->subMinutes(5),
        ]);

        return $item;
    }

    private function createUnpackedItem(): Epc
    {
        $serial = '9'.str_pad((string) random_int(0, 9999999999999), 13, '0', STR_PAD_LEFT);
        $uri = 'urn:epc:id:sgtin:030116.0200116.'.$serial;

        $item = Epc::query()->firstOrCreate(
            ['epc_uri' => $uri],
            Epc::materializeAttributesFromUri($uri),
        );
        $this->epcIds[] = (int) $item->getKey();

        return $item;
    }

    private function createAuthoredShippingDocument(Site $site, ?EpcisDocument $corrects): EpcisDocument
    {
        $isCorrection = $corrects !== null;

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Shipping,
            'corrects_epcis_document_id' => $corrects?->getKey(),
            'format' => 'xml',
            'status' => 'generated',
            'received_at' => now(),
            'ship_from_site_id' => (int) $site->getKey(),
            'notes' => 'Generated outbound shipping EPCIS for ship order session #0.'.
                ($isCorrection ? ' Corrective shipment.' : ''),
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function attachShippingEvent(
        EpcisDocument $document,
        Epc $epc,
        Site $site,
        TradingPartner $partner,
    ): EpcisEvent {
        $attributes = [
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '-05:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            'read_point_gln' => $site->gln,
            'biz_location_gln' => $partner->gln,
        ];

        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $attributes['ingest_generation'] = 1;
        }

        $event = EpcisEvent::query()->create($attributes);

        DB::table('event_epcs')->insert([
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]);

        return $event;
    }

    #[Test]
    public function corrective_ship_without_a_document_requires_prior_shipment_from_the_same_site(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $this->completeShipOrderFor($site);

            $otherSite = Site::query()->create([
                'name' => 'Other Ship Site '.Str::random(6),
                'gln' => $this->uniqueOrgGln(self::CORRECTIVE_COMPANY_PREFIX),
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds[] = (int) $otherSite->getKey();

            $elsewhere = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $otherSite->getKey(),
                isCorrective: true,
                correctiveReason: 'Correcting from a dock this unit never left.',
            );
            $this->sessionIds[] = (int) $elsewhere->getKey();

            $blocked = app(ConfirmOutboundShippingScan::class)->handle($elsewhere, self::SSCC_URI);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('not_correctable', $blocked['effect']);
            $this->assertStringContainsString('ship-from site', $blocked['message']);

            $sameSite = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                isCorrective: true,
                correctiveReason: 'Correcting from the dock it left.',
            );
            $this->sessionIds[] = (int) $sameSite->getKey();

            $allowed = app(ConfirmOutboundShippingScan::class)->handle($sameSite, self::SSCC_URI);
            $this->assertTrue($allowed['ok'], $allowed['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function directly_scanned_sgtin_reaches_the_authored_shipping_event(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $sgtinId = (int) Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id');
            AggregationLink::query()
                ->where('child_epc_id', $sgtinId)
                ->whereNull('valid_to')
                ->update(['valid_to' => now()]);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $confirmed = app(ConfirmOutboundShippingScan::class)->handle($session, self::SGTIN_URI);
            $this->assertTrue($confirmed['ok'], $confirmed['message']);
            $this->assertSame('parent', $confirmed['line']?->line_role);

            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-SGTIN-'.Str::random(4),
                'customer_po' => 'PO-SGTIN-'.Str::random(4),
                'dscsa_affirm' => true,
            ]);

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->assertNotNull($completed->epcis_document_id);

            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            $this->documentIds[] = (int) $document->getKey();

            $shipping = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                ->firstOrFail();

            $sgtin = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $this->assertTrue(
                DB::table('event_epcs')
                    ->where('event_id', $shipping->getKey())
                    ->where('epc_id', $sgtin->getKey())
                    ->exists(),
                'A directly scanned SGTIN must appear on the shipping event epcList.',
            );

            $this->assertStringContainsString(
                self::SGTIN_URI,
                (string) Storage::disk($document->payload_disk)->get($document->payload_path),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function first_send_authors_ti_ts_without_inventing_history(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $completed = $this->completeShipOrderWithReferences($site, [
                'asn_number' => 'ASN-TITS-001',
                'customer_po' => 'PO-TITS-001',
                'dscsa_affirm' => true,
            ]);

            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            $xml = (string) Storage::disk($document->payload_disk)->get($document->payload_path);

            $this->assertSame(
                [],
                $this->xsdFindings($xml),
                'Authored shipping EPCIS must satisfy the GS1 EPCIS 1.2 XSD.',
            );

            // Ours is split on the company prefix we hold; theirs is the SGLN they gave us.
            $shipFromSgln = Sgln::toUrn((string) $site->gln, strlen(self::CORRECTIVE_COMPANY_PREFIX));
            $shipToSgln = (string) $partner->sgln;
            $this->assertNotNull($shipFromSgln);
            $this->assertSame(self::DEMO_PARTNER_SGLN, $shipToSgln);

            // Party GLNs are read back off the authored SGLNs, so the SBDH and the
            // source/destination lists can never name different identities.
            $sellerGln = Sgln::fromUrn($shipFromSgln)['gln'] ?? null;
            $buyerGln = Sgln::fromUrn($shipToSgln)['gln'] ?? null;
            $this->assertSame($site->gln, $sellerGln);
            $this->assertNotNull($buyerGln);

            // SBDH: sender and receiver GLNs, and the product's InstanceIdentifier convention.
            $this->assertStringContainsString('<sbdh:StandardBusinessDocumentHeader>', $xml);
            $this->assertStringContainsString(
                '<sbdh:Identifier Authority="GLN">'.$sellerGln.'</sbdh:Identifier>',
                $xml,
            );
            $this->assertStringContainsString(
                '<sbdh:Identifier Authority="GLN">'.$buyerGln.'</sbdh:Identifier>',
                $xml,
            );
            $this->assertStringContainsString(
                '<sbdh:InstanceIdentifier>'.$document->document_uuid.'</sbdh:InstanceIdentifier>',
                $xml,
            );

            // TI: the PO rides on the buyer's GLN, the ASN on the seller's.
            $poUrn = 'urn:epcglobal:cbv:bt:'.$buyerGln.':PO-TITS-001';
            $asnUrn = 'urn:epcglobal:cbv:bt:'.$sellerGln.':ASN-TITS-001';
            $this->assertStringContainsString(
                '<bizTransaction type="urn:epcglobal:cbv:btt:po">'.$poUrn.'</bizTransaction>',
                $xml,
            );
            $this->assertStringContainsString(
                '<bizTransaction type="urn:epcglobal:cbv:btt:desadv">'.$asnUrn.'</bizTransaction>',
                $xml,
            );

            foreach ([
                '<source type="urn:epcglobal:cbv:sdt:owning_party">'.$shipFromSgln.'</source>',
                '<source type="urn:epcglobal:cbv:sdt:location">'.$shipFromSgln.'</source>',
                '<destination type="urn:epcglobal:cbv:sdt:owning_party">'.$shipToSgln.'</destination>',
                '<destination type="urn:epcglobal:cbv:sdt:location">'.$shipToSgln.'</destination>',
            ] as $expected) {
                $this->assertStringContainsString($expected, $xml);
            }

            // TS: the seller's affirmation.
            $this->assertStringContainsString('<gs1ushc:affirmTransactionStatement>true</gs1ushc:affirmTransactionStatement>', $xml);
            $this->assertStringContainsString('FDCA Sec. 581(27)(A)-(G)', $xml);

            // A first send attests only to what we witnessed: no commissioning or packing
            // history, and no bizLocation on the shipping event.
            $this->assertStringNotContainsString('bizstep:commissioning', $xml);
            $this->assertStringNotContainsString('bizstep:packing', $xml);
            $this->assertStringNotContainsString('<AggregationEvent>', $xml);
            $this->assertStringNotContainsString('<bizLocation>', $xml);
            $this->assertSame(1, substr_count($xml, '<ObjectEvent>'));
            $this->assertSame(1, (int) $document->event_count);

            $shipping = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                ->firstOrFail();

            $transactions = DB::table('event_biz_transactions')
                ->where('event_id', $shipping->getKey())
                ->orderBy('type_uri')
                ->get(['type_uri', 'value']);

            $this->assertCount(2, $transactions);
            $this->assertSame('urn:epcglobal:cbv:btt:desadv', $transactions[0]->type_uri);
            $this->assertSame($asnUrn, $transactions[0]->value);
            $this->assertSame('urn:epcglobal:cbv:btt:po', $transactions[1]->type_uri);
            $this->assertSame($poUrn, $transactions[1]->value);

            $parties = $this->shippingPartiesByType($shipping);
            $this->assertSame(
                ['destination:location', 'destination:owning_party', 'source:location', 'source:owning_party'],
                $parties->keys()->sort()->values()->all(),
            );

            $this->assertSame($shipFromSgln, $parties['source:owning_party']->gln_uri);
            $this->assertSame($sellerGln, $parties['source:owning_party']->gln);
            $this->assertSame($shipFromSgln, $parties['source:location']->gln_uri);
            $this->assertSame((int) $site->getKey(), (int) $parties['source:location']->site_id);
            $this->assertSame($shipToSgln, $parties['destination:owning_party']->gln_uri);
            $this->assertSame($buyerGln, $parties['destination:owning_party']->gln);
            $this->assertSame(
                (int) $partner->getKey(),
                (int) $parties['destination:owning_party']->trading_partner_id,
            );
            $this->assertSame($shipToSgln, $parties['destination:location']->gln_uri);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function first_send_references_the_invoice_number_when_there_is_no_customer_po(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $completed = $this->completeShipOrderWithReferences($site, [
                'asn_number' => 'ASN-INV-001',
                'invoice_number' => 'INV-INV-001',
                'dscsa_affirm' => true,
            ]);

            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            $xml = (string) Storage::disk($document->payload_disk)->get($document->payload_path);

            $shipToSgln = (string) $partner->sgln;
            $this->assertSame(self::DEMO_PARTNER_SGLN, $shipToSgln);
            $buyerGln = Sgln::fromUrn($shipToSgln)['gln'] ?? null;
            $this->assertNotNull($buyerGln);

            $poUrn = 'urn:epcglobal:cbv:bt:'.$buyerGln.':INV-INV-001';
            $this->assertStringContainsString(
                '<bizTransaction type="urn:epcglobal:cbv:btt:po">'.$poUrn.'</bizTransaction>',
                $xml,
            );

            $shipping = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                ->firstOrFail();

            $this->assertTrue(
                DB::table('event_biz_transactions')
                    ->where('event_id', $shipping->getKey())
                    ->where('type_uri', 'urn:epcglobal:cbv:btt:po')
                    ->where('value', $poUrn)
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function authored_shipping_epcis_omits_the_transaction_statement_when_ti_ts_is_not_affirmed(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-NOTS-001',
                'customer_po' => 'PO-NOTS-001',
                'dscsa_affirm' => false,
            ]);

            // The send gate refuses an unaffirmed shipment, so drive the authoring action
            // directly: the statement is a seller affirmation, never a default.
            $session->fresh()->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $document = app(GenerateShippingEpcisEvents::class)->handle($session->fresh())['document'];
            $this->assertNotNull($document);
            $this->documentIds[] = (int) $document->getKey();

            $xml = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertStringNotContainsString('<gs1ushc:dscsaTransactionStatement>', $xml);

            // The rest of the transaction information still travels with the shipment.
            $this->assertStringContainsString('<sbdh:StandardBusinessDocumentHeader>', $xml);
            $this->assertStringContainsString('<bizTransactionList>', $xml);
            $this->assertStringContainsString('<destinationList>', $xml);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * GS1 EPCIS 1.2 XSD complaints about an authored payload, as descriptions.
     *
     * @return list<string>
     */
    private function xsdFindings(string $xml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'shipping_epcis_');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, $xml);

            return array_map(
                fn (EpcisValidationFinding $finding): string => $finding->description,
                app(EpcisXsdValidator::class)->validateFile($path),
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * Shipping event parties keyed by "{party_role}:{source_dest_type}".
     *
     * @return Collection<string, object>
     */
    private function shippingPartiesByType(EpcisEvent $shipping): Collection
    {
        return DB::table('event_parties')
            ->where('event_id', $shipping->getKey())
            ->get(['party_role', 'gln', 'gln_uri', 'site_id', 'trading_partner_id', 'extra_json'])
            ->mapWithKeys(function (object $row): array {
                $extra = json_decode((string) $row->extra_json, true);
                $type = is_array($extra) ? (string) ($extra['source_dest_type'] ?? '') : '';

                return [$row->party_role.':'.$type => $row];
            });
    }

    /**
     * Send the fixture SSCC out of $site to the demo partner with explicit references.
     *
     * @param  array<string, mixed>  $references
     */
    private function completeShipOrderWithReferences(Site $site, array $references): OutboundShippingSession
    {
        $partner = $this->ensureDemoPartner();

        $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
        $this->sessionIds[] = (int) $session->getKey();

        $confirmed = app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
        $this->assertTrue($confirmed['ok'], $confirmed['message']);

        app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
            'trading_partner_id' => (int) $partner->getKey(),
        ]);
        app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), $references);

        $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());

        if ($completed->epcis_document_id !== null) {
            $this->documentIds[] = (int) $completed->epcis_document_id;
        }

        return $completed;
    }

    #[Test]
    public function send_reports_failure_when_shipping_epcis_cannot_be_authored(): void
    {
        $tenant = $this->initializeWholesalerTenant();
        $restorePrefix = null;

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-AUTHOR-FAIL',
                'customer_po' => 'PO-AUTHOR-FAIL',
                'dscsa_affirm' => true,
            ]);

            // With neither a company prefix nor an SGLN on the site, the ship-from location
            // cannot be named, so authoring throws.
            $settings = TenantSettings::forTenant(tenant());
            $restorePrefix = $settings->companyPrefix();
            $settings->setCompanyPrefix(null);
            tenant()->save();
            $site->forceFill(['sgln' => null])->save();
            $this->assertNull($site->fresh()->sgln);

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException when shipping EPCIS cannot be authored.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('could not be authored', $e->getMessage());
            }

            $session = $session->fresh();
            $this->assertNotSame('completed', $session->status);
            $this->assertNull($session->completed_at);
            $this->assertNull($session->epcis_document_id);
            $this->assertNull($session->shipping_events_generated_at);
        } finally {
            if ($restorePrefix !== null && tenancy()->initialized) {
                TenantSettings::forTenant(tenant())->setCompanyPrefix($restorePrefix);
                tenant()->save();
            }

            $this->cleanup($tenant);
        }
    }

    /**
     * A prior request can mark the session completed and then die before EPCIS
     * authoring finishes (crash, timeout, worker restart) — the session is left
     * `completed` with no `shipping_events_generated_at`. Retrying Send must not
     * leave the order stuck: on continued failure it must revert to the status
     * the order was in before that completion, not stay "completed" forever with
     * no document and no way to scan or cancel.
     */
    #[Test]
    public function send_recovers_a_session_stuck_completed_without_shipping_epcis(): void
    {
        $tenant = $this->initializeWholesalerTenant();
        $restorePrefix = null;

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-STUCK-001',
                'customer_po' => 'PO-STUCK-001',
                'dscsa_affirm' => true,
            ]);

            // Simulate a prior request that flipped the session to completed and then
            // died before shipping EPCIS authoring ran — status is completed, but
            // nothing was authored.
            $session = $session->fresh();
            $session->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            $this->assertSame('in_progress', $this->priorActiveStatus($session));

            $settings = TenantSettings::forTenant(tenant());
            $restorePrefix = $settings->companyPrefix();
            $settings->setCompanyPrefix(null);
            tenant()->save();
            $site->forceFill(['sgln' => null])->save();

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException when shipping EPCIS still cannot be authored.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('could not be authored', $e->getMessage());
            }

            $session = $session->fresh();
            $this->assertSame('in_progress', $session->status, 'Session must not stay stuck completed with no EPCIS document.');
            $this->assertNull($session->completed_at);
            $this->assertNull($session->epcis_document_id);
            $this->assertNull($session->shipping_events_generated_at);
            $this->assertTrue($session->isActive());
            $this->assertFalse($session->needsShippingEpcis());

            // Fix the underlying problem and retry: the order recovers fully.
            $settings->setCompanyPrefix($restorePrefix);
            tenant()->save();

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->assertSame('completed', $completed->status);
            $this->assertNotNull($completed->shipping_events_generated_at);
            $this->assertNotNull($completed->epcis_document_id);
            $this->documentIds[] = (int) $completed->epcis_document_id;
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * If authoring stamped shipping_events_generated_at, revert must not wipe
     * the completed session (GenerateShippingEpcisEvents is final — exercise the guard).
     */
    #[Test]
    public function generate_failure_does_not_revert_when_shipping_events_already_exist(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $customer = $this->createCustomerPartner('Revert Guard Customer');
            $shipTo = $this->createCustomerSite($customer, '037100');
            $session = $this->readyToSendSession($site, $customer, 'ASN-REVERT-GUARD', 'PO-REVERT-GUARD', $shipTo);

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'shipping_events_generated_at' => now(),
            ])->save();

            $action = app(CompleteOutboundShippingSession::class);
            $method = new ReflectionMethod($action, 'revertIncompleteCompletion');
            $method->invoke($action, $session, 'in_progress');

            $session = $session->fresh();
            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->completed_at);
            $this->assertNotNull($session->shipping_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * A failed transmit hook must not look like a successful send, but authored
     * shipping events must stay on the completed session.
     */
    #[Test]
    public function failed_transmit_hook_throws_without_reverting_authored_shipping_events(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config([
                'tracepharma.epcis_jobs.enabled' => false,
                'tracepharma.epcis.enforce_atp_outbound_gate' => false,
            ]);

            $this->mock(OutboundEpcisTransmitter::class, function ($mock): void {
                $mock->shouldReceive('transmit')
                    ->atLeast()
                    ->once()
                    ->andReturnUsing(function (EpcisDocument $document): void {
                        $document->forceFill([
                            'transmission_status' => 'failed',
                            'error_message' => 'partner rejected payload',
                        ])->save();
                    });
            });

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $customer = $this->createCustomerPartner('Hook Fail Customer');
            $shipTo = $this->createCustomerSite($customer, '037101');
            $session = $this->readyToSendSession($site, $customer, 'ASN-HOOK-FAIL', 'PO-HOOK-FAIL', $shipTo);

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException when outbound transmission failed after authoring.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('outbound transmission did not succeed', $e->getMessage());
                $this->assertStringContainsString('partner rejected payload', $e->getMessage());
            }

            $session = $session->fresh();
            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->shipping_events_generated_at);
            $this->assertNotNull($session->epcis_document_id);
            $this->documentIds[] = (int) $session->epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($session->epcis_document_id);
            $this->assertSame('failed', $document->transmission_status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * Once shipping EPCIS is authored, re-invoking Send (double submit, retried
     * job, etc.) must be a no-op success rather than re-authoring or throwing.
     */
    #[Test]
    public function send_is_idempotent_once_shipping_epcis_is_authored(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();
            $session = $this->readyToSendSession($site, $partner, 'ASN-IDEMPOTENT-001', 'PO-IDEMPOTENT-001');

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->documentIds[] = (int) $completed->epcis_document_id;

            $again = app(CompleteOutboundShippingSession::class)->handle($completed->fresh());

            $this->assertSame($completed->epcis_document_id, $again->epcis_document_id);
            $this->assertEquals($completed->shipping_events_generated_at, $again->shipping_events_generated_at);
            $this->assertSame(
                1,
                EpcisEvent::query()->where('document_id', $completed->epcis_document_id)->count(),
                'Re-sending an already-authored session must not author duplicate events.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * A GLN says nothing about where its GS1 company prefix ends, so an SGLN we built by
     * splitting a customer's GLN on our own prefix names a location that is ours to have
     * imagined. It reaches them on the transaction information for a shipment they have
     * to hold for six years, so the send stops until they tell us their own.
     */
    #[Test]
    public function send_refuses_to_invent_an_sgln_for_a_customer_that_has_none(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('SGLN Withheld Customer');
            $shipTo = $this->createCustomerSite($customer, '037020');
            $license = AtpLicense::query()->create([
                'site_id' => (int) $shipTo->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();
            // Their company prefix is not ours, so nothing on record encodes their GLN.
            $customer->forceFill(['sgln' => null])->save();
            $shipTo->forceFill(['sgln' => null])->save();
            $this->assertNull($customer->fresh()->sgln);
            $this->assertNull($shipTo->fresh()->sgln);

            $session = $this->readyToSendSession($site, $customer, 'ASN-SGLN-001', 'PO-SGLN-001', $shipTo);

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected the send to refuse a customer with no SGLN on record.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('not ours to guess', $e->getMessage());
                $this->assertStringContainsString((string) $shipTo->gln, $e->getMessage());
            }

            $session = $session->fresh();
            $this->assertNotSame('completed', $session->status);
            $this->assertNull($session->epcis_document_id);

            // Once they state it, that exact SGLN is what the shipment carries.
            $shipToSgln = Sgln::toUrn((string) $shipTo->gln, 6);
            $this->assertNotNull($shipToSgln);
            $shipTo->forceFill(['sgln' => $shipToSgln])->save();
            $this->assertSame($shipToSgln, $shipTo->fresh()->sgln);

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->assertSame('completed', $completed->status);
            $this->assertNotNull($completed->epcis_document_id);
            $this->documentIds[] = (int) $completed->epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            $xml = (string) Storage::disk($document->payload_disk)->get($document->payload_path);

            $this->assertStringContainsString(
                '<destination type="urn:epcglobal:cbv:sdt:location">'.$shipToSgln.'</destination>',
                $xml,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_uses_inbound_event_party_sgln_when_customer_site_sgln_is_blank(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('SGLN From Inbound Party');
            $shipTo = $this->createCustomerSite($customer, '037021');
            $license = AtpLicense::query()->create([
                'site_id' => (int) $shipTo->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();

            $published = Sgln::toUrn((string) $shipTo->gln, 6);
            $this->assertNotNull($published);

            $customer->forceFill(['sgln' => null])->save();
            $shipTo->forceFill(['sgln' => null])->save();
            $this->assertNull($customer->fresh()->sgln);
            $this->assertNull($shipTo->fresh()->sgln);

            $inbound = $this->createAuthoredShippingDocument($site, null);
            $inbound->forceFill(['direction' => 'inbound', 'authored_kind' => null])->save();
            $eventAttributes = [
                'document_id' => $inbound->getKey(),
                'event_id' => 'urn:uuid:'.Str::uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'record_time' => now(),
                'event_timezone_offset' => '-05:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ];
            if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
                $eventAttributes['ingest_generation'] = 1;
            }
            $event = EpcisEvent::query()->create($eventAttributes);
            DB::table('event_parties')->insert([
                'event_id' => $event->getKey(),
                'party_role' => 'destination',
                'gln' => $shipTo->gln,
                'gln_uri' => $published,
                'trading_partner_id' => $customer->getKey(),
                'site_id' => $shipTo->getKey(),
                'extra_json' => json_encode(['source_dest_type' => 'location']),
            ]);
            DB::table('event_locations')->insert([
                'event_id' => $event->getKey(),
                'location_type' => 'readPoint',
                'gln' => $shipTo->gln,
                'gln_uri' => $published,
                'site_id' => $shipTo->getKey(),
            ]);

            $session = $this->readyToSendSession($site, $customer, 'ASN-SGLN-INBOUND-001', 'PO-SGLN-INBOUND-001', $shipTo);
            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());

            $this->assertSame('completed', $completed->status);
            $this->assertNotNull($completed->epcis_document_id);
            $this->documentIds[] = (int) $completed->epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            $xml = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertStringContainsString($published, $xml);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_authors_destination_list_from_captured_site_sgln(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('SGLN Captured On Site');
            $shipTo = $this->createCustomerSite($customer, '037022');
            $license = AtpLicense::query()->create([
                'site_id' => (int) $shipTo->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();

            $published = Sgln::toUrn((string) $shipTo->gln, 6);
            $this->assertNotNull($published);

            $customer->forceFill(['sgln' => null])->save();
            $shipTo->forceFill(['sgln' => $published])->save();
            $this->assertNull($customer->fresh()->sgln);
            $this->assertSame($published, $shipTo->fresh()->sgln);

            $session = $this->readyToSendSession($site, $customer, 'ASN-SGLN-SITE-001', 'PO-SGLN-SITE-001', $shipTo);
            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());

            $this->assertSame('completed', $completed->status);
            $this->assertNotNull($completed->epcis_document_id);
            $this->documentIds[] = (int) $completed->epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            $xml = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertStringContainsString(
                '<destination type="urn:epcglobal:cbv:sdt:location">'.$published.'</destination>',
                $xml,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * Ship the fixture SSCC out of $site so it has prior ship evidence.
     */
    private function completeShipOrderFor(Site $site): OutboundShippingSession
    {
        $partner = $this->ensureDemoPartner();

        $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
        $this->sessionIds[] = (int) $session->getKey();

        $confirmed = app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
        $this->assertTrue($confirmed['ok'], $confirmed['message']);

        app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
            'trading_partner_id' => (int) $partner->getKey(),
        ]);
        app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
            'asn_number' => 'ASN-ORIG-'.Str::random(4),
            'customer_po' => 'PO-ORIG-'.Str::random(4),
            'dscsa_affirm' => true,
        ]);

        $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());

        if ($completed->epcis_document_id !== null) {
            $this->documentIds[] = (int) $completed->epcis_document_id;
        }

        return $completed;
    }

    #[Test]
    public function complete_without_asn_po_and_ti_fails(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);

            $this->expectException(DomainException::class);
            app(CompleteOutboundShippingSession::class)->handle($session->fresh());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function complete_with_asn_only_fails_without_po_or_invoice(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-ONLY-001',
                'customer_po' => 'PO-WILL-CLEAR',
                'invoice_number' => null,
                'dscsa_affirm' => true,
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'customer_po' => null,
                'invoice_number' => null,
            ]);

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException when sending with ASN only.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Customer PO or invoice number is required', $e->getMessage());
            }

            $this->assertNotSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_shipment_action_blocks_when_po_and_invoice_cleared(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createShippingUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant);
            $user->syncSites([(int) $site->getKey()], (int) $site->getKey());
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $session = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                openedBy: (int) $user->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-UI-001',
                'customer_po' => 'PO-UI-001',
                'dscsa_affirm' => true,
            ]);

            Livewire::test(ViewOutboundShippingSession::class, ['record' => $session->getKey()])
                ->set('wizardStep', 3)
                ->set('asn_number', 'ASN-UI-001')
                ->set('customer_po', '')
                ->set('invoice_number', '')
                ->assertActionDisabled('sendShipment');

            $this->assertNotSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function complete_with_valid_refs_generates_epcis_and_transmits(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Http::fake([
                'https://example.com/epcis-inbound' => Http::response('OK', 202),
            ]);

            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $connection = OutboundConnection::query()->create([
                'name' => 'Test HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://example.com/epcis-inbound'],
                'credentials' => ['webhook_token' => 'ship-test-token'],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);

            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
                'outbound_connection_id' => (int) $connection->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-SHIP-001',
                'customer_po' => 'PO-12345',
                'dscsa_affirm' => true,
            ]);

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->assertSame('completed', $completed->status);
            $this->assertNotNull($completed->shipping_events_generated_at);
            $this->assertNotNull($completed->epcis_document_id);

            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            $this->documentIds[] = (int) $document->getKey();
            $this->assertSame('outbound', $document->direction);
            $this->assertTrue($document->dscsa_affirm);
            $this->assertSame('parsed', $document->status);
            $this->assertTrue(Storage::disk($document->payload_disk)->exists($document->payload_path));

            $this->assertSame((string) tenant()->name, $document->ship_from_name);
            $this->assertSame($site->name, $document->ship_from_site_name);
            $this->assertSame($partner->name, $document->ship_to_name);
            $this->assertNull($document->ship_to_site_name);
            $this->assertSame((int) $partner->getKey(), (int) $document->ship_to_partner_id);
            $this->assertSame(
                TenantSettings::forTenant(tenant())->gln() ?? $site->gln,
                $document->sender_gln,
            );
            $this->assertSame($partner->gln, $document->receiver_gln);

            $shipping = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                ->first();
            $this->assertNotNull($shipping);
            $this->assertSame('OBSERVE', $shipping->action);
            $this->assertSame('urn:epcglobal:cbv:disp:in_transit', $shipping->disposition);

            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);
            $this->assertNotNull($document->sent_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function add_from_receiving_session_copies_confirmed_epcs(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $receivingSessionId = $this->makeEpcShippableAtSite($site);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $result = app(AddOutboundShippingEpcsFromReceivingSession::class)->handle(
                $session,
                $receivingSessionId,
            );

            $this->assertSame(1, $result['added']);
            $this->assertSame(0, $result['skipped']);
            $this->assertSame(1, (int) $session->fresh()->confirmed_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function add_from_receiving_session_rejects_in_progress_receiving(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $receivingSessionId = $this->makeEpcShippableAtSite($site, completed: false);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('must be completed');

            app(AddOutboundShippingEpcsFromReceivingSession::class)->handle(
                $session,
                $receivingSessionId,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function add_from_receiving_session_rejects_null_ship_order_site(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $receivingSessionId = $this->makeEpcShippableAtSite($site);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            $session = $session->fresh();
            $session->site_id = null;

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Ship order has no site');

            app(AddOutboundShippingEpcsFromReceivingSession::class)->handle(
                $session,
                $receivingSessionId,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function add_from_receiving_session_rejects_when_receiving_events_not_generated(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $receivingSessionId = $this->makeEpcShippableAtSite($site);

            ReceivingSession::query()
                ->whereKey($receivingSessionId)
                ->update(['receiving_events_generated_at' => null]);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Receiving EPCIS events must be generated');

            app(AddOutboundShippingEpcsFromReceivingSession::class)->handle(
                $session,
                $receivingSessionId,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function add_from_receiving_session_rejects_null_receiving_site(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $receivingSessionId = $this->makeEpcShippableAtSite($site);

            ReceivingSession::query()
                ->whereKey($receivingSessionId)
                ->update(['site_id' => null]);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Receiving session has no site');

            app(AddOutboundShippingEpcsFromReceivingSession::class)->handle(
                $session,
                $receivingSessionId,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function create_is_ungated_while_send_shipment_requires_confirmation_when_gate_enabled(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => true]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createShippingUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant);
            $user->syncSites([(int) $site->getKey()], (int) $site->getKey());

            $createPage = Livewire::test(CreateOutboundShippingSession::class);
            $createAction = (new ReflectionMethod(CreateOutboundShippingSession::class, 'getCreateFormAction'))
                ->invoke($createPage->instance());

            $this->assertFalse(
                $createAction->isConfirmationRequired(),
                'Opening a ship order session must not require regulatory password confirmation.',
            );

            $session = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                openedBy: (int) $user->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $view = Livewire::test(ViewOutboundShippingSession::class, ['record' => $session->getKey()]);
            $sendShipment = $view->instance()->getAction('sendShipment');

            $this->assertTrue(
                $sendShipment->isConfirmationRequired(),
                'Send shipment must require confirmation when submitting scanned data.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_without_site_access_cannot_confirm_at_that_site(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $siteA = $this->createShipSite($tenant);
            $siteB = Site::query()->create([
                'name' => 'Restricted Site '.Str::random(6),
                'gln' => $this->uniqueOrgGln('036615'),
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds[] = (int) $siteB->getKey();

            $owner = $this->createShippingUser();
            $this->actingAs($owner);

            $this->makeEpcShippableAtSite($siteB);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $siteB->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $user = User::factory()->create();
            $user->syncSites([(int) $siteA->getKey()], (int) $siteA->getKey());
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $this->expectException(AuthorizationException::class);
            app(ConfirmOutboundShippingScan::class)->handle($session->fresh(), self::SSCC_URI);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_without_site_access_cannot_open_at_that_site(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $siteA = $this->createShipSite($tenant);
            $siteB = Site::query()->create([
                'name' => 'Restricted Site '.Str::random(6),
                'gln' => $this->uniqueOrgGln('036615'),
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds[] = (int) $siteB->getKey();

            $user = User::factory()->create();
            $user->syncSites([(int) $siteA->getKey()], (int) $siteA->getKey());
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $this->expectException(AuthorizationException::class);
            app(OpenOutboundShippingSession::class)->handle((int) $siteB->getKey());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function customer_autocomplete_lists_company_address_and_autofills_gln(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createShippingUser();
            $this->actingAs($user);

            $shipFrom = $this->createShipSite($tenant);
            $user->syncSites([(int) $shipFrom->getKey()], (int) $shipFrom->getKey());

            $partner = $this->ensureDemoPartner();
            $uniqueStreet = 'Autocomplete '.Str::random(8).' Ave';
            $shipToGln = $this->uniqueOrgGln('036616');
            $shipTo = Site::query()->create([
                'trading_partner_id' => (int) $partner->getKey(),
                'name' => 'Ship-To '.$uniqueStreet,
                'gln' => $shipToGln,
                'street_address' => $uniqueStreet,
                'city' => 'Dallas',
                'state' => 'TX',
                'zipcode' => '75201',
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->siteIds[] = (int) $shipTo->getKey();

            $hits = app(SearchShipToCustomers::class)->handle($uniqueStreet);
            $this->assertNotEmpty($hits);
            $match = collect($hits)->firstWhere('site_id', (int) $shipTo->getKey());
            $this->assertNotNull($match);
            $this->assertSame($partner->name, $match['company']);
            $this->assertStringContainsString($uniqueStreet, $match['address']);
            $this->assertSame($shipToGln, $match['gln']);

            $session = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $shipFrom->getKey(),
                openedBy: (int) $user->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $browse = app(SearchShipToCustomers::class)->handle('');
            $this->assertNotEmpty($browse, 'Empty search should browse active partner sites.');
            $this->assertNotNull(collect($browse)->firstWhere('site_id', (int) $shipTo->getKey()));

            Livewire::test(ViewOutboundShippingSession::class, ['record' => $session->getKey()])
                ->call('openCustomerDropdown')
                ->assertSet('customerDropdownOpen', true)
                ->assertSet('customerSuggestions', fn (array $suggestions): bool => $suggestions !== [])
                ->call('selectShipToCustomer', (int) $shipTo->getKey())
                ->assertSet('trading_partner_id', (int) $partner->getKey())
                ->assertSet('ship_to_site_id', (int) $shipTo->getKey())
                ->assertSet('ship_to_gln', $shipToGln)
                ->assertSet('customerSearch', $partner->name)
                ->assertDontSee('Ship-to GLN');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function party_update_rejects_a_ship_to_site_owned_by_another_customer(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);

            $customerA = $this->createCustomerPartner('Customer A');
            $customerB = $this->createCustomerPartner('Customer B');
            $siteOfB = $this->createCustomerSite($customerB, '037001');

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            try {
                app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                    'trading_partner_id' => (int) $customerA->getKey(),
                    'ship_to_site_id' => (int) $siteOfB->getKey(),
                ]);
                $this->fail('Expected pairing a customer with another customer\'s site to be refused.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Customer B', $e->getMessage());
                $this->assertStringContainsString('not Customer A', $e->getMessage());
            }

            // A ship-to site with no customer of its own is not a customer address.
            $organizationSite = Site::query()->create([
                'name' => 'Org Site '.Str::random(6),
                'gln' => $this->uniqueOrgGln('037002'),
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds[] = (int) $organizationSite->getKey();

            try {
                app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                    'trading_partner_id' => (int) $customerA->getKey(),
                    'ship_to_site_id' => (int) $organizationSite->getKey(),
                ]);
                $this->fail('Expected an organization-owned site to be refused as a ship-to.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('not a customer site', $e->getMessage());
            }

            // Naming a site without naming its customer leaves the shipment half-addressed.
            try {
                app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                    'trading_partner_id' => null,
                    'ship_to_site_id' => (int) $siteOfB->getKey(),
                ]);
                $this->fail('Expected a ship-to site without a customer to be refused.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Select the customer', $e->getMessage());
            }

            $paired = app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $customerB->getKey(),
                'ship_to_site_id' => (int) $siteOfB->getKey(),
            ]);

            $this->assertSame((int) $customerB->getKey(), (int) $paired->trading_partner_id);
            $this->assertSame((int) $siteOfB->getKey(), (int) $paired->ship_to_site_id);

            // The pair is rechecked on later saves, so swapping only the customer cannot
            // orphan the ship-to site that is already on the order.
            try {
                app(UpdateOutboundShippingParty::class)->handle($paired->fresh(), [
                    'trading_partner_id' => (int) $customerA->getKey(),
                ]);
                $this->fail('Expected swapping the customer under an existing ship-to site to be refused.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Customer B', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function party_update_normalizes_ship_to_gln_and_refuses_a_bad_check_digit(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $customer = $this->createCustomerPartner('GLN Customer');
            $shipTo = $this->createCustomerSite($customer, '037003');
            $shipToGln = (string) $shipTo->gln;

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            try {
                app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                    'trading_partner_id' => (int) $customer->getKey(),
                    'ship_to_gln' => '1234567890123',
                ]);
                $this->fail('Expected a GLN with a bad check digit to be refused.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('check digit', $e->getMessage());
            }

            try {
                app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                    'trading_partner_id' => (int) $customer->getKey(),
                    'ship_to_gln' => '06141410000',
                ]);
                $this->fail('Expected a GLN that is not 13 digits to be refused.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('13-digit', $e->getMessage());
            }

            // Scanner and keyboard input arrive punctuated; the stored GLN is digits only.
            $updated = app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $customer->getKey(),
                'ship_to_site_id' => (int) $shipTo->getKey(),
                'ship_to_gln' => substr($shipToGln, 0, 4).' '.substr($shipToGln, 4, 4).'-'.substr($shipToGln, 8),
            ]);

            $this->assertSame($shipToGln, $updated->ship_to_gln);

            // A GLN that names a different location than the ship-to site would author two
            // conflicting destinations into the same shipment.
            $otherGln = $this->uniqueOrgGln('037004');

            try {
                app(UpdateOutboundShippingParty::class)->handle($updated->fresh(), [
                    'ship_to_gln' => $otherGln,
                ]);
                $this->fail('Expected a GLN that contradicts the ship-to site to be refused.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('does not match ship-to site', $e->getMessage());
            }

            $cleared = app(UpdateOutboundShippingParty::class)->handle($updated->fresh(), [
                'ship_to_gln' => '',
            ]);
            $this->assertNull($cleared->ship_to_gln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_is_blocked_while_the_ship_to_site_has_no_valid_atp_license(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, 'TX');

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('ATP Customer');
            $shipTo = $this->createCustomerSite($customer, '037005');

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $customer->getKey(),
                'ship_to_site_id' => (int) $shipTo->getKey(),
                'ship_to_gln' => (string) $shipTo->gln,
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-ATP-001',
                'customer_po' => 'PO-ATP-001',
                'dscsa_affirm' => true,
            ]);

            $validate = app(ValidateOutboundShippingSend::class);

            // No license at all for the state we ship under.
            $this->assertNotEmpty(array_filter(
                $validate->handle($session->fresh()),
                fn (string $blocker): bool => str_contains($blocker, 'ATP license for TX'),
            ));

            $license = AtpLicense::query()->create([
                'site_id' => (int) $shipTo->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->subDay(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();

            $blockers = $validate->handle($session->fresh());
            $this->assertNotEmpty(array_filter(
                $blockers,
                fn (string $blocker): bool => str_contains($blocker, 'expired ATP license for TX'),
            ));

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected an expired ship-to ATP license to stop the send.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('ATP license for TX', $e->getMessage());
            }

            $session = $session->fresh();
            $this->assertNotSame('completed', $session->status);
            $this->assertNull($session->epcis_document_id);

            // A license for a state we do not ship under does not unblock the shipment.
            $otherState = AtpLicense::query()->create([
                'site_id' => (int) $shipTo->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'IL',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $otherState->getKey();

            $this->assertNotEmpty(array_filter(
                $validate->handle($session->fresh()),
                fn (string $blocker): bool => str_contains($blocker, 'ATP license for TX'),
            ));

            $license->forceFill(['license_expiration_date' => now()->addYear()])->save();

            $this->assertSame([], $validate->handle($session->fresh()));

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->assertSame('completed', $completed->status);

            if ($completed->epcis_document_id !== null) {
                $this->documentIds[] = (int) $completed->epcis_document_id;
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_is_allowed_when_one_customer_site_holds_a_valid_license(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, 'TX');

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Multi Site Customer');
            $unlicensed = $this->createCustomerSite($customer, '037006');
            $licensed = $this->createCustomerSite($customer, '037007');

            $license = AtpLicense::query()->create([
                'site_id' => (int) $licensed->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-ATP-002',
                'customer_po' => 'PO-ATP-002',
                'dscsa_affirm' => true,
            ]);

            $validate = app(ValidateOutboundShippingSend::class);

            // Customer only: one licensed address on record is enough to send.
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $customer->getKey(),
            ]);
            $this->assertSame([], $validate->handle($session->fresh()));

            // Addressed to the site that has no license, the same customer is blocked.
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $customer->getKey(),
                'ship_to_site_id' => (int) $unlicensed->getKey(),
                'ship_to_gln' => (string) $unlicensed->gln,
            ]);
            $this->assertNotEmpty(array_filter(
                $validate->handle($session->fresh()),
                fn (string $blocker): bool => str_contains($blocker, 'ATP license for TX'),
            ));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ti_ts_affirmation_starts_unchecked_and_gates_the_send(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createShippingUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $user->syncSites([(int) $site->getKey()], (int) $site->getKey());
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();

            $session = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                openedBy: (int) $user->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            // Nobody has affirmed anything yet.
            $this->assertFalse((bool) $session->dscsa_affirm);

            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-TS-001',
                'customer_po' => 'PO-TS-001',
            ]);

            $this->assertContains(
                'TI/TS affirmation is required.',
                app(ValidateOutboundShippingSend::class)->handle($session->fresh()),
            );

            Livewire::test(ViewOutboundShippingSession::class, ['record' => $session->getKey()])
                ->assertSet('dscsa_affirm', false)
                ->set('wizardStep', 3)
                ->assertActionDisabled('sendShipment')
                ->set('dscsa_affirm', true)
                ->assertActionEnabled('sendShipment')
                ->callAction('saveReferences');

            $this->assertTrue((bool) $session->fresh()->dscsa_affirm);
            $this->assertSame([], app(ValidateOutboundShippingSend::class)->handle($session->fresh()));

            // Unchecking it puts the affirmation back on the operator.
            Livewire::test(ViewOutboundShippingSession::class, ['record' => $session->getKey()])
                ->assertSet('dscsa_affirm', true)
                ->set('wizardStep', 3)
                ->set('dscsa_affirm', false)
                ->callAction('saveReferences');

            $this->assertFalse((bool) $session->fresh()->dscsa_affirm);
            $this->assertNotSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function trading_partner_with_no_destination_sites_is_blocked(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $partner = TradingPartner::query()->create([
                'name' => 'No Sites Customer',
                'partner_type' => PartnerType::Pharmacy,
                'gln' => '0614141999999',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-NOSITES-1',
                'customer_po' => 'PO-NOSITES-1',
            ]);
            $session->fresh()->forceFill(['dscsa_affirm' => true])->save();

            $blockers = app(ValidateOutboundShippingSend::class)->handle($session->fresh());

            $this->assertTrue(collect($blockers)->contains(
                fn (string $blocker): bool => str_contains($blocker, 'no destination sites on record'),
            ));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_bare_ship_to_gln_is_bound_to_the_customer_facility_it_names(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, 'TX');

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $customer = $this->createCustomerPartner('GLN Resolve Customer');
            $shipTo = $this->createCustomerSite($customer, '037010');

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            // The operator typed a destination GLN without picking the address from the list.
            $updated = app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $customer->getKey(),
                'ship_to_gln' => (string) $shipTo->gln,
            ]);

            $this->assertSame((int) $shipTo->getKey(), (int) $updated->ship_to_site_id);
            $this->assertSame((string) $shipTo->gln, $updated->ship_to_gln);

            // A GLN the customer has no address for leaves the ATP gate nothing to judge.
            $strangerGln = $this->uniqueOrgGln('037011');

            try {
                app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                    'trading_partner_id' => (int) $customer->getKey(),
                    'ship_to_site_id' => null,
                    'ship_to_gln' => $strangerGln,
                ]);
                $this->fail('Expected a ship-to GLN with no customer site behind it to be refused.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('does not match any active site', $e->getMessage());
            }

            // The same GLN under a customer that does own it resolves, so the refusal is
            // about the address and not about the digits.
            $ownerOfStranger = $this->createCustomerPartner('Stranger GLN Owner');
            $ownedSite = Site::query()->create([
                'trading_partner_id' => (int) $ownerOfStranger->getKey(),
                'name' => 'Stranger Site '.Str::random(6),
                'gln' => $strangerGln,
                'country_code' => 'US',
                'is_active' => true,
                'is_organization_facility' => false,
            ]);
            $this->siteIds[] = (int) $ownedSite->getKey();

            $rebound = app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $ownerOfStranger->getKey(),
                'ship_to_site_id' => null,
                'ship_to_gln' => $strangerGln,
            ]);

            $this->assertSame((int) $ownedSite->getKey(), (int) $rebound->ship_to_site_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function an_unresolvable_ship_to_gln_blocks_the_send(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, 'TX');

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Orphan GLN Customer');
            $orphanGln = $this->uniqueOrgGln('037012');

            $session = $this->readyToSendSession($site, $customer, 'ASN-GLN-001', 'PO-GLN-001');

            // Written straight onto the row: a session saved before the destination site was
            // retired still has to be judged, not waved through.
            $session->forceFill(['ship_to_gln' => $orphanGln, 'ship_to_site_id' => null])->save();

            $blockers = app(ValidateOutboundShippingSend::class)->handle($session->fresh());

            $this->assertNotEmpty(array_filter(
                $blockers,
                fn (string $blocker): bool => str_contains($blocker, 'does not match any active site'),
            ));

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected an unresolvable ship-to GLN to stop the send.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('does not match any active site', $e->getMessage());
            }

            $this->assertNotSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_is_blocked_while_the_ship_to_license_has_no_expiration_date(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, 'TX');

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Undated ATP Customer');
            $shipTo = $this->createCustomerSite($customer, '037013');

            $license = AtpLicense::query()->create([
                'site_id' => (int) $shipTo->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => null,
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();

            $session = $this->readyToSendSession(
                $site,
                $customer,
                'ASN-UNDATED-001',
                'PO-UNDATED-001',
                $shipTo,
            );

            $validate = app(ValidateOutboundShippingSend::class);

            // A licence with no expiry on file cannot be shown to be in force, so it is not
            // evidence that the destination may take ownership today.
            $this->assertNotEmpty(array_filter(
                $validate->handle($session->fresh()),
                fn (string $blocker): bool => str_contains($blocker, 'no expiration date on file'),
            ));

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected an undated ship-to ATP license to stop the send.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('no expiration date on file', $e->getMessage());
            }

            // Expiring soon still authorizes today's shipment.
            $license->forceFill(['license_expiration_date' => now()->addDays(20)])->save();
            $this->assertSame([], $this->atpBlockers($validate->handle($session->fresh())));

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->assertSame('completed', $completed->status);

            if ($completed->epcis_document_id !== null) {
                $this->documentIds[] = (int) $completed->epcis_document_id;
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_is_blocked_while_the_organization_has_no_receiving_state(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, null);

            $orgSites = Site::query()
                ->ownedByOrganization()
                ->whereNotNull('state')
                ->get(['id', 'state']);
            $priorStates = $orgSites->mapWithKeys(
                fn (Site $site): array => [(int) $site->id => $site->state],
            )->all();

            foreach ($orgSites as $site) {
                $site->forceFill(['state' => null])->save();
            }

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('No State Customer');
            $shipTo = $this->createCustomerSite($customer, '037014');

            // A licence that would satisfy any state: the gap is ours, not the customer's.
            $license = AtpLicense::query()->create([
                'site_id' => (int) $shipTo->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();

            $session = $this->readyToSendSession(
                $site,
                $customer,
                'ASN-NOSTATE-001',
                'PO-NOSTATE-001',
                $shipTo,
            );

            $blockers = app(ValidateOutboundShippingSend::class)->handle($session->fresh());

            $this->assertNotEmpty(array_filter(
                $blockers,
                fn (string $blocker): bool => str_contains($blocker, 'receiving state')
                    || str_contains($blocker, 'jurisdictions'),
            ));

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected missing jurisdictions to stop the send rather than allow it.');
            } catch (DomainException $e) {
                $this->assertTrue(
                    str_contains($e->getMessage(), 'receiving state')
                    || str_contains($e->getMessage(), 'jurisdictions'),
                );
            }

            $this->assertNotSame('completed', $session->fresh()->status);
            $this->assertNull($session->fresh()->epcis_document_id);

            // Naming the state we receive under lets the same order through.
            $this->setTenantReceivingState($tenant, 'TX');
            $this->assertSame([], $this->atpBlockers(
                app(ValidateOutboundShippingSend::class)->handle($session->fresh()),
            ));

            foreach ($priorStates as $siteId => $state) {
                Site::query()->whereKey($siteId)->update(['state' => $state]);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_named_destination_gln_is_never_widened_to_another_address_of_the_customer(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, 'TX');

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Two Address Customer');
            $licensed = $this->createCustomerSite($customer, '037015');
            $unlicensed = $this->createCustomerSite($customer, '037016');

            $license = AtpLicense::query()->create([
                'site_id' => (int) $licensed->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();

            $session = $this->readyToSendSession($site, $customer, 'ASN-NARROW-001', 'PO-NARROW-001');

            $validate = app(ValidateOutboundShippingSend::class);

            // Customer only, no address named: one licensed dock on record is enough.
            $this->assertSame([], $this->atpBlockers($validate->handle($session->fresh())));

            // The GLN of the unlicensed dock, with no site id alongside it. The licence held
            // by the sister address does not authorize a delivery to this one.
            $session->forceFill([
                'ship_to_gln' => (string) $unlicensed->gln,
                'ship_to_site_id' => null,
            ])->save();

            $blockers = $validate->handle($session->fresh());

            $this->assertNotEmpty(array_filter(
                $blockers,
                fn (string $blocker): bool => str_contains($blocker, 'ATP license for TX'),
            ));
            $this->assertEmpty(array_filter(
                $blockers,
                fn (string $blocker): bool => str_contains($blocker, 'checked'),
            ));

            // Addressed to the licensed dock by GLN alone, the same order sends.
            $session->forceFill([
                'ship_to_gln' => (string) $licensed->gln,
                'ship_to_site_id' => null,
            ])->save();

            $this->assertSame([], $this->atpBlockers($validate->handle($session->fresh())));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function the_outbound_atp_gate_enforces_when_its_config_key_is_absent_and_lifts_only_when_set_false(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, 'TX');

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Kill Switch Customer');
            $shipTo = $this->createCustomerSite($customer, '037017');

            $session = $this->readyToSendSession(
                $site,
                $customer,
                'ASN-SWITCH-001',
                'PO-SWITCH-001',
                $shipTo,
            );

            $validate = app(ValidateOutboundShippingSend::class);
            $priorEpcis = config('tracepharma.epcis');

            // A config file that never grew the key must not read as "gate disabled".
            $withoutKey = $priorEpcis;
            unset($withoutKey['enforce_atp_outbound_gate']);
            config(['tracepharma.epcis' => $withoutKey]);

            $this->assertNotEmpty(array_filter(
                $validate->handle($session->fresh()),
                fn (string $blocker): bool => str_contains($blocker, 'ATP license for TX'),
            ));

            // Only an explicit false lets an unlicensed destination through.
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);
            $this->assertSame([], $this->atpBlockers($validate->handle($session->fresh())));

            config(['tracepharma.epcis' => $priorEpcis]);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * The kill switch is a deliberate operational choice, but the document it lets out is
     * the evidence a buyer and an inspector read later. Without a mark on the shipment
     * itself, nothing on record says the destination's ATP licence went unverified.
     */
    #[Test]
    public function a_send_made_while_the_atp_gate_is_down_says_so_on_the_authored_document(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->setTenantReceivingState($tenant, 'TX');

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Bypass Stamp Customer');
            $shipTo = $this->createCustomerSite($customer, '037019');

            $session = $this->readyToSendSession(
                $site,
                $customer,
                'ASN-BYPASS-001',
                'PO-BYPASS-001',
                $shipTo,
            );

            $priorEpcis = config('tracepharma.epcis');

            try {
                config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

                $this->assertTrue(AtpGateBypass::isBypassed());
                $this->assertSame([], $this->atpBlockers(app(ValidateOutboundShippingSend::class)->handle($session->fresh())));

                $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
                $this->documentIds[] = (int) $document->getKey();

                $this->assertTrue(
                    AtpGateBypass::stampedOn($document),
                    'An unverified destination must be readable off the shipment: '.(string) $document->notes,
                );
                $this->assertStringContainsString('was not verified', (string) $document->notes);
            } finally {
                config(['tracepharma.epcis' => $priorEpcis]);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * The counterpart: a shipment sent with the gate up carries no bypass note, so the
     * marker never reads as boilerplate.
     */
    #[Test]
    public function a_send_made_with_the_atp_gate_up_carries_no_bypass_note(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $priorEpcis = config('tracepharma.epcis');

            try {
                config(['tracepharma.epcis.enforce_atp_outbound_gate' => true]);

                $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
                $this->makeEpcShippableAtSite($site);

                $completed = $this->completeShipOrderFor($site);
                $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
                $this->documentIds[] = (int) $document->getKey();

                $this->assertFalse(AtpGateBypass::isBypassed());
                $this->assertFalse(AtpGateBypass::stampedOn($document));
            } finally {
                config(['tracepharma.epcis' => $priorEpcis]);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function kill_switch_blocked_transmit_shows_honest_ship_complete_state_not_sent_success(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config([
                'tracepharma.epcis_jobs.enabled' => false,
                'tracepharma.regulatory_compliance.password_gate' => false,
                'tracepharma.epcis.enforce_atp_outbound_gate' => false,
            ]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setTenantReceivingState($tenant, 'TX');

            $liveTenant = tenant() instanceof Tenant ? tenant() : $tenant;
            TenantSettings::forTenant($liveTenant)->setKillSwitch(TenantKillSwitches::OUTBOUND_EPCIS, true);
            $liveTenant->save();
            $liveTenant->refresh();
            $this->assertTrue(TenantKillSwitches::forTenant()->outboundEpcisKilled());

            $user = $this->createShippingUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $user->syncSites([(int) $site->getKey()], (int) $site->getKey());
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Kill Switch Ship Customer');
            $shipTo = $this->createCustomerSite($customer, '037020');

            $session = $this->readyToSendSession(
                $site,
                $customer,
                'ASN-KILL-SHIP-001',
                'PO-KILL-SHIP-001',
                $shipTo,
            );

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException when outbound EPCIS kill switch blocks transmission.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('outbound transmission did not succeed', $e->getMessage());
                $this->assertStringContainsString(
                    TenantKillSwitches::blockedMessage(TenantKillSwitches::OUTBOUND_EPCIS),
                    $e->getMessage(),
                );
            }

            $session = $session->fresh()->load('epcisDocument');
            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->shipping_events_generated_at);
            $this->assertNotNull($session->epcis_document_id);
            $this->assertSame('failed', $session->epcisDocument?->transmission_status);
            $this->documentIds[] = (int) $session->epcis_document_id;

            Livewire::test(ViewOutboundShippingSession::class, ['record' => $session->getKey()])
                ->assertDontSee('Shipment sent')
                ->assertSee('Shipment not transmitted')
                ->assertSee('Outbound EPCIS is disabled for this organization.');

            Livewire::test(MobileViewOutboundShippingSession::class, ['record' => $session->getKey()])
                ->assertDontSee('Shipment sent')
                ->assertSee('Shipment not transmitted')
                ->assertSee('Outbound EPCIS is disabled for this organization.');
        } finally {
            $liveTenant = tenant() instanceof Tenant ? tenant() : $tenant;
            TenantSettings::forTenant($liveTenant)->setKillSwitch(TenantKillSwitches::OUTBOUND_EPCIS, false);
            $liveTenant->save();
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function the_ship_page_warns_the_operator_while_the_outbound_atp_gate_is_down(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setTenantReceivingState($tenant, 'TX');

            $user = $this->createShippingUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant, self::CORRECTIVE_COMPANY_PREFIX);
            $user->syncSites([(int) $site->getKey()], (int) $site->getKey());
            $this->makeEpcShippableAtSite($site);

            $customer = $this->createCustomerPartner('Gate Notice Customer');
            $shipTo = $this->createCustomerSite($customer, '037018');

            $session = $this->readyToSendSession(
                $site,
                $customer,
                'ASN-NOTICE-001',
                'PO-NOTICE-001',
                $shipTo,
            );

            $priorEpcis = config('tracepharma.epcis');

            try {
                config(['tracepharma.epcis.enforce_atp_outbound_gate' => true]);

                $enforcing = Livewire::test(ViewOutboundShippingSession::class, ['record' => $session->getKey()]);
                $this->assertFalse($enforcing->instance()->atpOutboundGateDisabled());
                $enforcing->assertDontSee('ATP outbound gate is disabled');

                config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

                $disabled = Livewire::test(ViewOutboundShippingSession::class, ['record' => $session->getKey()]);
                $this->assertTrue($disabled->instance()->atpOutboundGateDisabled());

                // The banner stands on the order itself, not only under the Send step, so an
                // operator who never opens step 3 still sees that ATP is not being checked.
                $disabled
                    ->assertSee('ATP outbound gate is disabled')
                    ->set('wizardStep', 3)
                    ->assertSee('this send will not verify the destination');
            } finally {
                config(['tracepharma.epcis' => $priorEpcis]);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * The blockers the ATP gate owns. Asserted on instead of the whole list so a shared
     * fixture problem elsewhere reads as its own failure rather than as an ATP verdict.
     *
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function atpBlockers(array $blockers): array
    {
        return array_values(array_filter(
            $blockers,
            fn (string $blocker): bool => str_contains($blocker, 'ATP license')
                || str_contains($blocker, 'receiving state')
                || str_contains($blocker, 'does not match any active site'),
        ));
    }

    /**
     * A session with everything but ATP satisfied: one confirmed unit, refs, affirmation.
     */
    /**
     * A prior request that scanned before failing leaves `confirmed_count` > 0;
     * recovery must land back on `in_progress`, not `open`, so the wizard state
     * matches what actually happened on the order.
     */
    private function priorActiveStatus(OutboundShippingSession $session): string
    {
        return (int) $session->confirmed_count > 0 ? 'in_progress' : 'open';
    }

    private function readyToSendSession(
        Site $site,
        TradingPartner $customer,
        string $asnNumber,
        string $customerPo,
        ?Site $shipTo = null,
    ): OutboundShippingSession {
        $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
        $this->sessionIds[] = (int) $session->getKey();

        $confirmed = app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
        $this->assertTrue(
            $confirmed['ok'],
            'Fixture SSCC was not confirmable: '.$confirmed['message'],
        );

        app(UpdateOutboundShippingParty::class)->handle($session->fresh(), array_filter([
            'trading_partner_id' => (int) $customer->getKey(),
            'ship_to_site_id' => $shipTo !== null ? (int) $shipTo->getKey() : null,
            'ship_to_gln' => $shipTo !== null ? (string) $shipTo->gln : null,
        ], fn (mixed $value): bool => $value !== null));

        app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
            'asn_number' => $asnNumber,
            'customer_po' => $customerPo,
            'dscsa_affirm' => true,
        ]);

        return $session->fresh();
    }

    private function createCustomerPartner(string $name): TradingPartner
    {
        $gln = $this->uniquePartnerGln('037100');

        $partner = TradingPartner::query()->create([
            'name' => $name,
            'gln' => $gln,
            // Their SGLN, split on their own six-digit company prefix.
            'sgln' => Sgln::toUrn($gln, 6),
            'partner_type' => PartnerType::Pharmacy,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function createCustomerSite(TradingPartner $partner, string $companyPrefix): Site
    {
        $gln = $this->uniqueOrgGln($companyPrefix);

        $site = Site::query()->create([
            'trading_partner_id' => (int) $partner->getKey(),
            'name' => 'Ship-To '.Str::random(6),
            'gln' => $gln,
            'sgln' => Sgln::toUrn($gln, strlen($companyPrefix)),
            'street_address' => Str::random(8).' Market St',
            'city' => 'Austin',
            'state' => 'TX',
            'zipcode' => '73301',
            'country_code' => 'US',
            'is_active' => true,
            'is_organization_facility' => false,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return $site;
    }

    private function setTenantReceivingState(Tenant $tenant, ?string $state): void
    {
        // Write through the live tenancy instance so the organization settings saved on
        // the same row are not overwritten from a stale snapshot.
        $target = tenant() instanceof Tenant ? tenant() : $tenant;

        if (! $this->receivingStateCaptured) {
            $this->priorReceivingState = $target->receiving_state !== null
                ? (string) $target->receiving_state
                : null;
            $this->receivingStateCaptured = true;
        }

        $target->receiving_state = $state;
        $target->save();

        // TenantReceivingState reads the live tenancy instance, so re-enter tenancy.
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        tenancy()->initialize($target->fresh());
    }

    private function uniquePartnerGln(string $companyPrefix): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $body12 = $companyPrefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $gln = $body12.$this->gs1CheckDigit($body12);

            if (! TradingPartner::query()->where('gln', $gln)->exists()) {
                return $gln;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique partner GLN for the test.');
    }

    private function createShippingUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

        $user = User::factory()->create([
            'email' => 'ship-session-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function initializeWholesalerTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        if (! $this->receivingStateCaptured) {
            $this->setTenantReceivingState($tenant, 'TX');
        }

        return $tenant;
    }

    private function createShipSite(Tenant $tenant, string $companyPrefix = '036615'): Site
    {
        // Prefer the live tenancy instance so authored actions see updated org GLN/name.
        $liveTenant = tenant() instanceof Tenant ? tenant() : $tenant;
        $settings = TenantSettings::forTenant($liveTenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }
        if ($this->priorDefaultReceiveSiteId === null) {
            $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        }

        $siteGln = $this->uniqueOrgGln($companyPrefix);

        $site = Site::query()->create([
            'name' => 'Ship Site '.Str::random(6),
            'gln' => $siteGln,
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings->saveOrganization([
            'gln' => $siteGln,
            'company_prefix' => $companyPrefix,
            'default_ship_from_site_id' => (int) $site->getKey(),
            'default_receive_site_id' => (int) $site->getKey(),
        ]);

        return $site;
    }

    /**
     * Ingest + receive SSCC at site so it becomes shippable. Returns receiving session id.
     */
    private function makeEpcShippableAtSite(Site $site, bool $completed = true): int
    {
        if (auth()->user() === null) {
            $this->actingAs($this->createShippingUser());
        }

        $this->prepareFixtureReceivingState();

        $document = $this->ingestMinimalFixture();
        $this->documentIds[] = (int) $document->getKey();
        $this->assertSame('validated', $document->status, (string) $document->error_message);

        $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
        $this->receivingSessionIds[] = (int) $session->getKey();
        $session->forceFill(['site_id' => (int) $site->getKey()])->save();

        app(ConfirmReceivingScan::class)->handle(
            $session->fresh(),
            self::SSCC_URI,
            userId: null,
            autoConfirmChildren: true,
        );

        $session = $session->fresh();
        if ($completed) {
            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'receiving_events_generated_at' => $session->receiving_events_generated_at ?? now(),
            ])->save();
        } else {
            $session->forceFill([
                'status' => 'in_progress',
                'completed_at' => null,
            ])->save();
        }

        if ($session->receiving_epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->receiving_epcis_document_id;
        }

        return (int) $session->getKey();
    }

    /**
     * The customer as a real one arrives: a check-digit-valid GLN, and the SGLN they
     * publish in their own EPCIS. Shipments name them by that SGLN — there is nothing
     * in a GLN that says where their company prefix ends, so it has to come from them.
     */
    private function ensureDemoPartner(): TradingPartner
    {
        // Retire the legacy seed row (…0003, invalid check digit, no SGLN) so a
        // name-based lookup elsewhere cannot author against a partner we refuse.
        TradingPartner::query()
            ->where('gln', '0614141000003')
            ->update([
                'is_active' => false,
                'name' => '[LEGACY] Demo Downstream Pharmacy',
            ]);

        $partner = TradingPartner::query()->updateOrCreate(
            ['gln' => self::DEMO_PARTNER_GLN],
            [
                'name' => 'Demo Downstream Pharmacy',
                'sgln' => self::DEMO_PARTNER_SGLN,
                'partner_type' => PartnerType::Pharmacy,
                'is_active' => true,
            ],
        );

        $this->ensureDemoPartnerHasShipToSite($partner);

        return $partner;
    }

    private function ensureDemoPartnerHasShipToSite(TradingPartner $partner): Site
    {
        $existing = Site::query()
            ->where('trading_partner_id', (int) $partner->getKey())
            ->where('is_active', true)
            ->where('is_organization_facility', false)
            ->first();

        $site = $existing instanceof Site
            ? $existing
            : $this->createCustomerSite($partner, '061414');

        if (! AtpLicense::query()
            ->where('site_id', (int) $site->getKey())
            ->where('license_state', 'TX')
            ->exists()) {
            $license = AtpLicense::query()->create([
                'site_id' => (int) $site->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'DEMO-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();
        }

        return $site;
    }

    /**
     * Remove leftover receiving sessions for fixture EPCs so shared demo2 state
     * does not pollute the next test run.
     *
     * @param  list<string>  $epcUris
     */
    private function prepareFixtureReceivingState(array $epcUris = [self::SSCC_URI, self::SGTIN_URI]): void
    {
        $epcIds = Epc::query()
            ->whereIn('epc_uri', $epcUris)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($epcIds === []) {
            return;
        }

        $ssccId = Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');
        if ($ssccId !== null) {
            AggregationLink::query()
                ->where('child_epc_id', (int) $ssccId)
                ->whereNull('valid_to')
                ->update(['valid_to' => now()]);
        }

        foreach ($epcIds as $epcId) {
            QuarantineHold::query()->where('epc_id', $epcId)->delete();
        }

        OutboundShippingScanLine::query()->whereIn('epc_id', $epcIds)->delete();
        TransferringScanLine::query()->whereIn('epc_id', $epcIds)->delete();

        $sessionIds = ReceivingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->distinct()
            ->pluck('receiving_session_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($sessionIds as $sessionId) {
            $session = ReceivingSession::query()->find($sessionId);
            if ($session === null) {
                continue;
            }

            if ($session->receiving_epcis_document_id !== null) {
                EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
            }

            ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
            $session->delete();
        }
    }

    #[Test]
    public function desktop_blade_has_live_blur_and_enter_stage_scan_binding(): void
    {
        $blade = File::get(resource_path(
            'views/filament/app/resources/outbound-shipping-sessions/pages/view-outbound-shipping-session.blade.php',
        ));

        $this->assertStringContainsString('wire:model.live.blur="scan"', $blade);
        $this->assertStringContainsString('keydown.enter.prevent="$wire.stageScan($refs.scanInput.value)"', $blade);
        $this->assertStringContainsString('wire:submit.prevent="stageScan"', $blade);
        $this->assertStringNotContainsString('wire:model="scan"', $blade);
        $this->assertStringNotContainsString("mountAction('confirmScan')", $blade);
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => basename($fixture),
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function uniqueOrgGln(string $companyPrefix): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $body12 = $companyPrefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $gln = $body12.$this->gs1CheckDigit($body12);

            if (! Site::query()->where('gln', $gln)->exists()) {
                return $gln;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique site GLN for the test.');
    }

    private function gs1CheckDigit(string $bodyWithoutCheck): string
    {
        $sum = 0;
        $digits = str_split(strrev($bodyWithoutCheck));

        foreach ($digits as $index => $digit) {
            $sum += ((int) $digit) * ($index % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionIds !== []) {
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
            }

            if ($this->transferSessionIds !== []) {
                TransferringSession::query()
                    ->whereIn('id', $this->transferSessionIds)
                    ->delete();
            }

            if ($this->receivingSessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $this->receivingSessionIds)->delete();
            }

            if ($this->ssccLabelIds !== []) {
                SsccLabel::query()->whereIn('id', $this->ssccLabelIds)->delete();
            }

            if ($this->documentIds !== []) {
                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')
                        ->whereIn('document_id', $this->documentIds)
                        ->delete();
                }

                if (Schema::hasTable('epcis_events')) {
                    $eventIds = DB::table('epcis_events')
                        ->whereIn('document_id', $this->documentIds)
                        ->pluck('id')
                        ->all();

                    if ($eventIds !== [] && Schema::hasTable('event_epcs')) {
                        DB::table('event_epcs')
                            ->whereIn('event_id', $eventIds)
                            ->delete();
                    }

                    DB::table('epcis_events')
                        ->whereIn('document_id', $this->documentIds)
                        ->delete();
                }

                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }

            if ($this->epcIds !== []) {
                AggregationLink::query()
                    ->where(function ($query): void {
                        $query->whereIn('parent_epc_id', $this->epcIds)
                            ->orWhereIn('child_epc_id', $this->epcIds);
                    })
                    ->delete();
                if (Schema::hasTable('epc_ilmd')) {
                    DB::table('epc_ilmd')->whereIn('epc_id', $this->epcIds)->delete();
                }
                Epc::query()->whereIn('id', $this->epcIds)->delete();
            }

            if ($this->connectionIds !== []) {
                OutboundConnection::query()->whereIn('id', $this->connectionIds)->delete();
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            if ($this->atpLicenseIds !== []) {
                AtpLicense::query()->whereIn('id', $this->atpLicenseIds)->delete();
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }

            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            }

            if ($this->priorDefaultShipFromSiteId !== null) {
                TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
                $tenant->save();
            }

            if ($this->priorDefaultReceiveSiteId !== null) {
                TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
                $tenant->save();
            }

            tenancy()->end();
        }

        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
        }

        if ($this->receivingStateCaptured) {
            $current = Tenant::query()->find($tenant->getKey());
            $current?->forceFill(['receiving_state' => $this->priorReceivingState])->save();
        }

        $this->siteIds = [];
        $this->sessionIds = [];
        $this->receivingSessionIds = [];
        $this->documentIds = [];
        $this->connectionIds = [];
        $this->userIds = [];
        $this->transferSessionIds = [];
        $this->ssccLabelIds = [];
        $this->epcIds = [];
        $this->partnerIds = [];
        $this->atpLicenseIds = [];
        $this->priorDefaultShipFromSiteId = null;
        $this->priorDefaultReceiveSiteId = null;
        $this->priorReceivingState = null;
        $this->receivingStateCaptured = false;
    }
}
