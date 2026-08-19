<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class ListEpcisDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && JobRoleAccess::allowsAny(
                Permissions::NavReceive,
                Permissions::NavIntegrations,
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
