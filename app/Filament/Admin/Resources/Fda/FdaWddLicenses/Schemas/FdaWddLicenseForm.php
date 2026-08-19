<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddLicenses\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FdaWddLicenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('license_number')->required()->maxLength(100),
            TextInput::make('jurisdiction')->required()->maxLength(2),
            DatePicker::make('expiration_date')->native(false),
            TextInput::make('reporting_year')->numeric(),
            Toggle::make('is_active')->inline(false),
        ]);
    }
}
