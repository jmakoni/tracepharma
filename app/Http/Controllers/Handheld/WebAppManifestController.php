<?php

namespace App\Http\Controllers\Handheld;

use App\Http\Controllers\Controller;
use App\Support\Floor\HandheldPwa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebAppManifestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(HandheldPwa::manifest($request), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
