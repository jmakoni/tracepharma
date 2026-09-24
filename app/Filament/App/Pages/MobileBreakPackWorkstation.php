<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\SetsFloorCameraScanPace;
use Filament\Actions\Action;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Scan-first floor break & pack (phone/tablet). Desktop remains {@see BreakPackWorkstation}.
 */
class MobileBreakPackWorkstation extends BreakPackWorkstation
{
    use SetsFloorCameraScanPace;

    protected string $view = 'filament.app.pages.mobile-break-pack-workstation';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-shell-page tp-floor-break-pack-page',
    ];

    public static function getSlug(?Panel $panel = null): string
    {
        return 'break-pack/floor';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function desktopBreakPackUrl(): string
    {
        return BreakPackWorkstation::getUrl(panel: 'app');
    }

    public function floorBreakPackUrl(): string
    {
        return static::getUrl(panel: 'app');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->confirmBreakPackAction(),
        ];
    }
}
