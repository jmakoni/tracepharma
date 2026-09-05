<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EpcisSubscriptions\Schemas;

use App\Models\Epcis\EpcisSubscription;
use App\Support\Epcis\EpcisSubscriptionUrl;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EpcisSubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Webhook subscription')
                    ->description('HTTPS callbacks fire when matching documents are validated (inbound) or sent (outbound). This is not a timed GS1 Query Control poller.')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('target_url')
                            ->label('HTTPS target URL')
                            ->required()
                            ->url()
                            ->maxLength(2048)
                            ->helperText('HTTPS only. Private/loopback hosts are rejected.')
                            ->rules([
                                fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                    try {
                                        EpcisSubscriptionUrl::assertSafeTargetUrl(is_string($value) ? $value : null);
                                    } catch (\InvalidArgumentException $exception) {
                                        $fail($exception->getMessage());
                                    }
                                },
                            ]),
                        Select::make('directions')
                            ->options([
                                EpcisSubscription::DIRECTION_INBOUND => 'Inbound (validated)',
                                EpcisSubscription::DIRECTION_OUTBOUND => 'Outbound (sent)',
                                EpcisSubscription::DIRECTION_BOTH => 'Both',
                            ])
                            ->required()
                            ->default(EpcisSubscription::DIRECTION_BOTH),
                        TagsInput::make('biz_step_filter')
                            ->label('BizStep filter (optional)')
                            ->helperText('Canonical CBV URN or short name. Empty = all bizSteps.')
                            ->placeholder('urn:epcglobal:cbv:bizstep:shipping'),
                        Toggle::make('is_active')
                            ->default(true),
                        TextInput::make('format')
                            ->disabled()
                            ->dehydrated()
                            ->default(EpcisSubscription::FORMAT_JSONLD_20),
                    ])
                    ->columns(2),
                Section::make('GS1 compatibility fields')
                    ->description('Accepted via the GS1 subscribe API for partner tooling. Stored only — schedule is not cron-executed; query params beyond EQ_bizStep (mapped into bizStep filter at subscribe) are not applied at delivery.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('query_name')
                            ->label('Query name')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('schedule')
                            ->label('Schedule (stored, not executed)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Present when created via GS1 subscribe API. Delivery remains document-event driven.'),
                        Textarea::make('query_params')
                            ->label('Query params (stored)')
                            ->disabled()
                            ->dehydrated(false)
                            ->rows(3)
                            ->formatStateUsing(fn (mixed $state): string => is_array($state)
                                ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : ''),
                    ])
                    ->columns(2),
            ]);
    }
}
