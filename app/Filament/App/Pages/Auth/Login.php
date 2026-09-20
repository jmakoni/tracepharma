<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Auth;

use App\Filament\Auth\Concerns\AuthenticatesWithAccountSecurity;
use App\Services\Auth\Oidc\OidcAuthenticator;
use Filament\Actions\Action;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;

class Login extends BaseLogin
{
    use AuthenticatesWithAccountSecurity;

    protected string $view = 'filament.app.pages.auth.login';

    protected static string $layout = 'layouts.floor-guest';

    /**
     * @var array<string, string>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-guest-login',
    ];

    public function hasLogo(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        if ($this->ssoOnly()) {
            return $schema->components([]);
        }

        return parent::form($schema);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getSsoActionsSchema(),
                $this->getFormContentComponent(),
                $this->getMultiFactorChallengeFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        if ($this->ssoOnly()) {
            return Form::make([])->hidden();
        }

        return parent::getFormContentComponent();
    }

    /**
     * @return list<Action>
     */
    protected function getSsoActions(): array
    {
        $config = app(OidcAuthenticator::class)->tenantConfig();

        if ($config === null || ! $config->isConfigured()) {
            return [];
        }

        return [
            Action::make('oidcLogin')
                ->label('Sign in with '.$config->provider->label())
                ->icon(Heroicon::OutlinedShieldCheck)
                ->url(route('tenant.oidc.redirect'))
                ->color('gray'),
        ];
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()
            ->extraAttributes([
                'class' => 'tp-login-submit-btn btn btn-primary btn-block w-full',
            ]);
    }

    protected function getSsoActionsSchema(): Component
    {
        $actions = $this->getSsoActions();

        if ($actions === []) {
            return Actions::make([])->hidden();
        }

        return Actions::make($actions)
            ->alignment(Alignment::Center)
            ->visible(fn (): bool => blank($this->userUndertakingMultiFactorAuthentication));
    }

    protected function ssoOnly(): bool
    {
        $config = app(OidcAuthenticator::class)->tenantConfig();

        return $config !== null && $config->isConfigured() && $config->ssoOnly;
    }
}
