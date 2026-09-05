<?php

namespace App\Filament\App\Resources\Exceptions\Actions;

use App\Actions\Epcis\AuthorizeMissingDocumentProducts;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Actions\MasterData\AddFdaPackagesToTradingPartner;
use App\Actions\MasterData\AuthorizeFdaPackagingForPartner;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Filament\Notifications\Notification;
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
use App\Support\Filament\ProseEditor;
use Database\Seeders\ExceptionCaseSeeder;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
 * Authorize a missing GTIN for receiving from the Rx catalog (or deep-link to FDA Products).
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
                    : 'Authorize product';
            })
            ->icon(Heroicon::OutlinedCube)
            ->color('primary')
            ->visible(function () use ($page): bool {
                /** @var ExceptionCase $record */
                $record = $page->getRecord();

                if ($record->status?->isOpen() !== true
                    || ! self::profileFor($page)->showsMasterDataProductForm()) {
                    return false;
                }

                $fingerprint = ExceptionCorrectionProfile::extractGtinFromDescription($record->description);

                return $fingerprint === null
                    || ! AssortmentFromCatalog::productAuthorizedForGtin($fingerprint);
            })
            ->modalHeading('Authorize product for receiving')
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
                    RegulatoryCompliance::assert($data, 'exception_correct_unknown_gtin', $action);

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
            $notes = (string) ($data['resolution_notes'] ?? 'Authorized missing GTIN for receiving via catalog.');

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
                $autoLinked = AssortmentFromCatalog::tryLinkManufacturerPartnerToCatalogLabeler($partner, $packaging);
                if ($autoLinked) {
                    $partner->refresh();
                }

                $listing = $packaging->relationLoaded('product') ? $packaging->product : $packaging->product()->first();
                $partnerOrgId = FdaTenantLink::organizationId($partner);
                $listingOrgId = $listing?->fda_organization_id !== null ? (int) $listing->fda_organization_id : null;
                $labelerMismatch = $partnerOrgId === null
                    || $listingOrgId === null
                    || $partnerOrgId !== $listingOrgId;

                if ($labelerMismatch) {
                    Notification::make()
                        ->title('No product authorized')
                        ->body(AssortmentFromCatalog::manufacturerLabelerLinkIssue($partner, $packaging)
                            ?? 'No matching FDA package for this manufacturer labeler. Link the partner to an FDA organization or choose a different receive-from partner.')
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

            $alsoResolve = (bool) ($data['also_resolve'] ?? false);
            $alsoReprocess = (bool) ($data['also_reprocess'] ?? false) && $record->document_id !== null;
            $reprocessOutcome = null;

            if ($alsoReprocess) {
                $reprocessOutcome = self::tryReprocess($record, $page);
            }

            $resolved = false;

            if ($alsoResolve) {
                $resolved = self::tryResolveAfterAuthorize(
                    $record,
                    $actor,
                    $notes,
                    $gtin,
                    $alsoReprocess,
                    $reprocessOutcome,
                );
            }

            if ($resolved) {
                app(ExceptionService::class)->closeMatchingDocumentSignals($record->fresh() ?? $record);
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
                        ->helperText(function (Get $get) use ($catalogFor): string {
                            $packaging = $catalogFor($get);
                            $partnerId = $get('trading_partner_id');

                            if ($packaging !== null && filled($partnerId)) {
                                $partner = TradingPartner::query()->find($partnerId);
                                $linkIssue = $partner !== null
                                    ? AssortmentFromCatalog::manufacturerLabelerLinkIssue($partner, $packaging)
                                    : null;

                                if ($linkIssue !== null) {
                                    return $linkIssue;
                                }
                            }

                            return 'Partner you receive this product from. Manufacturer first when available.';
                        })
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
                    ProseEditor::make('resolution_notes')
                        ->label('Resolution notes')
                        ->required()
                        ->default('Authorized missing GTIN for receiving via catalog.')
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

        return 'Product already authorized for receiving';
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

    /**
     * @return 'completed'|'queued'|'skipped_receiving'|'failed'|'no_document'
     */
    private static function tryReprocess(ExceptionCase $record, ViewException $page): string
    {
        $record->loadMissing('document');
        $document = $record->document;

        if ($document === null) {
            return 'no_document';
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

            return 'skipped_receiving';
        }

        try {
            $document = app(ReprocessEpcisDocument::class)->handle($document, $sync);
        } catch (Throwable $e) {
            Notification::make()
                ->title('Product authorized, but re-process failed')
                ->body($e->getMessage())
                ->warning()
                ->send();

            return 'failed';
        }

        if ($sync || $document->status === 'parsed') {
            Notification::make()
                ->title('Linked document re-processed')
                ->body('Status: '.$document->status.' · Reprocess #'.(int) $document->reprocess_count)
                ->success()
                ->send();

            return 'completed';
        }

        Notification::make()
            ->title('Linked document re-process queued')
            ->body('The document will be processed in the background.')
            ->success()
            ->send();

        return 'queued';
    }

    /**
     * Resolve only when there is nothing left to re-validate, or after re-process
     * confirms the GTIN is no longer unknown on the linked document.
     *
     * @param  'completed'|'queued'|'skipped_receiving'|'failed'|'no_document'|null  $reprocessOutcome
     */
    private static function tryResolveAfterAuthorize(
        ExceptionCase $record,
        User $actor,
        string $notes,
        string $gtin,
        bool $alsoReprocess,
        ?string $reprocessOutcome,
    ): bool {
        if ($record->document_id === null) {
            return self::tryResolve($record, $actor, $notes);
        }

        if ($reprocessOutcome === 'skipped_receiving') {
            Notification::make()
                ->title('Product authorized — case left open')
                ->body('Finish receiving, then re-process the linked document to clear UNKNOWN_GTIN and close this case.')
                ->warning()
                ->send();

            return false;
        }

        if ($alsoReprocess && in_array($reprocessOutcome, ['failed', 'no_document'], true)) {
            Notification::make()
                ->title('Product authorized — case left open')
                ->body('Re-process the linked document after the failure is cleared, then resolve this case.')
                ->warning()
                ->send();

            return false;
        }

        if (! $alsoReprocess) {
            Notification::make()
                ->title('Product authorized — case left open')
                ->body('Re-process the linked EPCIS document to confirm UNKNOWN_GTIN is cleared, then resolve this case.')
                ->warning()
                ->send();

            return false;
        }

        if (! AssortmentFromCatalog::productAuthorizedForGtin($gtin)) {
            Notification::make()
                ->title('Product authorized — case left open')
                ->body('The GTIN is not yet in product master. Resolve after authorization completes.')
                ->warning()
                ->send();

            return false;
        }

        $record->loadMissing('document');
        $document = $record->document;

        if ($document !== null) {
            $stillUnknown = AuthorizeMissingDocumentProducts::unknownGtinsForDocument($document->fresh() ?? $document);
            $stillUnknownNormalized = collect($stillUnknown)
                ->map(fn (string $value): string => ltrim(AssortmentFromCatalog::normalizeGtinDigits($value), '0') ?: '0')
                ->all();
            $gtinNormalized = ltrim(AssortmentFromCatalog::normalizeGtinDigits($gtin), '0') ?: '0';

            if (in_array($gtinNormalized, $stillUnknownNormalized, true)) {
                Notification::make()
                    ->title('Product authorized — case left open')
                    ->body('The linked document still reports this GTIN as unknown. Re-process again after master data is ready.')
                    ->warning()
                    ->send();

                return false;
            }
        }

        return self::tryResolve($record, $actor, $notes);
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
        ExceptionCaseSeeder::ensureResolutionCatalog();

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
