<?php

namespace App\Filament\App\Resources\TradingPartners\RelationManagers;

use App\Filament\Support\PartnerContactTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class ContactRelationManager extends RelationManager
{
    protected static string $relationship = 'contactCard';

    protected static ?string $title = 'Contact';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return PartnerContactTable::configure($table);
    }
}
