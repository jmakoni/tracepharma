<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord as FilamentEditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Edit form save action: primary background + icon.
 */
abstract class EditRecord extends FilamentEditRecord
{
    protected function getSaveFormAction(): Action
    {
        $action = parent::getSaveFormAction()
            ->color('primary')
            ->icon(Heroicon::OutlinedCheck);

        if (! RegulatoryCompliance::enabled() || ! RegulatoryCompliance::isAppPanel()) {
            return $action;
        }

        return $action
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading('Confirm save')
            ->schema(RegulatoryCompliance::fields(requireReason: false))
            ->mountUsing(function (?Schema $schema = null): void {
                $this->form->validate();
                $schema?->fill();
            })
            ->action(function (array $data, Action $action): void {
                RegulatoryCompliance::assert($data, 'save_record', $action);
                RegulatoryCompliance::audit('save_record', $this->getRecord());
                RegulatoryCompliance::markVerified('save_record');
                $this->save();
            });
    }

    protected function beforeSave(): void
    {
        RegulatoryCompliance::requireVerified('save_record');
    }
}
