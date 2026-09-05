<?php

namespace App\Filament\App\Resources\Fda3911Reports\Pages;

use App\Actions\Fda3911\GenerateFda3911Pdf;
use App\Filament\App\Resources\Fda3911Reports\Fda3911ReportResource;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFda3911Report extends EditRecord
{
    protected static string $resource = Fda3911ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regeneratePdf')
                ->label('Regenerate PDF')
                ->icon('heroicon-o-arrow-path')
                ->action(function (GenerateFda3911Pdf $generator): void {
                    $generator->execute($this->getRecord());
                    $this->refreshFormData(['generated_pdf_path']);
                    Notification::make()->title('PDF regenerated')->success()->send();
                }),
        ];
    }
}
