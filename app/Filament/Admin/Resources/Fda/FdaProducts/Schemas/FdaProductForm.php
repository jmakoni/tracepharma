<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\Schemas;

use App\Filament\Admin\Support\FdaOrganizationSelect;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    TextInput::make('product_ndc')->label('NDC')->maxLength(20),
                    TextInput::make('product_id')->label('Product ID')->required()->maxLength(150),
                    FdaOrganizationSelect::make(required: false),
                    TextInput::make('name')->maxLength(255),
                    TextInput::make('brand_name')->maxLength(255),
                    TextInput::make('generic_name')->maxLength(255),
                    Toggle::make('is_active')->inline(false),
                ]),
            Section::make('Regulatory')
                ->columns(2)
                ->schema([
                    TextInput::make('dea_schedule')->label('DEA')->maxLength(10),
                    TextInput::make('dosage_form')->maxLength(100),
                    TextInput::make('strength')->maxLength(255),
                    TextInput::make('marketing_category')->maxLength(255),
                    TextInput::make('application_number')->maxLength(50),
                    TextInput::make('product_type')->maxLength(100),
                    DatePicker::make('marketing_start_date')->native(false),
                    DatePicker::make('listing_expiration_date')->native(false),
                ]),
        ]);
    }
}
