<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class EpcisInboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && JobRoleAccess::allows(Permissions::NavIntegrations, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('tracepharma.epcis.max_upload_kb', 20480);

        return [
            'file' => [
                'nullable',
                'file',
                'extensions:xml',
                'mimetypes:text/xml,application/xml,application/xhtml+xml,application/octet-stream,text/plain',
                'max:'.$maxKb,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $hasFile = $this->file('file') instanceof UploadedFile;
            $hasBody = $this->getContent() !== '';

            if (! $hasFile && ! $hasBody) {
                $validator->errors()->add('file', 'Provide an EPCIS XML file or raw XML body.');
            }
        });
    }
}
