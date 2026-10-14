<?php

namespace App\Http\Controllers\Handheld;

use App\Http\Controllers\Controller;
use App\Support\Floor\HandheldPwa;
use Illuminate\Http\JsonResponse;

class AssetLinksController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(HandheldPwa::assetLinks(), 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
