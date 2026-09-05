<?php

namespace App\Actions\Shipping;

use App\Enums\ExceptionStatus;
use App\Enums\SiteAtpReadinessStatus;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Services\Exceptions\ExceptionService;
use App\Support\Gs1\Sgln;
use App\Support\MasterData\AtpDisclosure;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\MasterData\AtpReadinessGate;
use App\Support\MasterData\SiteAtpReadiness;
use App\Support\Shipping\AssertOutermostSsccHasChildren;
use App\Support\Shipping\AtpGateBypass;
use App\Support\Shipping\DetectOpenParentHierarchyOnShip;
use App\Support\Shipping\ResolveOutboundShipToSgln;
use App\Support\Shipping\SsccShipCompletenessException;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Return human-readable blockers before sending a ship order.
 *
 * @return list<string>
 */
final class ValidateOutboundShippingSend
{
    public function __construct(
        private readonly DetectOpenParentHierarchyOnShip $openParentHierarchyOnShip,
        private readonly AssertOutermostSsccHasChildren $assertOutermostSsccHasChildren,
        private readonly ResolveOutboundShipToSgln $resolveOutboundShipToSgln,
        private readonly ExceptionService $exceptionService,
    ) {}

    public function handle(OutboundShippingSession $session): array
    {
        $session->loadMissing(['tradingPartner', 'shipToSite', 'site']);
        $blockers = [];

        if ($session->trading_partner_id === null) {
            $blockers[] = 'Select a customer (trading partner).';
        }

        if ($session->trading_partner_id !== null) {
            $destination = $this->destinationCandidates($session);

            if ($destination['unresolved_gln'] === null && $destination['sites']->isEmpty()) {
                $blockers[] = sprintf(
                    'Customer "%s" has no destination sites on record — add a ship-to site before sending.',
                    $session->tradingPartner?->name ?? 'selected customer',
                );
            }
        }

        if (! $this->hasShipTo($session)) {
            $blockers[] = 'Provide a ship-to GLN or partner site.';
        } elseif (($destSglnBlocker = $this->destSglnBlocker($session)) !== null) {
            $blockers[] = $destSglnBlocker;
        }

        if (blank($session->asn_number)) {
            $blockers[] = 'ASN number is required.';
        }

        if (blank($session->customer_po) && blank($session->invoice_number)) {
            $blockers[] = 'Customer PO or invoice number is required.';
        }

        if (! $session->dscsa_affirm) {
            $blockers[] = 'TI/TS affirmation is required.';
        }

        if (! $this->hasConfirmedLines($session)) {
            $blockers[] = 'Confirm at least one unit before sending.';
        }

        foreach ($this->quantityBlockers($session) as $quantityBlocker) {
            $blockers[] = $quantityBlocker;
        }

        if (($openParentBlocker = $this->openParentHierarchyOnShip->blockerMessage($session)) !== null) {
            $blockers[] = $openParentBlocker;
        }

        foreach ($this->emptyPlateBlockers($session) as $emptyPlateBlocker) {
            $blockers[] = $emptyPlateBlocker;
        }

        if (($atpIssue = $this->atpIssue($session)) !== null
            && TenantSettings::forTenant(tenant())->blockSendOnAtpGap()) {
            $blockers[] = $atpIssue;
        }

        return $blockers;
    }

    /**
     * Soft ATP gaps when {@see TenantSettings::blockSendOnAtpGap()} is off.
     *
     * @return list<string>
     */
    public function warnings(OutboundShippingSession $session): array
    {
        if (TenantSettings::forTenant(tenant())->blockSendOnAtpGap()) {
            return [];
        }

        $session->loadMissing(['tradingPartner', 'shipToSite', 'site']);
        $issue = $this->atpIssue($session);

        return $issue !== null ? [$issue] : [];
    }

    /**
     * ATP destination-license issue text, or null when the gate is quiet / bypassed.
     */
    public function atpIssue(OutboundShippingSession $session): ?string
    {
        $session->loadMissing(['tradingPartner', 'shipToSite', 'site']);

        return $this->atpBlocker($session);
    }

    /**
     * @return list<string>
     */
    private function emptyPlateBlockers(OutboundShippingSession $session): array
    {
        $epcIds = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->pluck('epc_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($epcIds === []) {
            return [];
        }

        $blockers = [];

        foreach (Epc::query()->whereIn('id', $epcIds)->get() as $epc) {
            try {
                $this->assertOutermostSsccHasChildren->handle($epc);
            } catch (SsccShipCompletenessException $exception) {
                $blockers[] = $exception->getMessage();
                $this->openHierarchyException($session, $exception);
            } catch (InvalidArgumentException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        return $blockers;
    }

    /**
     * When expected_count > 0: overscan always blocks; under-scan requires split_declared.
     * Live-ladder connections (first_live_lot / hypercare / live) cannot skip with expected_count = 0.
     *
     * @return list<string>
     */
    private function quantityBlockers(OutboundShippingSession $session): array
    {
        $expected = (int) $session->expected_count;
        $confirmed = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->count();

        if ($expected <= 0) {
            $session->loadMissing('outboundConnection');
            $connection = $session->outboundConnection;

            if ($connection !== null && $connection->conformanceState()->requiresExpectedQuantity()) {
                if ((bool) $session->quantity_gate_overridden) {
                    return [];
                }

                $message = 'Expected unit count is required for live outbound connections. '
                    .'Set expected count from the ASN/order/WMS or on the ship order before sending.';
                $this->openQuantityMismatchException($session, $message, $expected, $confirmed);

                return [$message];
            }

            return [];
        }

        if ($confirmed > $expected) {
            return [
                sprintf(
                    'Confirmed count (%d) exceeds expected units (%d). Remove extra scans before sending.',
                    $confirmed,
                    $expected,
                ),
            ];
        }

        if ($confirmed < $expected && ! (bool) $session->split_declared) {
            $message = sprintf(
                'Confirmed count (%d) is below expected units (%d). Confirm the remaining units or declare a split/partial shipment.',
                $confirmed,
                $expected,
            );
            $this->openQuantityMismatchException($session, $message, $expected, $confirmed);

            return [$message];
        }

        return [];
    }

    private function openQuantityMismatchException(
        OutboundShippingSession $session,
        string $message,
        int $expected,
        int $confirmed,
    ): void {
        $type = ExceptionType::query()->where('code', 'QUANTITY_MISMATCH')->first();

        if ($type === null) {
            (new ExceptionTypeSeeder)->run();
            $type = ExceptionType::query()->where('code', 'QUANTITY_MISMATCH')->first();
        }

        if ($type === null) {
            return;
        }

        $fingerprint = 'ship-order-#'.$session->getKey().'-qty';

        $alreadyOpen = ExceptionCase::query()
            ->where('exception_type_id', $type->getKey())
            ->whereNotIn('status', [
                ExceptionStatus::Resolved->value,
                ExceptionStatus::Closed->value,
                ExceptionStatus::Cancelled->value,
            ])
            ->where('description', 'like', '%'.$fingerprint.'%')
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        $epcIds = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->pluck('epc_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->exceptionService->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => null,
            'site_id' => $session->site_id,
            'trading_partner_id' => $session->trading_partner_id,
            'title' => $type->name,
            'description' => $message.' ['.$fingerprint.'; expected='.$expected.'; confirmed='.$confirmed.']',
            'status' => ExceptionStatus::New->value,
        ], $epcIds);
    }

    private function openHierarchyException(
        OutboundShippingSession $session,
        SsccShipCompletenessException $exception,
    ): void {
        $type = ExceptionType::query()->where('code', $exception->exceptionTypeCode)->first();

        if ($type === null) {
            (new ExceptionTypeSeeder)->run();
            $type = ExceptionType::query()->where('code', $exception->exceptionTypeCode)->first();
        }

        if ($type === null) {
            return;
        }

        $alreadyOpen = ExceptionCase::query()
            ->where('exception_type_id', $type->getKey())
            ->whereNotIn('status', [
                ExceptionStatus::Resolved->value,
                ExceptionStatus::Closed->value,
                ExceptionStatus::Cancelled->value,
            ])
            ->whereHas('epcs', fn ($query) => $query->where('epcs.id', $exception->parentEpcId))
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        $this->exceptionService->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => null,
            'site_id' => $session->site_id,
            'trading_partner_id' => $session->trading_partner_id,
            'title' => $type->name,
            'description' => $exception->getMessage(),
            'status' => ExceptionStatus::New->value,
        ], $exception->epcIdsForCase());
    }

    private function hasConfirmedLines(OutboundShippingSession $session): bool
    {
        return OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->exists();
    }

    private function destSglnBlocker(OutboundShippingSession $session): ?string
    {
        $party = $this->resolveOutboundShipToSgln->destParty($session);
        if ($party['gln'] === null) {
            return null;
        }

        if ($this->resolveOutboundShipToSgln->resolve($session) !== null) {
            return null;
        }

        return sprintf(
            'Record the customer\'s SGLN on the trading partner or ship-to site for GLN %s. A partner\'s GS1 company prefix is theirs to state, not ours to guess.',
            $party['gln'],
        );
    }

    private function hasShipTo(OutboundShippingSession $session): bool
    {
        if (filled($session->ship_to_gln) && Sgln::normalizeGln((string) $session->ship_to_gln) !== null) {
            return true;
        }

        if ($session->ship_to_site_id !== null && filled($session->shipToSite?->gln)) {
            return true;
        }

        if (filled($session->tradingPartner?->gln)) {
            return true;
        }

        return false;
    }

    /**
     * Inbound only soft-warns on partner ATP. Outbound defaults to a hard send block when
     * the destination license is missing/expired; tenants may switch to soft warning via
     * {@see TenantSettings::blockSendOnAtpGap()} (false).
     *
     * Silent only when there is nothing to judge at all — no customer, and no site on
     * record for the one that is selected.
     *
     * The ingest soft warning judges a party by the same rule, through
     * {@see AtpReadinessGate::blocksParty()}.
     */
    private function atpBlocker(OutboundShippingSession $session): ?string
    {
        if (AtpGateBypass::isBypassed()) {
            return null;
        }

        $evaluationKeys = AtpLicenseRelevance::evaluationJurisdictionKeys();
        $jurisdictionLabel = AtpLicenseRelevance::evaluationJurisdictionsLabel();

        // Without org footprint or preferred receiving state, every partner reads as
        // NeedsReceivingState — say so rather than waving the shipment through.
        if ($evaluationKeys === []) {
            $tail = TenantSettings::forTenant(tenant())->blockSendOnAtpGap()
                ? 'Partner ATP licenses cannot be evaluated without jurisdictions.'
                : 'Partner ATP licenses cannot be evaluated without jurisdictions (soft warning).';

            return 'Add organization facility sites with country/state, or set a preferred receiving state in Organization settings, before sending — '.$tail;
        }

        $destination = $this->destinationCandidates($session);

        if ($destination['unresolved_gln'] !== null) {
            $tail = TenantSettings::forTenant(tenant())->blockSendOnAtpGap()
                ? 'Add the destination site before sending.'
                : 'Add the destination site when you can (soft warning — send is allowed).';

            return sprintf(
                'Ship-to GLN %s does not match any active site on record for %s, so its ATP license for %s cannot be checked. %s',
                $destination['unresolved_gln'],
                $session->tradingPartner?->name ?? 'the selected customer',
                $jurisdictionLabel,
                $tail,
            );
        }

        $sites = $destination['sites'];

        if ($sites->isEmpty()) {
            return null;
        }

        $unready = [];

        foreach ($sites as $site) {
            $status = SiteAtpReadiness::summarize($site)['status'];

            // One licensed destination is enough: the shipment can lawfully land there.
            if (! AtpReadinessGate::blocks($status)) {
                return null;
            }

            $unready[] = $status;
        }

        return $this->atpBlockerMessage($session, $sites, $unready, $jurisdictionLabel);
    }

    /**
     * The facilities the ATP gate judges, and the destination GLN that named none of them.
     *
     * A named ship-to site, or the site a destination GLN resolves to, is the only
     * candidate: a license held by another address of the same customer does not authorize
     * a delivery to this one. Only when the order names no destination at all does every
     * address the customer has on record stand in.
     *
     * ship_to_gln is checked before shipToSite, mirroring
     * {@see ResolveOutboundShipToSgln::destParty()}: it is
     * the destination that gets authored onto the shipping event, and it can name a
     * different address than the saved ship-to site (e.g. a specific dock/sub-location for
     * the same partner). Judging the site instead would pass ATP against a location the
     * shipment is not actually addressed to.
     *
     * @return array{sites: Collection<int, Site>, unresolved_gln: ?string}
     */
    private function destinationCandidates(OutboundShippingSession $session): array
    {
        $partnerId = $session->trading_partner_id !== null ? (int) $session->trading_partner_id : null;

        if (filled($session->ship_to_gln)) {
            $shipToGln = Sgln::normalizeGln($session->ship_to_gln);

            if ($shipToGln !== null) {
                $resolved = $partnerId !== null
                    ? AtpReadinessGate::siteForGln($partnerId, $shipToGln)
                    : null;

                return $resolved instanceof Site
                    ? ['sites' => collect([$resolved]), 'unresolved_gln' => null]
                    : ['sites' => collect(), 'unresolved_gln' => $shipToGln];
            }
        }

        if ($session->shipToSite instanceof Site) {
            return ['sites' => collect([$session->shipToSite]), 'unresolved_gln' => null];
        }

        if ($partnerId === null) {
            return ['sites' => collect(), 'unresolved_gln' => null];
        }

        return [
            'sites' => Site::query()
                ->where('trading_partner_id', $partnerId)
                ->where('is_active', true)
                ->get(),
            'unresolved_gln' => null,
        ];
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @param  list<SiteAtpReadinessStatus>  $unready
     */
    private function atpBlockerMessage(
        OutboundShippingSession $session,
        Collection $sites,
        array $unready,
        string $tenantState,
    ): string {
        if ($sites->count() === 1) {
            return sprintf(
                '%s %s',
                $this->singleSiteReason($sites->first(), $unready[0], $tenantState),
                AtpDisclosure::SHORT,
            );
        }

        $hard = TenantSettings::forTenant(tenant())->blockSendOnAtpGap();
        $tail = $hard
            ? 'Sending is blocked until one is.'
            : 'Sending is allowed with a soft warning until one is.';

        return sprintf(
            'Customer "%s" has no site with a valid ATP license on record for %s — checked %d site(s). %s %s',
            $session->tradingPartner?->name ?? 'selected customer',
            $tenantState,
            $sites->count(),
            $tail,
            AtpDisclosure::SHORT,
        );
    }

    private function singleSiteReason(Site $site, SiteAtpReadinessStatus $status, string $tenantState): string
    {
        $hard = TenantSettings::forTenant(tenant())->blockSendOnAtpGap();
        $tail = $hard
            ? 'Sending is blocked until a valid license is on record.'
            : 'Sending is allowed with a soft warning until a valid license is on record.';

        return match ($status) {
            SiteAtpReadinessStatus::Expired => sprintf(
                'Ship-to site "%s" has an expired ATP license for %s on record. %s',
                $site->name,
                $tenantState,
                $tail,
            ),
            SiteAtpReadinessStatus::UnknownExpiry => sprintf(
                'Ship-to site "%s" has an ATP license for %s with no expiration date on file, so it cannot be shown to be in force. %s',
                $site->name,
                $tenantState,
                $tail,
            ),
            SiteAtpReadinessStatus::NeedsReceivingState => sprintf(
                'Ship-to site "%s" cannot be ATP-evaluated — organization jurisdictions are not configured. %s',
                $site->name,
                $tail,
            ),
            default => sprintf(
                'Ship-to site "%s" has no ATP license for %s on record. %s',
                $site->name,
                $tenantState,
                $tail,
            ),
        };
    }
}
