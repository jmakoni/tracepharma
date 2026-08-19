<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDemoRequest;
use App\Models\DemoRequest;
use App\Notifications\DemoRequestAcknowledgment;
use App\Notifications\DemoRequestReceived;
use App\Support\Marketing\DemoOrganizationSolutions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;

class DemoRequestController extends Controller
{
    public function store(StoreDemoRequest $request): RedirectResponse
    {
        $demoRequest = DemoRequest::query()->create([
            ...$request->validated(),
            'source' => 'demo',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgentForStorage(),
        ]);

        $notifyEmail = config('tracepharma.marketing.demo_notify_email');

        Notification::route('mail', $demoRequest->email)
            ->notify(new DemoRequestAcknowledgment($demoRequest));

        if (filled($notifyEmail)) {
            Notification::route('mail', $notifyEmail)
                ->notify(new DemoRequestReceived($demoRequest));
        }

        return redirect('/demo')
            ->with('demo_submitted', true)
            ->with('demo_solution_url', DemoOrganizationSolutions::url($demoRequest->organization_type))
            ->with('demo_solution_label', DemoOrganizationSolutions::label($demoRequest->organization_type));
    }
}
