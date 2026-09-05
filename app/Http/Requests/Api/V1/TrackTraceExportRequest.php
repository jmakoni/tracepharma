<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class TrackTraceExportRequest extends FormRequest
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
        return [
            'document_id' => ['nullable', 'integer', 'min:1', 'required_without:rules'],
            'rules' => ['nullable', 'array', 'required_without:document_id'],
            'rules.*.field' => ['required_with:rules', 'string'],
            'rules.*.operator' => ['required_with:rules', 'string'],
            'rules.*.value' => ['nullable'],
            'rules.*.value_to' => ['nullable'],
            'rules.*.boolean' => ['nullable', 'in:and,or'],
            'notify_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasDocument = filled($this->input('document_id'));
            $rules = $this->input('rules');
            $hasRules = is_array($rules) && $rules !== [];

            if ($hasDocument && $hasRules) {
                $validator->errors()->add('document_id', 'Provide either document_id or rules, not both.');
                $validator->errors()->add('rules', 'Provide either document_id or rules, not both.');
            }
        });
    }
}
