<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClientPortal;

use App\Actions\Portal\QueuePortalSerializedTrackTraceExport;
use App\Enums\Portal\PortalShipmentExportFormat;
use App\Enums\Portal\PortalShipmentExportGrain;
use App\Http\Controllers\Controller;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\PortalPublication;
use App\Models\PortalUser;
use App\Services\Dscsa\TransactionReportGenerator;
use App\Services\Portal\ClientPortalAccess;
use App\Services\Portal\PortalShipmentsExportService;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use App\Support\Portal\PortalShipmentDisplay;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ShipmentController extends Controller
{
    public function index(Request $request, ClientPortalAccess $access): View
    {
        /** @var PortalUser $user */
        $user = $request->user('portal');

        $from = $this->parseDate($request->query('from'), endOfDay: false);
        $to = $this->parseDate($request->query('to'), endOfDay: true);
        $po = filled($request->query('po')) ? trim((string) $request->query('po')) : null;

        $publications = $this
            ->filteredPublicationsQuery($access, $user, $from, $to, $po)
            ->paginate(50)
            ->withQueryString();

        return view('client-portal.shipments.index', [
            'publications' => $publications,
            'filters' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'po' => $po,
            ],
        ]);
    }

    public function show(Request $request, int $document, ClientPortalAccess $access): View
    {
        /** @var PortalUser $user */
        $user = $request->user('portal');

        $row = EpcisDocument::query()->findOrFail($document);
        $access->assertDocumentVisible($user, $row);

        $events = EpcisEvent::query()
            ->where('document_id', $row->getKey())
            ->notSuperseded()
            ->with([
                'epcs:id,epc_type,gtin14,sscc18,epc_uri,serial_number',
                'epcIlmd',
            ])
            ->orderBy('event_time')
            ->orderBy('id')
            ->limit(500)
            ->get([
                'id',
                'document_id',
                'event_type',
                'event_time',
                'action',
                'biz_step',
                'disposition',
            ]);

        $tiSummary = $this->buildTiSummary($events);

        return view('client-portal.shipments.show', [
            'document' => $row,
            'events' => $events,
            'tiSummary' => $tiSummary,
            'downloadAvailable' => EpcisDocumentXmlDownload::available($row),
            'reportsAvailable' => PortalShipmentDisplay::reportsAvailable($row),
        ]);
    }

    public function download(Request $request, int $document, ClientPortalAccess $access): StreamedResponse
    {
        /** @var PortalUser $user */
        $user = $request->user('portal');

        $row = EpcisDocument::query()->findOrFail($document);
        $access->assertDocumentVisible($user, $row);
        abort_unless(EpcisDocumentXmlDownload::available($row), 404, 'EPCIS file is not available.');

        $download = EpcisDocumentXmlDownload::response($row);
        $download->headers->set('Cache-Control', 'no-store, private');

        return $download;
    }

    public function downloadTrackTrace(
        Request $request,
        int $document,
        ClientPortalAccess $access,
        TransactionReportGenerator $generator,
    ): StreamedResponse {
        /** @var PortalUser $user */
        $user = $request->user('portal');

        $row = EpcisDocument::query()->findOrFail($document);
        $access->assertDocumentVisible($user, $row);
        abort_unless(PortalShipmentDisplay::reportsAvailable($row), 404, 'Track & Trace report is not available yet.');

        $result = $generator->generate($row, null);

        return response()->streamDownload(
            static function () use ($result): void {
                echo $result['binary'];
            },
            $result['filename'],
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    public function queueSerializedTrackTrace(
        Request $request,
        int $document,
        ClientPortalAccess $access,
        QueuePortalSerializedTrackTraceExport $queue,
    ): RedirectResponse {
        /** @var PortalUser $user */
        $user = $request->user('portal');

        $row = EpcisDocument::query()->findOrFail($document);
        $access->assertDocumentVisible($user, $row);
        abort_unless(PortalShipmentDisplay::reportsAvailable($row), 404, 'Serialized Track & Trace report is not available yet.');

        try {
            $queue->handle($row, $user);
        } catch (DomainException $exception) {
            return redirect()
                ->route('tenant.client-portal.shipments.show', ['document' => $row->getKey()])
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('tenant.client-portal.shipments.show', ['document' => $row->getKey()])
            ->with(
                'status',
                'Your Serialized Track & Trace PDF is generating. We will email a download link to '.$user->email.' when it is ready.',
            );
    }

    public function export(
        Request $request,
        ClientPortalAccess $access,
        PortalShipmentsExportService $exporter,
    ): StreamedResponse|RedirectResponse {
        /** @var PortalUser $user */
        $user = $request->user('portal');

        $grain = PortalShipmentExportGrain::tryFromRequest($request->query('grain'))
            ?? PortalShipmentExportGrain::Summary;
        $format = PortalShipmentExportFormat::tryFromRequest($request->query('format'))
            ?? PortalShipmentExportFormat::Csv;

        $from = $this->parseDate($request->query('from'), endOfDay: false);
        $to = $this->parseDate($request->query('to'), endOfDay: true);
        $po = filled($request->query('po')) ? trim((string) $request->query('po')) : null;

        $publications = $this
            ->filteredPublicationsQuery($access, $user, $from, $to, $po)
            ->get();

        try {
            return $exporter->download($publications, $grain, $format);
        } catch (DomainException $exception) {
            return redirect()
                ->route('tenant.client-portal.shipments.index', array_filter([
                    'from' => $from?->toDateString(),
                    'to' => $to?->toDateString(),
                    'po' => $po,
                ]))
                ->with('error', $exception->getMessage());
        }
    }

    public function exportDocument(
        Request $request,
        int $document,
        ClientPortalAccess $access,
        PortalShipmentsExportService $exporter,
    ): StreamedResponse|RedirectResponse {
        /** @var PortalUser $user */
        $user = $request->user('portal');

        $row = EpcisDocument::query()->findOrFail($document);
        $access->assertDocumentVisible($user, $row);

        $grain = PortalShipmentExportGrain::tryFromRequest($request->query('grain'))
            ?? PortalShipmentExportGrain::Lines;
        $format = PortalShipmentExportFormat::tryFromRequest($request->query('format'))
            ?? PortalShipmentExportFormat::Csv;

        $publication = PortalPublication::query()
            ->active()
            ->where('epcis_document_id', $row->getKey())
            ->with([
                'document:id,document_uuid,original_filename,creation_date,created_at,event_count,epc_count,customer_po,asn_number,direction,payload_path,format,status,payload_disk,trading_partner_id',
                'tradingPartner:id,name',
            ])
            ->firstOrFail();

        try {
            return $exporter->download(collect([$publication]), $grain, $format);
        } catch (DomainException $exception) {
            return redirect()
                ->route('tenant.client-portal.shipments.show', ['document' => $row->getKey()])
                ->with('error', $exception->getMessage());
        }
    }

    /**
     * @return Builder<PortalPublication>
     */
    private function filteredPublicationsQuery(
        ClientPortalAccess $access,
        PortalUser $user,
        ?Carbon $from,
        ?Carbon $to,
        ?string $po,
    ): Builder {
        $query = $access->publicationsQuery($user)
            ->with([
                'document:id,document_uuid,original_filename,creation_date,created_at,event_count,epc_count,customer_po,asn_number,direction,payload_path,format,status,payload_disk,trading_partner_id',
                'tradingPartner:id,name',
            ])
            ->join('epcis_documents', 'epcis_documents.id', '=', 'portal_publications.epcis_document_id')
            ->select('portal_publications.*')
            ->orderByDesc('portal_publications.published_at');

        if ($from !== null) {
            $query->where('portal_publications.published_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('portal_publications.published_at', '<=', $to);
        }

        if ($po !== null) {
            $like = '%'.$po.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('epcis_documents.customer_po', 'like', $like)
                    ->orWhere('epcis_documents.asn_number', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @return list<array{gtin: ?string, lot: ?string, expiry: ?string, biz_step: ?string, qty: int}>
     */
    private function buildTiSummary($events): array
    {
        $rows = [];

        foreach ($events as $event) {
            $bizStep = filled($event->biz_step) ? (string) $event->biz_step : null;
            $ilmdByEpc = $event->epcIlmd->keyBy('epc_id');

            foreach ($event->epcs as $epc) {
                $ilmd = $ilmdByEpc->get($epc->getKey());
                $gtin = filled($epc->gtin14) ? (string) $epc->gtin14 : null;
                $lot = filled($ilmd?->lot_number) ? (string) $ilmd->lot_number : null;
                $expiry = $ilmd?->expiry_date?->toDateString();
                $key = implode('|', [$gtin ?? '', $lot ?? '', $expiry ?? '', $bizStep ?? '']);

                if (! isset($rows[$key])) {
                    $rows[$key] = [
                        'gtin' => $gtin,
                        'lot' => $lot,
                        'expiry' => $expiry,
                        'biz_step' => $bizStep,
                        'qty' => 0,
                    ];
                }

                $rows[$key]['qty']++;
            }

            if ($event->epcs->isEmpty() && $bizStep !== null) {
                $key = 'step|'.$bizStep.'|'.$event->getKey();
                $rows[$key] = [
                    'gtin' => null,
                    'lot' => null,
                    'expiry' => null,
                    'biz_step' => $bizStep,
                    'qty' => 0,
                ];
            }
        }

        return array_values($rows);
    }

    private function parseDate(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if (! is_string($value) || ! filled($value)) {
            return null;
        }

        try {
            $date = Carbon::parse($value);

            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
