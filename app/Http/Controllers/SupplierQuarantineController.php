<?php

namespace App\Http\Controllers;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\TradingPartner;
use App\Services\Quarantine\QuarantineService;
use App\Services\Quarantine\SupplierQuarantineTableBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class SupplierQuarantineController extends Controller
{
    private const IDENTIFIER_PER_PAGE_OPTIONS = [50, 100, 250];

    public function show(Request $request, string $shareUuid): View
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['page', 'per_page']), 403);

        $case = ExceptionCase::query()
            ->where('share_uuid', $shareUuid)
            ->with([
                'type:id,name,code',
                'tradingPartner:id,name,is_active,portal_share_uuid',
                'document:id,customer_po,asn_number,ingest_generation',
                'quarantineHolds.epc.product:id,name,ndc,package_ndc,ndc11',
                'quarantineHolds.epc.ilmd',
                'quarantineHolds.document:id,customer_po',
                'epcs.product:id,name,ndc,package_ndc,ndc11',
                'epcs.ilmd',
            ])
            ->firstOrFail();

        $this->assertSupplierCollaborationAccess($case);

        $openHoldCount = QuarantineHold::query()
            ->open()
            ->where('exception_id', $case->getKey())
            ->count();

        $partnerActivities = $case->activities()
            ->where('visibility', ExceptionActivityVisibility::Partner->value)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'kind', 'body', 'created_at']);

        $builder = app(SupplierQuarantineTableBuilder::class);
        $allIdentifierRows = $builder->identifierRows($case);
        $summaryRows = $builder->summaryRows($allIdentifierRows);
        $documentScoped = $builder->isDocumentScoped($case);
        $shipmentRef = $allIdentifierRows->first()['po'] ?? null;
        if ($shipmentRef === '—' || $shipmentRef === 'Not in file') {
            $shipmentRef = null;
        }

        $perPage = (int) $request->query('per_page', 50);
        if (! in_array($perPage, self::IDENTIFIER_PER_PAGE_OPTIONS, true)) {
            $perPage = 50;
        }

        $page = max(1, (int) $request->query('page', 1));
        $total = $allIdentifierRows->count();
        $identifierRows = new LengthAwarePaginator(
            $allIdentifierRows->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => 'page',
            ],
        );
        $identifierRows->withQueryString();

        return view('supplier-quarantine.show', [
            'case' => $case,
            'openHoldCount' => $openHoldCount,
            'partnerActivities' => $partnerActivities,
            'identifierRows' => $identifierRows,
            'summaryRows' => $summaryRows,
            'commentUrl' => app(QuarantineService::class)->signedSupplierCommentUrl($case),
            'documentScoped' => $documentScoped,
            'shipmentRef' => $shipmentRef,
            'perPage' => $perPage,
            'perPageOptions' => self::IDENTIFIER_PER_PAGE_OPTIONS,
            'identifierTotal' => $total,
        ]);
    }

    public function comment(Request $request, string $shareUuid): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $case = ExceptionCase::query()
            ->where('share_uuid', $shareUuid)
            ->firstOrFail();

        $this->assertSupplierCollaborationAccess($case);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'supplier_name' => ['nullable', 'string', 'max:150'],
        ]);

        $prefix = filled($data['supplier_name'] ?? null)
            ? '['.trim((string) $data['supplier_name']).'] '
            : '[Supplier] ';

        $case->logActivity(
            ExceptionActivityKind::Comment,
            null,
            $prefix.trim((string) $data['body']),
            ExceptionActivityVisibility::Partner,
            ['source' => 'supplier_quarantine_page'],
        );

        return redirect()
            ->to(app(QuarantineService::class)->signedSupplierUrl($case))
            ->with('status', 'Your response was recorded.');
    }

    private function assertSupplierCollaborationAccess(ExceptionCase $case): void
    {
        abort_unless($case->status?->isOpen() === true, 403, 'This exception is no longer open for supplier collaboration.');

        if ($case->share_expires_at !== null && $case->share_expires_at->isPast()) {
            abort(403, 'This supplier collaboration link has expired.');
        }

        if ($case->trading_partner_id === null) {
            return;
        }

        $partner = $case->relationLoaded('tradingPartner')
            ? $case->tradingPartner
            : $case->tradingPartner()->first(['id', 'is_active', 'portal_share_uuid']);

        abort_if(
            ! $partner instanceof TradingPartner
            || ! $partner->is_active
            || $partner->portal_share_uuid === null,
            403,
            'This supplier portal link is no longer active.',
        );
    }
}
