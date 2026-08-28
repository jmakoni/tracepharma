<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\PmsIntegrationChecklist;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class PmsIntegrationChecklistPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $navigationLabel = 'PMS integration';

    protected static ?string $title = 'PMS integration checklist';

    protected static ?int $navigationSort = 21;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected string $view = 'filament.app.pages.pms-integration-checklist';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsVrs()
            && JobRoleAccess::allowsAny(
                Permissions::NavIntegrations,
                Permissions::NavVerify,
            );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Certify dispense-check for your pharmacy management system — one reference integration, not 30 connectors. Follow docs/integrations/pms/*.md runbooks.';
    }

    public function checklistScore(): int
    {
        return app(PmsIntegrationChecklist::class)->score();
    }

    /**
     * @return list<array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}>
     */
    public function checklistItems(): array
    {
        return app(PmsIntegrationChecklist::class)->items();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('openApiTokens')
                ->label('Create dispense-check token')
                ->icon(Heroicon::OutlinedKey)
                ->url(fn (): string => ApiTokens::getUrl(panel: 'app').'?ability=vrs:dispense-check')
                ->visible(fn (): bool => ApiTokens::canAccess()),
        ];
    }
}
