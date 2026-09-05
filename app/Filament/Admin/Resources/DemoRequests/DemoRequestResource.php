<?php

namespace App\Filament\Admin\Resources\DemoRequests;

use App\Filament\Admin\Resources\DemoRequests\Pages\ListDemoRequests;
use App\Filament\Admin\Resources\DemoRequests\Pages\ViewDemoRequest;
use App\Models\Admin;
use App\Models\DemoRequest;
use App\Support\Auth\Permissions;
use App\Support\CustomerOnboarding\OrganizationTypeMapper;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class DemoRequestResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = DemoRequest::class;

    protected static ?string $slug = 'demo-requests';

    protected static ?string $navigationLabel = 'Demo requests';

    protected static ?string $modelLabel = 'Demo request';

    protected static ?string $pluralModelLabel = 'Demo requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Tenants';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'company';

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::TenantsManage);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        TextEntry::make('company'),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('role')->placeholder('—'),
                        TextEntry::make('organization_type')
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? (OrganizationTypeMapper::options()[$state] ?? $state)
                                : '—'),
                        TextEntry::make('source'),
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('message')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('company')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('organization_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? (OrganizationTypeMapper::options()[$state] ?? $state)
                        : '—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoRequests::route('/'),
            'view' => ViewDemoRequest::route('/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'tenants.demo-requests';
    }
}
