<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Compliance\InspectionDayReadiness;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class InspectionDayReadinessPage extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Inspection day';

    protected static ?string $title = 'Inspection day readiness';

    protected static ?int $navigationSort = 10;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.inspection-day-readiness';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsComplianceReports()
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'FDA walk-in path: ZIP evidence, ATP, exceptions, SOPs, and Alert Center — under 10 minutes.';
    }

    public function checklistScore(): int
    {
        return app(InspectionDayReadiness::class)->score();
    }

    /**
     * @return list<array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}>
     */
    public function checklistItems(): array
    {
        return app(InspectionDayReadiness::class)->items();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadInspectionPack')
                ->label('Open inspection pack')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->url(fn (): string => InspectionPack::getUrl(panel: 'app'))
                ->visible(fn (): bool => InspectionPack::canAccess()),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'compliance.recall-and-inspection';
    }
}
