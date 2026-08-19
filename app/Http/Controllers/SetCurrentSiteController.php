<?php

namespace App\Http\Controllers;

use App\Support\Auth\CurrentSite;
use Illuminate\Http\RedirectResponse;

class SetCurrentSiteController extends Controller
{
    public function __invoke(int $site): RedirectResponse
    {
        $options = CurrentSite::options();

        if (! isset($options[$site]) && ! isset($options[(string) $site])) {
            abort(403);
        }

        CurrentSite::set($site);

        return back();
    }
}
