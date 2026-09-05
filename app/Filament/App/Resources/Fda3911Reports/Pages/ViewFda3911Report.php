<?php

namespace App\Filament\App\Resources\Fda3911Reports\Pages;

use App\Actions\Fda3911\GenerateFda3911Pdf;
use App\Actions\Fda3911\MarkFda3911Submitted;
use App\Actions\Fda3911\RecordFda3911IncidentNumber;
use App\Enums\Fda3911ReportStatus;
use App\Filament\App\Resources\Fda3911Reports\Fda3911ReportResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Fda3911Report;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewFda3911Report extends ViewRecord
{
    protected static string $resource = Fda3911ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->getRecord()->generated_pdf_path !== null)
                ->action(function (): StreamedResponse {
                    /** @var Fda3911Report $record */
                    $record = $this->getRecord();
                    $disk = config('filesystems.default', 'local');

                    return Storage::disk($disk)->download(
                        (string) $record->generated_pdf_path,
                        'fda-3911-'.$record->id.'.pdf',
                    );
                }),
            Action::make('regeneratePdf')
                ->label('Regenerate PDF')
                ->icon('heroicon-o-document')
                ->action(function (GenerateFda3911Pdf $generator): void {
                    $generator->execute($this->getRecord());
                    $this->refreshRecord();
                    Notification::make()->title('PDF regenerated')->success()->send();
                }),
            RegulatoryCompliance::apply(
                Action::make('markSubmitted')
                    ->label('Mark submitted to FDA')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (): bool => ! in_array($this->getRecord()->status, [
                        Fda3911ReportStatus::Submitted,
                        Fda3911ReportStatus::Acknowledged,
                        Fda3911ReportStatus::Terminated,
                    ], true))
                    ->action(function (MarkFda3911Submitted $markSubmitted): void {
                        /** @var User $user */
                        $user = Auth::user();
                        $markSubmitted->execute($this->getRecord(), $user);
                        $this->refreshRecord();
                        Notification::make()->title('Marked as submitted')->success()->send();
                    }),
                'fda_3911_submit',
                requireReason: true,
            ),
            Action::make('recordIncident')
                ->label('Record FDA incident #')
                ->icon('heroicon-o-hashtag')
                ->color('success')
                ->visible(fn (): bool => in_array($this->getRecord()->status, [
                    Fda3911ReportStatus::Submitted,
                    Fda3911ReportStatus::Acknowledged,
                ], true))
                ->schema([
                    TextInput::make('incident_number')
                        ->label('FDA incident number')
                        ->required()
                        ->default(fn (): ?string => $this->getRecord()->incident_number),
                ])
                ->action(function (array $data, RecordFda3911IncidentNumber $recordIncident): void {
                    $recordIncident->execute($this->getRecord(), (string) $data['incident_number']);
                    $this->refreshRecord();
                    Notification::make()->title('Incident number recorded')->success()->send();
                }),
            EditAction::make(),
        ];
    }

    private function refreshRecord(): void
    {
        $this->record = $this->getRecord()->fresh();
    }
}
