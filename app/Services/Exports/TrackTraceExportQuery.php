<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Actions\Epcis\SearchEpcisSchema;
use App\Models\DataExport;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Portal\PortalShipmentDisplay;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class TrackTraceExportQuery
{
    public function __construct(
        private readonly SearchEpcisSchema $search,
    ) {}

    public function countForExport(DataExport $export, ?User $actor): int
    {
        $query = $this->build($export, $actor);

        return (int) $query->count();
    }

    public function assertExportableRowCount(int $count): void
    {
        $maxRows = max(1, (int) config('tracepharma.exports.max_rows', 500_000));

        if ($count === 0) {
            throw new DomainException('No serialized units match the export criteria.');
        }

        if ($count > $maxRows) {
            throw new DomainException(
                "Export would return {$count} rows, which exceeds the limit of {$maxRows}. Refine your filters.",
            );
        }
    }

    public function build(DataExport $export, ?User $actor): Builder
    {
        $filters = is_array($export->filters) ? $export->filters : [];

        if (isset($filters['document_id'])) {
            return $this->documentQuery((int) $filters['document_id'], $actor);
        }

        if (! isset($filters['rules']) || ! is_array($filters['rules'])) {
            throw new InvalidArgumentException('Export filters must include document_id or rules.');
        }

        return $this->rulesQuery($filters['rules'], $actor);
    }

    public function resolveRequestingUser(DataExport $export): User
    {
        $userId = $export->requested_by_user_id;

        if ($userId === null) {
            throw new DomainException('Export requestor is no longer available.');
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            throw new DomainException('Export requestor is no longer available.');
        }

        return $user;
    }

    public function resolveDocumentId(DataExport $export, ?User $actor): int
    {
        $filters = is_array($export->filters) ? $export->filters : [];

        if (isset($filters['document_id'])) {
            $documentId = (int) $filters['document_id'];
            $this->assertDocumentAccess($documentId, $actor, $export);

            return $documentId;
        }

        if (! isset($filters['rules']) || ! is_array($filters['rules'])) {
            throw new InvalidArgumentException('Export filters must include document_id or rules.');
        }

        $documentIds = DB::query()
            ->fromSub($this->rulesQuery($filters['rules'], $actor), 'export_rows')
            ->select('document_id')
            ->distinct()
            ->whereNotNull('document_id')
            ->pluck('document_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($documentIds->isEmpty()) {
            throw new DomainException('No serialized units match the export criteria.');
        }

        if ($documentIds->count() > 1) {
            throw new DomainException(
                'Serialized Track & Trace PDF export requires a single inbound document. Pass document_id or narrow your filters.',
            );
        }

        return (int) $documentIds->first();
    }

    public function isPortalExport(DataExport $export): bool
    {
        $filters = is_array($export->filters) ? $export->filters : [];

        return (bool) ($filters['portal'] ?? false);
    }

    public function assertDocumentReady(DataExport $export, ?User $actor): void
    {
        $documentId = $this->resolveDocumentId($export, $actor);

        $document = EpcisDocument::query()->find($documentId);

        if ($document === null) {
            throw new DomainException('Document not found.');
        }

        if ($this->isPortalExport($export)) {
            if (! PortalShipmentDisplay::reportsAvailable($document)) {
                throw new DomainException(
                    'This shipment is not ready for a Serialized Track & Trace report yet.',
                );
            }

            return;
        }

        if (! in_array($document->status, ['parsed', 'validated'], true)) {
            throw new DomainException(
                'Document must be parsed or validated before generating a Serialized Track & Trace report.',
            );
        }
    }

    /**
     * @param  list<array{field?: string, operator?: string, value?: mixed, value_to?: mixed, boolean?: string}>  $rules
     */
    private function rulesQuery(array $rules, ?User $actor): Builder
    {
        $epcQuery = $this->search->buildExportableEpcQuery($rules, $actor);

        $documentIdSql = $this->latestDocumentIdSubquery($actor);

        return DB::query()
            ->fromSub($epcQuery->select('epcs.id'), 'filtered_epcs')
            ->join('epcs', 'epcs.id', '=', 'filtered_epcs.id')
            ->leftJoin('epc_ilmd', 'epc_ilmd.epc_id', '=', 'epcs.id')
            ->select([
                'epcs.id',
                'epcs.gtin14',
                'epcs.serial_number',
                'epcs.sscc18',
                'epcs.epc_uri',
                DB::raw('epc_ilmd.lot_number as lot_number'),
                DB::raw("({$documentIdSql}) as document_id"),
            ])
            ->orderBy('epcs.id');
    }

    private function documentQuery(int $documentId, ?User $actor): Builder
    {
        $this->assertDocumentAccess($documentId, $actor);

        $document = EpcisDocument::query()->find($documentId);

        if ($document === null) {
            throw new DomainException('Document not found.');
        }

        if (! Schema::hasTable('document_epcs') || ! Schema::hasTable('epcs')) {
            throw new DomainException('EPCIS schema is not available for export.');
        }

        $generation = (int) ($document->ingest_generation ?? 1);

        return DB::table('document_epcs as de')
            ->join('epcs', 'epcs.id', '=', 'de.epc_id')
            ->leftJoin('epc_ilmd', 'epc_ilmd.epc_id', '=', 'epcs.id')
            ->where('de.document_id', $documentId)
            ->where('de.ingest_generation', $generation)
            ->where('epcs.epc_type', 'sgtin')
            ->select([
                'epcs.id',
                'epcs.gtin14',
                'epcs.serial_number',
                'epcs.sscc18',
                'epcs.epc_uri',
                DB::raw('epc_ilmd.lot_number as lot_number'),
                DB::raw('de.document_id as document_id'),
            ])
            ->orderBy('epcs.id');
    }

    private function assertDocumentAccess(int $documentId, ?User $actor, ?DataExport $export = null): void
    {
        if ($export !== null && $this->isPortalExport($export)) {
            if (! EpcisDocument::query()->whereKey($documentId)->exists()) {
                throw new DomainException('Document not found.');
            }

            return;
        }

        if (! $actor instanceof User) {
            throw new DomainException('Export requestor is no longer available.');
        }

        $accessQuery = EpcisDocument::query()
            ->inboundCatalog()
            ->whereKey($documentId);

        $accessQuery = SiteAccess::constrainInboundDocuments($accessQuery, $actor);

        if (! $accessQuery->exists()) {
            throw new DomainException('You do not have access to export this document.');
        }
    }

    private function latestDocumentIdSubquery(?User $actor = null): string
    {
        $siteFilter = '';

        if ($actor instanceof User && ! $actor->can(Permissions::SitesAccessAll)) {
            $siteIds = SiteAccess::userSiteIds($actor)
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values();

            if ($siteIds->isEmpty()) {
                return '(SELECT NULL)';
            }

            $siteFilter = ' AND ed.ship_to_site_id IN ('.$siteIds->implode(',').')';
        }

        return <<<SQL
(SELECT de.document_id
 FROM document_epcs de
 INNER JOIN epcis_documents ed ON ed.id = de.document_id
 WHERE de.epc_id = epcs.id{$siteFilter}
 ORDER BY ed.processed_at DESC, ed.received_at DESC, ed.id DESC
 LIMIT 1)
SQL;
    }
}
