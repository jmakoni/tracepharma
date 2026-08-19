<?php

namespace App\Actions\Epcis;

use App\Actions\MasterData\AddFdaPackagesToTradingPartner;
use App\Actions\MasterData\AuthorizeFdaPackagingForPartner;
use App\Models\Epcis\EpcisDocument;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Exceptions\ExceptionAction as ExceptionActionModel;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\Exceptions\ExceptionType;
use App\Models\Receiving\ReceivingSession;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Exceptions\AssortmentFromCatalog;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use App\Support\Fda\FdaTenantLink;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bulk-authorize catalog hits for SGTIN GTIN-14s on an EPCIS document that are absent
 * from tenant product master (products.gtin / gtin14).
 */
final class AuthorizeMissingDocumentProducts
{
    public function __construct(
        private AddFdaPackagesToTradingPartner $addPackagesAction,
        private AuthorizeFdaPackagingForPartner $authorizePackaging,
        private ExceptionService $exceptionService,
        private ReprocessEpcisDocument $reprocessDocument,
    ) {}

    /**
     * Distinct SGTIN GTIN-14 values on the document's active ingest generation that are
     * not present in tenant products.gtin (or gtin14 when that column exists).
     *
     * @return list<string>
     */
    public static function unknownGtinsForDocument(EpcisDocument $document): array
    {
        $productColumn = self::productGtinColumn();

        if ($productColumn === null) {
            return [];
        }

        $documentEpcIds = $document->epcsQuery()->pluck('epcs.id')->map(fn ($id): int => (int) $id)->all();

        if ($documentEpcIds === []) {
            return [];
        }

        $sgtinGtins = collect();
        foreach (array_chunk($documentEpcIds, 1000) as $chunk) {
            $sgtinGtins = $sgtinGtins->merge(
                DB::table('epcs')
                    ->whereIn('id', $chunk)
                    ->where('epc_type', 'sgtin')
                    ->whereNotNull('gtin14')
                    ->pluck('gtin14'),
            );
        }

        $sgtinGtins = $sgtinGtins->map(fn ($v): string => (string) $v)->unique()->values();

        if ($sgtinGtins->isEmpty()) {
            return [];
        }

        $known = collect();
        foreach ($sgtinGtins->chunk(1000) as $chunk) {
            $known = $known->merge(
                DB::table('products')->whereIn($productColumn, $chunk->all())->pluck($productColumn),
            );
        }
        $known = $known->map(fn ($v): string => (string) $v)->flip();

        return $sgtinGtins
            ->reject(fn (string $gtin): bool => $known->has($gtin))
            ->values()
            ->all();
    }

    /**
     * Column tenant products publish their GTIN-14 in, or null when unavailable.
     */
    private static function productGtinColumn(): ?string
    {
        if (! Schema::hasTable('products')) {
            return null;
        }

        if (Schema::hasColumn('products', 'gtin14')) {
            return 'gtin14';
        }

        return Schema::hasColumn('products', 'gtin') ? 'gtin' : null;
    }

    private static function productExistsForGtin(string $gtin): bool
    {
        $productColumn = self::productGtinColumn();

        if ($productColumn === null) {
            return false;
        }

        return DB::table('products')->where($productColumn, $gtin)->exists();
    }

    /**
     * Preview catalog hits/misses for unknown GTINs (no mutations).
     *
     * @return array{
     *     unknown_gtins: list<string>,
     *     catalog_hits: int,
     *     catalog_misses: list<string>,
     * }
     */
    public static function preview(EpcisDocument $document): array
    {
        $unknownGtins = self::unknownGtinsForDocument($document);
        $misses = [];
        $hits = 0;

        foreach ($unknownGtins as $gtin) {
            if (AssortmentFromCatalog::findPackagingByGtin($gtin) === null) {
                $misses[] = $gtin;

                continue;
            }

            $hits++;
        }

        return [
            'unknown_gtins' => $unknownGtins,
            'catalog_hits' => $hits,
            'catalog_misses' => $misses,
        ];
    }

    /**
     * @return array{
     *     unknown_gtins: list<string>,
     *     catalog_hits: int,
     *     catalog_misses: list<string>,
     *     added: int,
     *     attached: int,
     *     skipped: int,
     *     manufacturer_pending: int,
     *     manufacturer_added: int,
     *     labeler_blocked: list<string>,
     *     authorized_gtins: list<string>,
     *     gtin_not_applied: list<string>,
     *     resolved_cases: int,
     *     reprocessed: bool,
     * }
     */
    public function handle(
        EpcisDocument $document,
        TradingPartner $partner,
        ?User $actor = null,
        bool $alsoResolve = true,
        bool $alsoReprocess = true,
        string $resolutionNotes = 'Bulk authorized missing GTINs from EPCIS document Products tab.',
    ): array {
        if (! JobRoleAccess::allows(Permissions::NavMasterData)) {
            throw new DomainException('Master data is not authorized for your job role.');
        }

        if (($alsoResolve || $alsoReprocess) && ! JobRoleAccess::allows(Permissions::NavExceptions)) {
            throw new DomainException('Exceptions are not authorized for your job role.');
        }

        $empty = [
            'unknown_gtins' => [],
            'catalog_hits' => 0,
            'catalog_misses' => [],
            'added' => 0,
            'attached' => 0,
            'skipped' => 0,
            'manufacturer_pending' => 0,
            'manufacturer_added' => 0,
            'labeler_blocked' => [],
            'authorized_gtins' => [],
            'gtin_not_applied' => [],
            'resolved_cases' => 0,
            'reprocessed' => false,
        ];

        $unknownGtins = self::unknownGtinsForDocument($document);

        if ($unknownGtins === []) {
            return $empty;
        }

        $result = $empty;
        $result['unknown_gtins'] = $unknownGtins;

        foreach ($unknownGtins as $gtin) {
            $packaging = AssortmentFromCatalog::findPackagingByGtin($gtin);

            if ($packaging === null) {
                $result['catalog_misses'][] = $gtin;

                continue;
            }

            $result['catalog_hits']++;

            if ($this->isLabelerBlocked($partner, $packaging)) {
                $result['labeler_blocked'][] = $gtin;

                continue;
            }

            $authorized = $this->authorizePackaging->handle(
                $partner,
                $packaging,
                gtinOverride: $gtin,
            );

            if ($authorized['added'] === 0 && $authorized['attached'] === 0 && $authorized['skipped'] === 0) {
                continue;
            }

            $result['added'] += $authorized['added'];
            $result['attached'] += $authorized['attached'];
            $result['skipped'] += $authorized['skipped'];
            $result['manufacturer_pending'] += $authorized['manufacturer_pending'];
            $result['manufacturer_added'] += $authorized['manufacturer_added'];

            // An authorization that resolved to a different packaging level leaves the
            // file's GTIN unknown to product master. Counting it as authorized would
            // clear the UNKNOWN_GTIN exception that is still true.
            if (self::productExistsForGtin($gtin)) {
                $result['authorized_gtins'][] = $gtin;
            } else {
                $result['gtin_not_applied'][] = $gtin;
            }
        }

        if ($alsoResolve && $actor !== null) {
            $result['resolved_cases'] = $this->resolveOpenProductExceptions(
                $document,
                $actor,
                $resolutionNotes,
                self::unknownGtinsForDocument($document),
            );
        }

        if ($alsoReprocess && $result['authorized_gtins'] !== []) {
            $result['reprocessed'] = $this->tryReprocess($document);
        }

        return $result;
    }

    /**
     * Resolve open document-scoped UNKNOWN_GTIN (and related master-data product) cases.
     *
     * Cases that still point at a GTIN missing from product master are left open — a
     * catalog miss, or a hit that authorized a different packaging level, is not a
     * correction.
     *
     * @param  list<string>  $stillUnknownGtins
     */
    public function resolveOpenProductExceptions(
        EpcisDocument $document,
        User $actor,
        string $notes,
        array $stillUnknownGtins = [],
    ): int {
        $rootCauseId = ExceptionRootCause::query()->where('code', 'internal_mapping_error')->value('id');

        $resolutionActionId = ExceptionActionModel::query()
            ->where('code', 'update_master_data')
            ->value('id');

        if ($rootCauseId === null || $resolutionActionId === null) {
            return 0;
        }

        $typeCodes = self::bulkResolvableProductTypeCodes();

        $cases = ExceptionCase::query()
            ->open()
            ->where('document_id', $document->getKey())
            ->whereHas('type', fn ($q) => $q->whereIn('code', $typeCodes))
            ->get();

        $resolved = 0;

        foreach ($cases as $case) {
            if ($this->caseReferencesGtin($case, $stillUnknownGtins)) {
                continue;
            }

            try {
                $this->exceptionService->resolve(
                    $case,
                    $actor,
                    (int) $rootCauseId,
                    (int) $resolutionActionId,
                    $notes,
                );
                $resolved++;
            } catch (ValidationException|Throwable) {
                continue;
            }
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $gtins
     */
    private function caseReferencesGtin(ExceptionCase $case, array $gtins): bool
    {
        if ($gtins === []) {
            return false;
        }

        $text = trim((string) $case->title.' '.(string) $case->description);

        foreach ($gtins as $gtin) {
            if ($gtin !== '' && str_contains($text, $gtin)) {
                return true;
            }
        }

        $linked = $case->epcs()
            ->whereNotNull('gtin14')
            ->pluck('gtin14')
            ->map(fn ($value): string => (string) $value)
            ->all();

        return array_intersect($linked, $gtins) !== [];
    }

    /**
     * @return list<string>
     */
    public static function bulkResolvableProductTypeCodes(): array
    {
        $codes = ['UNKNOWN_GTIN'];

        try {
            $types = ExceptionType::query()->where('is_active', true)->get(['code']);

            foreach ($types as $type) {
                $profile = ExceptionCorrectionProfile::for($type->code);

                if (
                    $profile->primaryActionKey() === ExceptionCorrectionProfile::ACTION_ADD_PRODUCT
                    && $profile->showsMasterDataProductForm()
                    && ! in_array($type->code, $codes, true)
                ) {
                    $codes[] = $type->code;
                }
            }
        } catch (Throwable) {
            // UNKNOWN_GTIN alone is sufficient when the catalog table is unavailable.
        }

        return $codes;
    }

    private function isLabelerBlocked(TradingPartner $partner, FdaProductPackaging $packaging): bool
    {
        if (! $this->addPackagesAction->requiresLabelerScope($partner)) {
            return false;
        }

        $partnerOrgId = FdaTenantLink::organizationId($partner);
        $listing = $packaging->relationLoaded('product') ? $packaging->product : $packaging->product()->first();
        $packageOrgId = $listing?->fda_organization_id;

        return $partnerOrgId === null
            || $packageOrgId === null
            || (int) $partnerOrgId !== (int) $packageOrgId;
    }

    private function tryReprocess(EpcisDocument $document): bool
    {
        if (Schema::hasTable('receiving_sessions')) {
            $activeReceiving = ReceivingSession::query()
                ->where('epcis_document_id', $document->getKey())
                ->whereIn('status', ['open', 'in_progress'])
                ->exists();

            if ($activeReceiving) {
                return false;
            }
        }

        try {
            $this->reprocessDocument->handle($document, Queue::getDefaultDriver() === 'sync');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
