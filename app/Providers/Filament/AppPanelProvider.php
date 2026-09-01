<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\OrganizationSettings;
use App\Http\Middleware\EnsureLegalAcceptance;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Models\User;
use App\Support\Auth\TracepharmaBreezyCore;
use BokshornIt\FilamentActivityTimeline\ActivityTimelinePlugin;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Guava\FilamentKnowledgeBase\Plugins\KnowledgeBaseCompanionPlugin;
use Zvizvi\FilamentNotificationsTabs\FilamentNotificationsTabsPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('')
            ->login(\App\Filament\App\Pages\Auth\Login::class)
            ->passwordReset()
            ->authPasswordBroker('users')
            ->authGuard('web')
            ->brandName('TracePharma')
            ->brandLogo(asset('images/brand/logo.svg'))
            ->darkModeBrandLogo(asset('images/brand/logo-dark.svg'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/brand/logo-mark.svg'))
            ->colors([
                'primary' => Color::hex('#51BC8F'),
                'secondary' => Color::hex('#838589'),
                'danger' => Color::hex('#EA4758'),
                'warning' => Color::hex('#FFAB00'),
                'success' => Color::hex('#51BC8F'),
                'info' => Color::hex('#838589'),
                'gray' => Color::hex('#676C73'),
            ])
            ->topNavigation()
            ->navigationGroups([
                'Operations',
                'Receiving',
                'Ship',
                'Compliance',
                'Master Data',
                'Integrations',
                'Settings',
                'Audit',
            ])
            ->sidebarWidth('16rem')
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/app/theme.css')
            ->assets([
                Js::make('zebra-browser-print')
                    ->html(new HtmlString(
                        '<script src="'.e($this->versionedPublicJs('js/vendor/BrowserPrint.min.js')).'" data-navigate-track></script>'
                    )),
                Js::make('tp-client-label-print')
                    ->html(new HtmlString(
                        '<script src="'.e($this->versionedPublicJs('js/tp-client-label-print.js')).'" data-navigate-track></script>'
                    )),
                Js::make('tp-scan-sounds')
                    ->html(new HtmlString(
                        '<script src="'.e($this->versionedPublicJs('js/tp-scan-sounds.js')).'" data-navigate-track></script>'
                    )),
            ])
            ->globalSearch(false)
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([])
            ->plugin(
                TracepharmaBreezyCore::make()
                    ->myProfile(hasAvatars: true)
                    ->enableTwoFactorAuthentication()
                    ->enablePasskeys(
                        relyingPartyName: (string) config('app.name'),
                        scopeToPanel: true,
                    )
            )
            ->plugin(
                KnowledgeBaseCompanionPlugin::make()
                    ->knowledgeBasePanelId('knowledge-base')
                    ->modalPreviews()
                    ->slideOverPreviews()
            )
            ->plugin(
                ActivityTimelinePlugin::make()
                    ->registerNavigation(false)
                    ->navigationGroup('Audit')
                    ->causerIcons([
                        User::class => 'heroicon-m-user',
                    ])
            )
            ->databaseNotifications()
            ->plugin(
                FilamentNotificationsTabsPlugin::make()
                    ->confirmDelete()
            )
            ->userMenuItems([
                Action::make('organizationSettings')
                    ->label('Organization Settings')
                    ->icon(Heroicon::OutlinedBuildingOffice)
                    ->url(fn (): string => OrganizationSettings::getUrl(panel: 'app'))
                    ->visible(fn (): bool => OrganizationSettings::canAccess())
                    ->sort(10),
                Action::make('operatorHelp')
                    ->label('Operator help')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->url(fn (): string => filament()->getPanel('knowledge-base')->getUrl())
                    ->openUrlInNewTab()
                    ->sort(20),
            ])
            ->middleware([
                PreventAccessFromCentralDomains::class,
                InitializeTenancyByDomain::class,
                EnsureTenantIsActive::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureLegalAcceptance::class,
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_AFTER,
                fn (): string => view('filament.app.hooks.impersonation-banner')->render()
                    .view('filament.app.hooks.legal-acceptance-banner')->render()
                    .view('filament.app.hooks.tenant-announcement-banner')->render(),
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => view('filament.app.hooks.current-site-switcher')->render(),
            )
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_END,
                fn (): string => view('filament.hooks.auth-legal-links')->render(),
            );
    }

    private function versionedPublicJs(string $relativePath): string
    {
        $absolute = public_path($relativePath);
        $version = is_file($absolute) ? (string) filemtime($absolute) : (string) time();

        return asset($relativePath).'?v='.$version;
    }
}
