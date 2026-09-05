<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\Schemas;

use App\Enums\PartnerType;
use App\Support\Gs1\GlnRules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaOrganizationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->maxLength(255),
                    TextInput::make('canonical_name')->required()->maxLength(255),
                    TextInput::make('original_name')->required()->maxLength(255),
                    TextInput::make('doing_business_as')->label('DBA')->maxLength(255),
                    Select::make('partner_type')
                        ->options(collect(PartnerType::cases())->mapWithKeys(
                            fn (PartnerType $type) => [$type->value => $type->label()]
                        ))
                        ->native(false),
                    Toggle::make('is_active')->inline(false),
                    GlnRules::input()->unique(ignoreRecord: true),
                    TextInput::make('duns_number')->label('DUNS')->maxLength(14),
                ]),
            Section::make('Contact')
                ->columns(2)
                ->schema([
                    TextInput::make('telephone')->tel()->maxLength(50),
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('fax')->maxLength(50),
                    TextInput::make('website')->url()->maxLength(255),
                ]),
            Section::make('Address')
                ->columns(2)
                ->schema([
                    TextInput::make('street_address')->maxLength(255),
                    TextInput::make('street_address_2')->maxLength(255),
                    TextInput::make('city')->maxLength(100),
                    TextInput::make('state_province')->label('State / province')->maxLength(64),
                    TextInput::make('postal_code')->maxLength(20),
                    TextInput::make('country_code')->maxLength(3),
                    TextInput::make('full_address')->columnSpanFull(),
                ]),
        ]);
    }
}
