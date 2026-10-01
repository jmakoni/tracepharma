<?php

namespace App\Filament\Admin\Resources\ConnectionRequests;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\SerializationProvider;
use App\Filament\Admin\Resources\ConnectionRequests\Actions\ReviewConnectionRequestActions;
use App\Filament\Admin\Resources\ConnectionRequests\Pages\ListConnectionRequests;
use App\Filament\Admin\Resources\ConnectionRequests\Pages\ViewConnectionRequest;
use App\Models\Admin;
use App\Models\ConnectionApprovalRequest;
use App\Models\Tenant;
use App\Support\Auth\Permissions;
use App\Support\Tables\DistinctColumnOptions;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class ConnectionRequestResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = ConnectionApprovalRequest::class;

    protected static ?string $slug = 'connection-requests';

    protected static ?string $navigationLabel = 'Connection requests';

    protected static ?string $modelLabel = 'Connection request';

    protected static ?string $pluralModelLabel = 'Connection requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Tenants / Hub';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'connection_name';

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

    public static function getNavigationBadge(): ?string
    {
        $count = ConnectionApprovalRequest::query()->pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->placeholder('—')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('tenant_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                ConnectionApprovalRequest::class,
                                'tenant_id',
                                Tenant::class,
                            )),
                    ),
                TextColumn::make('direction')
                    ->badge()
                    ->color(fn (string $state): string => $state === ConnectionApprovalRequest::DIRECTION_INBOUND ? 'info' : 'primary')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->columnFilter(ColumnFilter::select()->syncWith('direction')),
                TextColumn::make('connection_name')
                    ->label('Connection')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('connection_name')
                            ->options(fn (): array => DistinctColumnOptions::of(ConnectionApprovalRequest::class, 'connection_name')),
                    ),
                TextColumn::make('provider')
                    ->formatStateUsing(fn (?string $state): string => SerializationProvider::tryFrom((string) $state)?->label() ?? ($state ?? '—'))
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('provider')
                            ->options(fn (): array => DistinctColumnOptions::of(ConnectionApprovalRequest::class, 'provider')),
                    ),
                TextColumn::make('transport')
                    ->formatStateUsing(fn (?string $state): string => $state !== null && $state !== '' ? ucfirst($state) : '—')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('transport')
                            ->options(fn (): array => DistinctColumnOptions::of(ConnectionApprovalRequest::class, 'transport')),
                    ),
                TextColumn::make('counterparty')
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('counterparty')
                            ->options(fn (): array => DistinctColumnOptions::of(ConnectionApprovalRequest::class, 'counterparty')),
                    ),
                TextColumn::make('endpoint_host')
                    ->label('Endpoint host')
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('endpoint_host')
                            ->options(fn (): array => DistinctColumnOptions::of(ConnectionApprovalRequest::class, 'endpoint_host')),
                    ),
                TextColumn::make('requested_by')
                    ->label('Requested by')
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('requested_by')
                            ->options(fn (): array => DistinctColumnOptions::of(ConnectionApprovalRequest::class, 'requested_by')),
                    ),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ConnectionApprovalStatus $state): string => $state->label())
                    ->color(fn (ConnectionApprovalStatus $state): string => $state->color())
                    ->columnFilter(ColumnFilter::select()->syncWith('status')),
                TextColumn::make('created_at')
                    ->label('Requested at')
                    ->dateTime()
                    ->sortable()
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ConnectionApprovalStatus::options()),
                SelectFilter::make('direction')
                    ->options([
                        ConnectionApprovalRequest::DIRECTION_INBOUND => 'Inbound',
                        ConnectionApprovalRequest::DIRECTION_OUTBOUND => 'Outbound',
                    ]),
            ])
            ->recordActions([
                ReviewConnectionRequestActions::approve(),
                ReviewConnectionRequestActions::reject(),
                ReviewConnectionRequestActions::suspend(),
                ReviewConnectionRequestActions::resume(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (ConnectionApprovalStatus $state): string => $state->label())
                            ->color(fn (ConnectionApprovalStatus $state): string => $state->color()),
                        TextEntry::make('direction')
                            ->badge()
                            ->color(fn (string $state): string => $state === ConnectionApprovalRequest::DIRECTION_INBOUND ? 'info' : 'primary')
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                        TextEntry::make('connection_name')
                            ->label('Connection'),
                        TextEntry::make('provider')
                            ->formatStateUsing(fn (?string $state): string => SerializationProvider::tryFrom((string) $state)?->label() ?? ($state ?? '—')),
                        TextEntry::make('transport')
                            ->formatStateUsing(fn (?string $state): string => $state !== null && $state !== '' ? ucfirst($state) : '—'),
                        TextEntry::make('counterparty')
                            ->label('Counterparty')
                            ->placeholder('—'),
                        TextEntry::make('endpoint_host')
                            ->label('Endpoint host')
                            ->placeholder('—'),
                        TextEntry::make('requested_by')
                            ->label('Requested by')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Requested at')
                            ->dateTime(),
                    ])
                    ->columns(2),
                Section::make('Tenant')
                    ->schema([
                        TextEntry::make('tenant.name')
                            ->label('Tenant')
                            ->placeholder('—'),
                        TextEntry::make('tenant_id')
                            ->label('Tenant ID'),
                    ])
                    ->columns(2),
                Section::make('Review')
                    ->schema([
                        TextEntry::make('reviewedBy.name')
                            ->label('Reviewed by')
                            ->placeholder('—'),
                        TextEntry::make('reviewed_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('review_note')
                            ->label('Review note')
                            ->placeholder('—')
                            ->visible(fn (ConnectionApprovalRequest $record): bool => in_array($record->status, [
                                ConnectionApprovalStatus::Rejected,
                                ConnectionApprovalStatus::Suspended,
                            ], true))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnectionRequests::route('/'),
            'view' => ViewConnectionRequest::route('/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'tenants.connection-requests';
    }
}
