<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Admin;
use App\Rules\RejectTenantDomainHost;
use App\Support\Auth\Permissions;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\PlatformSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class EpcisHubSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'EPCIS Hub';

    protected static ?string $title = 'EPCIS Hub';

    protected static ?int $navigationSort = 20;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.admin.pages.epcis-hub-settings';

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::CatalogManage);
    }

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->fillForm();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Configure demo, stage, and prod hub edges: tokens, enabled providers, and optional host overrides.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runAggregationLinkFkDoctor')
                ->label('Check aggregation FK drift')
                ->icon(Heroicon::OutlinedHeart)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Check aggregation link FK drift')
                ->modalDescription('Run detect-only doctor across all tenants (no --fix). Results appear on the admin Hub health widget.')
                ->action(function (): void {
                    $exitCode = Artisan::call('tracepharma:doctor-aggregation-link-fk');

                    $output = trim(Artisan::output());

                    $notification = Notification::make()
                        ->body($output !== '' ? $output : 'Inspect complete. See Hub health on the dashboard.');

                    if ($exitCode !== 0) {
                        $notification
                            ->title('Aggregation link FK doctor found drift')
                            ->warning();
                    } else {
                        $notification
                            ->title('Aggregation link FK doctor finished')
                            ->success();
                    }

                    $notification->send();
                }),
        ];
    }

    protected function fillForm(): void
    {
        $config = app(EpcisHubPlatformConfig::class);

        $this->form->fill([
            'demo' => [
                'hub_token' => '',
                'providers' => $config->enabledProviders('demo'),
                'host' => PlatformSettings::get('epcis_hub.demo.host') ?? '',
            ],
            'stage' => [
                'hub_token' => '',
                'providers' => $config->enabledProviders('stage'),
                'host' => PlatformSettings::get('epcis_hub.stage.host') ?? '',
            ],
            'prod' => [
                'hub_token' => '',
                'providers' => $config->enabledProviders('prod'),
                'host' => PlatformSettings::get('epcis_hub.prod.host') ?? '',
            ],
            'demo_url_systech' => $config->hubUrl('demo', 'systech'),
            'demo_url_unitrace' => $config->hubUrl('demo', 'unitrace'),
            'stage_url_systech' => $config->hubUrl('stage', 'systech'),
            'stage_url_unitrace' => $config->hubUrl('stage', 'unitrace'),
            'prod_url_systech' => $config->hubUrl('prod', 'systech'),
            'prod_url_unitrace' => $config->hubUrl('prod', 'unitrace'),
        ]);
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
                $this->environmentSection('demo', 'Demo'),
                $this->environmentSection('stage', 'Stage'),
                $this->environmentSection('prod', 'Prod'),
            ]);
    }

    private function environmentSection(string $environment, string $label): Section
    {
        return Section::make($label)
            ->compact()
            ->columns(['md' => 2])
            ->schema([
                TextInput::make("{$environment}.hub_token")
                    ->label('Hub token')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Leave blank to keep the existing token.')
                    ->columnSpanFull(),
                CheckboxList::make("{$environment}.providers")
                    ->label('Enabled providers')
                    ->options([
                        'systech' => 'Systech',
                        'unitrace' => 'UniTrace',
                    ])
                    ->columns(2)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, callable $set) => $this->syncHubUrlFields($environment, $get, $set)),
                TextInput::make("{$environment}.host")
                    ->label('Host override')
                    ->placeholder(fn (): string => app(EpcisHubPlatformConfig::class)->host($environment))
                    ->helperText('Optional. Blank uses the configured default host.')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, callable $set) => $this->syncHubUrlFields($environment, $get, $set))
                    ->rules([new RejectTenantDomainHost])
                    ->maxLength(255),
                TextInput::make("{$environment}_url_systech")
                    ->label('Systech hub URL')
                    ->disabled()
                    ->dehydrated(false)
                    ->copyable()
                    ->visible(fn (Get $get): bool => in_array('systech', $get("{$environment}.providers") ?? [], true))
                    ->columnSpanFull(),
                TextInput::make("{$environment}_url_unitrace")
                    ->label('UniTrace hub URL')
                    ->disabled()
                    ->dehydrated(false)
                    ->copyable()
                    ->visible(fn (Get $get): bool => in_array('unitrace', $get("{$environment}.providers") ?? [], true))
                    ->columnSpanFull(),
            ]);
    }

    private function syncHubUrlFields(string $environment, Get $get, callable $set): void
    {
        $override = $get("{$environment}.host");
        $host = is_string($override) && trim($override) !== ''
            ? strtolower(trim($override))
            : app(EpcisHubPlatformConfig::class)->host($environment);

        $set("{$environment}_url_systech", 'https://'.$host.'/api/webhooks/epcis/hub/systech');
        $set("{$environment}_url_unitrace", 'https://'.$host.'/api/webhooks/epcis/hub/unitrace');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $config = app(EpcisHubPlatformConfig::class);

        foreach (EpcisHubPlatformConfig::ENVIRONMENTS as $environment) {
            $envData = is_array($data[$environment] ?? null) ? $data[$environment] : [];

            if (filled($envData['hub_token'] ?? null)) {
                $config->setHubToken($environment, (string) $envData['hub_token']);
            }

            $providers = $envData['providers'] ?? [];
            $config->setProviders(
                $environment,
                is_array($providers) ? array_values($providers) : [],
            );

            $host = $envData['host'] ?? null;
            $config->setHost(
                $environment,
                is_string($host) ? $host : null,
            );
        }

        Notification::make()
            ->title('EPCIS hub settings saved')
            ->success()
            ->send();

        $this->fillForm();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }
}
