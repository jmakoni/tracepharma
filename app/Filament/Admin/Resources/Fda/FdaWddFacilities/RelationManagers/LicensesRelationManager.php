<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\RelationManagers;

use App\Filament\Admin\Resources\Fda\FdaWddLicenses\Schemas\FdaWddLicenseForm;
use App\Filament\Admin\Resources\Fda\FdaWddLicenses\Tables\FdaWddLicensesTable;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class LicensesRelationManager extends RelationManager
{
    protected static string $relationship = 'licenses';

    protected static ?string $title = 'Licenses';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return FdaWddLicenseForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return FdaWddLicensesTable::configure($table, standalone: false)
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]));
    }
}
