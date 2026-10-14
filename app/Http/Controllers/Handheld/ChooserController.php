<?php

namespace App\Http\Controllers\Handheld;

use App\Http\Controllers\Controller;
use App\Support\Floor\HandheldPwa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChooserController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (tenant() !== null) {
            return redirect('/floor');
        }

        $prefill = HandheldPwa::normalizeHost((string) $request->query('host', ''));

        return view('handheld.chooser', [
            'prefillHost' => $prefill ?? '',
            'error' => null,
        ]);
    }

    public function open(Request $request): RedirectResponse|View
    {
        if (tenant() !== null) {
            return redirect('/floor');
        }

        $host = HandheldPwa::normalizeHost((string) $request->input('host', ''));

        if ($host === null) {
            return view('handheld.chooser', [
                'prefillHost' => (string) $request->input('host', ''),
                'error' => 'Enter a TracePharma floor host such as demo2.internal.vatengi.com.',
            ])->withHeaders(['Cache-Control' => 'no-store']);
        }

        return redirect()->away(HandheldPwa::floorLoginUrl($host));
    }
}
