<?php

namespace Tests\Support;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

trait PlacesEpcOnHandAtSite
{
    /**
     * @return array{document: EpcisDocument, event: EpcisEvent}
     */
    protected function placeEpcOnHandAtSite(Site $site, Epc $epc): array
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'status' => 'validated',
        ]);

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => $site->gln,
            'biz_location_gln' => $site->gln,
        ]);

        DB::table('event_epcs')->insert([
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]);

        return ['document' => $document, 'event' => $event];
    }
}
