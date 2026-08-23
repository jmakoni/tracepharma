<?php

namespace App\Filament\App\Resources\TransferringSessions\Pages;

use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Actions\Transferring\CancelTransferringSession;
use App\Actions\Transferring\DeleteTransferringSession;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\Support\Floor\UnsubmittedSessionDeleteAction;
use App\Filament\App\Resources\TransferringSessions\Concerns\InteractsWithTransferringSessionHud;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Transferring\TransferringSession;
use App\Support\Auth\SiteAccess;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;

class ViewTransferringSession extends ViewRecord
{
    use InteractsWithTransferringSessionHud {
        getHeaderActions as getTransferringSessionHudHeaderActions;
    }

    protected static string $resource = TransferringSessionResource::class;

    protected string $view = 'filament.app.resources.transferring-sessions.pages.view-transferring-session';

    public function getHeading(): string|Htmlable|null
    {
        /** @var TransferringSession $record */
        $record = $this->getRecord();

        $from = $record->fromSite?->name;
        $to = $record->toSite?->name;

        if (filled($from) && filled($to)) {
            return 'Transfer · '.$from.' → '.$to;
        }

        return 'Transfer session #'.$record->getKey();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
            $this->getInfolistContentComponent(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $completeTransfer = collect($this->getTransferringSessionHudHeaderActions())
            ->first(fn (Action $action): bool => $action->getName() === 'completeTransfer');

        if ($completeTransfer !== null) {
            $completeTransfer = $completeTransfer->visible(function (): bool {
                if (! $this->isOpen()) {
                    return false;
                }

                $user = auth()->user();

                return $user !== null
                    && SiteAccess::canAccessSite($user, (int) $this->getRecord()->from_site_id);
            });
        }

        return array_values(array_filter([
            Action::make('receiveAtDestination')
                ->label('Receive at destination')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('primary')
                ->visible(fn (): bool => $this->isInTransit() && ReceivingSessionResource::canAccess())
                ->action(function (): void {
                    /** @var TransferringSession $session */
                    $session = $this->getRecord();

                    try {
                        $receiving = app(OpenTransferReceivingSession::class)->handle(
                            $session,
                            auth()->id(),
                        );
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Receive blocked')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->redirect(ReceivingSessionResource::getUrl('view', [
                        'record' => $receiving,
                    ], panel: 'app'));
                }),
            $completeTransfer,
            Action::make('cancelTransfer')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->canCancel())
                ->requiresConfirmation()
                ->modalHeading('Cancel this transfer?')
                ->modalDescription('Discards this open transfer before shipping EPCIS is authored.')
                ->action(function (): void {
                    try {
                        app(CancelTransferringSession::class)->handle($this->getRecord(), auth()->id());
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Cancel blocked')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->getRecord()->refresh();

                    Notification::make()
                        ->title('Transfer cancelled')
                        ->success()
                        ->send();
                }),
            UnsubmittedSessionDeleteAction::forTransfer(
                fn (TransferringSession $record) => app(DeleteTransferringSession::class)->handle($record, auth()->id()),
                TransferringSessionResource::getUrl(name: 'index', panel: 'app'),
            ),
            Action::make('viewTransferDocument')
                ->label('View transfer EPCIS')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->transferDocument !== null)
                ->url(fn (): ?string => $this->getRecord()->transferDocument?->filamentViewUrl()),
            Action::make('viewReceivingSession')
                ->label('Open receive session')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (): bool => $this->receivingSession() !== null && ReceivingSessionResource::canAccess())
                ->url(fn (): string => ReceivingSessionResource::getUrl(
                    'view',
                    ['record' => $this->receivingSession()],
                    panel: 'app',
                )),
        ]));
    }
}
