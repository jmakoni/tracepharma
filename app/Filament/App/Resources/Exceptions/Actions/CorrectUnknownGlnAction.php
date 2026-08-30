<?php

namespace App\Filament\App\Resources\Exceptions\Actions;

use App\Enums\PartnerType;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Exceptions\ExceptionAction as ExceptionActionModel;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use App\Support\Filament\ProseEditor;
use App\Support\Gs1\GlnRules;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Quick-register a missing GLN — as a new trading partner or as one of the tenant's own
 * sites — directly from an UNKNOWN_GLN (or similar master-data) exception.
 */
final class CorrectUnknownGlnAction
{
    private const REGISTER_AS_TRADING_PARTNER = 'trading_partner';

    private const REGISTER_AS_SITE = 'site';

    public static function make(ViewException $page): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('registerGln')
                ->label('Register GLN')
                ->icon(Heroicon::OutlinedMapPin)
                ->color('primary')
                ->visible(function () use ($page): bool {
                    /** @var ExceptionCase $record */
                    $record = $page->getRecord();

                    return $record->status?->isOpen() === true
                        && self::profileFor($page)->showsMasterDataLocationForm();
                })
                ->modalHeading('Register GLN')
                ->modalSubmitActionLabel('Register')
                ->schema(function () use ($page): array {
                    /** @var ExceptionCase $record */
                    $record = $page->getRecord();
                    $fingerprintGln = ExceptionCorrectionProfile::extractGlnFromDescription($record->description);

                    return [
                        Select::make('register_as')
                            ->label('Register as')
                            ->options([
                                self::REGISTER_AS_SITE => 'One of your sites',
                                self::REGISTER_AS_TRADING_PARTNER => 'A trading partner',
                            ])
                            ->default(self::REGISTER_AS_SITE)
                            ->required()
                            ->live(),
                        GlnRules::input()
                            ->default(fn (): ?string => $fingerprintGln)
                            ->required()
                            ->readOnly(filled($fingerprintGln))
                            ->dehydrated()
                            ->helperText(filled($fingerprintGln)
                                ? 'Locked to the unknown GLN from this exception.'
                                : null),
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        Select::make('partner_type')
                            ->label('Partner type')
                            ->options(collect(PartnerType::cases())->mapWithKeys(
                                fn (PartnerType $type): array => [$type->value => $type->label()],
                            )->all())
                            ->default(PartnerType::Other->value)
                            ->visible(fn (Get $get): bool => $get('register_as') === self::REGISTER_AS_TRADING_PARTNER)
                            ->required(fn (Get $get): bool => $get('register_as') === self::REGISTER_AS_TRADING_PARTNER),
                        Select::make('trading_partner_id')
                            ->label('Trading partner (optional)')
                            ->options(fn (): array => TradingPartner::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->helperText('Leave blank if this is one of your own sites.')
                            ->visible(fn (Get $get): bool => $get('register_as') === self::REGISTER_AS_SITE),
                        Toggle::make('also_resolve')
                            ->label('Mark exception resolved after registering')
                            ->default(true)
                            ->live(),
                        ProseEditor::make('resolution_notes')
                            ->label('Resolution notes')
                            ->required()
                            ->default('Registered missing GLN.')
                            ->helperText(fn (Get $get): string => (bool) $get('also_resolve')
                                ? 'Recorded on the resolved case.'
                                : 'Recorded as the compliance reason for this correction.'),
                    ];
                })
                ->action(function (array $data) use ($page): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $page->getRecord();
                    $notes = (string) ($data['resolution_notes'] ?? 'Registered missing GLN.');
                    $registerAs = (string) ($data['register_as'] ?? self::REGISTER_AS_SITE);
                    $gln = (string) $data['gln'];
                    $name = (string) $data['name'];

                    $fingerprint = ExceptionCorrectionProfile::extractGlnFromDescription($record->description);
                    if ($fingerprint !== null && $gln !== $fingerprint) {
                        Notification::make()
                            ->title('GLN does not match this exception')
                            ->body("Register only the unknown GLN from this case ({$fingerprint}).")
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($registerAs === self::REGISTER_AS_TRADING_PARTNER) {
                        $partner = TradingPartner::query()->create([
                            'name' => $name,
                            'gln' => $gln,
                            'partner_type' => (string) ($data['partner_type'] ?? PartnerType::Other->value),
                            'is_active' => true,
                        ]);
                        $label = 'Trading partner #'.$partner->getKey();
                    } else {
                        $tradingPartnerId = filled($data['trading_partner_id'] ?? null)
                            ? (int) $data['trading_partner_id']
                            : null;

                        $site = Site::query()->create([
                            'trading_partner_id' => $tradingPartnerId,
                            'name' => $name,
                            'gln' => $gln,
                            'is_active' => true,
                        ]);
                        $label = 'Site #'.$site->getKey();
                    }

                    if ((bool) ($data['also_resolve'] ?? false)) {
                        $resolved = self::tryResolve($record, $actor, $notes);

                        if (! $resolved) {
                            $page->refreshRecord();

                            return;
                        }
                    }

                    $page->refreshRecord();

                    Notification::make()
                        ->title($label.' registered')
                        ->success()
                        ->send();
                }),
            'exception_correct_unknown_gln',
            requireReason: true,
            existingReasonField: 'resolution_notes',
        );
    }

    private static function profileFor(ViewException $page): ExceptionCorrectionProfile
    {
        /** @var ExceptionCase $record */
        $record = $page->getRecord();

        return ExceptionCorrectionProfile::forCase($record);
    }

    private static function tryResolve(ExceptionCase $record, User $actor, string $notes): bool
    {
        $rootCauseId = ExceptionRootCause::query()->where('code', 'internal_mapping_error')->value('id');
        $resolutionActionId = ExceptionActionModel::query()->where('code', 'update_master_data')->value('id');

        if ($rootCauseId === null || $resolutionActionId === null) {
            Notification::make()
                ->title('Registered, but resolve failed')
                ->body('Resolution catalog is missing the expected root cause / action codes.')
                ->warning()
                ->send();

            return false;
        }

        try {
            app(ExceptionService::class)->resolve(
                $record,
                $actor,
                (int) $rootCauseId,
                (int) $resolutionActionId,
                $notes,
            );
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Registered, but resolve failed')
                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->warning()
                ->send();

            return false;
        } catch (Throwable $e) {
            Notification::make()
                ->title('Registered, but resolve failed')
                ->body($e->getMessage())
                ->warning()
                ->send();

            return false;
        }

        return true;
    }
}
