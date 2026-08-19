<?php

namespace App\Http\Requests;

use App\Support\CustomerOnboarding\OrganizationTypeMapper;
use App\Support\Marketing\LeadSubmissionMeta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function userAgentForStorage(): ?string
    {
        return LeadSubmissionMeta::truncateUserAgent($this->userAgent());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_company_name' => ['required', 'string', 'max:255'],
            'company_display_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_role' => ['nullable', 'string', 'max:120'],
            'organization_type' => ['required', 'string', Rule::in(array_keys(OrganizationTypeMapper::options()))],
            'gln' => ['nullable', 'string', 'size:13', new \App\Rules\ValidGln],
            'message' => ['nullable', 'string', 'max:2000'],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'legal_company_name' => 'legal company name',
            'company_display_name' => 'organization display name',
            'organization_type' => 'organization type',
            'accept_terms' => 'Terms of Service',
            'accept_privacy' => 'Privacy Policy',
        ];
    }
}
