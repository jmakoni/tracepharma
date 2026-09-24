<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Gs1\ElementString;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Containers still to scan on an inbound EPCIS receive, at the SOP level.
 *
 * SSCC policy lists SSCC-18s only. Case and tote policies list cases, never a
 * logistics pallet. A container already in custody is omitted even when its
 * expected line is still marked expected or confirmed.
 */
final class OutstandingReceiveTargets
{
    public const LIMIT = 40;

    private const CANDIDATE_WINDOW = 400;

    /**
     * @return array{
     *     heading: string,
     *     rows: list<array{id: int, label: string, type: string, can_remove: bool}>,
     *     caption: ?string
     * }
     */
    public function forSession(ReceivingSession $session): array
    {
        $empty = [
            'heading' => '',
            'rows' => [],
            'caption' => null,
        ];

        if (! $session->isInboundAsn() || $session->status === 'completed' || $session->status === 'cancelled') {
            return $empty;
        }

        $mode = ReceivingPolicy::forTenant(tenant())->edgeMode();
        $candidates = $this->candidates($session);

        if ($candidates === []) {
            return [
                'heading' => $this->heading($mode),
                'rows' => [],
                'caption' => null,
            ];
        }

        $matched = $this->match($session, $mode, $candidates);
        $matched = $this->withoutCustody($matched);
        $total = count($matched);
        $rows = array_slice($matched, 0, self::LIMIT);

        return [
            'heading' => $this->heading($mode),
            'rows' => $rows,
            'caption' => $total > self::LIMIT
                ? 'Showing '.self::LIMIT.' of '.$total
                : null,
        ];
    }

    /**
     * @param  list<array{id: int, label: string, type: string, can_remove: bool}>  $rows
     */
    private function heading(ReceivingEdgeMode $mode): string
    {
        return match ($mode) {
            ReceivingEdgeMode::SealedParent => 'SSCC still to scan',
            ReceivingEdgeMode::CaseOnly => 'Cases still to scan',
            ReceivingEdgeMode::ToteLpn, ReceivingEdgeMode::OpenTote => 'Cases still to scan',
            ReceivingEdgeMode::UnitsOnly, ReceivingEdgeMode::OpenCount => 'Units still to scan',
        };
    }

    /**
     * @return list<array{id: int, epc: Epc, line_role: string}>
     */
    private function candidates(ReceivingSession $session): array
    {
        $mode = ReceivingPolicy::forTenant(tenant())->edgeMode();
        $preferChild = in_array($mode, [ReceivingEdgeMode::UnitsOnly, ReceivingEdgeMode::OpenCount], true);

        if ($session->inbound_shipment_id !== null && Schema::hasTable('inbound_expected_lines')) {
            $query = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $session->inbound_shipment_id)
                ->whereIn('status', ['expected', 'confirmed'])
                ->with(['epc:id,epc_uri,sscc18,gtin14,serial_number,epc_type,ai_01_21,ai_00']);

            if ($this->scansSscc($mode)) {
                $query->whereHas('epc', fn ($epc) => $epc->where('epc_type', 'sscc'));
            }

            if (Schema::hasColumn('inbound_expected_lines', 'claimed_receiving_session_id')) {
                $sessionId = (int) $session->getKey();
                $query->where(function ($inner) use ($sessionId): void {
                    $inner->whereNull('claimed_receiving_session_id')
                        ->orWhere('claimed_receiving_session_id', $sessionId);
                });
            }

            return $this->mapLines($query->orderByRaw($preferChild ? "line_role = 'child' desc" : "line_role = 'parent' desc")->orderBy('id')->limit(self::CANDIDATE_WINDOW)->get());
        }

        $lines = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->whereIn('status', ['expected', 'confirmed'])
            ->when($this->scansSscc($mode), fn ($query) => $query->whereHas('epc', fn ($epc) => $epc->where('epc_type', 'sscc')))
            ->with(['epc:id,epc_uri,sscc18,gtin14,serial_number,epc_type,ai_01_21,ai_00'])
            ->orderByRaw($preferChild ? "line_role = 'child' desc" : "line_role = 'parent' desc")
            ->orderBy('id')
            ->limit(self::CANDIDATE_WINDOW)
            ->get();

        return $this->mapLines($lines);
    }

    /**
     * @param  Collection<int, InboundExpectedLine|ReceivingScanLine>  $lines
     * @return list<array{id: int, epc: Epc, line_role: string}>
     */
    private function mapLines($lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            $epc = $line->epc;

            if (! $epc instanceof Epc) {
                continue;
            }

            $out[] = [
                'id' => (int) $epc->getKey(),
                'epc' => $epc,
                'line_role' => (string) $line->line_role,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{id: int, epc: Epc, line_role: string}>  $candidates
     * @return list<array{id: int, label: string, type: string, can_remove: bool}>
     */
    private function match(ReceivingSession $session, ReceivingEdgeMode $mode, array $candidates): array
    {
        $shapes = $this->shapes($session, $candidates);

        $rowsFor = function (array $picked, string $forcedType = '') use ($shapes): array {
            $rows = [];

            foreach ($picked as $candidate) {
                $epc = $candidate['epc'];
                $isSscc = $epc->epc_type === 'sscc';
                $shape = $shapes[$candidate['id']] ?? 'unit';
                $rows[] = [
                    'id' => $candidate['id'],
                    'label' => $isSscc ? $this->ssccLabel($epc) : $this->label($epc),
                    'type' => $forcedType !== '' ? $forcedType : ($isSscc ? 'SSCC' : match ($shape) {
                        'case' => 'Case',
                        default => 'Unit',
                    }),
                    'can_remove' => false,
                ];
            }

            return $rows;
        };

        if ($this->scansSscc($mode)) {
            return $rowsFor(array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => $candidate['epc']->epc_type === 'sscc',
            )), 'SSCC');
        }

        if (in_array($mode, [ReceivingEdgeMode::UnitsOnly, ReceivingEdgeMode::OpenCount], true)) {
            return $rowsFor(array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => ($shapes[$candidate['id']] ?? null) === 'unit',
            )));
        }

        return $rowsFor(array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => ($shapes[$candidate['id']] ?? null) === 'case',
        )));
    }

    /**
     * @param  list<array{id: int, epc: Epc, line_role: string}>  $candidates
     * @return array<int, string>
     */
    private function shapes(ReceivingSession $session, array $candidates): array
    {
        $ids = array_column($candidates, 'id');
        $childTypes = $this->childTypesByParent($session, $ids);
        $shapes = [];

        foreach ($candidates as $candidate) {
            $types = $childTypes[$candidate['id']] ?? [];
            $hasChild = $types !== [];
            $hasSsccChild = in_array('sscc', $types, true);
            $isSscc = $candidate['epc']->epc_type === 'sscc';

            $shapes[$candidate['id']] = match (true) {
                $isSscc && $hasSsccChild => 'pallet',
                $hasChild && ! $hasSsccChild => 'case',
                $isSscc => 'case',
                default => 'unit',
            };
        }

        return $shapes;
    }

    /**
     * @param  list<int>  $parentIds
     * @return array<int, list<string>>
     */
    private function childTypesByParent(ReceivingSession $session, array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $documentIds = $this->documentIds($session);

        $query = AggregationLink::query()
            ->whereIn('parent_epc_id', $parentIds)
            ->whereNull('valid_to')
            ->whereHas('childEpc');

        if ($documentIds !== []) {
            $query->whereIn('established_by_event_id', function ($sub) use ($documentIds): void {
                $sub->select('id')
                    ->from('epcis_events')
                    ->whereIn('document_id', $documentIds);
            });
        }

        $grouped = [];

        foreach ($query->with('childEpc:id,epc_type')->get(['id', 'parent_epc_id', 'child_epc_id']) as $link) {
            $parentId = (int) $link->parent_epc_id;
            $type = (string) ($link->childEpc?->epc_type ?? '');

            if ($type === '') {
                continue;
            }

            $grouped[$parentId][] = $type;
        }

        return $grouped;
    }

    /**
     * @return list<int>
     */
    private function documentIds(ReceivingSession $session): array
    {
        if (
            $session->inbound_shipment_id !== null
            && Schema::hasColumn('epcis_documents', 'inbound_shipment_id')
        ) {
            $ids = EpcisDocument::query()
                ->where('inbound_shipment_id', $session->inbound_shipment_id)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($ids !== []) {
                return $ids;
            }
        }

        $ids = [];

        if ($session->epcis_document_id !== null) {
            $ids[] = (int) $session->epcis_document_id;
        }

        if ($session->matched_epcis_document_id !== null) {
            $ids[] = (int) $session->matched_epcis_document_id;
        }

        return array_values(array_unique($ids));
    }

    private function label(Epc $epc): string
    {
        foreach (['ai_01_21', 'sscc18', 'ai_00', 'epc_uri'] as $column) {
            if (filled($epc->{$column})) {
                return ElementString::identityBarcodeDisplay((string) $epc->{$column});
            }
        }

        return 'EPC #'.$epc->getKey();
    }

    private function ssccLabel(Epc $epc): string
    {
        foreach (['sscc18', 'ai_00'] as $column) {
            if (filled($epc->{$column})) {
                return ElementString::identityBarcodeDisplay((string) $epc->{$column});
            }
        }

        if (filled($epc->epc_uri)) {
            return ElementString::identityBarcodeDisplay((string) $epc->epc_uri);
        }

        return 'SSCC #'.$epc->getKey();
    }

    /**
     * @param  list<array{id: int, label: string, type: string, can_remove: bool}>  $rows
     * @return list<array{id: int, label: string, type: string, can_remove: bool}>
     */
    private function withoutCustody(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $inCustody = array_flip(app(EpcCustodyGate::class)->epcIdsInCustody(array_column($rows, 'id')));

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ! isset($inCustody[$row['id']]),
        ));
    }

    private function scansSscc(ReceivingEdgeMode $mode): bool
    {
        return $mode === ReceivingEdgeMode::SealedParent;
    }
}
