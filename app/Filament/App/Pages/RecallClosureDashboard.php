<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Recalls\RecallClosureMetrics;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class RecallClosureDashboard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Recall closure';

    protected static ?string $title = 'Recall closure';

    protected static ?int $navigationSort = 7;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.recall-closure-dashboard';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsTracingRequests()
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Open recalls: partner broadcast ack % and unreconciled on-hand hits. Find/Recall and ack portal stay as they are.';
    }

    /**
     * @return list<array{id: int, title: string, status: string, ack_percent: int|null, ack_label: string, unreconciled: int, href: string|null, site_recall_href: string|null}>
     */
    public function rows(): array
    {
        return app(RecallClosureMetrics::class)->rows();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('openSiteRecall')
                ->label('Site recall')
                ->icon(Heroicon::OutlinedMapPin)
                ->url(fn (): string => SiteRecallReconciliation::getUrl(panel: 'app'))
                ->visible(fn (): bool => SiteRecallReconciliation::canAccess()),
        ];
    }
}
