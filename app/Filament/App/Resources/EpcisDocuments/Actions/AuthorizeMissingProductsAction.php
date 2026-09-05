<?php

namespace App\Filament\App\Resources\EpcisDocuments\Actions;

use App\Actions\Epcis\AuthorizeMissingDocumentProducts;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\ProductsRelationManager;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\EpcisDocument;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Receiving\ReceivingSession;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Exceptions\AssortmentFromCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use App\Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;

/**
 * Bulk authorize catalog hits for unknown GTINs on an EPCIS document Products tab.
 */
final class AuthorizeMissingProductsAction
{
    public static function make(ProductsRelationManager $manager): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('authorizeMissingProducts')
                ->label('Authorize missing products')
                ->icon(Heroicon::OutlinedCube)
                ->color('primary')
                ->visible(function () use ($manager): bool {
                    if (! JobRoleAccess::allows(Permissions::NavMasterData)) {
                        return false;
                    }

                    /** @var EpcisDocument $document */
                    $document = $manager->getOwnerRecord();

                    return AuthorizeMissingDocumentProducts::unknownGtinsForDocument($document) !== [];
                })
                ->modalHeading('Authorize missing products from file')
                ->modalWidth(Width::TwoExtraLarge)
                ->modalSubmitActionLabel('Authorize catalog hits')
                ->modalSubmitAction(function (Action $action) use ($manager): Action|false {
                    /** @var EpcisDocument $document */
                    $document = $manager->getOwnerRecord();
                    $preview = AuthorizeMissingDocumentProducts::preview($document);

                    return $preview['catalog_hits'] > 0 ? $action : false;
                })
                ->schema(function () use ($manager): array {
                    /** @var EpcisDocument $document */
                    $document = $manager->getOwnerRecord();
                    $preview = AuthorizeMissingDocumentProducts::preview($document);
                    $firstHitPackaging = self::firstPackagingHit($preview['unknown_gtins']);
                    $missList = implode(', ', $preview['catalog_misses']);
                    $fdaUrl = filled($preview['catalog_misses'])
                        ? AssortmentFromCatalog::fdaProductsUrl($preview['catalog_misses'][0])
                        : AssortmentFromCatalog::fdaProductsUrl(null);

                    return [
                        Grid::make(2)
                            ->schema([
                                Placeholder::make('summary_unknown')
                                    ->label('Unknown GTINs in file')
                                    ->content((string) count($preview['unknown_gtins'])),
                                Placeholder::make('summary_hits')
                                    ->label('Catalog hits (will authorize)')
                                    ->content((string) $preview['catalog_hits']),
                                Placeholder::make('summary_misses')
                                    ->label('Not in Rx catalog')
                                    ->content((string) count($preview['catalog_misses']))
                                    ->visible($preview['catalog_misses'] !== []),
                                Placeholder::make('catalog_miss_list')
                                    ->label('GTINs not in catalog')
                                    ->content(function () use ($missList, $fdaUrl): HtmlString {
                                        if ($missList === '') {
                                            return new HtmlString('—');
                                        }

                                        return new HtmlString(
                                            e($missList)
                                            .' · <a href="'.e($fdaUrl).'" target="_blank" rel="noopener" class="text-primary-600 underline font-medium">Open FDA Products</a>'
                                        );
                                    })
                                    ->visible($preview['catalog_misses'] !== [])
                                    ->columnSpanFull(),
                                Select::make('trading_partner_id')
                                    ->label('Receive from partner')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->options(fn (): array => AssortmentFromCatalog::receiveFromPartnerOptions($firstHitPackaging))
                                    ->default(fn (): ?int => self::defaultPartnerId($document, $firstHitPackaging))
                                    ->helperText('Partner you receive these products from. Manufacturer first when available.')
                                    ->visible($preview['catalog_hits'] > 0),
                                Toggle::make('also_resolve')
                                    ->label('Mark related exceptions resolved after authorizing')
                                    ->default(true)
                                    ->visible($preview['catalog_hits'] > 0
                                        && JobRoleAccess::allows(Permissions::NavExceptions)),
                                Toggle::make('also_reprocess')
                                    ->label('Re-process document after authorizing')
                                    ->default(fn (): bool => ! self::documentHasActiveReceivingSession($document))
                                    ->helperText(function () use ($document): ?string {
                                        return self::documentHasActiveReceivingSession($document)
                                            ? 'Disabled while receiving is open or in progress. Finish receiving, then re-process from the document.'
                                            : null;
                                    })
                                    ->disabled(fn (): bool => self::documentHasActiveReceivingSession($document))
                                    ->dehydrated()
                                    ->visible($preview['catalog_hits'] > 0
                                        && JobRoleAccess::allows(Permissions::NavExceptions)),
                                Textarea::make('resolution_notes')
                                    ->label('Resolution notes')
                                    ->required()
                                    ->rows(3)
                                    ->maxLength(5000)
                                    ->default('Bulk authorized missing GTINs from EPCIS document Products tab.')
                                    ->helperText('Recorded on resolved exception cases and the compliance gate.')
                                    ->columnSpanFull(),
                            ]),
                    ];
                })
                ->action(function (array $data) use ($manager): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var EpcisDocument $document */
                    $document = $manager->getOwnerRecord();

                    $partner = TradingPartner::query()->find($data['trading_partner_id'] ?? null);

                    if ($partner === null) {
                        Notification::make()
                            ->title('No products authorized')
                            ->body('Select the trading partner you receive these products from.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $notes = (string) ($data['resolution_notes'] ?? 'Bulk authorized missing GTINs from EPCIS document Products tab.');

                    $canResolveOrReprocess = JobRoleAccess::allows(Permissions::NavExceptions);

                    $result = app(AuthorizeMissingDocumentProducts::class)->handle(
                        $document,
                        $partner,
                        $actor,
                        alsoResolve: $canResolveOrReprocess && (bool) ($data['also_resolve'] ?? false),
                        alsoReprocess: $canResolveOrReprocess && (bool) ($data['also_reprocess'] ?? false),
                        resolutionNotes: $notes,
                    );

                    if ($result['authorized_gtins'] === [] && $result['labeler_blocked'] === []) {
                        Notification::make()
                            ->title('No products authorized')
                            ->body(self::noAuthorizationBody($result))
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(self::successTitle($result))
                        ->body(self::successBody($result, $partner, $document))
                        ->success()
                        ->send();
                }),
            'epcis_authorize_missing_products',
            requireReason: true,
            existingReasonField: 'resolution_notes',
        );
    }

    /**
     * @param  list<string>  $unknownGtins
     */
    private static function firstPackagingHit(array $unknownGtins): ?FdaProductPackaging
    {
        foreach ($unknownGtins as $gtin) {
            $packaging = AssortmentFromCatalog::findPackagingByGtin($gtin);

            if ($packaging !== null) {
                return $packaging;
            }
        }

        return null;
    }

    private static function defaultPartnerId(EpcisDocument $document, ?FdaProductPackaging $packaging): ?int
    {
        if ($document->trading_partner_id !== null) {
            return (int) $document->trading_partner_id;
        }

        if ($packaging === null) {
            return null;
        }

        $organizationId = $packaging->product?->fda_organization_id
            ?? $packaging->product()->value('fda_organization_id');

        if ($organizationId === null) {
            return null;
        }

        $id = TradingPartner::query()
            ->where('is_active', true)
            ->where('fda_organization_id', $organizationId)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private static function documentHasActiveReceivingSession(EpcisDocument $document): bool
    {
        if (! Schema::hasTable('receiving_sessions')) {
            return false;
        }

        return ReceivingSession::query()
            ->where('epcis_document_id', $document->getKey())
            ->whereIn('status', ['open', 'in_progress'])
            ->exists();
    }

    /**
     * @param  array{
     *     catalog_misses: list<string>,
     *     catalog_hits: int,
     *     labeler_blocked: list<string>,
     * }  $result
     */
    private static function noAuthorizationBody(array $result): string
    {
        if ($result['catalog_hits'] === 0 && $result['catalog_misses'] !== []) {
            return AssortmentFromCatalog::catalogMissMessage().' Use Open FDA Products for GTINs not in the Rx catalog.';
        }

        if ($result['labeler_blocked'] !== []) {
            return 'Catalog hits were skipped due to manufacturer labeler scope. Choose a matching receive-from partner or link the partner to the app catalog.';
        }

        return 'No catalog packages could be linked for the unknown GTINs in this file.';
    }

    /**
     * @param  array{added: int, attached: int, authorized_gtins: list<string>}  $result
     */
    private static function successTitle(array $result): string
    {
        $count = count($result['authorized_gtins']);

        if ($result['added'] > 0) {
            return $count === 1
                ? 'Product authorized from catalog'
                : "{$count} products authorized from catalog";
        }

        if ($result['attached'] > 0) {
            return $count === 1
                ? 'Product linked to partner'
                : "{$count} products linked to partner";
        }

        return 'Assortment updated';
    }

    /**
     * @param  array{
     *     added: int,
     *     attached: int,
     *     skipped: int,
     *     catalog_misses: list<string>,
     *     labeler_blocked: list<string>,
     *     gtin_not_applied: list<string>,
     *     resolved_cases: int,
     *     reprocessed: bool,
     * }  $result
     */
    private static function successBody(array $result, TradingPartner $partner, EpcisDocument $document): string
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
            $parts[] = "{$result['skipped']} already linked to {$name}";
        }

        if ($result['catalog_misses'] !== []) {
            $parts[] = count($result['catalog_misses']).' GTIN(s) not in Rx catalog — use FDA Products';
        }

        if ($result['labeler_blocked'] !== []) {
            $parts[] = count($result['labeler_blocked']).' GTIN(s) skipped (labeler scope)';
        }

        if (($result['gtin_not_applied'] ?? []) !== []) {
            $parts[] = count($result['gtin_not_applied']).' GTIN(s) still missing from product master — add the packaging level manually';
        }

        if ($result['resolved_cases'] > 0) {
            $parts[] = $result['resolved_cases'].' exception case(s) resolved';
        }

        if ($result['reprocessed']) {
            $parts[] = 'document #'.$document->getKey().' re-processed';
        } elseif ($result['authorized_gtins'] !== [] && self::documentHasActiveReceivingSession($document)) {
            $parts[] = 're-process skipped (receiving in progress)';
        }

        return $parts !== [] ? implode('. ', $parts).'.' : 'Receive-from updated for '.$name.'.';
    }
}
