<?php

namespace App\Providers\Filament;

use App\Support\Auth\TracepharmaBreezyCore;
use App\Support\KnowledgeBase\PublicAssetImageRenderer;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Guava\FilamentKnowledgeBase\Plugins\KnowledgeBasePlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;

class AdminKnowledgeBasePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin-knowledge-base')
            ->domain(config('tracepharma.admin_domain'))
            ->path('help')
            ->login()
            ->authGuard('admin')
            ->brandName('TracePharma Admin Help')
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
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->plugin(
                KnowledgeBasePlugin::make()
                    ->articleClass('prose dark:prose-invert max-w-4xl')
                    ->configureCommonMarkEnvironmentUsing(function (EnvironmentBuilderInterface $environment): EnvironmentBuilderInterface {
                        $environment->addRenderer(Image::class, new PublicAssetImageRenderer, 10);

                        return $environment;
                    })
            )
            ->plugin(
                TracepharmaBreezyCore::make()
                    ->enableTwoFactorAuthentication()
            )
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
            ]);
    }
}
