<?php

namespace App\Support\Epcis;

use App\Models\Epcis\Epc;
use App\Models\Product;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Dscsa\Support\DscsaDirectPurchaseStatements;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use App\Support\Gs1\Sgtin;
use App\Support\Shipping\ResolveOwningPartySite;
use App\Support\TenantSettings;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Build a self-contained EPCIS 1.2 shipping document that REPLAYS prior
 * commissioning/packing from retained inbound (or authored) payloads for the
 * open aggregation tree, then appends only the wholesaler shipping ObjectEvent.
 *
 * Does not invent manufacturer history or retimestamp commission/pack events.
 */
final class BuildFullHistoryShippingEpcisXml
{
    public function __construct(
        private readonly ResolveOwningPartySite $resolveOwningPartySite,
        private readonly DscsaDirectPurchaseStatements $directPurchaseStatements,
        private readonly ExtractPriorPedigreeXml $extractPriorPedigreeXml,
    ) {}

    /**
     * @return array{xml: string, filename: string, path: string, ship_event_time: Carbon, instance_id: string}
     */
    public function handle(OutboundShippingSession $session): array
    {
        $session->loadMissing(['site', 'tradingPartner', 'shipToSite', 'epcisDocument']);

        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new DomainException('Tenant context required.');
        }

        $ssccIds = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->where('line_role', 'parent')
            ->orderBy('id')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ssccIds === []) {
            throw new DomainException('No confirmed parent EPCs on shipping session.');
        }

        /** @var Collection<int, Epc> $ssccs */
        $ssccs = Epc::query()->whereIn('id', $ssccIds)->get()->keyBy('id');

        $shipEventTime = $session->completed_at !== null
            ? Carbon::parse($session->completed_at)->utc()
            : Carbon::parse($session->epcisDocument?->creation_date ?? now())->utc();

        $shippingEventId = $this->resolveShippingEventId($session)
            ?? 'urn:uuid:'.(string) Str::uuid();

        $parties = $this->resolveParties($session);
        $pedigree = $this->extractPriorPedigreeXml->forOpenTree($ssccIds);

        $ssccUris = [];
        foreach ($ssccIds as $ssccId) {
            $sscc = $ssccs->get($ssccId);
            if ($sscc instanceof Epc) {
                $ssccUris[] = (string) $sscc->epc_uri;
            }
        }

        $instanceId = SbdhInstanceIdentifier::uuid();
        $directPurchaseStatement = $this->resolveDirectPurchaseStatement($session);

        $xml = $this->render(
            session: $session,
            shipEventTime: $shipEventTime,
            shippingEventId: $shippingEventId,
            parties: $parties,
            ssccUris: $ssccUris,
            pedigree: $pedigree,
            instanceId: $instanceId,
            directPurchaseStatement: $directPurchaseStatement,
        );

        $filename = OutboundEpcisFilename::forShippingEvent($tenant, $shipEventTime);

        return [
            'xml' => $xml,
            'filename' => $filename,
            'path' => OutboundEpcisFilename::storagePath($tenant, $shipEventTime),
            'ship_event_time' => $shipEventTime,
            'instance_id' => $instanceId,
        ];
    }

    private function resolveShippingEventId(OutboundShippingSession $session): ?string
    {
        $documentId = $session->epcis_document_id;
        if ($documentId === null) {
            return null;
        }

        $eventId = DB::table('epcis_events')
            ->where('document_id', $documentId)
            ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
            ->value('event_id');

        return is_string($eventId) && $eventId !== '' ? $eventId : null;
    }

    /**
     * @return array{
     *     source_owning: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string},
     *     source_location: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string},
     *     dest_owning: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string},
     *     dest_location: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string}
     * }
     */
    private function resolveParties(OutboundShippingSession $session): array
    {
        $shipFrom = $session->site;
        if (! $shipFrom instanceof Site || blank($shipFrom->gln)) {
            throw new DomainException('Ship-from site GLN is required.');
        }

        $sourceOwning = $this->partyFromSite(
            $this->resolveOwningPartySite->handle($shipFrom),
            'Ship-from owning party',
        );
        $sourceLocation = $this->partyFromSite($shipFrom, 'Ship-from location');

        $partner = $session->tradingPartner;
        $shipToSite = $session->shipToSite;
        if (! $partner instanceof TradingPartner && ! $shipToSite instanceof Site) {
            throw new DomainException('Ship-to partner or site is required.');
        }

        if ($shipToSite instanceof Site && filled($shipToSite->gln)) {
            $extra = [];
            if ($partner instanceof TradingPartner) {
                $partnerSgln = $partner->getAttribute('sgln');
                if (is_string($partnerSgln) && $partnerSgln !== '') {
                    $extra[] = $partnerSgln;
                }
            }
            $destLocation = $this->partyFromSite($shipToSite, 'Ship-to location', $extra);
        } elseif ($partner instanceof TradingPartner) {
            $destLocation = $this->partyFromPartner($partner, 'Ship-to location');
        } else {
            throw new DomainException('Ship-to GLN is required.');
        }

        $destOwning = $partner instanceof TradingPartner
            ? $this->partyFromPartner($partner, 'Ship-to owning party')
            : $destLocation;

        // Emit the SGLN recorded on site/partner master data — do not rewrite extensions.
        return [
            'source_owning' => $sourceOwning,
            'source_location' => $sourceLocation,
            'dest_owning' => $destOwning,
            'dest_location' => $destLocation,
        ];
    }

    /**
     * @param  list<string>  $extraSglnCandidates
     * @return array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string}
     */
    private function partyFromSite(Site $site, string $fallbackName, array $extraSglnCandidates = []): array
    {
        $gln = Sgln::normalizeGln((string) $site->gln) ?? (string) $site->gln;
        $candidates = [];
        if (is_string($site->sgln) && $site->sgln !== '') {
            $candidates[] = $site->sgln;
        }
        // Partner ship-to sites often store SGLN on the trading partner only.
        if ($site->trading_partner_id !== null) {
            $site->loadMissing('tradingPartner');
            $partnerSgln = $site->tradingPartner?->getAttribute('sgln');
            if (is_string($partnerSgln) && $partnerSgln !== '') {
                $candidates[] = $partnerSgln;
            }
        }
        foreach ($extraSglnCandidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $candidates[] = $candidate;
            }
        }
        $sgln = $this->resolveSglnUrnForGln(
            $gln,
            $candidates,
            partnerLocation: $site->trading_partner_id !== null || $extraSglnCandidates !== [],
        );
        if ($sgln === null) {
            throw new DomainException(
                'No SGLN on record for '.$fallbackName.' (GLN '.$gln.'). Record the site SGLN before sending.',
            );
        }
        $parsed = Sgln::fromUrn($sgln);
        if ($parsed !== null) {
            $gln = $parsed['gln'];
        }

        return [
            'gln' => $gln,
            'sgln' => $sgln,
            'name' => (string) ($site->name ?: $fallbackName),
            'street' => filled($site->street_address) ? (string) $site->street_address : '100 Distribution Way',
            'city' => filled($site->city) ? (string) $site->city : 'Unknown',
            'state' => filled($site->state) ? (string) $site->state : 'XX',
            'postal' => filled($site->zipcode) ? (string) $site->zipcode : '00000',
            'country' => filled($site->country_code) ? (string) $site->country_code : 'US',
        ];
    }

    /**
     * @return array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string}
     */
    private function partyFromPartner(TradingPartner $partner, string $fallbackName): array
    {
        $gln = Sgln::normalizeGln((string) $partner->gln);
        if ($gln === null || $gln === '') {
            throw new DomainException('Trading partner GLN is required.');
        }

        $candidates = [];
        $partnerSgln = $partner->getAttribute('sgln');
        if (is_string($partnerSgln) && $partnerSgln !== '') {
            $candidates[] = $partnerSgln;
        }

        $sgln = $this->resolveSglnUrnForGln($gln, $candidates, partnerLocation: true);
        if ($sgln === null) {
            throw new DomainException(
                'No SGLN on record for '.$fallbackName.' (GLN '.$gln.'). Record the trading partner\'s own SGLN '
                .'before sending — their GS1 company prefix is theirs to state, not ours to guess.',
            );
        }
        $parsed = Sgln::fromUrn($sgln);
        if ($parsed !== null) {
            $gln = $parsed['gln'];
        }

        return [
            'gln' => $gln,
            'sgln' => $sgln,
            'name' => (string) ($partner->name ?: $fallbackName),
            'street' => filled($partner->street_address) ? (string) $partner->street_address : '200 Pharmacy Plaza',
            'city' => filled($partner->city) ? (string) $partner->city : 'Unknown',
            'state' => filled($partner->state) ? (string) $partner->state : 'XX',
            'postal' => filled($partner->zipcode) ? (string) $partner->zipcode : '00000',
            'country' => filled($partner->country_code) ? (string) $partner->country_code : 'US',
        ];
    }

    /**
     * The SGLN on record, or the one our own company prefix encodes — never a guess.
     * The history document repeats these locations to every partner downstream, so a
     * company-prefix split we invented would travel further than the shipment itself.
     *
     * @param  list<string>  $candidates
     */
    private function resolveSglnUrnForGln(string $gln, array $candidates, bool $partnerLocation = false): ?string
    {
        $settings = TenantSettings::forTenant(tenant());
        $prefix = $partnerLocation
            ? $settings->companyPrefixForPartnerEncoding()
            : $settings->companyPrefix();

        return SglnResolution::resolve(
            $gln,
            $candidates,
            $prefix,
        );
    }

    /**
     * @return array{
     *     sscc_uri: string,
     *     cases: list<array{uri: string, lot: ?string, expiry: ?string, bottles: list<array{uri: string, lot: ?string, expiry: ?string}>}>
     * }
     */
    private function walkTree(int $ssccId, string $ssccUri): array
    {
        $caseIds = DB::table('aggregation_links')
            ->where('parent_epc_id', $ssccId)
            ->whereNull('valid_to')
            ->orderBy('id')
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $cases = [];
        foreach ($caseIds as $caseId) {
            $case = Epc::query()->find($caseId);
            if (! $case instanceof Epc) {
                continue;
            }
            $caseIlmd = DB::table('epc_ilmd')->where('epc_id', $caseId)->first();
            $bottleIds = DB::table('aggregation_links')
                ->where('parent_epc_id', $caseId)
                ->whereNull('valid_to')
                ->orderBy('id')
                ->pluck('child_epc_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $bottles = [];
            foreach ($bottleIds as $bottleId) {
                $bottle = Epc::query()->find($bottleId);
                if (! $bottle instanceof Epc) {
                    continue;
                }
                $bottleIlmd = DB::table('epc_ilmd')->where('epc_id', $bottleId)->first();
                $bottles[] = [
                    'uri' => (string) $bottle->epc_uri,
                    'lot' => $bottleIlmd->lot_number ?? $caseIlmd->lot_number ?? null,
                    'expiry' => $this->formatExpiry($bottleIlmd->expiry_date ?? $caseIlmd->expiry_date ?? null),
                ];
            }

            $cases[] = [
                'uri' => (string) $case->epc_uri,
                'lot' => $caseIlmd->lot_number ?? null,
                'expiry' => $this->formatExpiry($caseIlmd->expiry_date ?? null),
                'bottles' => $bottles,
            ];
        }

        return [
            'sscc_uri' => $ssccUri,
            'cases' => $cases,
        ];
    }

    private function formatExpiry(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{
     *     source_owning: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string},
     *     source_location: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string},
     *     dest_owning: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string},
     *     dest_location: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string}
     * }  $parties
     * @param  list<string>  $ssccUris
     * @param  array{
     *     event_xml: list<string>,
     *     location_elements_xml: list<string>,
     *     epc_class_elements_xml: list<string>,
     *     source_document_ids: list<int>,
     *     tree_epc_uris: list<string>,
     *     event_count: int
     * }  $pedigree
     */
    private function render(
        OutboundShippingSession $session,
        Carbon $shipEventTime,
        string $shippingEventId,
        array $parties,
        array $ssccUris,
        array $pedigree,
        string $instanceId,
        ?string $directPurchaseStatement = null,
    ): string {
        $creationDate = $shipEventTime->copy()->addSeconds(4)->format('Y-m-d\TH:i:s.v\Z');

        $po = (string) ($session->customer_po ?: $session->invoice_number ?: '');
        $asn = (string) ($session->asn_number ?: '');
        if ($asn === '' || $po === '') {
            throw new DomainException('ASN and customer PO or invoice are required to author shipping EPCIS.');
        }

        $events = $pedigree['event_xml'];
        $events[] = $this->shippingXml(
            eventTime: $shipEventTime,
            eventId: $shippingEventId,
            ssccUris: $ssccUris,
            parties: $parties,
            po: $po,
            asn: $asn,
            directPurchaseStatement: $directPurchaseStatement,
        );

        $locationXml = $this->mergedLocationVocabularyXml(
            $pedigree['location_elements_xml'],
            [
                $parties['source_owning'],
                $parties['source_location'],
                $parties['dest_owning'],
                $parties['dest_location'],
            ],
        );
        $epcClassXml = $this->vocabularyFromElements(
            'urn:epcglobal:epcis:vtype:EPCClass',
            $pedigree['epc_class_elements_xml'],
        );

        $headerExtras = '';
        $headerExtras .= "    <gs1ushc:guidelineVersion>R1.3</gs1ushc:guidelineVersion>\n";
        if ((bool) $session->dscsa_affirm) {
            $headerExtras .= ShippingTiTsFragments::dscsaTransactionStatementXml('    ');
        }
        $headerExtras .= ShippingTiTsFragments::dropShipmentIndicatorXml(
            (bool) $session->is_drop_shipment,
            '    ',
        );

        // EPCISHeaderExtensionType = EPCISMasterData + optional nested extension only.
        // GS1 US HC elements are ##other on EPCISHeader and must follow </extension>.
        $header =
            "  <EPCISHeader>\n".
            ShippingTiTsFragments::sbdhXml(
                senderGln: $parties['source_owning']['gln'],
                receiverGln: $parties['dest_owning']['gln'],
                instanceId: $instanceId,
                creationDate: $creationDate,
            ).
            "    <extension>\n".
            "      <EPCISMasterData>\n".
            "        <VocabularyList>\n".
            $locationXml.
            $epcClassXml.
            "        </VocabularyList>\n".
            "      </EPCISMasterData>\n".
            "    </extension>\n".
            $headerExtras.
            "  </EPCISHeader>\n";

        $body =
            "  <EPCISBody>\n".
            "    <EventList>\n".
            implode("\n", $events)."\n".
            "    </EventList>\n".
            "  </EPCISBody>\n";

        $xml =
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".
            "<!-- EPCIS 1.2 / GS1 US R1.3: replayed prior commission+pack + authored shipping. -->\n".
            "<epcis:EPCISDocument\n".
            "    xmlns:epcis=\"urn:epcglobal:epcis:xsd:1\"\n".
            "    xmlns:sbdh=\"http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader\"\n".
            "    xmlns:cbvmda=\"urn:epcglobal:cbv:mda\"\n".
            "    xmlns:gs1ushc=\"http://epcis.gs1us.org/hc/ns\"\n".
            "    schemaVersion=\"1.2\"\n".
            '    creationDate="'.$creationDate."\">\n".
            $header.
            $body.
            "</epcis:EPCISDocument>\n";

        ShippingTiTsFragments::assertDropShipmentEmitted(
            isDropShipment: (bool) $session->is_drop_shipment,
            payload: $xml,
        );

        return $xml;
    }

    /**
     * @param  list<string>  $priorElements
     * @param  list<array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string}>  $parties
     */
    private function mergedLocationVocabularyXml(array $priorElements, array $parties): string
    {
        $byId = [];
        foreach ($priorElements as $element) {
            if (preg_match('/id="([^"]+)"/', $element, $m)) {
                $byId[$m[1]] = $element;
            } else {
                $byId[hash('sha256', $element)] = $element;
            }
        }

        foreach ($parties as $party) {
            $sgln = $party['sgln'];
            if (isset($byId[$sgln])) {
                continue;
            }
            $byId[$sgln] =
                '              <VocabularyElement id="'.$this->e($sgln)."\">\n".
                '                <attribute id="urn:epcglobal:cbv:mda#name">'.$this->e($party['name'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#streetAddressOne">'.$this->e($party['street'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#city">'.$this->e($party['city'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#state">'.$this->e($party['state'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#postalCode">'.$this->e($party['postal'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#countryCode">'.$this->e($party['country'])."</attribute>\n".
                '              </VocabularyElement>';
        }

        return $this->vocabularyFromElements('urn:epcglobal:epcis:vtype:Location', array_values($byId));
    }

    /**
     * @param  list<string>  $elements
     */
    private function vocabularyFromElements(string $type, array $elements): string
    {
        if ($elements === []) {
            return '';
        }

        $body = '';
        foreach ($elements as $element) {
            $body .= rtrim($element)."\n";
        }

        return
            '          <Vocabulary type="'.$this->e($type)."\">\n".
            "            <VocabularyElementList>\n".
            $body.
            "            </VocabularyElementList>\n".
            "          </Vocabulary>\n";
    }

    /**
     * @param  list<array{uri: string, lot: ?string, expiry: ?string}>  $items
     * @return list<array{lot: ?string, expiry: ?string, uris: list<string>}>
     */
    private function groupByLotExpiry(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = ($item['lot'] ?? '').'|'.($item['expiry'] ?? '');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'lot' => $item['lot'],
                    'expiry' => $item['expiry'],
                    'uris' => [],
                ];
            }
            $groups[$key]['uris'][] = $item['uri'];
        }

        return array_values($groups);
    }

    /**
     * @param  list<string>  $epcs
     */
    private function objectCommissionXml(
        Carbon $eventTime,
        array $epcs,
        string $sgln,
        ?string $lot,
        ?string $expiry,
    ): string {
        $recordTime = $eventTime->copy()->addSeconds(3);
        $epcXml = collect($epcs)
            ->map(fn (string $uri): string => '          <epc>'.$this->e($uri).'</epc>')
            ->implode("\n");

        $ilmd = '';
        if ($lot !== null && $lot !== '' && $expiry !== null && $expiry !== '') {
            $ilmd =
                "        <extension>\n".
                "          <ilmd>\n".
                '            <cbvmda:lotNumber>'.$this->e($lot)."</cbvmda:lotNumber>\n".
                '            <cbvmda:itemExpirationDate>'.$this->e($expiry)."</cbvmda:itemExpirationDate>\n".
                "          </ilmd>\n".
                "        </extension>\n";
        }

        return
            "      <ObjectEvent>\n".
            '        <eventTime>'.$eventTime->format('Y-m-d\TH:i:s.v\Z')."</eventTime>\n".
            '        <recordTime>'.$recordTime->format('Y-m-d\TH:i:s.v\Z')."</recordTime>\n".
            "        <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>\n".
            "        <baseExtension>\n".
            '          <eventID>urn:uuid:'.(string) Str::uuid()."</eventID>\n".
            "        </baseExtension>\n".
            "        <epcList>\n".
            $epcXml."\n".
            "        </epcList>\n".
            "        <action>ADD</action>\n".
            "        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>\n".
            "        <disposition>urn:epcglobal:cbv:disp:active</disposition>\n".
            "        <readPoint>\n".
            '          <id>'.$this->e($sgln)."</id>\n".
            "        </readPoint>\n".
            "        <bizLocation>\n".
            '          <id>'.$this->e($sgln)."</id>\n".
            "        </bizLocation>\n".
            $ilmd.
            '      </ObjectEvent>';
    }

    /**
     * @param  list<string>  $childUris
     */
    private function aggregationXml(
        Carbon $eventTime,
        string $parentUri,
        array $childUris,
        string $sgln,
    ): string {
        $recordTime = $eventTime->copy()->addSeconds(3);
        $childXml = collect($childUris)
            ->unique()
            ->values()
            ->map(fn (string $uri): string => '          <epc>'.$this->e($uri).'</epc>')
            ->implode("\n");

        return
            "      <AggregationEvent>\n".
            '        <eventTime>'.$eventTime->format('Y-m-d\TH:i:s.v\Z')."</eventTime>\n".
            '        <recordTime>'.$recordTime->format('Y-m-d\TH:i:s.v\Z')."</recordTime>\n".
            "        <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>\n".
            "        <baseExtension>\n".
            '          <eventID>urn:uuid:'.(string) Str::uuid()."</eventID>\n".
            "        </baseExtension>\n".
            '        <parentID>'.$this->e($parentUri)."</parentID>\n".
            "        <childEPCs>\n".
            $childXml."\n".
            "        </childEPCs>\n".
            "        <action>ADD</action>\n".
            "        <bizStep>urn:epcglobal:cbv:bizstep:packing</bizStep>\n".
            "        <disposition>urn:epcglobal:cbv:disp:in_progress</disposition>\n".
            "        <readPoint>\n".
            '          <id>'.$this->e($sgln)."</id>\n".
            "        </readPoint>\n".
            "        <bizLocation>\n".
            '          <id>'.$this->e($sgln)."</id>\n".
            "        </bizLocation>\n".
            '      </AggregationEvent>';
    }

    /**
     * @param  list<string>  $ssccUris
     * @param  array{
     *     source_owning: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string},
     *     source_location: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string},
     *     dest_owning: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string},
     *     dest_location: array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string}
     * }  $parties
     */
    private function shippingXml(
        Carbon $eventTime,
        string $eventId,
        array $ssccUris,
        array $parties,
        string $po,
        string $asn,
        ?string $directPurchaseStatement = null,
    ): string {
        $recordTime = $eventTime->copy()->addSeconds(3);
        $epcXml = collect($ssccUris)
            ->map(fn (string $uri): string => '          <epc>'.$this->e($uri).'</epc>')
            ->implode("\n");
        $shipFromSgln = $parties['source_location']['sgln'];

        // GS1 US R1.3 / TraceLink: omit bizLocation on shipping — destination is unknown
        // until receiving; destinationList carries ship-to identity.
        return
            "      <ObjectEvent>\n".
            '        <eventTime>'.$eventTime->format('Y-m-d\TH:i:s.v\Z')."</eventTime>\n".
            '        <recordTime>'.$recordTime->format('Y-m-d\TH:i:s.v\Z')."</recordTime>\n".
            "        <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>\n".
            "        <baseExtension>\n".
            '          <eventID>'.$this->e($eventId)."</eventID>\n".
            "        </baseExtension>\n".
            "        <epcList>\n".
            $epcXml."\n".
            "        </epcList>\n".
            "        <action>OBSERVE</action>\n".
            "        <bizStep>urn:epcglobal:cbv:bizstep:shipping</bizStep>\n".
            "        <disposition>urn:epcglobal:cbv:disp:in_transit</disposition>\n".
            "        <readPoint>\n".
            '          <id>'.$this->e($shipFromSgln)."</id>\n".
            "        </readPoint>\n".
            ShippingTiTsFragments::bizTransactionListXml(
                po: $po,
                asn: $asn,
                destOwningGln: $parties['dest_owning']['gln'],
                sourceOwningGln: $parties['source_owning']['gln'],
            ).
            ShippingTiTsFragments::sourceDestinationExtensionXml(
                sourceOwningSgln: $parties['source_owning']['sgln'],
                sourceLocationSgln: $parties['source_location']['sgln'],
                destOwningSgln: $parties['dest_owning']['sgln'],
                destLocationSgln: $parties['dest_location']['sgln'],
                directPurchaseStatement: $directPurchaseStatement,
            ).
            '      </ObjectEvent>';
    }

    private function resolveDirectPurchaseStatement(OutboundShippingSession $session): ?string
    {
        if (! (bool) $session->dscsa_affirm) {
            return null;
        }

        $partnerType = $this->directPurchaseStatements->tenantProfileToPartnerType(tenant());
        $sellerName = filled($session->site?->name)
            ? (string) $session->site->name
            : (string) (tenant()?->name ?? 'Seller');

        return $this->directPurchaseStatements->statementForSeller($partnerType, $sellerName);
    }

    /**
     * @param  list<array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string}>  $parties
     */
    private function locationVocabularyXml(array $parties): string
    {
        $bySgln = [];
        foreach ($parties as $party) {
            $bySgln[$party['sgln']] = $party;
        }

        $elements = '';
        foreach ($bySgln as $party) {
            $elements .=
                '              <VocabularyElement id="'.$this->e($party['sgln'])."\">\n".
                '                <attribute id="urn:epcglobal:cbv:mda#name">'.$this->e($party['name'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#streetAddressOne">'.$this->e($party['street'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#city">'.$this->e($party['city'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#state">'.$this->e($party['state'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#postalCode">'.$this->e($party['postal'])."</attribute>\n".
                '                <attribute id="urn:epcglobal:cbv:mda#countryCode">'.$this->e($party['country'])."</attribute>\n".
                "              </VocabularyElement>\n";
        }

        return
            "          <Vocabulary type=\"urn:epcglobal:epcis:vtype:Location\">\n".
            "            <VocabularyElementList>\n".
            $elements.
            "            </VocabularyElementList>\n".
            "          </Vocabulary>\n";
    }

    /**
     * @param  array<string, array{company_prefix: string, indicator_digit: string, item_reference: string, gtin14: string}>  $patterns
     */
    private function epcClassVocabularyXml(
        array $patterns,
        ?int $documentId = null,
        ?int $ingestGeneration = null,
    ): string {
        if ($patterns === []) {
            return '';
        }

        $elements = '';
        foreach ($patterns as $parsed) {
            $idpat = self::idpatFor($parsed['company_prefix'], $parsed['indicator_digit'], $parsed['item_reference']);
            $master = $this->resolveTradeItemMaster($parsed, $documentId, $ingestGeneration);

            $attrs = '';
            // FDA_NDC_11 must only label a real NDC-11. Emitting a GTIN-14 under that
            // type code makes downstream partners ingest the GTIN as the product's NDC.
            if ($master['ndc11'] !== null) {
                $attrs .= '                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">'.$this->e($master['ndc11'])."</attribute>\n";
                $attrs .= "                <attribute id=\"urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode\">FDA_NDC_11</attribute>\n";
            }
            if ($master['manufacturer'] !== null) {
                $attrs .= '                <attribute id="urn:epcglobal:cbv:mda#manufacturerOfTradeItemPartyName">'.$this->e($master['manufacturer'])."</attribute>\n";
            }
            $attrs .= '                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">'.$this->e($master['name'])."</attribute>\n";
            if ($master['dosage_form'] !== null) {
                $attrs .= '                <attribute id="urn:epcglobal:cbv:mda#dosageFormType">'.$this->e($master['dosage_form'])."</attribute>\n";
            }
            if ($master['strength'] !== null) {
                $attrs .= '                <attribute id="urn:epcglobal:cbv:mda#strengthDescription">'.$this->e($master['strength'])."</attribute>\n";
            }
            if ($master['net_content'] !== null) {
                $attrs .= '                <attribute id="urn:epcglobal:cbv:mda#netContentDescription">'.$this->e($master['net_content'])."</attribute>\n";
            }

            $elements .=
                '              <VocabularyElement id="'.$this->e($idpat)."\">\n".
                $attrs.
                "              </VocabularyElement>\n";
        }

        return
            "          <Vocabulary type=\"urn:epcglobal:epcis:vtype:EPCClass\">\n".
            "            <VocabularyElementList>\n".
            $elements.
            "            </VocabularyElementList>\n".
            "          </Vocabulary>\n";
    }

    /**
     * @param  array{company_prefix: string, indicator_digit: string, item_reference: string, gtin14: string}  $parsed
     * @return array{name: string, ndc11: ?string, manufacturer: ?string, dosage_form: ?string, strength: ?string, net_content: ?string}
     */
    private function resolveTradeItemMaster(array $parsed, ?int $documentId = null, ?int $ingestGeneration = null): array
    {
        $product = $this->findProductForGtinPattern($parsed);
        $class = $this->findBestProductClass($parsed, $documentId, $ingestGeneration);

        $name = $this->usableMasterText($product?->name)
            ?? $this->usableMasterText($class?->name)
            ?? 'Trade item '.$parsed['gtin14'];

        $manufacturer = $this->usableMasterText($class?->manufacturer);
        if ($manufacturer !== null && $this->looksLikePlaceholderName($manufacturer)) {
            $manufacturer = null;
        }

        $dosage = $this->usableMasterText($product?->dosage_form)
            ?? $this->usableMasterText($class?->dosage_form);

        $strength = $this->usableMasterText($product?->strength)
            ?? $this->usableMasterText($class?->strength);

        $netContent = $this->usableMasterText($class?->net_content)
            ?? $this->netContentFromPackaging($product);

        $ndc11 = Ndc::toNdc11($product?->ndc11)
            ?? Ndc::derive($product?->package_ndc, $product?->ndc)
            ?? Ndc::toNdc11($class?->ndc11)
            ?? Ndc::toNdc11(is_string($class?->ndc_raw ?? null) ? $class->ndc_raw : null);

        return [
            'name' => $name,
            'ndc11' => $ndc11,
            'manufacturer' => $manufacturer,
            'dosage_form' => $dosage,
            'strength' => $strength,
            'net_content' => $netContent,
        ];
    }

    /**
     * @param  array{company_prefix: string, indicator_digit: string, item_reference: string, gtin14: string}  $parsed
     */
    private function findProductForGtinPattern(array $parsed): ?Product
    {
        $exact = Product::query()->where('gtin', $parsed['gtin14'])->first();
        if ($exact instanceof Product && $this->usableMasterText($exact->name) !== null) {
            return $exact;
        }

        // Same GCP + item reference at another packaging indicator (each vs case).
        // Anchored on the GTIN body so the match cannot land on an unrelated GTIN
        // that merely contains these digits.
        $candidates = Product::query()
            ->whereRaw('SUBSTRING(gtin, 2, 12) = ?', [$parsed['company_prefix'].$parsed['item_reference']])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->usableMasterText($candidate->name) === null) {
                continue;
            }
            if ($this->usableMasterText($candidate->strength) !== null) {
                return $candidate;
            }
        }

        return $exact instanceof Product ? $exact : $candidates->first();
    }

    /**
     * Master data this document declared for a trade item.
     *
     * Vocabulary rows belong to one document and ingest generation, so the search is
     * scoped to them: an unscoped one could label this shipment with another file's
     * master data. The GTIN-14 and its idpat are matched exactly before any sibling
     * packaging level is considered, so a case never overrides an exact unit match.
     *
     * @param  array{company_prefix: string, indicator_digit: string, item_reference: string, gtin14: string}  $parsed
     */
    private function findBestProductClass(array $parsed, ?int $documentId, ?int $ingestGeneration): ?object
    {
        if ($documentId === null || ! Schema::hasTable('epcis_document_product_classes')) {
            return null;
        }

        $scoped = fn (): Builder => DB::table('epcis_document_product_classes')
            ->where('document_id', $documentId)
            ->when($ingestGeneration !== null, fn ($q) => $q->where('ingest_generation', $ingestGeneration));

        $exact = $scoped()
            ->where(function ($q) use ($parsed): void {
                $q->where('gtin14', $parsed['gtin14'])
                    ->orWhere('idpat', self::idpatFor(
                        $parsed['company_prefix'],
                        $parsed['indicator_digit'],
                        $parsed['item_reference'],
                    ));
            })
            ->orderByDesc('id')
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        $siblings = $this->siblingPackagingIdentifiers($parsed);

        if ($siblings['gtins'] === [] && $siblings['idpats'] === []) {
            return null;
        }

        $rows = $scoped()
            ->where(function ($q) use ($siblings): void {
                $q->whereIn('gtin14', $siblings['gtins'])
                    ->orWhereIn('idpat', $siblings['idpats']);
            })
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $best = null;
        $bestScore = 0;
        foreach ($rows as $row) {
            $score = 0;
            if ($this->usableMasterText($row->strength ?? null) !== null) {
                $score += 2;
            }
            if ($this->usableMasterText($row->net_content ?? null) !== null) {
                $score += 2;
            }
            if ($this->usableMasterText($row->manufacturer ?? null) !== null
                && ! $this->looksLikePlaceholderName((string) $row->manufacturer)) {
                $score += 1;
            }
            if ($this->usableMasterText($row->name ?? null) !== null
                && ! $this->looksLikePlaceholderName((string) $row->name)) {
                $score += 1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * The same trade item at every other packaging indicator, enumerated exactly so
     * the query never needs an unanchored LIKE over the vocabulary table.
     *
     * @param  array{company_prefix: string, indicator_digit: string, item_reference: string, gtin14: string}  $parsed
     * @return array{gtins: list<string>, idpats: list<string>}
     */
    private function siblingPackagingIdentifiers(array $parsed): array
    {
        $gtins = [];
        $idpats = [];

        foreach (range(0, 9) as $indicator) {
            $indicator = (string) $indicator;

            if ($indicator === $parsed['indicator_digit']) {
                continue;
            }

            $body = $indicator.$parsed['company_prefix'].$parsed['item_reference'];

            if (strlen($body) !== 13 || ! ctype_digit($body)) {
                continue;
            }

            $gtins[] = $body.Gtin::checkDigit($body);
            $idpats[] = self::idpatFor($parsed['company_prefix'], $indicator, $parsed['item_reference']);
        }

        return ['gtins' => $gtins, 'idpats' => $idpats];
    }

    private static function idpatFor(string $companyPrefix, string $indicatorDigit, string $itemReference): string
    {
        return 'urn:epc:idpat:sgtin:'.$companyPrefix.'.'.$indicatorDigit.$itemReference.'.*';
    }

    private function netContentFromPackaging(?Product $product): ?string
    {
        if (! $product instanceof Product || ! Schema::hasTable('product_packaging_links')) {
            return null;
        }

        $link = DB::table('product_packaging_links')
            ->where('parent_product_id', $product->getKey())
            ->orderBy('id')
            ->first();

        if ($link === null || (int) ($link->quantity ?? 0) <= 0) {
            return null;
        }

        $child = Product::query()->find($link->child_product_id);
        $childLabel = $this->usableMasterText($child?->dosage_form) ?? 'UNIT';
        $parentLabel = $this->usableMasterText($product->dosage_form) ?? 'PACKAGE';

        return ((int) $link->quantity).' '.$childLabel.' in 1 '.$parentLabel;
    }

    private function usableMasterText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $normalized = strtolower($text);
        if (in_array($normalized, [
            'n/a',
            'na',
            'see label',
            'see packaging',
            'unknown',
            'pharmaceutical trade item',
        ], true)) {
            return null;
        }

        if (str_starts_with($normalized, 'trade item ')) {
            return null;
        }

        return $text;
    }

    private function looksLikePlaceholderName(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return $normalized === 'pharmaceutical trade item'
            || str_starts_with($normalized, 'trade item ');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
