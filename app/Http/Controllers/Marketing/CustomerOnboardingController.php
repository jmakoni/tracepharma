<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerOnboardingRequest;
use App\Models\CustomerOnboarding;
use App\Notifications\CustomerOnboardingAcknowledgment;
use App\Notifications\CustomerOnboardingReceived;
use App\Support\Marketing\PrivacyPolicy;
use App\Support\Marketing\TermsOfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

class CustomerOnboardingController extends Controller
{
    public function create(): View
    {
        return view('marketing.get-started');
    }

    public function store(StoreCustomerOnboardingRequest $request): RedirectResponse
    {
        $now = now();

        $onboarding = CustomerOnboarding::query()->create([
            'status' => 'submitted',
            'legal_company_name' => $request->string('legal_company_name')->toString(),
            'company_display_name' => $request->string('company_display_name')->toString(),
            'contact_name' => $request->string('contact_name')->toString(),
            'contact_email' => $request->string('contact_email')->toString(),
            'contact_phone' => $request->string('contact_phone')->toString() ?: null,
            'contact_role' => $request->string('contact_role')->toString() ?: null,
            'organization_type' => $request->string('organization_type')->toString(),
            'gln' => $request->string('gln')->toString() ?: null,
            'message' => $request->string('message')->toString() ?: null,
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => $now,
            'privacy_accepted_at' => $now,
            'acceptance_ip' => $request->ip(),
            'acceptance_user_agent' => $request->userAgentForStorage(),
            'submission_ip' => $request->ip(),
            'submission_user_agent' => $request->userAgentForStorage(),
        ]);

        Notification::route('mail', $onboarding->contact_email)
            ->notify(new CustomerOnboardingAcknowledgment($onboarding));

        $notifyEmail = config('tracepharma.marketing.onboarding_notify_email')
            ?? config('tracepharma.marketing.demo_notify_email');

        if (filled($notifyEmail)) {
            Notification::route('mail', $notifyEmail)
                ->notify(new CustomerOnboardingReceived($onboarding));
        }

        return redirect('/get-started')
            ->with('onboarding_submitted', true);
    }
}
