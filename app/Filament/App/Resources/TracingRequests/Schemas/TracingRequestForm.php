<?php

namespace App\Filament\App\Resources\TracingRequests\Schemas;

use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Support\Auth\SiteAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class TracingRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('requestor_type')
                            ->label('Requestor type')
                            ->options(collect(TracingRequestorType::cases())->mapWithKeys(
                                fn (TracingRequestorType $type): array => [$type->value => $type->label()]
                            ))
                            ->default(TracingRequestorType::Internal->value)
                            ->required(),
                        Select::make('status')
                            ->options(collect(TracingRequestStatus::cases())->mapWithKeys(
                                fn (TracingRequestStatus $status): array => [$status->value => $status->label()]
                            ))
                            ->default(TracingRequestStatus::Open->value)
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('scope')
                            ->options(collect(TracingRequestScope::cases())->mapWithKeys(
                                fn (TracingRequestScope $scope): array => [$scope->value => $scope->label()]
                            ))
                            ->default(TracingRequestScope::SingleProduct->value)
                            ->live()
                            ->required(),
                        TextInput::make('gtin')
                            ->label('GTIN')
                            ->maxLength(14),
                        TextInput::make('serial')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('scope') === TracingRequestScope::SingleProduct->value),
                        TextInput::make('lot')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('scope') === TracingRequestScope::Lot->value),
                        DatePicker::make('expiry')
                            ->visible(fn (Get $get): bool => $get('scope') === TracingRequestScope::Lot->value),
                        Select::make('exception_id')
                            ->label('Linked exception')
                            ->relationship(
                                name: 'exceptionCase',
                                titleAttribute: 'title',
                                modifyQueryUsing: fn (Builder $query): Builder => SiteAccess::constrainExceptionCases($query),
                            )
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Toggle::make('is_recall')
                            ->label('Recall request'),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
