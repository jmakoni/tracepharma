<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class ShowEpcisDocumentRequest extends FormRequest
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
}
