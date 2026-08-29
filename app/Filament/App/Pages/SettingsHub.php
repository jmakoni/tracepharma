<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Filament\App\Resources\LabelPrinters\LabelPrinterResource;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Filament\App\Resources\SsccNumberRanges\SsccNumberRangeResource;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Filament\App\Resources\Users\UserResource;
use App\Support\Auth\JobRoleAccess;
use App\Support\OnboardingCopy;
use App\Support\TenantFeatures;
use App\Support\TenantOnboarding;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Support\Facades\Route;
use Throwable;
use UnitEnum;

class SettingsHub extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 0;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.app.pages.settings-hub';

    public bool $showCompletedChecklist = false;

    public static function canAccess(): bool
    {
        if (! TenantFeatures::forTenant(tenant())->supportsMasterData()) {
            return false;
        }

        return JobRoleAccess::canAccessOrganizationSettings();
    }

    public function readinessScore(): int
    {
        return TenantOnboarding::forTenant(tenant())->score();
    }

    public function criticalScore(): int
    {
        return TenantOnboarding::forTenant(tenant())->criticalScore();
    }

    public function readinessDoneCount(): int
    {
        return count(array_filter(
            TenantOnboarding::forTenant(tenant())->items(),
            fn (array $item): bool => $item['done'],
        ));
    }

    public function readinessTotalCount(): int
    {
        return count(TenantOnboarding::forTenant(tenant())->items());
    }

    /**
     * Critical go-live ready (org GLN + default site GLNs) — not 100% of recommended checklist.
     */
    public function isReadinessComplete(): bool
    {
        return TenantOnboarding::forTenant(tenant())->isComplete();
    }

    public function isRecommendedComplete(): bool
    {
        return $this->readinessTotalCount() > 0
            && $this->readinessDoneCount() === $this->readinessTotalCount();
    }

    public function toggleCompletedChecklist(): void
    {
        $this->showCompletedChecklist = ! $this->showCompletedChecklist;
    }

    public function acknowledgeOutboundDeferred(): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()) {
            return;
        }

        if ($this->isOutboundConfigured()) {
            return;
        }

        $tenant = tenant();
        $settings = TenantSettings::forTenant($tenant);
        $settings->acknowledgeOutboundDeferred();
        $tenant?->save();

        Notification::make()
            ->title('Outbound deferred')
            ->body('You can configure outbound shipping later. Receiving setup can continue.')
            ->success()
            ->send();
    }

    public function canDeferOutbound(array $item): bool
    {
        return ($item['id'] ?? null) === 'outbound_configured'
            && ! ($item['done'] ?? false)
            && TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations();
    }

    private function isOutboundConfigured(): bool
    {
        foreach (TenantOnboarding::forTenant(tenant())->items() as $item) {
            if (($item['id'] ?? null) === 'outbound_configured') {
                return (bool) ($item['done'] ?? false);
            }
        }

        return true;
    }

    public function operationsHubUrl(): ?string
    {
        return $this->pageUrl(OperationsHub::class);
    }

    /**
     * Incomplete items first; completed follow (hidden in the view unless toggled).
     *
     * @return list<array{id: string, label: string, done: bool, href?: string, description?: string}>
     */
    public function checklistItems(): array
    {
        $descriptions = OnboardingCopy::forTenant(tenant())->itemDescriptions();

        $items = array_map(function (array $item) use ($descriptions): array {
            if (isset($descriptions[$item['id']])) {
                $item['description'] = $descriptions[$item['id']];
            }

            return $item;
        }, TenantOnboarding::forTenant(tenant())->items());

        usort($items, function (array $a, array $b): int {
            return ((int) $a['done']) <=> ((int) $b['done']);
        });

        return $items;
    }

    /**
     * @return list<array{id: string, label: string, done: bool, href?: string, description?: string}>
     */
    public function incompleteChecklistItems(): array
    {
        return array_values(array_filter(
            $this->checklistItems(),
            fn (array $item): bool => ! $item['done'],
        ));
    }

    /**
     * @return list<array{id: string, label: string, done: bool, href?: string, description?: string}>
     */
    public function completedChecklistItems(): array
    {
        return array_values(array_filter(
            $this->checklistItems(),
            fn (array $item): bool => $item['done'],
        ));
    }

    /**
     * Grouped settings deep-links. Organization-bound prefs are one card.
     *
     * @return list<array{
     *     title: string,
     *     cards: list<array{label: string, description: string, url: string, icon: string}>
     * }>
     */
    public function cardSections(): array
    {
        $features = TenantFeatures::forTenant(tenant());
        $organizationUrl = $this->pageUrl(OrganizationSettings::class);
        $sections = [];

        $orgCards = [];
        $orgDescription = $features->supportsOutboundIntegrations()
            ? 'Company GLN, receive and ship-from sites, contacts.'
            : 'Company GLN, default receive site, and contacts.';

        $this->pushCard($orgCards, [
            'label' => 'Organization',
            'description' => $features->supportsSsccLabeling()
                ? ($features->supportsOutboundIntegrations()
                    ? 'Company GLN, GCP, ship sites, SSCC defaults, and contacts.'
                    : 'Company GLN, GCP, receive site, SSCC defaults, and contacts.')
                : $orgDescription,
            'url' => $organizationUrl,
            'icon' => 'heroicon-o-building-office',
        ]);

        $this->pushCard($orgCards, [
            'label' => 'Sites & ATP',
            'description' => $features->supportsSsccLabeling()
                ? 'Locations, GLNs, ATP licenses, and site SSCC number ranges.'
                : 'Your locations, GLNs, and ATP licenses.',
            'url' => $this->resourceIndexUrl(SiteResource::class),
            'icon' => 'heroicon-o-map-pin',
        ]);

        if ($orgCards !== []) {
            $sections[] = ['title' => 'Organization & sites', 'cards' => $orgCards];
        }

        $partnerCards = [];
        $this->pushCard($partnerCards, [
            'label' => 'Trading partners',
            'description' => 'Upstream and downstream partners.',
            'url' => $this->resourceIndexUrl(TradingPartnerResource::class),
            'icon' => 'heroicon-o-building-storefront',
        ]);

        if ($partnerCards !== []) {
            $sections[] = ['title' => 'Partners', 'cards' => $partnerCards];
        }

        $complianceCards = [];
        if ($features->supportsComplianceReports()) {
            $this->pushCard($complianceCards, [
                'label' => 'Inspection day',
                'description' => 'FDA walk-in checklist: ZIP pack, ATP, exceptions, SOPs, Alert Center.',
                'url' => $this->pageUrl(InspectionDayReadinessPage::class),
                'icon' => 'heroicon-o-clipboard-document-check',
            ]);
        }

        if ($complianceCards !== []) {
            $sections[] = ['title' => 'Compliance', 'cards' => $complianceCards];
        }

        $integrationCards = [];
        $this->pushCard($integrationCards, [
            'label' => 'Integration health',
            'description' => '24-hour EPCIS throughput and connection status.',
            'url' => $this->pageUrl(IntegrationHealth::class),
            'icon' => 'heroicon-o-signal',
        ]);

        if ($features->supportsInboundIntegrations()) {
            $this->pushCard($integrationCards, [
                'label' => 'Partner onboarding kit',
                'description' => 'Connect a supplier, validate inbound EPCIS, and complete first receive.',
                'url' => $this->pageUrl(PartnerOnboardingKitPage::class),
                'icon' => 'heroicon-o-user-group',
            ]);
            $this->pushCard($integrationCards, [
                'label' => 'Inbound connections',
                'description' => 'HTTPS webhooks and SFTP for partner EPCIS.',
                'url' => $this->resourceIndexUrl(InboundConnectionResource::class),
                'icon' => 'heroicon-o-arrow-down-tray',
            ]);
            $this->pushCard($integrationCards, [
                'label' => 'API tokens',
                'description' => 'Programmatic access for inbound integrations.',
                'url' => $this->pageUrl(ApiTokens::class),
                'icon' => 'heroicon-o-key',
            ]);
        }

        if ($features->supportsVrs()) {
            $this->pushCard($integrationCards, [
                'label' => 'PMS integration',
                'description' => 'Dispense-check certification checklist for pharmacy PMS pilots.',
                'url' => $this->pageUrl(PmsIntegrationChecklistPage::class),
                'icon' => 'heroicon-o-puzzle-piece',
            ]);
        }

        if ($features->supportsOutboundIntegrations()) {
            $this->pushCard($integrationCards, [
                'label' => 'Wholesaler / WMS pack',
                'description' => 'Ship-confirm webhook + Sanctum outbound kit for WMS partners.',
                'url' => $this->pageUrl(WholesalerIntegrationPackPage::class),
                'icon' => 'heroicon-o-truck',
            ]);
        }

        if ($integrationCards !== []) {
            $sections[] = ['title' => 'Integrations', 'cards' => $integrationCards];
        }

        $labelingCards = [];
        if ($features->supportsSsccLabeling()) {
            $this->pushCard($labelingCards, [
                'label' => 'SSCC Labels',
                'description' => 'Generate and print pallet SSCC-18 labels.',
                'url' => $this->resourceIndexUrl(SsccLabelResource::class),
                'icon' => 'heroicon-o-qr-code',
            ]);
            $this->pushCard($labelingCards, [
                'label' => 'SSCC Number Ranges',
                'description' => 'Allocate serial ranges by tenant, site, or partner.',
                'url' => $this->resourceIndexUrl(SsccNumberRangeResource::class),
                'icon' => 'heroicon-o-hashtag',
            ]);
            $this->pushCard($labelingCards, [
                'label' => 'Label Printers',
                'description' => 'Network, QZ Tray, and Zebra Browser Print label printers for SSCC labels.',
                'url' => $this->resourceIndexUrl(LabelPrinterResource::class),
                'icon' => 'heroicon-o-printer',
            ]);
        }

        if ($labelingCards !== []) {
            $sections[] = ['title' => 'Labeling', 'cards' => $labelingCards];
        }

        $peopleCards = [];
        $this->pushCard($peopleCards, [
            'label' => 'Users & roles',
            'description' => 'People with access to this organization.',
            'url' => $this->resourceIndexUrl(UserResource::class),
            'icon' => 'heroicon-o-users',
        ]);

        if ($peopleCards !== []) {
            $sections[] = ['title' => 'People', 'cards' => $peopleCards];
        }

        $setupCards = [];
        if (! $this->isReadinessComplete()) {
            $this->pushCard($setupCards, [
                'label' => 'Guided setup',
                'description' => 'Step-by-step go-live wizard.',
                'url' => $this->pageUrl(OnboardingWizard::class),
                'icon' => 'heroicon-o-rocket-launch',
            ]);
        }

        if ($setupCards !== []) {
            $sections[] = ['title' => 'Setup', 'cards' => $setupCards];
        }

        return $sections;
    }

    /**
     * @param  list<array{label: string, description: string, url: string, icon: string}>  $cards
     * @param  array{label: string, description: string, url: string|null, icon: string}  $card
     */
    private function pushCard(array &$cards, array $card): void
    {
        if ($card['url'] === null) {
            return;
        }

        $cards[] = [
            'label' => $card['label'],
            'description' => $card['description'],
            'url' => $card['url'],
            'icon' => $card['icon'],
        ];
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
        return 'settings.settings-hub';
    }
}
