<?php

namespace App\Http\Responses;

use App\Support\Floor\FloorRouteMap;
use App\Support\Floor\FloorShell;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * App-panel login: phone/tablet floor shell lands on /floor, not Dashboard.
 */
class AppLoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $panel = Filament::getCurrentPanel();

        if ($panel?->getId() === 'app' && FloorShell::active()) {
            return redirect()->intended(FloorRouteMap::launcherUrl());
        }

        return redirect()->intended(Filament::getUrl());
    }
}
