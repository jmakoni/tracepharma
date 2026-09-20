<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Pages\Concerns\InteractsWithFloorHome;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Handheld floor launcher — custom layout, no Filament sidebar/topbar.
 */
class FloorHome extends Page
{
    use InteractsWithFloorHome;

    protected static string $layout = 'layouts.floor-shell';

    protected string $view = 'filament.app.pages.floor-home';

    protected static bool $shouldRegisterNavigation = false;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'floor';
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
