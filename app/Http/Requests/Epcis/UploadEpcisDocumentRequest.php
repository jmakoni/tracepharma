<?php

namespace App\Http\Requests\Epcis;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Canonical validation rules for EPCIS document uploads.
 * Filament upload actions should mirror these (including max_upload_kb from config).
 */
class UploadEpcisDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('tracepharma.epcis.max_upload_kb', 81920);

        return [
            'file' => [
                'required',
                'file',
                'extensions:xml',
                'mimetypes:text/xml,application/xml,application/xhtml+xml,application/octet-stream,text/plain',
                'max:'.$maxKb,
            ],
            'direction' => ['required', 'string', Rule::in(['inbound', 'outbound'])],
            'trading_partner_id' => ['nullable', 'integer', 'exists:trading_partners,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
