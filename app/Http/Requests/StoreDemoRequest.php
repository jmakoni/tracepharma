<?php

namespace App\Http\Requests;

use App\Support\Marketing\LeadSubmissionMeta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDemoRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['nullable', 'string', 'max:120'],
            'organization_type' => ['nullable', 'string', Rule::in([
                'independent_pharmacy',
                'hospital_health_system',
                'wholesaler',
                'manufacturer',
                'logistics_3pl',
                'buying_group',
                'dental_medical',
                'prepackager',
                'other',
            ])],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_type' => 'organization type',
        ];
    }
}
