<?php

namespace App\Filament\App\Resources\TradingPartners\Actions;

use App\Enums\AtpVerificationSource;
use App\Enums\PartnerType;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\TradingPartner;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Records that we checked this partner is authorized to transact, and what we checked.
 *
 * The check is of the partner, not of one of its addresses: the licences that authorize a
 * specific facility live on that site and are scored by site ATP readiness, while this is
 * the buyer-side diligence DSCSA asks us to be able to show for the company we trade with.
 * Each save replaces the previous one, so the record answers "when did we last look, who
 * looked, and against what" rather than keeping a history.
 */
final class RecordAtpVerificationAction
{
    public static function make(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('recordAtpVerification')
                ->label('Record ATP verification')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->authorize('update')
                ->modalHeading('Record ATP verification')
                ->modalDescription('Log how you confirmed this partner is an authorized trading partner. The newest entry replaces the one before it.')
                ->modalWidth(Width::TwoExtraLarge)
                ->modalSubmitActionLabel('Save verification')
                ->fillForm(fn (TradingPartner $record): array => [
                    'atp_verified_at' => $record->atp_verified_at ?? now(),
                    'atp_verification_source' => $record->atp_verification_source?->value
                        ?? self::defaultSource($record)->value,
                    'atp_verification_url' => $record->atp_verification_url,
                    'atp_verification_note' => $record->atp_verification_note,
                ])
                ->schema([
                    DateTimePicker::make('atp_verified_at')
                        ->label('Verified at')
                        ->seconds(false)
                        ->default(fn (): string => now()->toDateTimeString())
                        ->required(),
                    Select::make('atp_verification_source')
                        ->label('Source')
                        ->options(AtpVerificationSource::options())
                        ->default(AtpVerificationSource::FdaWdd3pl->value)
                        ->required(),
                    TextInput::make('atp_verification_url')
                        ->label('Evidence link')
                        ->url()
                        ->maxLength(500)
                        ->helperText('Registry lookup or document location, when there is one.')
                        ->columnSpanFull(),
                    Textarea::make('atp_verification_note')
                        ->label('Note')
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data, TradingPartner $record): void {
                    $record->update([
                        'atp_verified_at' => $data['atp_verified_at'] ?? now(),
                        'atp_verified_by' => auth()->id(),
                        'atp_verification_source' => $data['atp_verification_source'] ?? null,
                        'atp_verification_url' => self::nullIfBlank($data['atp_verification_url'] ?? null),
                        'atp_verification_note' => self::nullIfBlank($data['atp_verification_note'] ?? null),
                    ]);

                    Notification::make()
                        ->title('ATP verification recorded')
                        ->success()
                        ->send();
                }),
            'trading_partner_atp_verification',
            requireReason: false,
        );
    }

    /**
     * A cleared field means "no evidence on file", which reads as null everywhere else.
     */
    private static function nullIfBlank(?string $value): ?string
    {
        return blank($value) ? null : trim($value);
    }

    private static function defaultSource(TradingPartner $record): AtpVerificationSource
    {
        return $record->partner_type === PartnerType::Manufacturer
            ? AtpVerificationSource::FdaDecrs
            : AtpVerificationSource::FdaWdd3pl;
    }
}
