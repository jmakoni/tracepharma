<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\SetsFloorCameraScanPace;
use Filament\Actions\Action;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Scan-first floor pack (phone/tablet). Desktop remains {@see PackWorkstation}.
 */
class MobilePackWorkstation extends PackWorkstation
{
    use SetsFloorCameraScanPace;

    protected string $view = 'filament.app.pages.mobile-pack-workstation';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-pack-page',
    ];

    public static function getSlug(?Panel $panel = null): string
    {
        return 'pack/floor';
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

    public function desktopPackUrl(): string
    {
        return PackWorkstation::getUrl(panel: 'app');
    }

    public function floorPackUrl(): string
    {
        return static::getUrl(panel: 'app');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->confirmPackAction(),
        ];
    }
}
