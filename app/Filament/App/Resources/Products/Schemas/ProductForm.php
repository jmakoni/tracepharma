<?php

namespace App\Filament\App\Resources\Products\Schemas;

use App\Filament\App\Support\FdaPicker;
use App\Support\Gs1\Gtin;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Source')
                    ->compact()
                    ->columns(1)
                    ->schema([
                        ...FdaPicker::packaging(),
                        Hidden::make('fda_product_id'),
                    ]),
                Section::make('Identity')
                    ->compact()
                    ->columns(['md' => 2, 'lg' => 3])
                    ->schema([
                        TextInput::make('gtin')
                            ->label('GTIN')
                            ->required()
                            ->length(14)
                            ->rule(static function (): Closure {
                                return static function (string $attribute, mixed $value, Closure $fail): void {
                                    if (! is_string($value) || $value === '') {
                                        return;
                                    }

                                    if (! preg_match('/^\d{14}$/', $value)) {
                                        $fail('The GTIN must be 14 digits.');

                                        return;
                                    }

                                    if (substr($value, 13, 1) !== Gtin::checkDigit(substr($value, 0, 13))) {
                                        $fail('The GTIN check digit is invalid — re-scan or re-key the GTIN-14.');
                                    }
                                };
                            })
                            ->helperText('GTIN-14 including the packaging indicator digit and a valid GS1 check digit.')
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('dosage_form')->maxLength(255),
                        TextInput::make('strength')->maxLength(255),
                        Select::make('trading_partner_id')
                            ->label('Manufacturer')
                            ->relationship('tradingPartner', 'name')
                            ->searchable()
                            ->preload()
                            ->searchDebounce(500)
                            ->nullable()
                            ->native(false),
                        TextInput::make('ndc')->label('NDC')->maxLength(255),
                        TextInput::make('package_ndc')->label('Package NDC')->maxLength(50),
                        Toggle::make('is_active')->default(true),
                    ]),
            ]);
    }
}
