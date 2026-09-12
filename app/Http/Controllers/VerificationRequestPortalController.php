<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\VerificationRequestOutcome;
use App\Enums\VerificationRequestReason;
use App\Models\VerificationRequestCase;
use App\Services\Vrs\VerificationRequestCaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class VerificationRequestPortalController extends Controller
{
    public function show(string $caseUuid): View
    {
        if (session('verification_request_submitted') === $caseUuid) {
            return view('verification-request.thank-you');
        }

        $case = $this->findCase($caseUuid);

        if ($case === null || $case->isExpired() || $case->status->value === 'responded') {
            return view('verification-request.invalid');
        }

        if (session('verification_request_unlocked') === $case->uuid) {
            return redirect()->route('tenant.verification-request.respond', ['caseUuid' => $case->uuid]);
        }

        return view('verification-request.gate', ['caseUuid' => $case->uuid]);
    }

    public function unlock(Request $request, string $caseUuid, VerificationRequestCaseService $service): RedirectResponse|View
    {
        $case = $this->findCase($caseUuid);

        if ($case === null) {
            return view('verification-request.invalid');
        }

        if (! $case->isPending()) {
            return view('verification-request.invalid');
        }

        $validated = $request->validate([
            'secure_code' => ['required', 'string', 'max:64'],
            'responder_email' => ['required', 'email', 'max:255'],
            'terms_accepted' => ['accepted'],
        ]);

        if (! $service->verifySecureCode($case, $validated['secure_code'])) {
            return back()->withErrors(['secure_code' => 'The secure code is incorrect.'])->withInput();
        }

        session([
            'verification_request_unlocked' => $case->uuid,
            'verification_request_responder_email' => strtolower(trim($validated['responder_email'])),
        ]);

        return redirect()->route('tenant.verification-request.respond', ['caseUuid' => $case->uuid]);
    }

    public function respondForm(string $caseUuid): View|RedirectResponse
    {
        $case = $this->findCase($caseUuid);

        if ($case === null || $case->isExpired() || $case->status->value === 'responded') {
            return view('verification-request.invalid');
        }

        if (session('verification_request_unlocked') !== $case->uuid) {
            return redirect()->route('tenant.verification-request.show', ['caseUuid' => $caseUuid]);
        }

        return view('verification-request.respond', [
            'case' => $case,
            'responderEmail' => session('verification_request_responder_email'),
            'outcomes' => VerificationRequestOutcome::cases(),
            'reasons' => VerificationRequestReason::cases(),
        ]);
    }

    public function submit(Request $request, string $caseUuid, VerificationRequestCaseService $service): RedirectResponse|View
    {
        $case = $this->findCase($caseUuid);

        if ($case === null || $case->isExpired() || $case->status->value === 'responded') {
            return view('verification-request.invalid');
        }

        if (session('verification_request_unlocked') !== $case->uuid) {
            return redirect()->route('tenant.verification-request.show', ['caseUuid' => $caseUuid]);
        }

        $validated = $request->validate([
            'outcome' => ['required', 'in:positive,negative'],
            'reason_code' => ['required', 'string', Rule::enum(VerificationRequestReason::class)],
            'comments' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
            'terms_accepted' => ['accepted'],
        ]);

        $validated['responder_email'] = session('verification_request_responder_email', $request->input('responder_email'));

        $service->submitResponse(
            $case,
            $validated,
            $request->file('attachment'),
            $request->ip(),
        );

        session()->forget(['verification_request_unlocked', 'verification_request_responder_email']);
        session(['verification_request_submitted' => $case->uuid]);

        return redirect()
            ->route('tenant.verification-request.show', ['caseUuid' => $caseUuid]);
    }

    private function findCase(string $caseUuid): ?VerificationRequestCase
    {
        return VerificationRequestCase::query()
            ->where('uuid', $caseUuid)
            ->with(['response'])
            ->first();
    }
}
