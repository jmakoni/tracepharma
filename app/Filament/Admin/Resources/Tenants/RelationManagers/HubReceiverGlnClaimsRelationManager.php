<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Actions\Integrations\ClaimTenantHubReceiverGln;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RecordActionGroup;
use App\Models\EpcisHubRoute;
use App\Models\Tenant;
use App\Support\EpcisHub\ClaimableReceiverGlns;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

class HubReceiverGlnClaimsRelationManager extends RelationManager
{
    protected static string $relationship = 'hubRoutes';

    protected static ?string $title = 'Claimed receiver GLNs';

    protected static ?string $recordTitleAttribute = 'gln';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('gln')
                ->label('Receiver GLN')
                ->options(fn (): array => ClaimableReceiverGlns::options($this->ownerTenant()))
                ->searchable()
                ->required()
                ->helperText('Company GLN and org-facility site GLNs. Prefer Sites so hub routing and custody stay aligned.'),
            Select::make('provider')
                ->label('Hub provider')
                ->options(fn (): array => $this->providerOptions())
                ->required()
                ->native(false),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('claimed_via', ClaimTenantHubReceiverGln::VIA_ADMIN))
            ->columns([
                TextColumn::make('gln')
                    ->label('GLN')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('provider')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'tracepharma' => 'TracePharma',
                        'systech' => 'Systech',
                        'unitrace' => 'UniTrace',
                        default => $state,
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('default_inbound_connection_id')
                    ->label('Default connection')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Claim GLN')
                    ->using(function (array $data): EpcisHubRoute {
                        try {
                            return app(ClaimTenantHubReceiverGln::class)->claim(
                                $this->ownerTenant(),
                                (string) $data['provider'],
                                (string) $data['gln'],
                                (bool) ($data['is_active'] ?? true),
                            );
                        } catch (RuntimeException $exception) {
                            Notification::make()
                                ->title('Claim blocked')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions(RecordActionGroup::make([
                EditAction::make()
                    ->using(function (EpcisHubRoute $record, array $data): EpcisHubRoute {
                        try {
                            if (
                                $record->gln !== $data['gln']
                                || $record->provider !== $data['provider']
                            ) {
                                app(ClaimTenantHubReceiverGln::class)->unclaim(
                                    $this->ownerTenant(),
                                    (string) $record->provider,
                                    (string) $record->gln,
                                );
                            }

                            return app(ClaimTenantHubReceiverGln::class)->claim(
                                $this->ownerTenant(),
                                (string) $data['provider'],
                                (string) $data['gln'],
                                (bool) ($data['is_active'] ?? true),
                            );
                        } catch (RuntimeException $exception) {
                            Notification::make()
                                ->title('Claim blocked')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            throw new Halt;
                        }
                    }),
                DeleteAction::make()
                    ->using(function (EpcisHubRoute $record): void {
                        app(ClaimTenantHubReceiverGln::class)->unclaim(
                            $this->ownerTenant(),
                            (string) $record->provider,
                            (string) $record->gln,
                        );
                    }),
            ]))
            ->emptyStateHeading('No claimed receiver GLNs')
            ->emptyStateDescription('Claim company or facility GLNs so the hub can route SBDH receiver GLNs to this tenant.');
    }

    private function ownerTenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = $this->getOwnerRecord();

        return $tenant;
    }

    /**
     * @return array<string, string>
     */
    private function providerOptions(): array
    {
        $tenant = $this->ownerTenant();
        $environment = $tenant->inbound_environment;
        $config = app(EpcisHubPlatformConfig::class);
        $enabled = is_string($environment) && in_array($environment, EpcisHubPlatformConfig::ENVIRONMENTS, true)
            ? $config->enabledProviders($environment)
            : [];

        $tenantProviders = is_array($tenant->hub_providers) ? $tenant->hub_providers : [];
        $tenantProviders = array_map(
            static fn ($p) => is_string($p) ? strtolower(trim($p)) : '',
            $tenantProviders,
        );

        $providers = array_values(array_intersect($tenantProviders, $enabled));

        return collect($providers)
            ->mapWithKeys(fn (string $provider): array => [
                $provider => match ($provider) {
                    'tracepharma' => 'TracePharma hub',
                    'systech' => 'Systech',
                    'unitrace' => 'UniTrace',
                    default => $provider,
                },
            ])
            ->all();
    }
}
