<?php

namespace App\Providers;

use App\Domain\Epcis\Validation\ValidationPipeline;
use App\Listeners\LogTenantUserImpersonationEnded;
use App\Models\Admin;
use App\Policies\ActivityPolicy;
use App\Policies\RolePolicy;
use App\Services\Auth\Oidc\GenericOpenIdConnectProvider;
use App\Services\Epcis\ConnectionOutboundEpcisTransmitter;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Services\Vrs\Contracts\VrsClient;
use App\Services\Vrs\FakeVrsClient;
use App\Services\Vrs\HttpVrsClient;
use App\Services\Vrs\NullVrsClient;
use App\Support\Places\HttpPlacesClient;
use App\Support\Places\PlacesClient;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Azure\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PlacesClient::class, HttpPlacesClient::class);
        $this->app->bind(OutboundEpcisTransmitter::class, ConnectionOutboundEpcisTransmitter::class);
        $this->app->bind(ValidationPipeline::class, fn (): ValidationPipeline => ValidationPipeline::default());
        $this->app->bind(VrsClient::class, function ($app): VrsClient {
            return match (config('vrs.driver', 'null')) {
                'http' => $app->make(HttpVrsClient::class),
                'fake' => $app->make(FakeVrsClient::class),
                default => $app->make(NullVrsClient::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep RapidAPI Local Business Data under a conservative request rate.
        RateLimiter::for('places-backfill', function () {
            return Limit::perMinute((int) config('services.places.rate_per_minute', 30));
        });

        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(120)->by($request->getHost().'|'.($request->ip() ?: 'unknown'));
        });

        RateLimiter::for('marketing-leads', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip() ?: 'marketing-leads');
        });

        $this->configureFilamentActions();

        Gate::define('command-center:access', fn ($user) => $user instanceof Admin);
        Gate::define('command-center:manage-commands', fn ($user) => $user instanceof Admin);
        Gate::define('command-center:prune-history', fn ($user) => $user instanceof Admin);

        // Role-based menus disabled: every authenticated admin passes all
        // permission checks (nav gates and admin policies). Temporary until
        // the admin role matrix is seeded; see tracepharma.role_based_menus.
        if (! config('tracepharma.role_based_menus', false)) {
            Gate::before(fn ($user, string $ability) => $user instanceof Admin ? true : null);
        }

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);

        Event::listen(Logout::class, LogTenantUserImpersonationEnded::class);

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('azure', Provider::class);
            $event->extendSocialite('okta', \SocialiteProviders\Okta\Provider::class);
            $event->extendSocialite('generic-oidc', GenericOpenIdConnectProvider::class);
        });

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => implode('', [
                '<link rel="stylesheet" href="'.e($this->versionedPublicCss('css/tracepharma-filament.css')).'" data-navigate-track />',
                '<link rel="stylesheet" href="'.e($this->versionedPublicCss('css/filament/daisy-filament-bridge.css')).'" data-navigate-track />',
            ]),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => view('filament.partials.daisy-theme-sync')->render(),
        );
    }

    /**
     * Icons for common actions; submit/save/create buttons use primary + icon;
     * action labels use HasLabel override (Str::ucwords).
     */
    private function configureFilamentActions(): void
    {
        Action::configureUsing(function (Action $parentAction): void {
            $name = $parentAction->getName();

            if (in_array($name, ['submit', 'save', 'create', 'createAnother'], true)) {
                $parentAction->color('primary');
            }

            if (! $parentAction->hasIcon()) {
                $icon = match ($name) {
                    'create' => Heroicon::OutlinedPlus,
                    'createAnother' => Heroicon::OutlinedPlusCircle,
                    'save', 'submit' => Heroicon::OutlinedCheck,
                    'cancel' => Heroicon::OutlinedXMark,
                    'edit' => Heroicon::OutlinedPencilSquare,
                    'delete' => Heroicon::OutlinedTrash,
                    'view' => Heroicon::OutlinedEye,
                    'open' => Heroicon::OutlinedArrowTopRightOnSquare,
                    default => null,
                };

                if ($icon !== null) {
                    $parentAction->icon($icon);
                }
            }

            // Modal footer submit is built after configureUsing and may reset color —
            // force primary + an icon appropriate to the parent action.
            // Filament injects the footer action as named parameter `action` — keep that name.
            $parentAction->modalSubmitAction(function (Action $action) use ($parentAction): Action {
                $icon = match (true) {
                    $parentAction instanceof DeleteAction,
                    $parentAction instanceof ForceDeleteAction => Heroicon::OutlinedTrash,
                    $parentAction instanceof CreateAction => Heroicon::OutlinedPlus,
                    $parentAction instanceof EditAction => Heroicon::OutlinedCheck,
                    default => Heroicon::OutlinedCheck,
                };

                return $action
                    ->color('primary')
                    ->icon($icon);
            });
        }, isImportant: true);

        CreateAction::configureUsing(function (CreateAction $parentAction): void {
            $parentAction->createAnotherAction(fn (Action $action): Action => $action
                ->color('primary')
                ->icon(Heroicon::OutlinedPlusCircle));
        }, isImportant: true);

        ActionGroup::configureUsing(function (ActionGroup $group): void {
            $resolved = $group->getLabel();

            if (is_string($resolved)) {
                $group->label($resolved);
            }
        });

        BulkActionGroup::configureUsing(function (BulkActionGroup $group): void {
            $group->icon(Heroicon::EllipsisHorizontal);
        });
    }

    /**
     * Public CSS under /public with filemtime cache-busting query string.
     */
    private function versionedPublicCss(string $relativePath): string
    {
        $absolute = public_path($relativePath);
        $version = is_file($absolute) ? (string) filemtime($absolute) : (string) time();

        return asset($relativePath).'?v='.$version;
    }
}
