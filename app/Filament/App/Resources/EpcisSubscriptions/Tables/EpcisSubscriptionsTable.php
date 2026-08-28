<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EpcisSubscriptions\Tables;

use App\Models\Epcis\EpcisSubscription;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EpcisSubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('target_url')->limit(40)->tooltip(fn (EpcisSubscription $record): string => (string) $record->target_url),
                TextColumn::make('directions')->badge(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('last_delivered_at')->dateTime()->placeholder('—'),
                TextColumn::make('last_error')->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('rotateSecret')
                    ->label('Rotate secret')
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
                    ->action(function (EpcisSubscription $record): void {
                        try {
                            \App\Support\Epcis\EpcisSubscriptionUrl::assertSafeAtConnect((string) $record->target_url);
                            $body = json_encode([
                                'ping' => true,
                                'subscription_id' => $record->getKey(),
                                'message' => 'TracePharma EPCIS subscription connectivity test',
                            ], JSON_THROW_ON_ERROR);
                            $timestamp = (string) now()->timestamp;
                            $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $record->secret);
                            $response = Http::timeout(10)
                                ->withHeaders([
                                    'Content-Type' => 'application/json',
                                    'X-TracePharma-Signature' => 't='.$timestamp.',v1='.$signature,
                                    'X-TracePharma-Trigger' => 'ping',
                                ])
                                ->withBody($body, 'application/json')
                                ->post((string) $record->target_url);

                            if ($response->successful()) {
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
