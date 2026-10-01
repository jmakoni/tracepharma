<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EpcisSubscriptions\Tables;

use App\Filament\Notifications\Notification;
use App\Models\Epcis\EpcisSubscription;
use App\Support\Epcis\EpcisSubscriptionUrl;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class EpcisSubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(EpcisSubscription::class, 'name')),
                    ),
                TextColumn::make('target_url')
                    ->limit(40)
                    ->tooltip(fn (EpcisSubscription $record): string => (string) $record->target_url)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('target_url')
                            ->options(fn (): array => DistinctColumnOptions::of(EpcisSubscription::class, 'target_url')),
                    ),
                TextColumn::make('directions')
                    ->badge()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('directions')
                            ->options(fn (): array => DistinctColumnOptions::of(EpcisSubscription::class, 'directions')),
                    ),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
                TextColumn::make('last_delivered_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('last_error')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('last_error')
                            ->options(fn (): array => DistinctColumnOptions::of(EpcisSubscription::class, 'last_error')),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('rotateSecret')
                    ->label('Rotate secret')
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->action(function (EpcisSubscription $record): void {
                        $secret = $record->rotateSecret();
                        Notification::make()
                            ->title('Secret rotated')
                            ->body('Copy now — shown once: '.$secret)
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                Action::make('testPing')
                    ->label('Test ping')
                    ->authorize('update')
                    ->action(function (EpcisSubscription $record): void {
                        try {
                            $targetUrl = (string) $record->target_url;
                            $body = json_encode([
                                'ping' => true,
                                'subscription_id' => $record->getKey(),
                                'message' => 'TracePharma EPCIS subscription connectivity test',
                            ], JSON_THROW_ON_ERROR);
                            $timestamp = (string) now()->timestamp;
                            $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $record->secret);
                            $response = EpcisSubscriptionUrl::httpClient($targetUrl, 10)
                                ->withHeaders([
                                    'Content-Type' => 'application/json',
                                    'X-TracePharma-Signature' => 't='.$timestamp.',v1='.$signature,
                                    'X-TracePharma-Trigger' => 'ping',
                                ])
                                ->withBody($body, 'application/json')
                                ->post($targetUrl);

                            if ($response->redirect()) {
                                Notification::make()
                                    ->title('Ping failed')
                                    ->body('HTTP '.$response->status().' redirect refused (SSRF protection).')
                                    ->danger()
                                    ->send();
                            } elseif ($response->successful()) {
                                Notification::make()->title('Ping succeeded')->success()->send();
                            } else {
                                Notification::make()
                                    ->title('Ping failed')
                                    ->body('HTTP '.$response->status().': '.Str::limit($response->body(), 200))
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Ping failed')
                                ->body(Str::limit($exception->getMessage(), 300))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
