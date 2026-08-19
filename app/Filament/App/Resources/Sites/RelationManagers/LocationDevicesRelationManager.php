<?php

namespace App\Filament\App\Resources\Sites\RelationManagers;

use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Site;
use App\Support\Catalog\DisplayName;
use App\Support\Gs1\GlnRules;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LocationDevicesRelationManager extends RelationManager
{
    protected static string $relationship = 'locationDevices';

    protected static ?string $title = 'Devices';

    protected static bool $isBadgeDeferred = true;

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Site $ownerRecord */
        return (string) $ownerRecord->locationDevices()->count();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(2),
            GlnRules::input()->required()->unique(ignoreRecord: true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('gln')
                    ->label('GLN')
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('sgln')
                    ->label('SGLN')
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('No location devices')
            ->emptyStateDescription('Add a device to assign a GLN to this site.')
            ->emptyStateActions([
                RegulatoryCompliance::apply(
                    CreateAction::make()->slideOver()->label('Add device'),
                    'sites_device_create',
                    requireReason: false,
                ),
            ])
            ->headerActions([
                RegulatoryCompliance::apply(
                    CreateAction::make()->slideOver()->label('Add device'),
                    'sites_device_create',
                    requireReason: false,
                ),
            ])
            ->recordActions(RecordActionGroup::make([
                RegulatoryCompliance::apply(
                    EditAction::make()->slideOver(),
                    'sites_device_edit',
                    requireReason: false,
                ),
                RegulatoryCompliance::apply(
                    DeleteAction::make(),
                    'sites_device_delete',
                    requireReason: true,
                ),
            ]));
    }
}
