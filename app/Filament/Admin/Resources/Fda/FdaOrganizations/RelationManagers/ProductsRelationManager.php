<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\RelationManagers;

use App\Filament\Admin\Resources\Fda\FdaProducts\FdaProductResource;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Products';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('packaging'))
            ->columns([
                FdaRegistryBadges::identifierColumn('product_ndc', 'NDC'),
                TextColumn::make('name')->searchable()->limit(40),
                TextColumn::make('dosage_form')->toggleable(),
                FdaRegistryBadges::productKindColumn(),
                TextColumn::make('packaging_count')->label('Packages'),
                FdaRegistryBadges::activeColumn(),
            ])
            ->recordUrl(fn ($record): string => FdaProductResource::getUrl('view', ['record' => $record]))
            ->recordActions(RecordActionGroup::make([
                ViewAction::make()
                    ->url(fn ($record): string => FdaProductResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn ($record): string => FdaProductResource::getUrl('edit', ['record' => $record])),
            ]));
    }
}
