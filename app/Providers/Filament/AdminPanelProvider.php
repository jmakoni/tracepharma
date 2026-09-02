<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Auth\Login;
use App\Filament\Admin\Pages\Dashboard;
use App\Models\Admin;
use App\Support\Auth\TracepharmaBreezyCore;
use App\Support\Filament\OptionalFilamentPlugins;
use Bityukov\CommandCenter\Filament\CommandCenterPlugin;
use BokshornIt\FilamentActivityTimeline\ActivityTimelinePlugin;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Guava\FilamentKnowledgeBase\Plugins\KnowledgeBaseCompanionPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use MKWebDesign\FilamentWatchdog\FilamentWatchdogPlugin;
use Zvizvi\FilamentNotificationsTabs\FilamentNotificationsTabsPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->domain(config('tracepharma.admin_domain'))
            ->path('')
            ->login(Login::class)
            ->passwordReset()
            ->authPasswordBroker('admins')
            ->authGuard('admin')
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
            ->globalSearch(false)
            ->sidebarWidth('16rem')
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            ->widgets([])
            ->plugin(
                TracepharmaBreezyCore::make()
                    ->myProfile(hasAvatars: true)
                    ->enableTwoFactorAuthentication()
                    ->enablePasskeys(
                        relyingPartyName: (string) config('app.name'),
                        relyingPartyId: (string) config('tracepharma.admin_domain'),
                        scopeToPanel: true,
                    )
            );

        $panel = OptionalFilamentPlugins::register(
            $panel,
            KnowledgeBaseCompanionPlugin::class,
            fn () => KnowledgeBaseCompanionPlugin::make()
                ->knowledgeBasePanelId('admin-knowledge-base')
                ->modalPreviews()
                ->slideOverPreviews(),
        );

        $panel = OptionalFilamentPlugins::register(
            $panel,
            CommandCenterPlugin::class,
            fn () => CommandCenterPlugin::make(),
        );

        $panel = OptionalFilamentPlugins::register(
            $panel,
            ActivityTimelinePlugin::class,
            fn () => ActivityTimelinePlugin::make()
                ->registerNavigation(false)
                ->navigationGroup('Audit')
                ->causerIcons([
                    Admin::class => 'heroicon-m-user',
                ]),
        );

        $panel = OptionalFilamentPlugins::register(
            $panel,
            FilamentNotificationsTabsPlugin::class,
            fn () => FilamentNotificationsTabsPlugin::make()->confirmDelete(),
        );

        $panel = OptionalFilamentPlugins::register(
            $panel,
            'MKWebDesign\\FilamentWatchdog\\FilamentWatchdogPlugin',
            fn () => FilamentWatchdogPlugin::make(),
        );

        return $panel
            ->databaseNotifications()
            ->userMenuItems([
                Action::make('adminHelp')
                    ->label('Admin help')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->url(fn (): string => filament()->getPanel('admin-knowledge-base')->getUrl())
                    ->openUrlInNewTab()
                    ->sort(20),
            ])
            ->middleware([
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
            ])
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_END,
                fn (): string => view('filament.hooks.auth-legal-links')->render(),
            );
    }
}
