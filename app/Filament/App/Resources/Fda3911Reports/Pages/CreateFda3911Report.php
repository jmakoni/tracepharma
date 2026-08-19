<?php

namespace App\Filament\App\Resources\Fda3911Reports\Pages;

use App\Actions\Fda3911\GenerateFda3911Pdf;
use App\Enums\Fda3911ReportStatus;
use App\Filament\App\Resources\Fda3911Reports\Fda3911ReportResource;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateFda3911Report extends CreateRecord
{
    protected static string $resource = Fda3911ReportResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $determinedAt = isset($data['determined_at'])
            ? Carbon::parse($data['determined_at'])
            : now();

        $data['status'] = Fda3911ReportStatus::Draft->value;
        $data['created_by'] = Auth::id();
        $data['due_at'] = $determinedAt->copy()->addHours(24);

        return $data;
    }

    protected function afterCreate(): void
    {
        app(GenerateFda3911Pdf::class)->execute($this->getRecord());
    }
}
