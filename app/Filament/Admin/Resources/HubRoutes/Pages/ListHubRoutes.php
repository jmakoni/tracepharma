<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\HubRoutes\Pages;

use App\Filament\Admin\Resources\HubRoutes\HubRouteResource;
use App\Services\Epcis\Hub\EpcisHubRouter;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListHubRoutes extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = HubRouteResource::class;

    public function getSubheading(): ?string
    {
        return 'Every receiver GLN claimed on the hub, and the tenant it routes to. CLI equivalent: hub:routes.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testRoute')
                ->label('Test route')
                ->icon('heroicon-o-beaker')
                ->modalHeading('Dry-run hub routing')
                ->modalDescription('Resolve a sender/receiver GLN pair against the hub router without sending a document.')
                ->schema([
                    Select::make('environment')
                        ->options(array_combine(
                            EpcisHubPlatformConfig::ENVIRONMENTS,
                            EpcisHubPlatformConfig::ENVIRONMENTS,
                        ))
                        ->default('demo')
                        ->required(),
                    Select::make('provider')
                        ->options([
                            'tracepharma' => 'TracePharma hub',
                            'systech' => 'Systech hub',
                            'unitrace' => 'Unitrace hub',
                        ])
                        ->required(),
                    TextInput::make('receiver_gln')
                        ->label('Receiver GLN')
                        ->required()
                        ->length(13)
                        ->numeric(),
                    TextInput::make('sender_gln')
                        ->label('Sender GLN (optional)')
                        ->length(13)
                        ->numeric(),
                ])
                ->action(function (array $data): void {
                    $steps = app(EpcisHubRouter::class)->describeResolution(
                        (string) $data['provider'],
                        (string) $data['receiver_gln'],
                        ($data['sender_gln'] ?? '') !== '' ? (string) $data['sender_gln'] : null,
                        (string) $data['environment'],
                    );

                    $failed = collect($steps)->firstWhere('ok', false);

                    $body = collect($steps)
                        ->map(fn (array $step): string => ($step['ok'] ? '✓ ' : '✗ ').$step['step'].' — '.$step['detail'])
                        ->implode("\n");

                    Notification::make()
                        ->title($failed === null ? 'Route resolves' : 'Route blocked: '.$failed['step'])
                        ->body($body)
                        ->color($failed === null ? 'success' : 'danger')
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
