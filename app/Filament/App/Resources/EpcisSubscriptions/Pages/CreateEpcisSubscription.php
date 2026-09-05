<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EpcisSubscriptions\Pages;

use App\Filament\App\Resources\EpcisSubscriptions\EpcisSubscriptionResource;
use App\Models\Epcis\EpcisSubscription;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateEpcisSubscription extends CreateRecord
{
    protected static string $resource = EpcisSubscriptionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['format'] = EpcisSubscription::FORMAT_JSONLD_20;
        $data['secret'] = Str::random(48);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var EpcisSubscription $record */
        $record = $this->record;

        Notification::make()
            ->title('Subscription created')
            ->body('HMAC secret (copy now): '.$record->secret)
            ->success()
            ->persistent()
            ->send();
    }
}
