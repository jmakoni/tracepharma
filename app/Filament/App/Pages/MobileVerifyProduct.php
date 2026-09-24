<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\SetsFloorCameraScanPace;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Scan-first floor verify (phone/tablet). Desktop remains {@see VerifyProduct}.
 */
class MobileVerifyProduct extends VerifyProduct
{
    use SetsFloorCameraScanPace;

    protected string $view = 'filament.app.pages.mobile-verify-product';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-verify-page',
    ];

    public static function getSlug(?Panel $panel = null): string
    {
        return 'verify-product/floor';
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

    public function desktopVerifyUrl(): string
    {
        return VerifyProduct::getUrl(panel: 'app');
    }

    public function floorVerifyUrl(): string
    {
        return static::getUrl(panel: 'app');
    }
}
