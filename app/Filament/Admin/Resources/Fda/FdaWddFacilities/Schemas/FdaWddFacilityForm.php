<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\Schemas;

use App\Enums\FacilityType;
use App\Filament\Admin\Support\FdaOrganizationSelect;
use App\Filament\Admin\Support\SyncFdaFacilityAddressFingerprint;
use App\Models\Fda\FdaWddFacility;
use App\Support\Gs1\GlnRules;
use App\Support\Gs1\SglnRules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                    TextInput::make('facility_name')->required()->maxLength(255),
                    TextInput::make('alternate_name')->maxLength(255),
                    Select::make('facility_type')
                        ->options(collect(FacilityType::cases())->mapWithKeys(
                            fn (FacilityType $type) => [$type->value => $type->label()]
                        ))
                        ->required()
                        ->native(false),
                    GlnRules::input()->unique(ignoreRecord: true),
                    SglnRules::input(),
                    TextInput::make('duns_number')->label('DUNS')->maxLength(9),
                    TextInput::make('dea_number')->label('DEA')->maxLength(20),
                    TextInput::make('hin_number')->label('HIN')->maxLength(20),
                    TextInput::make('code')->unique(ignoreRecord: true)->maxLength(255),
                    Toggle::make('is_active')->inline(false)->default(true),
                    TextInput::make('contact_person')->maxLength(255),
                    TextInput::make('contact_email')->email()->maxLength(255),
                    TextInput::make('contact_phone')->tel()->maxLength(50),
                ]),
            Section::make('Address')
                ->columns(2)
                ->schema([
                    TextInput::make('street_address')
                        ->required()
                        ->maxLength(255)
                        ->rule(function (Get $get, TextInput $component) {
                            $record = $component->getRecord();

                            return (SyncFdaFacilityAddressFingerprint::uniqueWddAddressRule(
                                $record instanceof FdaWddFacility ? $record : null,
                            ))($get);
                        }),
                    TextInput::make('street_address_2')->maxLength(255),
                    TextInput::make('city')->required()->maxLength(100),
                    TextInput::make('state_province')->label('State / province')->required()->maxLength(2),
                    TextInput::make('postal_code')->required()->maxLength(20),
                    TextInput::make('country_code')->default('US')->maxLength(2),
                    TextInput::make('full_address')->columnSpanFull(),
                ]),
        ]);
    }
}
