<?php

namespace App\Filament\App\Resources\Fda3911Reports\Schemas;

use App\Enums\Fda3911Classification;
use App\Models\TradingPartner;
use App\Support\Auth\SiteAccess;
use App\Support\Filament\ProseEditor;
use App\Support\Gs1\GlnRules;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class Fda3911ReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Notification')
                    ->schema([
                        Select::make('classification')
                            ->options(collect(Fda3911Classification::cases())->mapWithKeys(
                                fn (Fda3911Classification $classification): array => [
                                    $classification->value => $classification->label(),
                                ]
                            ))
                            ->required(),
                        DateTimePicker::make('determined_at')
                            ->label('Date product determined illegitimate')
                            ->required(),
                        Select::make('trading_partner_id')
                            ->label('Manufacturer / supplier')
                            ->options(fn (): array => TradingPartner::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
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
                    ])
                    ->columns(2),
                Section::make('Notifier')
                    ->schema([
                        TextInput::make('notifier_name')->required()->maxLength(255),
                        TextInput::make('notifier_title')->maxLength(255),
                        TextInput::make('notifier_phone')->tel()->maxLength(50),
                        TextInput::make('notifier_email')->email()->required()->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Facility')
                    ->schema([
                        TextInput::make('facility_name')->required()->maxLength(255),
                        GlnRules::input('facility_gln'),
                        Textarea::make('facility_address')->rows(2)->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Product')
                    ->schema([
                        TextInput::make('product_ndc')->label('NDC')->maxLength(20),
                        TextInput::make('product_name')->required()->maxLength(255),
                        TextInput::make('product_gtin')->label('GTIN')->maxLength(14),
                        TextInput::make('lot')->maxLength(255),
                        TextInput::make('serial')->maxLength(255),
                        TextInput::make('strength')->maxLength(255),
                        TextInput::make('dosage_form')->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Circumstances')
                    ->schema([
                        ProseEditor::make('circumstances')
                            ->label('Description of circumstances')
                            ->required(),
                    ]),
            ]);
    }
}
