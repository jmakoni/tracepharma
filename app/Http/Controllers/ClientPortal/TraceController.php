<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClientPortal;

use App\Http\Controllers\Controller;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisEvent;
use App\Models\PortalUser;
use App\Services\Portal\ClientPortalAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TraceController extends Controller
{
    public function index(Request $request, ClientPortalAccess $access): View
    {
        /** @var PortalUser $user */
        $user = $request->user('portal');

        $code = filled($request->query('code')) ? trim((string) $request->query('code')) : null;
        $events = collect();
        $children = collect();
        $epc = null;
        $searched = false;

        if ($code !== null && $code !== '') {
            $searched = true;
            $documentIds = $access->publishedDocumentIdsFor($user);

            if ($documentIds !== []) {
                $epc = $this->resolveEpc($code);

                if ($epc !== null) {
                    $events = EpcisEvent::query()
                        ->whereIn('document_id', $documentIds)
                        ->notSuperseded()
                        ->whereHas('epcs', fn ($q) => $q->where('epcs.id', $epc->getKey()))
                        ->orderBy('event_time')
                        ->orderBy('id')
                        ->limit(200)
                        ->get([
                            'id',
                            'document_id',
                            'event_type',
                            'event_time',
                            'action',
                            'biz_step',
                            'disposition',
                            'read_point_gln',
                            'biz_location_gln',
                        ]);

                    if ($events->isNotEmpty()) {
                        $children = AggregationLink::query()
                            ->open()
                            ->where('parent_epc_id', $epc->getKey())
                            ->with('childEpc:id,epc_type,epc_uri,gtin14,sscc18,serial_number,ai_01_21,ai_00')
                            ->limit(100)
                            ->get();
                    } else {
                        $epc = null;
                    }
                }
            }
        }

        return view('client-portal.trace.index', [
            'code' => $code,
            'searched' => $searched,
            'epc' => $epc,
            'events' => $events,
            'children' => $children,
        ]);
    }

    private function resolveEpc(string $input): ?Epc
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        $byUri = Epc::query()->where('epc_uri', $input)->first();
        if ($byUri !== null) {
            return $byUri;
        }

        $attrs = Epc::materializeAttributesFromUri($input);
        if (isset($attrs['epc_uri']) && $attrs['epc_uri'] !== $input) {
            $byParsed = Epc::query()->where('epc_uri', $attrs['epc_uri'])->first();
            if ($byParsed !== null) {
                return $byParsed;
            }
        }

        return Epc::query()
            ->where(function ($q) use ($input): void {
                $q->where('ai_01_21', $input)
                    ->orWhere('ai_00', $input)
                    ->orWhere('sscc18', $input)
                    ->orWhere('digital_link', $input)
                    ->orWhere('gtin14', $input);
            })
            ->first();
    }
}
