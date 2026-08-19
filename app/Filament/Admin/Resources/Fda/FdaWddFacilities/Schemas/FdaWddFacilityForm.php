<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\Schemas;

use App\Enums\FacilityType;
use App\Filament\Admin\Support\FdaOrganizationSelect;
use App\Support\Gs1\GlnRules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaWddFacilityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    FdaOrganizationSelect::make(),
                    TextInput::make('name')->maxLength(255),
                    TextInput::make('facility_name')->maxLength(255),
                    TextInput::make('alternate_name')->maxLength(255),
                    Select::make('facility_type')
                        ->options(collect(FacilityType::cases())->mapWithKeys(
                            fn (FacilityType $type) => [$type->value => $type->label()]
                        ))
                        ->required()
                        ->native(false),
                    GlnRules::input()->unique(ignoreRecord: true),
                    TextInput::make('code')->maxLength(255),
                    Toggle::make('is_active')->inline(false),
                    TextInput::make('contact_person')->maxLength(255),
                    TextInput::make('contact_email')->email()->maxLength(255),
                    TextInput::make('contact_phone')->tel()->maxLength(50),
                ]),
            Section::make('Address')
                ->columns(2)
                ->schema([
                    TextInput::make('street_address')->maxLength(255),
                    TextInput::make('street_address_2')->maxLength(255),
                    TextInput::make('city')->maxLength(100),
                    TextInput::make('state_province')->label('State / province')->maxLength(2),
                    TextInput::make('postal_code')->maxLength(20),
                    TextInput::make('country_code')->maxLength(3),
                    TextInput::make('full_address')->columnSpanFull(),
                ]),
        ]);
    }
}
