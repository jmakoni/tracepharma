<?php

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Compliance\SopLibraryCatalog;
use App\Support\TenantFeatures;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class SopLibrary extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'SOP library';

    protected static ?string $title = 'SOP library';

    protected static ?int $navigationSort = 12;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.sop-library';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'sop-library';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsComplianceCases()
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'DSCSA checklists. Receive SOP on Organization Settings is unchanged.';
    }

    /**
     * @return list<array{slug: string, title: string, summary: string, steps: list<string>}>
     */
    public function sops(): array
    {
        return SopLibraryCatalog::all();
    }
}
