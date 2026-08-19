<?php

namespace App\Filament\Admin\Support;

use App\Models\Fda\FdaProduct;
use Filament\Forms\Components\Select;

final class FdaProductSelect
{
    public static function make(): Select
    {
        return Select::make('fda_product_id')
            ->label('FDA product')
            ->relationship(
                name: 'fdaProduct',
                titleAttribute: 'product_ndc',
                modifyQueryUsing: fn ($query) => $query->prescription()->orderBy('product_ndc'),
            )
            ->getOptionLabelFromRecordUsing(fn (FdaProduct $record): string => self::formatLabel($record))
            ->searchable(['product_ndc', 'brand_name', 'generic_name', 'product_id'])
            ->preload(false)
            ->searchDebounce(500)
            ->nullable()
            ->native(false);
    }

    public static function formatLabel(FdaProduct $record): string
    {
        return trim(($record->product_ndc ?? '').' — '.($record->brand_name ?? $record->generic_name ?? $record->product_id));
    }
}
