<?php

namespace App\Filament\App\Resources\OutboundEpcisDocuments\Pages;

use App\Filament\App\Resources\OutboundEpcisDocuments\Actions\RetryOutboundEpcisTransmitAction;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewOutboundEpcisDocument extends ViewRecord
{
    protected static string $resource = OutboundEpcisDocumentResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing([
            'tradingPartner',
            'shipFromSite',
            'shipToSite',
            'shipToPartner',
            'outboundShippingSession',
            'transferringSession',
        ]);
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Summary';
    }

    public function getHeading(): string|Htmlable|null
    {
        return '#'.$this->getRecord()->getKey();
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var EpcisDocument $record */
        $record = $this->getRecord();

        $status = (string) ($record->status ?? '—');
        $transmit = filled($record->transmission_status)
            ? ' · transmit '.$record->transmission_status
            : '';
        if ($record->transmission_status === 'failed' && filled($record->error_message)) {
            $error = (string) $record->error_message;
            $transmit .= ' ('.(mb_strlen($error) > 80 ? mb_substr($error, 0, 80).'…' : $error).')';
        }
        $events = number_format((int) $record->event_count);
        $epcs = number_format((int) $record->epc_count);
        $payloadNote = filled($record->payload_path)
            ? ' · Download = partner TI payload'
            : '';

        return "{$status}{$transmit} · {$events} events · {$epcs} EPCs · ".$record->directionDisplayLabel().$payloadNote;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadXml')
                ->label('Download EPCIS')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => filled($this->getRecord()->payload_path))
                ->disabled(fn (): bool => ! EpcisDocumentXmlDownload::available($this->getRecord()))
                ->tooltip(fn (): ?string => EpcisDocumentXmlDownload::available($this->getRecord())
                    ? 'Download the stored EPCIS XML payload'
                    : 'XML payload is missing from storage')
                ->action(function () {
                    /** @var EpcisDocument $record */
                    $record = $this->getRecord();

                    if (! EpcisDocumentXmlDownload::available($record)) {
                        Notification::make()
                            ->title('XML file missing')
                            ->body('The payload path is recorded but the file is not on disk.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    /** @var User|null $actor */
                    $actor = auth()->user();

                    activity()
                        ->performedOn($record)
                        ->causedBy($actor)
                        ->withProperties([
                            'filename' => EpcisDocumentXmlDownload::filename($record),
                            'payload_path' => $record->payload_path,
                        ])
                        ->log('Downloaded EPCIS XML');

                    return EpcisDocumentXmlDownload::response($record);
                }),
            RetryOutboundEpcisTransmitAction::forView(
                fn (): EpcisDocument => $this->getRecord(),
            ),
            Action::make('viewShipOrder')
                ->label('Ship Order')
                ->icon(Heroicon::OutlinedTruck)
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->outboundShippingSession !== null)
                ->url(fn (): ?string => $this->getRecord()->outboundShippingSession
                    ? OutboundShippingSessionResource::getUrl('view', [
                        'record' => $this->getRecord()->outboundShippingSession,
                    ])
                    : null),
            Action::make('viewTransfer')
                ->label('Transfer')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->transferringSession !== null)
                ->url(fn (): ?string => $this->getRecord()->transferringSession
                    ? TransferringSessionResource::getUrl('view', [
                        'record' => $this->getRecord()->transferringSession,
                    ])
                    : null),
            Action::make('viewSsccBatch')
                ->label('SSCC batch')
                ->icon(Heroicon::OutlinedQrCode)
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->isSsccAuthoredKind()
                    || $this->getRecord()->ssccLabelBatch() !== null)
                ->disabled(fn (): bool => $this->getRecord()->ssccLabelBatch() === null)
                ->url(function (): ?string {
                    $batch = $this->getRecord()->ssccLabelBatch();

                    return $batch !== null
                        ? SsccLabelResource::getUrl('view-batch', ['record' => $batch])
                        : null;
                }),
        ];
    }
}
