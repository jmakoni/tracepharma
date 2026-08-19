<?php

namespace App\Filament\App\Resources\TracingRequests\Pages;

use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Filament\App\Resources\TracingRequests\TracingRequestResource;
use App\Services\Tracing\TracingRequestService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTracingRequest extends CreateRecord
{
    protected static string $resource = TracingRequestResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(TracingRequestService::class)->create($data, auth()->user());
    }

    /**
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $gtin = request()->string('gtin')->toString();
        $serial = request()->string('serial')->toString();
        $lot = request()->string('lot')->toString();
        $exceptionId = request()->integer('exception_id') ?: null;

        if ($gtin !== '' && blank($data['gtin'] ?? null)) {
            $data['gtin'] = $gtin;
        }
        if ($serial !== '' && blank($data['serial'] ?? null)) {
            $data['serial'] = $serial;
            $data['scope'] = TracingRequestScope::SingleProduct->value;
        }
        if ($lot !== '' && blank($data['lot'] ?? null)) {
            $data['lot'] = $lot;
            $data['scope'] = TracingRequestScope::Lot->value;
        }
        if ($exceptionId !== null && blank($data['exception_id'] ?? null)) {
            $data['exception_id'] = $exceptionId;
        }

        $data['requestor_type'] ??= TracingRequestorType::Internal->value;

        return $data;
    }
}
