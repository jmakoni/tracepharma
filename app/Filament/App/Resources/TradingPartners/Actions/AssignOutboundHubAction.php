<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\TradingPartners\Actions;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\OutboundTransport;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\OutboundConnection;
use App\Models\TradingPartner;
use App\Support\Integrations\OutboundConnectionKindValidator;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

final class AssignOutboundHubAction
{
    public static function make(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('assignOutboundHub')
                ->label('Send files through')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->modalHeading('Send files through')
                ->modalDescription('Assign this customer to an existing network connection, or open Outbound connections to create one.')
                ->form([
                    Select::make('outbound_connection_id')
                        ->label('Network connection')
                        ->options(fn (): array => OutboundConnection::query()
                            ->where('is_active', true)
                            ->where('approval_status', ConnectionApprovalStatus::Approved->value)
                            ->whereIn('transport', [
                                OutboundTransport::Https->value,
                                OutboundTransport::Sftp->value,
                                OutboundTransport::As2->value,
                                OutboundTransport::Portal->value,
                                OutboundTransport::Email->value,
                            ])
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->helperText('Moving this customer removes them from any other connection in the same Test/Live band.'),
                ])
                ->action(function (array $data, TradingPartner $record): void {
                    $connection = OutboundConnection::query()->find((int) ($data['outbound_connection_id'] ?? 0));
                    if (! $connection instanceof OutboundConnection) {
                        Notification::make()->title('Connection not found')->danger()->send();

                        return;
                    }

                    $partnerId = (int) $record->getKey();

                    try {
                        // Detach from other B2B hubs in the same conformance band.
                        $band = OutboundConnectionKindValidator::conformanceBand($connection->conformanceState());
                        OutboundConnection::query()
                            ->whereKeyNot($connection->getKey())
                            ->where('is_active', true)
                            ->whereIn('conformance_state', $band)
                            ->whereIn('transport', [
                                OutboundTransport::Https->value,
                                OutboundTransport::Sftp->value,
                                OutboundTransport::As2->value,
                            ])
                            ->whereHas('tradingPartners', fn ($q) => $q->where('trading_partners.id', $partnerId))
                            ->each(function (OutboundConnection $other) use ($partnerId): void {
                                $ids = $other->tradingPartners()
                                    ->pluck('trading_partners.id')
                                    ->map(fn ($id): int => (int) $id)
                                    ->reject(fn (int $id): bool => $id === $partnerId)
                                    ->values()
                                    ->all();
                                $other->syncPartners($ids);
                            });

                        $ids = $connection->tradingPartners()
                            ->pluck('trading_partners.id')
                            ->map(fn ($id): int => (int) $id)
                            ->push($partnerId)
                            ->unique()
                            ->values()
                            ->all();

                        $connection->syncPartners($ids);
                        $connection->assertAssignableConfiguration();

                        Notification::make()
                            ->title('Connection updated')
                            ->body($record->name.' now sends via '.$connection->name.'.')
                            ->success()
                            ->send();
                    } catch (DomainException $e) {
                        Notification::make()
                            ->title('Could not assign connection')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->extraModalFooterActions([
                    Action::make('createHub')
                        ->label('Create outbound connection')
                        ->url(OutboundConnectionResource::getUrl('create'))
                        ->openUrlInNewTab(),
                ]),
            'trading_partner_assign_outbound_hub',
            requireReason: false,
        );
    }
}
