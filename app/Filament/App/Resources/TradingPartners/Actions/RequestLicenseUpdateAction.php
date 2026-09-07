<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\TradingPartners\Actions;

use App\Models\TradingPartner;
use App\Notifications\PartnerLicenseUpdateRequestMail;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\URL;

/**
 * Sends the partner a secure, expiring signed link to submit a current ATP
 * license document themselves (LSPedia-style self-service collection).
 */
class RequestLicenseUpdateAction
{
    public static function make(): Action
    {
        return Action::make('requestLicenseUpdate')
            ->label('Request license update')
            ->icon(Heroicon::OutlinedDocumentArrowUp)
            ->color('gray')
            ->modalHeading('Request license update')
            ->modalDescription('Emails the partner a secure link to upload their current license. Submissions land as pending verification until you confirm them.')
            ->schema([
                TextInput::make('recipient_email')
                    ->label('Partner email')
                    ->email()
                    ->required()
                    ->default(fn (TradingPartner $record): ?string => $record->email),
                TextInput::make('expires_in_days')
                    ->label('Link valid for (days)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(30)
                    ->default(7)
                    ->required(),
            ])
            ->action(function (TradingPartner $record, array $data): void {
                $days = max(1, min(30, (int) $data['expires_in_days']));

                $signedUrl = URL::temporarySignedRoute(
                    'tenant.partner-license-update.show',
                    now()->addDays($days),
                    ['partner' => $record->getKey()],
                );

                NotificationFacade::route('mail', (string) $data['recipient_email'])
                    ->notify(new PartnerLicenseUpdateRequestMail(
                        (string) $record->name,
                        (string) (tenant()?->name ?? 'your trading partner'),
                        $signedUrl,
                        $days,
                    ));

                activity()
                    ->performedOn($record)
                    ->causedBy(auth()->user())
                    ->withProperties(['recipient' => $data['recipient_email'], 'expires_in_days' => $days])
                    ->log('ATP license update requested');

                Notification::make()
                    ->title('License update requested')
                    ->body('Secure link sent to '.$data['recipient_email'].'.')
                    ->success()
                    ->send();
            });
    }
}
