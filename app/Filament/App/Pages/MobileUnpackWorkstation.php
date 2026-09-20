<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\SetsFloorCameraScanPace;
use Filament\Actions\Action;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Scan-first floor unpack (phone/tablet). Desktop remains {@see UnpackWorkstation}.
 */
class MobileUnpackWorkstation extends UnpackWorkstation
{
    use SetsFloorCameraScanPace;

    protected string $view = 'filament.app.pages.mobile-unpack-workstation';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-shell-page tp-floor-unpack-page',
    ];

    public static function getSlug(?Panel $panel = null): string
    {
        return 'unpack/floor';
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

    public function desktopUnpackUrl(): string
    {
        return UnpackWorkstation::getUrl(panel: 'app');
    }

    public function floorUnpackUrl(): string
    {
        return static::getUrl(panel: 'app');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->confirmUnpackAction(),
            $this->unpackAllAction(),
        ];
    }
}
