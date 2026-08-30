<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SerializationLots\Pages;

use App\Filament\App\Resources\SerializationLots\SerializationLotResource;
use App\Models\L3\SerializationLot;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewSerializationLot extends ViewRecord
{
    protected static string $resource = SerializationLotResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(['site', 'epcisDocument']);
    }

    public function getHeading(): string|Htmlable|null
    {
        /** @var SerializationLot $record */
        $record = $this->getRecord();

        return $record->lot_number;
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var SerializationLot $record */
        $record = $this->getRecord();

        $bits = array_filter([
            $record->product_name,
            filled($record->status) ? ucfirst((string) $record->status) : null,
        ]);

        return $bits === [] ? null : implode(' · ', $bits);
    }
}
