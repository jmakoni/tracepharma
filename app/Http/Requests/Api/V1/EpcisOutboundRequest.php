<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\OutboundConnection;
use App\Services\Epcis\OutboundConnectionResolver;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EpcisOutboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && JobRoleAccess::allows(Permissions::NavShip, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('tracepharma.epcis.max_upload_kb', 81920);

        return [
            'file' => [
                'nullable',
                'file',
                'extensions:xml',
                'mimetypes:text/xml,application/xml,application/xhtml+xml,application/octet-stream,text/plain',
                'max:'.$maxKb,
            ],
            'outbound_connection_id' => [
                'nullable',
                'integer',
                Rule::exists('outbound_connections', 'id')->where('is_active', true),
            ],
            'trading_partner_id' => [
                'nullable',
                'integer',
                Rule::exists('trading_partners', 'id'),
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

            $partnerId = $this->filled('trading_partner_id')
                ? (int) $this->input('trading_partner_id')
                : null;
            $connectionId = $this->filled('outbound_connection_id')
                ? (int) $this->input('outbound_connection_id')
                : null;

            if ($partnerId === null && $connectionId === null) {
                $validator->errors()->add(
                    'trading_partner_id',
                    'Provide trading_partner_id or outbound_connection_id.',
                );
            }

            if ($partnerId !== null && $connectionId !== null) {
                $connection = OutboundConnection::query()
                    ->where('is_active', true)
                    ->find($connectionId);

                if ($connection !== null && ! OutboundConnectionResolver::connectionMatchesPartner($connection, $partnerId)) {
                    $validator->errors()->add(
                        'outbound_connection_id',
                        'Selected outbound connection is not scoped to this trading partner.',
                    );
                }
            }
        });
    }
}
