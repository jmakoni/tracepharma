<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\View\View;

/**
 * Filament list-page header banner for Logistics 3PL principal honesty.
 */
trait ShowsPrincipalsHonestyBanner
{
    public function getHeader(): ?View
    {
        if (! PrincipalsHonesty::forTenant()->shouldShow()) {
            return null;
        }

        return view('filament.app.partials.principals-honesty-banner');
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        // Banner carries honesty copy — avoid duplicating the same sentence as a subheading.
        return null;
    }
}
