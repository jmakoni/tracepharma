<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Pages;

use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Support\Auth\CurrentSite;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ListOutboundShippingSessions extends ListRecords
{
    protected static string $resource = OutboundShippingSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New ship order'),
            $this->startCorrectiveShipAction(),
        ];
    }

    private function startCorrectiveShipAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('startCorrectiveShip')
                ->label('Start corrective ship')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                ->modalHeading('Start a corrective ship order')
                ->modalDescription('Corrective orders amend a shipment that has already left. Scans are authorized by prior ship evidence instead of on-hand inventory at the ship-from site.')
                ->modalSubmitActionLabel('Open corrective order')
                // Only sites the operator may ship from: a correction authors EPCIS under
                // that site's GLN, so site access is the permission that matters here.
                ->visible(fn (): bool => EligibleReceiveSites::count() > 0)
                ->schema([
                    Select::make('site_id')
                        ->label('Ship-from site')
                        ->options(fn (): array => EligibleReceiveSites::options())
                        ->default(fn (): ?int => CurrentSite::preferredId(
                            TenantSettings::forTenant(tenant())->defaultShipFromSiteId(),
                            EligibleReceiveSites::options(),
                        ))
                        ->required()
                        ->searchable()
                        ->native(false),
                    Textarea::make('corrective_reason')
                        ->label('Reason for the correction')
                        ->required()
                        ->rows(3)
                        ->maxLength(2000)
                        ->helperText('Recorded on the ship order and carried into the authored EPCIS notes.'),
                ])
                ->action(function (array $data): void {
                    $reason = isset($data['corrective_reason']) ? (string) $data['corrective_reason'] : null;

                    try {
                        $session = app(OpenOutboundShippingSession::class)->handle(
                            siteId: isset($data['site_id']) ? (int) $data['site_id'] : null,
                            openedBy: auth()->id(),
                            isCorrective: true,
                            correctiveReason: $reason,
                        );
                    } catch (AuthorizationException $e) {
                        Notification::make()
                            ->title('Corrective order blocked')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } catch (InvalidArgumentException|DomainException $e) {
                        throw ValidationException::withMessages([
                            'site_id' => $e->getMessage(),
                        ]);
                    }

                    activity()
                        ->performedOn($session)
                        ->causedBy(auth()->user())
                        ->withProperties([
                            'site_id' => (int) $session->site_id,
                            'corrective_reason' => $session->corrective_reason,
                        ])
                        ->log('Opened corrective ship order');

                    Notification::make()
                        ->title('Corrective ship order opened')
                        ->success()
                        ->send();

                    $this->redirect(OutboundShippingSessionResource::getUrl('view', ['record' => $session]));
                }),
            'outbound_shipping_corrective_open',
            requireReason: true,
            existingReasonField: 'corrective_reason',
        );
    }
}
