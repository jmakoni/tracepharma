<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DispenseCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && JobRoleAccess::allows(Permissions::NavVerify, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'barcode' => ['nullable', 'string', 'max:512'],
            'gtin' => ['nullable', 'string', 'max:14'],
            'gtin14' => ['nullable', 'string', 'max:14'],
            'serial' => ['nullable', 'string', 'max:20'],
            'lot' => ['nullable', 'string', 'max:20'],
            'expiry' => ['nullable', 'string', 'max:8'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $hasBarcode = filled($this->input('barcode'));
            $hasGtin = filled($this->input('gtin')) || filled($this->input('gtin14'));
            $hasSerial = filled($this->input('serial'));

            if ($hasBarcode) {
                return;
            }

            if (! $hasGtin || ! $hasSerial) {
                $validator->errors()->add(
                    'barcode',
                    'Provide barcode or both gtin/gtin14 and serial.',
                );
            }
        });
    }
}
