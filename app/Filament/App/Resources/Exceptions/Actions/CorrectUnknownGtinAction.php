<?php

namespace App\Filament\App\Resources\Exceptions\Actions;

use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Actions\MasterData\AddFdaPackagesToTradingPartner;
use App\Actions\MasterData\AuthorizeFdaPackagingForPartner;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Exceptions\ExceptionAction as ExceptionActionModel;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\Receiving\ReceivingSession;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Exceptions\AssortmentFromCatalog;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use App\Support\Fda\FdaTenantLink;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Authorize a missing GTIN into the assortment from the Rx catalog (or deep-link to FDA Products).
 */
final class CorrectUnknownGtinAction
{
    public static function make(ViewException $page): Action
    {
        $action = Action::make('addProductToAssortment')
            ->label(function () use ($page): string {
                $profile = self::profileFor($page);

                return $profile->primaryActionKey() === ExceptionCorrectionProfile::ACTION_ADD_PRODUCT
                    ? $profile->primaryActionLabel()
                    : 'Add product to assortment';
            })
            ->icon(Heroicon::OutlinedCube)
            ->color('primary')
            ->visible(function () use ($page): bool {
                /** @var ExceptionCase $record */
                $record = $page->getRecord();

                return $record->status?->isOpen() === true
                    && self::profileFor($page)->showsMasterDataProductForm();
            })
            ->modalHeading('Add product to assortment')
            ->modalWidth(Width::FiveExtraLarge);

        if (RegulatoryCompliance::enabled()) {
            // Product details first; compliance password gate is step 2 after "Authorize product".
            $action
                ->modalSubmitActionLabel('Confirm')
                ->modifyWizardUsing(fn (Wizard $wizard): Wizard => $wizard
                    ->hiddenHeader()
                    ->nextAction(fn (Action $action): Action => $action->label('Authorize product')))
                ->steps(fn (): array => [
                    Step::make('Product')
                        ->description('Choose the GTIN and receive-from partner.')
                        ->schema(self::productSchema($page)),
                    Step::make('Confirm')
                        ->description('Enter your password to confirm this master-data change.')
                        ->schema(RegulatoryCompliance::fields(requireReason: false)),
                ])
                ->before(function (Action $action) use ($page): void {
                    if (! RegulatoryCompliance::enabled() || ! RegulatoryCompliance::isAppPanel()) {
                        return;
                    }

                    $data = $action->getData();
                    RegulatoryCompliance::assert($data, 'exception_correct_unknown_gtin');

                    $reason = trim((string) ($data['resolution_notes'] ?? ''));
                    if ($reason === '') {
                        throw ValidationException::withMessages([
                            'resolution_notes' => 'A reason for this action is required.',
                        ]);
                    }

                    RegulatoryCompliance::audit(
                        'exception_correct_unknown_gtin',
                        $page->getRecord(),
                        $reason,
                    );
                });
        } else {
            $action
                ->modalSubmitActionLabel('Authorize product')
                ->schema(fn (): array => self::productSchema($page));
        }

        return $action->action(function (array $data) use ($page): void {
            /** @var User $actor */
            $actor = auth()->user();
            /** @var ExceptionCase $record */
            $record = $page->getRecord();
            $notes = (string) ($data['resolution_notes'] ?? 'Added missing GTIN to product assortment via catalog authorization.');

            $gtin = (string) ($data['gtin'] ?? '');
            $fingerprint = ExceptionCorrectionProfile::extractGtinFromDescription($record->description);
            if ($fingerprint !== null && ! self::gtinsMatch($gtin, $fingerprint)) {
                Notification::make()
                    ->title('GTIN does not match this exception')
                    ->body("Authorize only the unknown GTIN from this case ({$fingerprint}).")
                    ->danger()
                    ->send();

                return;
            }

            $packaging = AssortmentFromCatalog::findPackagingByGtin($gtin);

            if ($packaging === null) {
                $url = AssortmentFromCatalog::fdaProductsUrl($gtin);

                Notification::make()
                    ->title('GTIN not in FDA packaging')
                    ->body(AssortmentFromCatalog::catalogMissMessage().' Open FDA Products to search and add packages, then return here.')
                    ->warning()
                    ->actions([
                        Action::make('openFda')
                            ->label('Open FDA Products')
                            ->url($url)
                            ->openUrlInNewTab(),
                    ])
                    ->send();

                return;
            }

            $partner = TradingPartner::query()->find($data['trading_partner_id'] ?? null);

            if ($partner === null) {
                Notification::make()
                    ->title('No product authorized')
                    ->body('Select the trading partner you receive this product from.')
                    ->warning()
                    ->send();

                return;
            }

            $addPackagesAction = app(AddFdaPackagesToTradingPartner::class);

            if ($addPackagesAction->requiresLabelerScope($partner)) {
                $listing = $packaging->relationLoaded('product') ? $packaging->product : $packaging->product()->first();
                $labelerMismatch = FdaTenantLink::organizationId($partner) === null
                    || $listing?->fda_organization_id === null
                    || (int) FdaTenantLink::organizationId($partner) !== (int) $listing->fda_organization_id;

                if ($labelerMismatch) {
                    Notification::make()
                        ->title('No product authorized')
                        ->body('No matching FDA package for this manufacturer labeler. Link the partner to an FDA organization or choose a different receive-from partner.')
                        ->warning()
                        ->send();

                    return;
                }
            }

            $authorized = app(AuthorizeFdaPackagingForPartner::class)->handle(
                $partner,
                $packaging,
                gtinOverride: $gtin,
            );

            $result = [
                'added' => $authorized['added'],
                'attached' => $authorized['attached'],
                'skipped' => $authorized['skipped'],
                'manufacturer_pending' => $authorized['manufacturer_pending'],
                'manufacturer_added' => $authorized['manufacturer_added'],
            ];

            if ($result['added'] === 0 && $result['attached'] === 0 && $result['skipped'] === 0) {
                Notification::make()
                    ->title('No product authorized')
                    ->body('The catalog package could not be linked to this partner.')
                    ->warning()
                    ->send();

                return;
            }

            if ((bool) ($data['also_resolve'] ?? false)) {
                $resolved = self::tryResolve($record, $actor, $notes);

                if (! $resolved) {
                    $page->refreshRecord();

                    return;
                }
            }

            if ((bool) ($data['also_reprocess'] ?? false) && $record->document_id !== null) {
                self::tryReprocess($record, $page);

                // Re-process recreates open epcis_exceptions; re-close signals
                // that this resolved case already addressed.
                if ((bool) ($data['also_resolve'] ?? false)) {
                    app(ExceptionService::class)->closeMatchingDocumentSignals($record->fresh() ?? $record);
                }
            }

            $page->refreshRecord();

            Notification::make()
                ->title(self::successTitle($result))
                ->body(self::successBody($result, $partner))
                ->success()
                ->send();
        });
    }

    /**
     * @return list<Component|\Filament\Forms\Components\Component>
     */
    private static function productSchema(ViewException $page): array
    {
        /** @var ExceptionCase $record */
        $record = $page->getRecord();
        $defaultGtin = ExceptionCorrectionProfile::extractGtinFromDescription($record->description);
        $defaultCatalog = filled($defaultGtin)
            ? AssortmentFromCatalog::findPackagingByGtin($defaultGtin)
            : null;

        $catalogFor = static function (Get $get) use ($defaultGtin, $defaultCatalog): ?\App\Models\Fda\FdaProductPackaging {
            $gtin = (string) ($get('gtin') ?? '');
            if ($gtin === '' || ($defaultGtin !== null && $gtin === $defaultGtin)) {
                return $defaultCatalog;
            }

            return AssortmentFromCatalog::findPackagingByGtin($gtin);
        };

        return [
            Grid::make(2)
                ->schema([
                    Placeholder::make('bulk_authorize_hint')
                        ->label('Multiple unknown GTINs?')
                        ->content(function () use ($record): HtmlString {
                            $url = EpcisDocumentResource::getUrl('view', [
                                'record' => $record->document_id,
                            ], panel: 'app').'?relation=0';

                            return new HtmlString(
                                'When this document has several missing products, use the '
                                .'<a href="'.e($url).'" class="text-primary-600 underline font-medium">Products tab</a> '
                                .'to authorize all catalog hits at once.'
                            );
                        })
                        ->visible($record->document_id !== null)
                        ->columnSpanFull(),
                    TextInput::make('gtin')
                        ->label('GTIN')
                        ->default($defaultGtin)
                        ->required()
                        ->maxLength(14)
                        ->readOnly(filled($defaultGtin))
                        ->dehydrated()
                        ->helperText(filled($defaultGtin)
                            ? 'Locked to the unknown GTIN from this exception.'
                            : null)
                        ->live(debounce: 500),
                    Select::make('trading_partner_id')
                        ->label('Receive from partner')
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->options(function (Get $get) use ($catalogFor): array {
                            return AssortmentFromCatalog::receiveFromPartnerOptions($catalogFor($get));
                        })
                        ->default(fn (): ?int => AssortmentFromCatalog::preferredPartnerId($record, $defaultCatalog))
                        ->helperText('Partner you receive this product from. Manufacturer first when available.')
                        ->visible(fn (Get $get): bool => $catalogFor($get) !== null),
                    Placeholder::make('catalog_match')
                        ->label('FDA package match')
                        ->content(function (Get $get) use ($catalogFor): string {
                            $searchedGtin = (string) ($get('gtin') ?? '');

                            return AssortmentFromCatalog::formatCatalogMatch($catalogFor($get), $searchedGtin);
                        })
                        ->visible(fn (Get $get): bool => $catalogFor($get) !== null)
                        ->columnSpanFull(),
                    Placeholder::make('catalog_miss')
                        ->label('Not in FDA packaging')
                        ->content(function (Get $get): HtmlString {
                            $url = AssortmentFromCatalog::fdaProductsUrl((string) ($get('gtin') ?? ''));

                            return new HtmlString(
                                e(AssortmentFromCatalog::catalogMissMessage())
                                .' <a href="'.e($url).'" target="_blank" rel="noopener" class="text-primary-600 underline font-medium">Open FDA Products</a>'
                            );
                        })
                        ->visible(fn (Get $get): bool => $catalogFor($get) === null)
                        ->columnSpanFull(),
                    Toggle::make('also_resolve')
                        ->label('Mark exception resolved after authorizing product')
                        ->default(true)
                        ->live()
                        ->visible(fn (Get $get): bool => $catalogFor($get) !== null),
                    Toggle::make('also_reprocess')
                        ->label('Re-process linked EPCIS document after authorizing')
                        ->default(fn (): bool => $record->document_id !== null
                            && ! self::documentHasActiveReceivingSession($record))
                        ->helperText(function () use ($record): ?string {
                            return self::documentHasActiveReceivingSession($record)
                                ? 'Disabled while receiving is open or in progress. Finish receiving, or force re-process from the document.'
                                : null;
                        })
                        ->disabled(fn (): bool => self::documentHasActiveReceivingSession($record))
                        ->dehydrated()
                        ->visible(fn (Get $get): bool => $record->document_id !== null
                            && $catalogFor($get) !== null),
                    Textarea::make('resolution_notes')
                        ->label('Resolution notes')
                        ->required()
                        ->rows(3)
                        ->maxLength(5000)
                        ->default('Added missing GTIN to product assortment via catalog authorization.')
                        ->helperText(function (Get $get) use ($catalogFor): string {
                            if ($catalogFor($get) === null) {
                                return 'Required for the compliance gate. Use Open FDA Products above when this GTIN is not in catalog.';
                            }

                            return (bool) $get('also_resolve')
                                ? 'Recorded on the resolved case.'
                                : 'Recorded as the compliance reason for this correction.';
                        })
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @param  array{added: int, attached: int, skipped: int, manufacturer_pending: int, manufacturer_added: int}  $result
     */
    private static function successTitle(array $result): string
    {
        if ($result['added'] > 0) {
            return $result['added'] === 1
                ? 'Product authorized from catalog'
                : "{$result['added']} products authorized from catalog";
        }

        if ($result['attached'] > 0) {
            return 'Product linked to partner';
        }

        return 'Product already in assortment';
    }

    /**
     * @param  array{added: int, attached: int, skipped: int, manufacturer_pending: int, manufacturer_added: int}  $result
     */
    private static function successBody(array $result, TradingPartner $partner): string
    {
        $parts = [];
        $name = $partner->name ?: 'partner';

        if ($result['added'] > 0) {
            $parts[] = $result['added'] === 1
                ? '1 product created'
                : "{$result['added']} products created";
        }

        if ($result['attached'] > 0) {
            $parts[] = $result['attached'] === 1
                ? '1 existing product linked'
                : "{$result['attached']} existing products linked";
        }

        if ($result['skipped'] > 0) {
            $parts[] = 'already linked to '.$name;
        }

        return $parts !== [] ? implode('. ', $parts).'.' : 'Receive-from updated for '.$name.'.';
    }

    private static function tryReprocess(ExceptionCase $record, ViewException $page): void
    {
        $record->loadMissing('document');
        $document = $record->document;

        if ($document === null) {
            return;
        }

        $sync = Queue::getDefaultDriver() === 'sync';

        // Do not force-reprocess under an open receiving session — that can invalidate
        // in-flight floor work. Skip with a clear message instead of a DomainException.
        if (self::documentHasActiveReceivingSession($record)) {
            Notification::make()
                ->title('Product authorized — re-process skipped')
                ->body('Document #'.$document->getKey().' has an open or in-progress receiving session. Finish receiving, then re-process from the document if you need UNKNOWN_GTIN cleared now.')
                ->warning()
                ->send();

            return;
        }

        try {
            $document = app(ReprocessEpcisDocument::class)->handle($document, $sync);
        } catch (Throwable $e) {
            Notification::make()
                ->title('Product authorized, but re-process failed')
                ->body($e->getMessage())
                ->warning()
                ->send();

            return;
        }

        if ($sync || $document->status === 'parsed') {
            Notification::make()
                ->title('Linked document re-processed')
                ->body('Status: '.$document->status.' · Reprocess #'.(int) $document->reprocess_count)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Linked document re-process queued')
            ->body('The document will be processed in the background.')
            ->success()
            ->send();
    }

    private static function profileFor(ViewException $page): ExceptionCorrectionProfile
    {
        /** @var ExceptionCase $record */
        $record = $page->getRecord();

        return ExceptionCorrectionProfile::forCase($record);
    }

    private static function documentHasActiveReceivingSession(ExceptionCase $record): bool
    {
        if ($record->document_id === null) {
            return false;
        }

        if (! Schema::hasTable('receiving_sessions')) {
            return false;
        }

        return ReceivingSession::query()
            ->where('epcis_document_id', $record->document_id)
            ->whereIn('status', ['open', 'in_progress'])
            ->exists();
    }

    private static function tryResolve(ExceptionCase $record, User $actor, string $notes): bool
    {
        $rootCauseId = ExceptionRootCause::query()->where('code', 'internal_mapping_error')->value('id');
        $resolutionActionId = ExceptionActionModel::query()->where('code', 'update_master_data')->value('id');

        if ($rootCauseId === null || $resolutionActionId === null) {
            Notification::make()
                ->title('Product authorized, but resolve failed')
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
                ->title('Product authorized, but resolve failed')
                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->warning()
                ->send();

            return false;
        } catch (Throwable $e) {
            Notification::make()
                ->title('Product authorized, but resolve failed')
                ->body($e->getMessage())
                ->warning()
                ->send();

            return false;
        }

        return true;
    }

    private static function gtinsMatch(string $submitted, string $fingerprint): bool
    {
        $normalize = static fn (string $value): string => ltrim($value, '0') ?: '0';

        return $normalize($submitted) === $normalize($fingerprint);
    }
}
