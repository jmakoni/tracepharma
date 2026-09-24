<?php

use App\Support\Floor\FloorShell;
use Illuminate\Http\Request;

/**
 * Phone/tablet floor shell (viewport &lt;768, or &lt;1024 without desktop-view cookie).
 */
function floorShell(?Request $request = null): bool
{
    return FloorShell::active($request);
}
