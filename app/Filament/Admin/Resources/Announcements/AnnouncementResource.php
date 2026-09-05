<?php

namespace App\Filament\Admin\Resources\Announcements;

use App\Enums\AnnouncementFanOutStatus;
use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Admin\Resources\Announcements\Pages\ViewAnnouncement;
use App\Filament\Admin\Resources\Announcements\Schemas\AnnouncementForm;
use App\Filament\Admin\Resources\Announcements\Tables\AnnouncementsTable;
use App\Models\Admin;
use App\Models\Announcement;
use App\Support\Auth\Permissions;
use App\Support\Filament\ProseContent;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static ?string $slug = 'announcements';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?string $modelLabel = 'Announcement';

    protected static ?string $pluralModelLabel = 'Announcements';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::AdminsManage);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Announcement
            && $record->status === AnnouncementStatus::Draft
            && static::canViewAny();
    }

    /**
     * @return Builder<Announcement>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount([
                'tenants',
                'tenants as fan_out_succeeded_count' => fn (Builder $query): Builder => $query
                    ->where('announcement_tenant.fan_out_status', AnnouncementFanOutStatus::Succeeded->value),
                'tenants as fan_out_failed_count' => fn (Builder $query): Builder => $query
                    ->where('announcement_tenant.fan_out_status', AnnouncementFanOutStatus::Failed->value),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return AnnouncementForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Announcement')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('title')
                        ->columnSpanFull(),
                    TextEntry::make('body')
                        ->formatStateUsing(fn (?string $state): string => filled($state)
                            ? (ProseContent::toHtml($state) ?? e($state))
                            : '—')
                        ->html()
                        ->columnSpanFull(),
                    TextEntry::make('severity')
                        ->badge()
                        ->formatStateUsing(fn (AnnouncementSeverity $state): string => ucfirst($state->value)),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (AnnouncementStatus $state): string => ucfirst($state->value)),
                    TextEntry::make('starts_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('ends_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('published_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('retired_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('tenants.name')
                        ->label('Tenants')
                        ->badge()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return AnnouncementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'view' => ViewAnnouncement::route('/{record}'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
