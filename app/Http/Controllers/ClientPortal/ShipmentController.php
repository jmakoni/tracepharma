<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClientPortal;

use App\Http\Controllers\Controller;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\PortalUser;
use App\Services\Portal\ClientPortalAccess;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $query = $access->publicationsQuery($user)
            ->with([
                'document:id,document_uuid,original_filename,creation_date,created_at,event_count,epc_count,customer_po,asn_number,direction,payload_path,format',
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

        $publications = $query->paginate(50)->withQueryString();

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

    /**
     * @param  \Illuminate\Support\Collection<int, EpcisEvent>  $events
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
