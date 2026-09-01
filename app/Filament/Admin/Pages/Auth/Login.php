<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

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
        $config = app(OidcAuthenticator::class)->adminConfig();

        if ($config === null || ! $config->isConfigured()) {
            return [];
        }

        return [
            Action::make('oidcLogin')
                ->label('Sign in with '.$config->provider->label())
                ->icon(Heroicon::OutlinedShieldCheck)
                ->url(route('admin.oidc.redirect'))
                ->color('gray'),
        ];
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
        $config = app(OidcAuthenticator::class)->adminConfig();

        return $config !== null && $config->isConfigured() && $config->ssoOnly;
    }
}
