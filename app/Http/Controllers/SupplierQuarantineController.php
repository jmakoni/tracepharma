<?php

namespace App\Http\Controllers;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\EpcisReceivedVia;
use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\TradingPartner;
use App\Services\Quarantine\QuarantineService;
use App\Services\Quarantine\SupplierQuarantineTableBuilder;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\Exceptions\GroupDocumentExceptionSignals;
use App\Support\Exceptions\ExceptionReceiveImpactMap;
use App\Support\Filesystem\SafeFilename;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class SupplierQuarantineController extends Controller
{
    private const IDENTIFIER_PER_PAGE_OPTIONS = [50, 100, 250];

    public function show(Request $request, string $shareUuid): View
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['page', 'per_page']), 403);

        $case = ExceptionCase::query()
            ->where('share_uuid', $shareUuid)
            ->with([
                'type:id,name,code,receive_impact',
                'tradingPartner:id,name,is_active,portal_share_uuid',
                'document:id,customer_po,asn_number,ingest_generation,event_count,epc_count',
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

        $canUploadCorrectedEpcis = $this->allowsCorrectedEpcisUpload($case);
        $signalGroups = collect();
        if ($case->document !== null) {
            $signalGroups = app(GroupDocumentExceptionSignals::class)->handle($case->document);
        }

        return view('supplier-quarantine.show', [
            'case' => $case,
            'openHoldCount' => $openHoldCount,
            'partnerActivities' => $partnerActivities,
            'identifierRows' => $identifierRows,
            'summaryRows' => $summaryRows,
            'commentUrl' => app(QuarantineService::class)->signedSupplierCommentUrl($case),
            'uploadUrl' => $canUploadCorrectedEpcis
                ? app(QuarantineService::class)->signedSupplierUploadUrl($case)
                : null,
            'canUploadCorrectedEpcis' => $canUploadCorrectedEpcis,
            'signalGroups' => $signalGroups,
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

    public function upload(Request $request, string $shareUuid): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $case = ExceptionCase::query()
            ->where('share_uuid', $shareUuid)
            ->with(['type:id,name,code,receive_impact', 'tradingPartner:id,is_active,portal_share_uuid', 'epcs', 'quarantineHolds'])
            ->firstOrFail();

        $this->assertSupplierCollaborationAccess($case);
        abort_unless($this->allowsCorrectedEpcisUpload($case), 403, 'Corrected EPCIS cannot be uploaded for this case.');

        $request->validate([
            'file' => ['required', 'file', 'max:51200'],
        ]);

        $file = $request->file('file');
        $originalFilename = SafeFilename::forUpload($file?->getClientOriginalName(), 'supplier-correction.xml');
        $extension = strtolower((string) $file?->getClientOriginalExtension());
        if ($extension !== 'xml') {
            return redirect()
                ->to(app(QuarantineService::class)->signedSupplierUrl($case))
                ->withErrors(['file' => 'Upload must be an EPCIS .xml file.']);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_supplier_');
        if ($tmp === false) {
            return redirect()
                ->to(app(QuarantineService::class)->signedSupplierUrl($case))
                ->withErrors(['file' => 'Unable to store the uploaded EPCIS file.']);
        }

        $absolutePath = $tmp.'.xml';
        rename($tmp, $absolutePath);
        file_put_contents($absolutePath, (string) $file->get());

        try {
            $this->assertEpcis12Schema($absolutePath);

            $document = app(ReceiveEpcisUpload::class)->handle($absolutePath, [
                'direction' => 'inbound',
                'received_via' => EpcisReceivedVia::FilamentUpload,
                'trading_partner_id' => $case->trading_partner_id,
                'notes' => 'Supplier portal correction',
                'original_filename' => $originalFilename,
                'dispatch' => true,
            ]);
        } catch (DuplicateEpcisUploadException $e) {
            return redirect()
                ->to(app(QuarantineService::class)->signedSupplierUrl($case))
                ->withErrors(['file' => 'This file was already received. Upload a different corrected EPCIS file.']);
        } catch (Throwable $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }

            report($e);

            return redirect()
                ->to(app(QuarantineService::class)->signedSupplierUrl($case))
                ->withErrors(['file' => 'Unable to receive this file. Upload a valid EPCIS 1.2 XML file and try again.']);
        } finally {
            @unlink($absolutePath);
        }

        $case->logActivity(
            ExceptionActivityKind::Comment,
            null,
            '[Supplier] Uploaded corrected EPCIS: '.$originalFilename,
            ExceptionActivityVisibility::Partner,
            [
                'source' => 'supplier_quarantine_page',
                'epcis_document_id' => $document->getKey(),
            ],
        );

        return redirect()
            ->to(app(QuarantineService::class)->signedSupplierUrl($case))
            ->with('status', 'Corrected EPCIS was received and queued for processing.');
    }

    private function allowsCorrectedEpcisUpload(ExceptionCase $case): bool
    {
        if (! $case->isDocumentScoped()) {
            return false;
        }

        $type = $case->relationLoaded('type')
            ? $case->type
            : $case->type()->first(['id', 'code', 'receive_impact']);

        if ($type === null) {
            return false;
        }

        if ($type->receive_impact !== null) {
            return $type->blocksReceiving();
        }

        return ExceptionReceiveImpactMap::forCode((string) $type->code)->blocksReceiving();
    }

    private function assertEpcis12Schema(string $absolutePath): void
    {
        try {
            EpcisSchemaVersion::assertAccepted(
                EpcisSchemaVersion::peekFile($absolutePath),
                EpcisSchemaVersion::detectFormat($absolutePath),
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
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
