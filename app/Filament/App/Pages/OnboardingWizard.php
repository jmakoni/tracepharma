<?php

namespace App\Filament\App\Pages;

use App\Actions\MasterData\AssignMissingDefaultSites;
use App\Support\Auth\JobRoleAccess;
use App\Support\OnboardingCopy;
use App\Support\TenantFeatures;
use App\Support\TenantOnboarding;
use App\Support\TenantSettings;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class OnboardingWizard extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $navigationLabel = 'Getting started';

    protected static ?string $title = 'Getting started';

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.app.pages.onboarding-wizard';

    public function mount(): void
    {
        app(AssignMissingDefaultSites::class)->healFromExistingSites();
    }

    public static function canAccess(): bool
    {
        if (! TenantFeatures::forTenant(tenant())->supportsMasterData()) {
            return false;
        }

        return JobRoleAccess::canAccessOrganizationSettings();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return OnboardingCopy::forTenant(tenant())->subheading();
    }

    public function bannerText(): string
    {
        return OnboardingCopy::forTenant(tenant())->banner();
    }

    public function readinessScore(): int
    {
        return TenantOnboarding::forTenant(tenant())->score();
    }

    public function isCriticalComplete(): bool
    {
        return TenantOnboarding::forTenant(tenant())->isCriticalComplete();
    }

    /**
     * Receiving without an authorized upstream partner is the failure DSCSA cares about,
     * so go-live is blocked rather than warned about: dismissing the checklist is the
     * other way out for a tenant that is not receiving yet.
     */
    public function isUpstreamAtpSatisfied(): bool
    {
        return TenantOnboarding::forTenant(tenant())->isUpstreamAtpSatisfied();
    }

    /**
     * @return list<array{id: string, label: string, done: bool, href?: string, description?: string}>
     */
    public function checklistItems(): array
    {
        $descriptions = $this->itemDescriptions();

        return array_map(function (array $item) use ($descriptions): array {
            if (isset($descriptions[$item['id']])) {
                $item['description'] = $descriptions[$item['id']];
            }

            return $item;
        }, TenantOnboarding::forTenant(tenant())->items());
    }

    public function markComplete(): void
    {
        if (! $this->isCriticalComplete()) {
            Notification::make()
                ->title('Critical setup incomplete')
                ->body('Add a company GLN and default receive site (and ship-from site when you ship) before marking complete.')
                ->warning()
                ->send();

            return;
        }

        if (! $this->isUpstreamAtpSatisfied()) {
            $this->notifyUpstreamAtpMissing();

            return;
        }

        $this->persistDismissed();

        Notification::make()
            ->title('Setup marked complete')
            ->success()
            ->send();

        $this->redirect($this->continueUrl());
    }

    public function dismiss(): void
    {
        $this->persistDismissed();

        Notification::make()
            ->title('Getting started dismissed')
            ->body('You can reopen this checklist anytime from Settings.')
            ->success()
            ->send();

        $this->redirect($this->continueUrl());
    }

    public function continueToOpsHub(): void
    {
        $this->redirect($this->continueUrl());
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

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($this->shouldShowDeferOutboundAction()) {
            $actions[] = Action::make('acknowledgeOutboundDeferred')
                ->label('Defer outbound for now')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->action(fn () => $this->acknowledgeOutboundDeferred());
        }

        $actions[] = Action::make('markComplete')
            ->label('Mark complete')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->action(fn () => $this->markComplete());
        $actions[] = Action::make('dismiss')
            ->label('Dismiss')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->action(fn () => $this->dismiss());
        $actions[] = Action::make('continueToOpsHub')
            ->label('Continue to Operations Hub')
            ->icon(Heroicon::OutlinedArrowRight)
            ->action(fn () => $this->continueToOpsHub());

        return $actions;
    }

    private function shouldShowDeferOutboundAction(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()
            && ! $this->isOutboundConfigured();
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

    private function notifyUpstreamAtpMissing(): void
    {
        $notification = Notification::make()
            ->title('Upstream partner ATP not ready')
            ->body('Before go-live, at least one manufacturer or wholesaler you receive from needs a site with an ATP / WDD license in force for your organization jurisdictions.')
            ->warning()
            ->persistent();

        $partnersUrl = TenantOnboarding::forTenant(tenant())->partnersHref();

        if ($partnersUrl !== null) {
            $notification->actions([
                Action::make('openPartners')
                    ->label('Open trading partners')
                    ->url($partnersUrl),
            ]);
        }

        $notification->send();
    }

    private function persistDismissed(): void
    {
        $tenant = tenant();
        $settings = TenantSettings::forTenant($tenant);
        $settings->setOnboardingDismissedAt(now());
        $tenant?->save();
    }

    private function continueUrl(): string
    {
        if (OperationsHub::canAccess()) {
            return OperationsHub::getUrl(panel: 'app');
        }

        if (SettingsHub::canAccess()) {
            return SettingsHub::getUrl(panel: 'app');
        }

        return Dashboard::getUrl(panel: 'app');
    }

    /**
     * @return array<string, string>
     */
    private function itemDescriptions(): array
    {
        return OnboardingCopy::forTenant(tenant())->itemDescriptions();
    }

    public static function getDocumentation(): array|string
    {
        return 'settings.onboarding';
    }
}
