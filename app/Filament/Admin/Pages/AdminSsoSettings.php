<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Admin;
use App\Support\Auth\OidcProvider;
use App\Support\Auth\Permissions;
use App\Support\PlatformSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class AdminSsoSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Admin SSO';

    protected static ?string $title = 'Admin SSO (OIDC)';

    protected static ?int $navigationSort = 25;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.admin.pages.admin-sso-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::TenantsManage);
    }

    public function mount(): void
    {
        $this->form->fill([
            'enabled' => PlatformSettings::ssoAdminEnabled(),
            'sso_only' => PlatformSettings::ssoAdminOnly(),
            'provider' => PlatformSettings::ssoAdminProvider(),
            'issuer' => PlatformSettings::ssoAdminIssuer(),
            'client_id' => PlatformSettings::ssoAdminClientId(),
            'client_secret' => '',
            'entra_tenant_id' => PlatformSettings::ssoAdminEntraTenantId(),
            'redirect_uri' => 'https://'.config('tracepharma.admin_domain').'/auth/oidc/callback',
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Platform admin Microsoft Entra ID / Okta / OIDC. Admins must already exist — SSO never JIT-creates platform admins.';
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->operation('edit')
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity provider')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Enable admin SSO')
                            ->live(),
                        Toggle::make('sso_only')
                            ->label('SSO only (hide password login)')
                            ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                        Select::make('provider')
                            ->label('Provider')
                            ->options(OidcProvider::options())
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                        TextInput::make('issuer')
                            ->label('Issuer URL')
                            ->url()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                        TextInput::make('client_id')
                            ->label('Client ID')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                        TextInput::make('client_secret')
                            ->label('Client secret')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Leave blank to keep the existing secret.')
                            ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                        TextInput::make('entra_tenant_id')
                            ->label('Entra directory (tenant) ID')
                            ->maxLength(64)
                            ->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('provider') === OidcProvider::Entra->value),
                        TextInput::make('redirect_uri')
                            ->label('Redirect URI (register in IdP)')
                            ->disabled()
                            ->dehydrated(false)
                            ->copyable()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PlatformSettings::saveSsoAdminConfig([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'sso_only' => (bool) ($data['sso_only'] ?? false),
            'provider' => (string) ($data['provider'] ?? OidcProvider::Entra->value),
            'issuer' => (string) ($data['issuer'] ?? ''),
            'client_id' => (string) ($data['client_id'] ?? ''),
            'client_secret' => $data['client_secret'] ?? null,
            'entra_tenant_id' => $data['entra_tenant_id'] ?? null,
        ]);

        Notification::make()
            ->title('Admin SSO settings saved')
            ->success()
            ->send();

        $this->mount();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save')
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }
}
