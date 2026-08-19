<?php

namespace App\Filament\App\Pages;

use App\Enums\TenantProfile;
use App\Filament\App\Widgets\CompliancePulseWidget;
use App\Filament\App\Widgets\FloorQueueWidget;
use App\Filament\App\Widgets\HomeAnalyticsBundleWidget;
use App\Filament\App\Widgets\IntegrationHealthWidget;
use App\Filament\App\Widgets\PrimaryCtasWidget;
use App\Filament\App\Widgets\TodayActivityWidget;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Dashboard\DashboardWidgetCatalog;
use App\Support\Dashboard\ResolveDashboardWidgets;
use App\Support\TenantFeatures;
use App\Support\TenantOnboarding;
use App\Support\TenantSettings;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

class Dashboard extends BaseDashboard
{
    /**
     * @var array<string, class-string<Widget>>
     */
    private const WIDGET_CLASSES = [
        'floor_queue' => FloorQueueWidget::class,
        'today_activity' => TodayActivityWidget::class,
        'compliance_pulse' => CompliancePulseWidget::class,
        'integration_health' => IntegrationHealthWidget::class,
        'primary_ctas' => PrimaryCtasWidget::class,
    ];

    private const ONBOARDING_REDIRECT_SESSION_KEY = 'filament.app.onboarding_wizard_redirected';

    public static function getNavigationItems(): array
    {
        return array_map(static function ($item) {
            return $item->extraAttributes([
                'class' => 'tp-nav-icon-only',
                'title' => __('filament-panels::pages/dashboard.title'),
                'aria-label' => __('filament-panels::pages/dashboard.title'),
            ]);
        }, parent::getNavigationItems());
    }

    public function mount(): void
    {
        if (! $this->shouldPromptOnboarding()) {
            return;
        }

        if (session()->get(self::ONBOARDING_REDIRECT_SESSION_KEY)) {
            return;
        }

        session()->put(self::ONBOARDING_REDIRECT_SESSION_KEY, true);

        $this->redirect(OnboardingWizard::getUrl(panel: 'app'));
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.app.partials.onboarding-banner')
                    ->visible(fn (): bool => $this->shouldShowOnboardingBanner()),
                View::make('filament.app.partials.job-role-required-banner')
                    ->visible(fn (): bool => $this->shouldShowJobRoleRequiredBanner()),
                View::make('filament.app.partials.buying-group-limited-banner')
                    ->visible(fn (): bool => $this->shouldShowBuyingGroupLimitedBanner()),
                $this->getWidgetsContentComponent()
                    ->visible(fn (): bool => ! $this->shouldHideDashboardWidgets()),
            ]);
    }

    private function shouldPromptOnboarding(): bool
    {
        if (! OrganizationSettings::canAccess() || ! OnboardingWizard::canAccess()) {
            return false;
        }

        $tenant = tenant();
        $onboarding = TenantOnboarding::forTenant($tenant);

        if ($onboarding->isCriticalComplete()) {
            return false;
        }

        return TenantSettings::forTenant($tenant)->onboardingDismissedAt() === null;
    }

    private function shouldShowOnboardingBanner(): bool
    {
        return $this->shouldPromptOnboarding();
    }

    private function shouldShowJobRoleRequiredBanner(): bool
    {
        return JobRoleAccess::enabled() && ! JobRoleAccess::hasAnyAppCapability();
    }

    private function shouldShowBuyingGroupLimitedBanner(): bool
    {
        if ($this->shouldShowJobRoleRequiredBanner()) {
            return false;
        }

        $features = TenantFeatures::forTenant(tenant());

        if ($features->profile() !== TenantProfile::BuyingGroup) {
            return false;
        }

        return JobRoleAccess::isOwner() && ! $features->hasAnyOperations();
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        $user = auth()->user();
        $keys = ResolveDashboardWidgets::make()->forUser($user instanceof User ? $user : null);
        $widgets = [];
        $analyticsLookup = array_flip(DashboardWidgetCatalog::analyticsKeys());
        $hasAnalytics = false;

        foreach ($keys as $key) {
            $class = self::WIDGET_CLASSES[$key] ?? null;

            if ($class !== null) {
                $widgets[] = $class;
            }

            if (isset($analyticsLookup[$key])) {
                $hasAnalytics = true;
            }
        }

        if ($hasAnalytics) {
            $widgets[] = HomeAnalyticsBundleWidget::class;
        }

        return $widgets;
    }

    private function shouldHideDashboardWidgets(): bool
    {
        return $this->shouldShowJobRoleRequiredBanner()
            || $this->shouldShowBuyingGroupLimitedBanner();
    }
}
