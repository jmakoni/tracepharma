<?php

namespace App\Support;

use App\Enums\PartnerType;
use App\Enums\SiteAtpReadinessStatus;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\OrganizationSettings;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\MasterData\SiteAtpReadiness;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Profile-aware go-live readiness checklist.
 *
 * DrugWholesaler gets the full distributor matrix; Pharmacy uses a thinner
 * receive-oriented list (outbound/downstream steps omitted).
 */
class TenantOnboarding
{
    /**
     * Readiness states that evidence a licence in force for the receiving state.
     *
     * @var list<SiteAtpReadinessStatus>
     */
    private const ATP_READY_STATUSES = [
        SiteAtpReadinessStatus::Ready,
        SiteAtpReadinessStatus::Expiring,
        SiteAtpReadinessStatus::FdaRegistered,
    ];

    public function __construct(
        protected ?Tenant $tenant,
        protected TenantSettings $settings,
        protected TenantFeatures $features,
    ) {}

    public static function forTenant(?Tenant $tenant): self
    {
        return new self(
            $tenant,
            TenantSettings::forTenant($tenant),
            TenantFeatures::forTenant($tenant),
        );
    }

    /**
     * @return list<array{id: string, label: string, done: bool, href?: string}>
     */
    public function items(): array
    {
        return match ($this->features->profile()) {
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => $this->wholesalerItems(),
            default => $this->pharmacyItems(),
        };
    }

    public function score(): int
    {
        $items = $this->items();

        if ($items === []) {
            return 0;
        }

        $done = count(array_filter($items, fn (array $item): bool => $item['done']));

        return (int) round(($done / count($items)) * 100);
    }

    /**
     * Percent of critical go-live checks complete (org GLN + default site GLNs).
     * Independent of recommended checklist items (partners, inbound, outbound, etc.).
     */
    public function criticalScore(): int
    {
        $checks = $this->criticalChecks();

        if ($checks === []) {
            return 0;
        }

        $done = count(array_filter($checks));

        return (int) round(($done / count($checks)) * 100);
    }

    public function isComplete(): bool
    {
        return $this->isCriticalComplete();
    }

    public function isCriticalComplete(): bool
    {
        return ! in_array(false, $this->criticalChecks(), true);
    }

    /**
     * Whether go-live may proceed on the ATP evidence for inbound product.
     *
     * Profiles that take possession of product from someone else have to show at least
     * one upstream partner facility licensed for the receiving state before they mark
     * setup complete; a manufacturer shipping its own product, and a buying group that
     * never takes possession, have no upstream to evidence.
     */
    public function isUpstreamAtpSatisfied(): bool
    {
        return ! $this->requiresUpstreamAtp() || $this->hasUpstreamPartnerAtpReady();
    }

    public function requiresUpstreamAtp(): bool
    {
        return match ($this->features->profile()) {
            TenantProfile::Pharmacy,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    /**
     * @return list<bool>
     */
    private function criticalChecks(): array
    {
        $checks = [
            filled($this->settings->gln()),
            $this->siteHasGln($this->settings->defaultReceiveSite()),
        ];

        if ($this->requiresShipFromSite()) {
            $checks[] = $this->siteHasGln($this->settings->defaultShipFromSite());
        }

        return $checks;
    }

    /**
     * @return list<array{id: string, label: string, done: bool, href?: string}>
     */
    private function wholesalerItems(): array
    {
        $items = [
            $this->item('org_gln', 'Company GLN', filled($this->settings->gln()), $this->organizationHref()),
            $this->item('receiving_state', 'Receiving / ATP evaluation state', filled($this->settings->receivingState()), $this->organizationHref()),
            $this->item(
                'default_receive_site',
                'Default receive site with GLN',
                $this->siteHasGln($this->settings->defaultReceiveSite()),
                $this->sitesHref(),
            ),
            $this->item(
                'default_ship_from_site',
                'Default ship-from site with GLN',
                $this->siteHasGln($this->settings->defaultShipFromSite()),
                $this->sitesHref(),
            ),
            $this->item(
                'atp_ready',
                'Upstream partner ATP ready for receiving state',
                $this->hasUpstreamPartnerAtpReady(),
                $this->partnersHref(),
            ),
            $this->item(
                'upstream_partner',
                'Upstream partner with GLN',
                $this->hasPartnerWithGln([PartnerType::Manufacturer, PartnerType::Wholesaler]),
                $this->partnersHref(),
            ),
            $this->item(
                'downstream_partner',
                'Downstream partner with GLN',
                $this->hasPartnerWithGln([PartnerType::Pharmacy]),
                $this->partnersHref(),
            ),
            $this->item(
                'inbound_path',
                'Inbound path (connection or validated EPCIS)',
                $this->hasInboundPath(),
                $this->inboundHref(),
            ),
            $this->item(
                'receive_proven',
                'Receiving proven (completed session with site)',
                $this->hasReceiveProven(),
                $this->receivingSessionsHref(),
            ),
        ];

        if ($this->features->supportsOutboundIntegrations()) {
            $items[] = $this->item(
                'outbound_configured',
                'Outbound integration configured',
                $this->hasOutboundConfigured(),
                $this->outboundHref(),
            );
            $items[] = $this->item(
                'ship_proven',
                'Shipping proven (completed Ship Order)',
                $this->hasShipProven(),
                $this->outboundShippingSessionsHref(),
            );
        }

        return $items;
    }

    /**
     * Thinner receive-oriented checklist for pharmacy and similar profiles.
     *
     * @return list<array{id: string, label: string, done: bool, href?: string}>
     */
    private function pharmacyItems(): array
    {
        $items = [
            $this->item('org_gln', 'Company GLN', filled($this->settings->gln()), $this->organizationHref()),
            $this->item('receiving_state', 'Receiving / ATP evaluation state', filled($this->settings->receivingState()), $this->organizationHref()),
            $this->item(
                'default_receive_site',
                'Default receive site with GLN',
                $this->siteHasGln($this->settings->defaultReceiveSite()),
                $this->sitesHref(),
            ),
        ];

        // Manufacturers and buying groups share this checklist without receiving product
        // from anyone, so an upstream ATP row would never be satisfiable for them.
        if ($this->requiresUpstreamAtp()) {
            $items[] = $this->item(
                'atp_ready',
                'Upstream partner ATP ready for receiving state',
                $this->hasUpstreamPartnerAtpReady(),
                $this->partnersHref(),
            );
        }

        return [
            ...$items,
            $this->item(
                'upstream_partner',
                'Upstream partner with GLN',
                $this->hasPartnerWithGln([PartnerType::Manufacturer, PartnerType::Wholesaler]),
                $this->partnersHref(),
            ),
            $this->item(
                'inbound_path',
                'Inbound path (connection or validated EPCIS)',
                $this->hasInboundPath(),
                $this->inboundHref(),
            ),
            $this->item(
                'receive_proven',
                'Receiving proven (completed session with site)',
                $this->hasReceiveProven(),
                $this->receivingSessionsHref(),
            ),
        ];
    }

    /**
     * @return array{id: string, label: string, done: bool, href?: string}
     */
    private function item(string $id, string $label, bool $done, ?string $href): array
    {
        $item = [
            'id' => $id,
            'label' => $label,
            'done' => $done,
        ];

        if ($href !== null) {
            $item['href'] = $href;
        }

        return $item;
    }

    private function requiresShipFromSite(): bool
    {
        return match ($this->features->profile()) {
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply,
            TenantProfile::Manufacturer => true,
            default => false,
        };
    }

    private function siteHasGln(?Site $site): bool
    {
        return $site !== null && filled($site->gln);
    }

    /**
     * Whether any upstream partner facility we could receive from is licensed for the
     * receiving state.
     *
     * Our own dock is deliberately not scored: DSCSA asks us to establish that the party
     * shipping to us is authorized, and our own licence says nothing about theirs. Only
     * partner-owned sites are candidates, which by construction excludes our facilities —
     * an organization facility never carries a trading_partner_id ({@see Site::saved}).
     *
     * Expiring counts: a licence with weeks left still authorizes today's delivery, and
     * the expiry dashboards chase it. Everything else — expired, missing, or with no
     * expiration date on file — cannot be shown to be in force.
     */
    private function hasUpstreamPartnerAtpReady(): bool
    {
        if (! $this->tenantDatabaseAvailable()) {
            return false;
        }

        foreach (self::ATP_READY_STATUSES as $status) {
            try {
                $ready = SiteAtpReadiness::applyStatusFilter($this->upstreamPartnerSites(), $status)->exists();
            } catch (Throwable) {
                return false;
            }

            if ($ready) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Builder<Site>
     */
    private function upstreamPartnerSites(): Builder
    {
        return Site::query()
            ->where('sites.is_active', true)
            ->whereHas('tradingPartner', fn (Builder $partners): Builder => $partners
                ->where('is_active', true)
                ->whereIn('partner_type', [
                    PartnerType::Manufacturer->value,
                    PartnerType::Wholesaler->value,
                ]));
    }

    /**
     * @param  list<PartnerType>  $types
     */
    private function hasPartnerWithGln(array $types): bool
    {
        if (! $this->tenantDatabaseAvailable()) {
            return false;
        }

        return TradingPartner::query()
            ->where('is_active', true)
            ->whereIn('partner_type', array_map(fn (PartnerType $type): string => $type->value, $types))
            ->whereNotNull('gln')
            ->where('gln', '!=', '')
            ->exists();
    }

    private function hasInboundPath(): bool
    {
        if (! $this->tenantDatabaseAvailable()) {
            return false;
        }

        if (InboundConnection::query()->where('is_active', true)->exists()) {
            return true;
        }

        return EpcisDocument::query()
            ->whereIn('status', ['parsed', 'validated'])
            ->exists();
    }

    /**
     * Done when outbound choreography is deferred, or an active outbound connection exists.
     */
    private function hasOutboundConfigured(): bool
    {
        if ($this->settings->outboundChoreographyDeferredAt() !== null) {
            return true;
        }

        return $this->hasOutboundConnection();
    }

    private function hasOutboundConnection(): bool
    {
        if (! $this->tenantDatabaseAvailable()) {
            return false;
        }

        return OutboundConnection::query()->where('is_active', true)->exists();
    }

    private function hasReceiveProven(): bool
    {
        if (! $this->tenantDatabaseAvailable()) {
            return false;
        }

        return ReceivingSession::query()
            ->where('status', 'completed')
            ->whereNotNull('site_id')
            ->exists();
    }

    private function hasShipProven(): bool
    {
        if (! $this->tenantDatabaseAvailable()) {
            return false;
        }

        return OutboundShippingSession::query()
            ->where('status', 'completed')
            ->whereNotNull('epcis_document_id')
            ->exists();
    }

    private function tenantDatabaseAvailable(): bool
    {
        return tenancy()->initialized;
    }

    private function organizationHref(): ?string
    {
        try {
            $page = OrganizationSettings::class;

            if (! $page::canAccess()) {
                return $this->sitesHref();
            }

            return $page::getUrl(panel: 'app');
        } catch (Throwable) {
            return $this->sitesHref();
        }
    }

    private function sitesHref(): ?string
    {
        return $this->resourceIndexUrl(SiteResource::class);
    }

    public function partnersHref(): ?string
    {
        return $this->resourceIndexUrl(TradingPartnerResource::class);
    }

    private function inboundHref(): ?string
    {
        return $this->resourceIndexUrl(InboundConnectionResource::class);
    }

    private function outboundHref(): ?string
    {
        return $this->resourceIndexUrl(OutboundConnectionResource::class);
    }

    private function receivingSessionsHref(): ?string
    {
        return $this->resourceIndexUrl(ReceivingSessionResource::class);
    }

    private function outboundShippingSessionsHref(): ?string
    {
        return $this->resourceIndexUrl(OutboundShippingSessionResource::class);
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function resourceIndexUrl(string $resource): ?string
    {
        try {
            $panel = Filament::getPanel('app');
            $name = $resource::getRouteBaseName($panel).'.index';

            if (! Route::has($name)) {
                return null;
            }

            return $resource::getUrl('index', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
