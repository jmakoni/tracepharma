<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AtpLicense;
use App\Models\TradingPartner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public, signed-link self-service form: a trading partner submits a license
 * document + details; the submission lands as pending_verification until a
 * tenant user confirms it on the partner record.
 */
class PartnerLicenseUpdateController extends Controller
{
    public function show(Request $request, int $partner): View
    {
        $partnerModel = $this->findPartner($partner);

        if (! $request->hasValidSignature() || $partnerModel === null) {
            return view('partner-license-update.invalid');
        }

        return view('partner-license-update.show', [
            'partner' => $partnerModel,
            'submitted' => $request->session()->pull('license_submitted', false),
        ]);
    }

    public function store(Request $request, int $partner): RedirectResponse|View
    {
        $partnerModel = $this->findPartner($partner);

        if (! $request->hasValidSignature() || $partnerModel === null) {
            return view('partner-license-update.invalid');
        }

        $data = $request->validate([
            'license_number' => ['required', 'string', 'max:100'],
            'license_state' => ['required', 'string', 'max:100'],
            'license_expiration_date' => ['required', 'date', 'after:today'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $file = $request->file('document');
        $path = $file->store('atp-license-documents', 'local');

        AtpLicense::query()->create([
            'trading_partner_id' => $partnerModel->getKey(),
            'license_number' => $data['license_number'],
            'license_country' => 'US',
            'license_state' => $data['license_state'],
            'license_expiration_date' => $data['license_expiration_date'],
            'facility_contact_email' => $data['contact_email'] ?? null,
            'document_path' => $path,
            'document_original_name' => $file->getClientOriginalName(),
            'verification_status' => AtpLicense::VERIFICATION_PENDING,
            'is_active' => true,
        ]);

        return redirect()
            ->to($request->fullUrl())
            ->with('license_submitted', true);
    }

    private function findPartner(int $partner): ?TradingPartner
    {
        return TradingPartner::query()
            ->whereKey($partner)
            ->where('is_active', true)
            ->first();
    }
}
