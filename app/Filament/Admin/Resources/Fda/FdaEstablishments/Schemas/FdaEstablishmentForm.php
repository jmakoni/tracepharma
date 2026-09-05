<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\Schemas;

use App\Filament\Admin\Support\FdaOrganizationSelect;
use App\Support\Gs1\GlnRules;
use App\Support\Gs1\SglnRules;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaEstablishmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    FdaOrganizationSelect::make(),
                    TextInput::make('fei_number')->label('FEI')->unique(ignoreRecord: true)->maxLength(20),
                    TextInput::make('name')->maxLength(255),
                    TextInput::make('firm_name')->required()->maxLength(255),
                    TextInput::make('code')->unique(ignoreRecord: true)->maxLength(255),
                    GlnRules::input()->unique(ignoreRecord: true),
                    SglnRules::input(),
                    TextInput::make('duns_number')->label('DUNS')->maxLength(14),
                    TextInput::make('dea_number')->label('DEA')->maxLength(20),
                    TextInput::make('hin_number')->label('HIN')->maxLength(20),
                    TextInput::make('chemical_reg_number')->label('Chemical Reg')->maxLength(30),
                    Toggle::make('is_currently_registered')->inline(false),
                    Toggle::make('is_active')->inline(false)->default(true),
                    DatePicker::make('expiration_date')->native(false),
                    Toggle::make('exclusion_flag')->inline(false),
                ]),
            Section::make('Address')
                ->columns(2)
                ->schema([
                    TextInput::make('street_address')->maxLength(255),
                    TextInput::make('street_address_2')->maxLength(255),
                    TextInput::make('city')->maxLength(100),
                    TextInput::make('state_province')->label('State / province')->maxLength(64),
                    TextInput::make('postal_code')->maxLength(20),
                    TextInput::make('country_code')->default('US')->maxLength(2),
                    TextInput::make('full_address')->columnSpanFull(),
                ]),
            Section::make('Contacts')
                ->columns(2)
                ->schema([
                    TextInput::make('establishment_contact_name')->maxLength(255),
                    TextInput::make('establishment_contact_email')->email()->maxLength(255),
                    TextInput::make('registrant_contact_name')->maxLength(255),
                    TextInput::make('registrant_contact_email')->email()->maxLength(255),
                    Textarea::make('agent_details')->columnSpanFull()->rows(3),
                ]),
        ]);
    }
}
