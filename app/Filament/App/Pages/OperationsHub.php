<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\FdaProducts\FdaProductResource;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\HidesForPharmacySimplifiedNav;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\ReceiveLayout;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ReceivingSessionStatus;
use App\Support\Receiving\ResolveOpenReceiveUrl;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use DomainException;
use Filament\Facades\Filament;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Throwable;
use UnitEnum;

class OperationsHub extends Page implements HasKnowledgeBase
{
    use HidesForPharmacySimplifiedNav;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Operations Hub';

    protected static ?string $title = 'Operations Hub';

    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.operations-hub';

    public ?string $hubScan = '';

    public bool $hubShipScanFailed = false;

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->hasAnyOperations()
            && JobRoleAccess::allowsAny(
                Permissions::NavReceive,
                Permissions::NavShip,
                Permissions::NavExceptions,
                Permissions::NavVerify,
            );
    }

    /**
     * Active receive sessions for the topbar-selected site (max 5).
     *
     * @return Collection<int, ReceivingSession>
     */
    public function activeReceivingSessions(): Collection
    {
        if (! ReceivingSessionResource::canAccess()) {
            return collect();
        }

        $siteId = CurrentSite::id();
        if ($siteId === null) {
            return collect();
        }

        $user = auth()->user();

        return ReceivingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->where('site_id', $siteId)
            ->with(['site', 'document'])
            ->orderByDesc('opened_at')
            ->limit(5)
            ->get()
            ->when(
                $user instanceof User,
                fn (Collection $sessions) => $sessions->filter(
                    fn (ReceivingSession $session): bool => Gate::forUser($user)->allows('view', $session),
                ),
            )
            ->values();
    }

    public function receivingSessionStatusLabel(ReceivingSession $session): string
    {
        return ReceivingSessionStatus::label($session->status);
    }

    public function receivingSessionUrl(ReceivingSession $session): string
    {
        return ReceiveLayout::sessionUrl($session);
    }

    /**
     * @return array<string, bool>
     */
    public function featureMap(): array
    {
        $features = TenantFeatures::forTenant(tenant());

        return [
            'Receiving' => $features->supportsReceiving(),
            'Transferring' => $features->supportsTransferring(),
            'Unpacking' => $features->supportsUnpacking()
                || ReceivingPolicy::forTenant(tenant())->canUnpackAtReceive(),
            'Packing' => $features->supportsPacking(),
            'Commissioning (pallets)' => $features->supportsCommissioning(),
            'Returning' => $features->supportsReturning(),
        ];
    }

    public function routeHubScan(ResolveEpcFromScan $resolveEpcFromScan): void
    {
        $scan = ElementString::normalize(trim((string) $this->hubScan));
        $this->hubScan = $scan;

        if ($scan === '') {
            Notification::make()
                ->title('Scan required')
                ->body('Scan an SSCC, SGTIN, or other identifier to route.')
                ->warning()
                ->send();

            $this->dispatch('focus-hub-scan');

            return;
        }

        $normalized = $scan;
        $features = TenantFeatures::forTenant(tenant());

        if ($features->supportsReceiving() && ElementString::ssccIdentity($normalized) !== null) {
            $url = $this->routeSsccScan($normalized, $resolveEpcFromScan);
            if ($url !== null) {
                $this->redirect($url);
            }

            return;
        }

        if ($features->supportsReceiving() && ElementString::sgtinIdentity($normalized) !== null) {
            $url = $this->routeSgtinScan($normalized, $resolveEpcFromScan);
            if ($url !== null) {
                $this->redirect($url);
            }

            return;
        }

        if ($features->supportsInboundIntegrations()) {
            $url = $this->resourceIndexUrl(EpcisDocumentResource::class);

            if ($url !== null) {
                $this->redirect($url.(str_contains($url, '?') ? '&' : '?').'findRecall=1');

                return;
            }
        }

        Notification::make()
            ->title('No route for scan')
            ->body('Could not route this scan. Try Receive or Verify product from the directories below.')
            ->warning()
            ->send();

        $this->hubScan = '';
        $this->dispatch('focus-hub-scan');
    }

    /**
     * SGTIN scan routing (multi-open safe) — mirrors SSCC:
     * 1) Exact EPC already on an open session's scan lines
     * 2) In-transit transfer → OpenTransferReceivingSession
     * 3) Unique ASN match without an open ASN session → open ASN
     * 4) Exactly one open session → that session
     * 5) Shippable outbound → open/resume ship session
     * 6) Else Asset Tracking
     */
    private function routeSgtinScan(string $normalized, ResolveEpcFromScan $resolveEpcFromScan): ?string
    {
        return $this->routeReceiveScan($normalized, $resolveEpcFromScan);
    }

    /**
     * SSCC scan routing (multi-open safe):
     * 1) Exact EPC already on an open session's scan lines
     * 2) In-transit transfer → OpenTransferReceivingSession
     * 3) Unique ASN match without an open ASN session → open ASN
     * 4) Exactly one open session → that session
     * 5) Shippable outbound → open/resume ship session
     * 6) Else Asset Tracking
     */
    private function routeSsccScan(string $normalized, ResolveEpcFromScan $resolveEpcFromScan): ?string
    {
        return $this->routeReceiveScan($normalized, $resolveEpcFromScan);
    }

    /**
     * Shared SSCC/SGTIN receive-preference routing (multi-open safe):
     * 1) Exact EPC already on an open session's scan lines
     * 2) In-transit transfer / unique ASN open via ResolveOpenReceiveUrl
     * 3) Exactly one open session → that session
     * 4) Shippable outbound → open/resume ship session
     * 5) Else Asset Tracking
     */
    private function routeReceiveScan(string $normalized, ResolveEpcFromScan $resolveEpcFromScan): ?string
    {
        if (ReceivingSessionResource::canAccess()) {
            $openReceiveUrl = app(ResolveOpenReceiveUrl::class)->handle($normalized, auth()->id());
            if ($openReceiveUrl !== null) {
                return $openReceiveUrl;
            }

            $singleOpenUrl = $this->singleOpenReceivingSessionUrl($normalized);
            if ($singleOpenUrl !== null) {
                return $singleOpenUrl;
            }
        }

        $resolved = $resolveEpcFromScan->handle($normalized);
        $shipUrl = $this->routeShippableEpcScan($resolved['epc'], $normalized);
        if ($shipUrl !== null) {
            return $shipUrl;
        }

        if ($this->hubShipScanFailed) {
            return null;
        }

        return AssetTracking::getUrl(['scan' => $normalized]);
    }

    /**
     * Route shippable inventory to an open ship order (resume or open + confirm).
     */
    private function routeShippableEpcScan(?Epc $epc, string $normalized): ?string
    {
        $this->hubShipScanFailed = false;
        if ($epc === null) {
            return null;
        }

        if (! OutboundShippingSessionResource::canAccess()) {
            return null;
        }

        $onShipSession = OutboundShippingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('scanLines', fn ($query) => $query->where('epc_id', $epc->getKey()))
            ->orderByDesc('opened_at')
            ->first();

        if ($onShipSession !== null) {
            return OutboundShippingSessionResource::getUrl('view', [
                'record' => $onShipSession,
                'scan' => $normalized,
            ]);
        }

        $siteId = CurrentSite::id();
        if ($siteId === null) {
            return null;
        }

        if (! app(ShippableEpcsAtSite::class)->contains($siteId, (int) $epc->getKey())) {
            return null;
        }

        $userId = auth()->id();

        $resumeSession = OutboundShippingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->where('site_id', $siteId)
            ->when($userId !== null, fn ($query) => $query->where('opened_by', $userId))
            ->orderByDesc('opened_at')
            ->first();

        if ($resumeSession === null) {
            $resumeSession = OutboundShippingSession::query()
                ->whereIn('status', ['open', 'in_progress'])
                ->where('site_id', $siteId)
                ->orderByDesc('opened_at')
                ->first();
        }

        if ($resumeSession !== null) {
            $result = app(ConfirmOutboundShippingScan::class)->handle($resumeSession, $normalized, $userId);

            if (! $result['ok']) {
                Notification::make()
                    ->title($result['message'])
                    ->danger()
                    ->send();

                $this->hubShipScanFailed = true;
                $this->dispatch('focus-hub-scan');

                return null;
            }

            return OutboundShippingSessionResource::getUrl('view', [
                'record' => $resumeSession->fresh(),
                'scan' => $normalized,
            ]);
        }

        try {
            $session = app(OpenOutboundShippingSession::class)->handle($siteId, $userId);
            $result = app(ConfirmOutboundShippingScan::class)->handle($session, $normalized, $userId);

            if (! $result['ok']) {
                Notification::make()
                    ->title($result['message'])
                    ->danger()
                    ->send();

                $this->hubShipScanFailed = true;
                $this->dispatch('focus-hub-scan');

                return null;
            }

            return OutboundShippingSessionResource::getUrl('view', [
                'record' => $session->fresh(),
                'scan' => $normalized,
            ]);
        } catch (DomainException|InvalidArgumentException $e) {
            Notification::make()
                ->title('Could not open ship order')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->hubShipScanFailed = true;
            $this->dispatch('focus-hub-scan');

            return null;
        }
    }

    private function singleOpenReceivingSessionUrl(string $normalized): ?string
    {
        $siteId = CurrentSite::id();
        if ($siteId === null) {
            return null;
        }

        $activeSessions = ReceivingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->where('site_id', $siteId)
            ->orderByDesc('opened_at')
            ->limit(2)
            ->get();

        if ($activeSessions->count() !== 1) {
            return null;
        }

        $session = $activeSessions->first();
        $user = auth()->user();

        if ($user instanceof User && ! Gate::forUser($user)->allows('view', $session)) {
            return null;
        }

        return ReceiveLayout::sessionUrl($session, [
            'scan' => $normalized,
        ]);
    }

    /**
     * Cross-partner Master Data directories (sidebar stays partner-first).
     *
     * @return list<array{label: string, description: string, url: string|null}>
     */
    public function directories(): array
    {
        $features = TenantFeatures::forTenant(tenant());
        $directories = [];

        if ($features->supportsReceiving()) {
            $this->pushDirectory($directories, [
                'label' => 'Receive',
                'description' => 'Active and history for ASN, scan-first, and transfer receive.',
                'url' => $this->resourceIndexUrl(ReceivingSessionResource::class),
            ]);
        }

        if ($features->supportsUnpacking()
            || ReceivingPolicy::forTenant(tenant())->canUnpackAtReceive()) {
            $this->pushDirectory($directories, [
                'label' => 'Unpacking',
                'description' => 'Break a case here. Build a mixed SSCC on Pack.',
                'url' => $this->pageUrl(UnpackWorkstation::class),
            ]);
        }

        if ($features->supportsUnpacking()
            || $features->supportsPacking()
            || ReceivingPolicy::forTenant(tenant())->canUnpackAtReceive()) {
            $this->pushDirectory($directories, [
                'label' => 'Unpacked items',
                'description' => 'View items unpacked and not yet packed again',
                'url' => $this->pageUrl(UnpackedItems::class),
            ]);
        }

        if ($features->supportsPacking() || $features->supportsSsccLabeling()) {
            $this->pushDirectory($directories, [
                'label' => 'Packing',
                'description' => 'Pack bottles onto a new or already generated mixed-lot SSCC.',
                'url' => $this->pageUrl(PackWorkstation::class),
            ]);
        }

        if ($features->supportsPacking()) {
            $this->pushDirectory($directories, [
                'label' => 'Break & pack',
                'description' => 'Break children from a source pallet onto a new outbound SSCC.',
                'url' => $this->pageUrl(BreakPackWorkstation::class),
            ]);
        }

        if ($features->supportsCommissioning()) {
            $this->pushDirectory($directories, [
                'label' => 'Commission-all',
                'description' => 'Author commissioning ObjectEvents for EPCs missing them.',
                'url' => $this->pageUrl(CommissionAllWorkstation::class),
            ]);
            $this->pushDirectory($directories, [
                'label' => 'Decommission',
                'description' => 'Author decommissioning (inactive) for on-hand EPCs.',
                'url' => $this->pageUrl(DecommissionWorkstation::class),
            ]);
        }

        if ($features->supportsReturning()) {
            $this->pushDirectory($directories, [
                'label' => 'Return',
                'description' => 'Author returning ObjectEvents with disposition returned.',
                'url' => $this->pageUrl(ReturnWorkstation::class),
            ]);
        }

        if ($features->supportsTransferring()) {
            $this->pushDirectory($directories, [
                'label' => 'Transfer',
                'description' => 'Active and history for intracompany moves and transfer EPCIS.',
                'url' => $this->resourceIndexUrl(TransferringSessionResource::class),
            ]);
        }

        if ($features->supportsOutboundIntegrations()) {
            $this->pushDirectory($directories, [
                'label' => 'Ship Order',
                'description' => 'Scan-first outbound ship to trading partners with EPCIS transmission.',
                'url' => $this->resourceIndexUrl(OutboundShippingSessionResource::class),
            ]);
            $this->pushDirectory($directories, [
                'label' => 'Outbound EPCIS',
                'description' => 'Browse authored shipping, transfer, and other outbound EPCIS documents.',
                'url' => $this->resourceIndexUrl(OutboundEpcisDocumentResource::class),
            ]);
        }

        $this->pushDirectory($directories, [
            'label' => 'Asset Tracking',
            'description' => 'Trace a unit or pallet for status, custody history, and contained serials.',
            'url' => $this->pageUrl(AssetTracking::class),
        ]);

        if ($features->supportsReceiving()) {
            $this->pushDirectory($directories, [
                'label' => 'Verify product',
                'description' => 'Scan a unit label and verify with VRS.',
                'url' => $this->pageUrl(VerifyProduct::class),
            ]);
        }

        if ($features->supportsInboundIntegrations() || $features->supportsOutboundIntegrations()) {
            $this->pushDirectory($directories, [
                'label' => 'Integration health',
                'description' => '24-hour EPCIS throughput and connection status.',
                'url' => $this->pageUrl(IntegrationHealth::class),
            ]);
        }

        $this->pushDirectory($directories, [
            'label' => 'Analytics',
            'description' => 'Volume, exceptions, VRS, partners, and compliance trends.',
            'url' => $this->pageUrl(Analytics::class),
        ]);

        if ($features->supportsInboundIntegrations()) {
            $this->pushDirectory($directories, [
                'label' => 'Inbound EPCIS',
                'description' => 'Browse ingested EPCIS documents, events, and EPCs.',
                'url' => $this->resourceIndexUrl(EpcisDocumentResource::class),
            ]);
            $this->pushDirectory($directories, [
                'label' => 'Inbound Connections',
                'description' => 'Configure HTTPS webhooks and SFTP polling for partner EPCIS.',
                'url' => $this->resourceIndexUrl(InboundConnectionResource::class),
            ]);
            $this->pushDirectory($directories, [
                'label' => 'API Tokens',
                'description' => 'Create and revoke Sanctum tokens for programmatic API access.',
                'url' => $this->pageUrl(ApiTokens::class),
            ]);
            $inboundUrl = $this->resourceIndexUrl(EpcisDocumentResource::class);
            $this->pushDirectory($directories, [
                'label' => 'Find / Recall',
                'description' => 'Find units by GTIN/lot, or shipments by ASN/PO, then quarantine matches.',
                'url' => $inboundUrl !== null
                    ? $inboundUrl.(str_contains($inboundUrl, '?') ? '&' : '?').'findRecall=1'
                    : null,
            ]);
        }

        if (! $features->supportsMasterData()) {
            return $directories;
        }

        $this->pushDirectory($directories, [
            'label' => 'Trading Partners',
            'description' => 'Primary master-data hub for sites and products.',
            'url' => $this->resourceIndexUrl(TradingPartnerResource::class),
        ]);
        $this->pushDirectory($directories, [
            'label' => 'FDA Products',
            'description' => 'Partner-first add path. Search Rx FDA NDCs and authorize packages.',
            'url' => $this->resourceIndexUrl(FdaProductResource::class),
        ]);
        $this->pushDirectory($directories, [
            'label' => 'Product directory',
            'description' => 'Authorized assortment and receive-from products. Search GTIN, NDC, or name.',
            'url' => $this->resourceIndexUrl(ProductResource::class),
        ]);
        $this->pushDirectory($directories, [
            'label' => 'Site directory',
            'description' => 'All sites across partners (active by default). Search GLN, code, or name.',
            'url' => $this->resourceIndexUrl(SiteResource::class),
        ]);

        return $directories;
    }

    /**
     * @param  list<array{label: string, description: string, url: string|null}>  $directories
     * @param  array{label: string, description: string, url: string|null}  $directory
     */
    private function pushDirectory(array &$directories, array $directory): void
    {
        if ($directory['url'] === null) {
            return;
        }

        $directories[] = $directory;
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function pageUrl(string $page): ?string
    {
        try {
            if (! $page::canAccess()) {
                return null;
            }

            return $page::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function resourceIndexUrl(string $resource): ?string
    {
        try {
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                return null;
            }

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

    public static function getDocumentation(): array|string
    {
        return 'workflows.shell-and-site';
    }
}
