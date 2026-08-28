<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\PartnerOnboardingKit;
use App\Support\PartnerOnboardingKitPdf;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class PartnerOnboardingKitPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Partner onboarding';

    protected static ?string $title = 'Partner onboarding kit';

    protected static ?int $navigationSort = 3;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected string $view = 'filament.app.pages.partner-onboarding-kit';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()
            && JobRoleAccess::canAccessOrganizationSettings();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Connect a supplier, prove inbound EPCIS, and complete your first receive.';
    }

    public function kitScore(): int
    {
        return app(PartnerOnboardingKit::class)->score();
    }

    /**
     * @return list<array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}>
     */
    public function kitSteps(): array
    {
        return app(PartnerOnboardingKit::class)->steps();
    }

    public function copyItBriefAction(): Action
    {
        return Action::make('copyItBrief')
            ->label('Copy IT brief')
            ->icon(Heroicon::OutlinedClipboardDocument)
            ->color('gray')
            ->action(function (): void {
                $this->dispatch('copy-it-brief', text: app(PartnerOnboardingKit::class)->exportBrief());

                Notification::make()
                    ->title('IT brief copied')
                    ->body('Paste into email for your supplier integration team.')
                    ->success()
                    ->send();
            });
    }

    public function downloadItBriefPdfAction(): Action
    {
        return Action::make('downloadItBriefPdf')
            ->label('Download PDF')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(fn (): StreamedResponse => response()->streamDownload(
                function (): void {
                    echo app(PartnerOnboardingKitPdf::class)->render();
                },
                'tracepharma-partner-onboarding.pdf',
                ['Content-Type' => 'application/pdf'],
            ));
    }

    public function emailItBriefAction(): Action
    {
        return Action::make('emailItBrief')
            ->label('Email IT brief')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->url(function (): string {
                $brief = app(PartnerOnboardingKit::class)->exportBrief();
                $subject = rawurlencode('TracePharma partner onboarding — '.(tenant()?->name ?? 'tenant'));
                $body = rawurlencode($brief);

                return 'mailto:?subject='.$subject.'&body='.$body;
            })
            ->openUrlInNewTab();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->copyItBriefAction(),
            $this->downloadItBriefPdfAction(),
            $this->emailItBriefAction(),
            Action::make('openGettingStarted')
                ->label('Organization checklist')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->url(fn (): string => OnboardingWizard::getUrl(panel: 'app'))
                ->visible(fn (): bool => OnboardingWizard::canAccess()),
        ];
    }
}
