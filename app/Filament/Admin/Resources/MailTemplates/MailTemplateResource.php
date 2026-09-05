<?php

namespace App\Filament\Admin\Resources\MailTemplates;

use App\Filament\Admin\Resources\MailTemplates\Pages\EditMailTemplate;
use App\Filament\Admin\Resources\MailTemplates\Pages\ListMailTemplates;
use App\Filament\Admin\Resources\MailTemplates\Schemas\MailTemplateForm;
use App\Filament\Admin\Resources\MailTemplates\Tables\MailTemplatesTable;
use App\Models\Admin;
use App\Models\MailTemplate;
use App\Support\Auth\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MailTemplateResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = MailTemplate::class;

    protected static ?string $slug = 'mail-templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Mail templates';

    protected static ?string $modelLabel = 'Mail template';

    protected static ?string $pluralModelLabel = 'Mail templates';

    protected static ?string $recordTitleAttribute = 'key';

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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return MailTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MailTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailTemplates::route('/'),
            'edit' => EditMailTemplate::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'platform.mail-templates';
    }
}
