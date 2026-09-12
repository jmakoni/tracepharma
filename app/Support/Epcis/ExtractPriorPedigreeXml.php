<?php

namespace App\Support\Epcis;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisPedigreeEventFragment;
use App\Models\Epcis\EpcisPedigreeVocabFragment;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use XMLReader;

/**
 * Replay prior commissioning + packing event XML for EPCs under a shipment.
 *
 * Preference order:
 * 1. Lossless DB fragments ({@see PersistPedigreeXmlFragments}) — survives payload file loss
 * 2. Retained inbound / Guardian-authored payload files
 *
 * Never invents history from normalized event columns.
 *
 * Matching is by EPC URI intersection (many inbound files omit eventID).
 *
 * Product policy ({@see config('tracepharma.epcis.outbound_pedigree_replay')}):
 * **whole_event** for commissioning ObjectEvents (may include off-shipment EPCs in
 * manufacturer batch commissions).
 * **open_tree_children** for packing AggregationEvent ADD: childEPCs are filtered to
 * current open aggregation children under parentID; empty packs are omitted. Manufacturer
 * eventTime/readPoint/bizLocation are preserved. Removed-case fragment history is not deleted.
 */
final class ExtractPriorPedigreeXml
{
    private const EVENT_LOCAL_NAMES = [
        'ObjectEvent',
        'AggregationEvent',
        'TransactionEvent',
        'TransformationEvent',
        'AssociationEvent',
    ];

    /**
     * @param  list<int>  $rootEpcIds  Confirmed outermost parent EPC ids
     * @return array{
     *     event_xml: list<string>,
     *     location_elements_xml: list<string>,
     *     epc_class_elements_xml: list<string>,
     *     source_document_ids: list<int>,
     *     tree_epc_uris: list<string>,
     *     event_count: int
     * }
     */
    public function forOpenTree(array $rootEpcIds): array
    {
        $treeIds = $this->collectOpenTreeEpcIds($rootEpcIds);
        if ($treeIds === []) {
            throw new DomainException('No EPCs found under confirmed shipping parents.');
        }

        /** @var list<string> $uris */
        $uris = Epc::query()
            ->whereIn('id', $treeIds)
            ->pluck('epc_uri')
            ->map(fn ($uri): string => trim((string) $uri))
            ->filter(fn (string $uri): bool => $uri !== '')
            ->unique()
            ->values()
            ->all();

        $uriSet = array_fill_keys($uris, true);

        $documentIds = $this->sourceDocumentIdsForTree($treeIds);
        if ($documentIds === []) {
            throw new DomainException(
                'No prior commissioning/packing documents found for the shipped hierarchy. '
                .'Cannot invent manufacturer history — ingest the inbound EPCIS first.',
            );
        }

        $events = [];
        $locationElements = [];
        $epcClassElements = [];
        $seenEventHash = [];

        foreach ($documentIds as $documentId) {
            $document = EpcisDocument::query()->find($documentId);
            if (! $document instanceof EpcisDocument) {
                continue;
            }

            $extracted = $this->extractFromDocument($document, $uriSet);

            foreach ($extracted['events'] as $row) {
                $hash = hash('sha256', $row['xml']);
                if (isset($seenEventHash[$hash])) {
                    continue;
                }
                $seenEventHash[$hash] = true;
                $events[] = $row;
            }

            foreach ($extracted['location_elements'] as $xml) {
                $locationElements[hash('sha256', $xml)] = $xml;
            }
            foreach ($extracted['epc_class_elements'] as $xml) {
                $epcClassElements[hash('sha256', $xml)] = $xml;
            }
        }

        usort(
            $events,
            static fn (array $a, array $b): int => [$a['event_time'], $a['seq']] <=> [$b['event_time'], $b['seq']],
        );

        $events = $this->filterPackingEventsToOpenChildren($events, $treeIds);

        if ($events === []) {
            throw new DomainException(
                'Prior commissioning/packing history was found but no events matched the shipped EPCs '
                .'(DB fragments and/or retained payloads).',
            );
        }

        return [
            'event_xml' => array_map(static fn (array $row): string => $row['xml'], $events),
            'location_elements_xml' => array_values($locationElements),
            'epc_class_elements_xml' => array_values($epcClassElements),
            'source_document_ids' => $documentIds,
            'tree_epc_uris' => $uris,
            'event_count' => count($events),
        ];
    }

    /**
     * @param  list<int>  $rootEpcIds
     * @return list<int>
     */
    public function collectOpenTreeEpcIds(array $rootEpcIds): array
    {
        $rootEpcIds = array_values(array_unique(array_map('intval', $rootEpcIds)));
        $seen = [];
        $queue = $rootEpcIds;

        while ($queue !== []) {
            $id = (int) array_shift($queue);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $children = DB::table('aggregation_links')
                ->where('parent_epc_id', $id)
                ->whereNull('valid_to')
                ->orderBy('id')
                ->pluck('child_epc_id')
                ->map(fn ($child): int => (int) $child)
                ->all();

            foreach ($children as $childId) {
                if (! isset($seen[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return array_map('intval', array_keys($seen));
    }

    /**
     * @param  array<string, true>  $uriSet
     * @return array{
     *     events: list<array{event_time: string, seq: int, xml: string}>,
     *     location_elements: list<string>,
     *     epc_class_elements: list<string>
     * }
     */
    private function extractFromDocument(EpcisDocument $document, array $uriSet): array
    {
        $fromDb = $this->extractFromFragments($document, $uriSet);
        if ($fromDb['events'] !== []) {
            return $fromDb;
        }

        if (blank($document->payload_path)) {
            return [
                'events' => [],
                'location_elements' => [],
                'epc_class_elements' => [],
            ];
        }

        $absolute = $document->materializePayloadPath();
        $shouldUnlink = str_contains($absolute, DIRECTORY_SEPARATOR.'epcis_payload_');

        try {
            return $this->extractFromPayload($absolute, $uriSet);
        } finally {
            if ($shouldUnlink && is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    /**
     * @param  array<string, true>  $uriSet
     * @return array{
     *     events: list<array{event_time: string, seq: int, xml: string}>,
     *     location_elements: list<string>,
     *     epc_class_elements: list<string>
     * }
     */
    private function extractFromFragments(EpcisDocument $document, array $uriSet): array
    {
        if (! Schema::hasTable('epcis_pedigree_event_fragments')) {
            return [
                'events' => [],
                'location_elements' => [],
                'epc_class_elements' => [],
            ];
        }

        $generation = (int) ($document->ingest_generation ?? 1);
        $documentId = (int) $document->getKey();

        $fragments = EpcisPedigreeEventFragment::query()
            ->where('document_id', $documentId)
            ->where('ingest_generation', $generation)
            ->orderBy('seq')
            ->get();

        if ($fragments->isEmpty()) {
            return [
                'events' => [],
                'location_elements' => [],
                'epc_class_elements' => [],
            ];
        }

        $events = [];
        $seq = 0;
        foreach ($fragments as $fragment) {
            $xml = (string) $fragment->event_xml;
            $local = (string) $fragment->event_local_name;
            if ($xml === '' || ! $this->eventMatchesTree($xml, $local, $uriSet)) {
                continue;
            }

            $events[] = [
                'event_time' => (string) ($fragment->event_time ?: sprintf('%020d', $seq)),
                'seq' => $seq++,
                'xml' => trim($xml),
            ];
        }

        $locationElements = [];
        $epcClassElements = [];

        if (Schema::hasTable('epcis_pedigree_vocab_fragments')) {
            $vocab = EpcisPedigreeVocabFragment::query()
                ->where('document_id', $documentId)
                ->where('ingest_generation', $generation)
                ->get();

            foreach ($vocab as $row) {
                $xml = trim((string) $row->element_xml);
                if ($xml === '') {
                    continue;
                }
                if ($row->vocabulary_type === 'Location') {
                    $locationElements[] = $xml;
                } elseif ($row->vocabulary_type === 'EPCClass') {
                    $epcClassElements[] = $xml;
                }
            }
        }

        return [
            'events' => $events,
            'location_elements' => $locationElements,
            'epc_class_elements' => $epcClassElements,
        ];
    }

    /**
     * @param  list<array{event_time: string, seq: int, xml: string}>  $events
     * @param  list<int>  $treeIds
     * @return list<array{event_time: string, seq: int, xml: string}>
     */
    private function filterPackingEventsToOpenChildren(array $events, array $treeIds): array
    {
        $openChildrenByParentUri = $this->openChildUrisByParentUri($treeIds);
        $out = [];

        foreach ($events as $row) {
            $xml = $row['xml'];
            if (! $this->isPackingAddEvent($xml)) {
                $out[] = $row;

                continue;
            }

            $rewritten = $this->rewritePackingChildEpcsToOpenChildren($xml, $openChildrenByParentUri);
            if ($rewritten === null) {
                continue;
            }

            $row['xml'] = $rewritten;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<int>  $treeIds
     * @return array<string, list<string>> parent URI => open child URIs
     */
    private function openChildUrisByParentUri(array $treeIds): array
    {
        if ($treeIds === []) {
            return [];
        }

        $rows = DB::table('aggregation_links as al')
            ->join('epcs as parent', 'parent.id', '=', 'al.parent_epc_id')
            ->join('epcs as child', 'child.id', '=', 'al.child_epc_id')
            ->whereIn('al.parent_epc_id', $treeIds)
            ->whereNull('al.valid_to')
            ->orderBy('al.id')
            ->get(['parent.epc_uri as parent_uri', 'child.epc_uri as child_uri']);

        $map = [];
        foreach ($rows as $row) {
            $parentUri = trim((string) $row->parent_uri);
            $childUri = trim((string) $row->child_uri);
            if ($parentUri === '' || $childUri === '') {
                continue;
            }
            $map[$parentUri][] = $childUri;
        }

        return $map;
    }

    private function isPackingAddEvent(string $xml): bool
    {
        if (! str_contains($xml, 'AggregationEvent') || ! str_contains($xml, 'bizstep:packing')) {
            return false;
        }

        return ! preg_match('/<action>\s*DELETE\s*<\/action>/i', $xml);
    }

    /**
     * @param  array<string, list<string>>  $openChildrenByParentUri
     */
    private function rewritePackingChildEpcsToOpenChildren(string $xml, array $openChildrenByParentUri): ?string
    {
        if (! preg_match('/<parentID>([^<]+)<\/parentID>/i', $xml, $parentMatch)) {
            return null;
        }

        $parentUri = trim(html_entity_decode($parentMatch[1], ENT_XML1 | ENT_QUOTES));
        $allowed = $openChildrenByParentUri[$parentUri] ?? [];
        if ($allowed === []) {
            return null;
        }

        $allowedSet = array_fill_keys($allowed, true);

        if (! preg_match('/<childEPCs\b[^>]*>(.*?)<\/childEPCs>/is', $xml, $blockMatch)) {
            return null;
        }

        if (! preg_match_all('/<epc>([^<]+)<\/epc>/i', $blockMatch[1], $epcMatches)) {
            return null;
        }

        $kept = [];
        foreach ($epcMatches[1] as $raw) {
            $uri = trim(html_entity_decode((string) $raw, ENT_XML1 | ENT_QUOTES));
            if ($uri !== '' && isset($allowedSet[$uri])) {
                $kept[] = $uri;
            }
        }

        $kept = array_values(array_unique($kept));
        if ($kept === []) {
            return null;
        }

        $inner = '';
        foreach ($kept as $uri) {
            $inner .= '          <epc>'.htmlspecialchars($uri, ENT_XML1 | ENT_QUOTES)."</epc>\n";
        }

        $newBlock = "<childEPCs>\n".$inner.'        </childEPCs>';
        $rewritten = preg_replace(
            '/<childEPCs\b[^>]*>.*?<\/childEPCs>/is',
            $newBlock,
            $xml,
            1,
        );

        if (! is_string($rewritten) || $rewritten === '') {
            return null;
        }

        // Drop quantity lists that would disagree with filtered children.
        $rewritten = preg_replace(
            '/<extension>\s*<childQuantityList>.*?<\/childQuantityList>\s*<\/extension>/is',
            '',
            $rewritten,
        ) ?? $rewritten;

        return $rewritten;
    }

    /**
     * @param  list<int>  $treeIds
     * @return list<int>
     */
    private function sourceDocumentIdsForTree(array $treeIds): array
    {
        $query = DB::table('epcis_events as e')
            ->join('event_epcs as ee', 'ee.event_id', '=', 'e.id')
            ->whereIn('ee.epc_id', $treeIds)
            ->where(function ($q): void {
                $q->where('e.biz_step', 'like', '%:commissioning')
                    ->orWhere('e.biz_step', 'like', '%:packing');
            });

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            $query->whereNull('e.superseded_at');
        }

        return $query
            ->distinct()
            ->orderBy('e.document_id')
            ->pluck('e.document_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, true>  $uriSet
     * @return array{
     *     events: list<array{event_time: string, seq: int, xml: string}>,
     *     location_elements: list<string>,
     *     epc_class_elements: list<string>
     * }
     */
    private function extractFromPayload(string $absolutePath, array $uriSet): array
    {
        $reader = new XMLReader;
        if (! @$reader->open($absolutePath)) {
            throw new DomainException('Unable to open EPCIS payload for pedigree replay: '.$absolutePath);
        }

        $events = [];
        $locationElements = [];
        $epcClassElements = [];
        $seq = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $local = $reader->localName;

                if ($local === 'Vocabulary') {
                    $type = (string) ($reader->getAttribute('type') ?? '');
                    $outer = $reader->readOuterXml();
                    if ($outer === '') {
                        continue;
                    }
                    if (str_contains($type, 'Location')) {
                        foreach ($this->vocabularyElements($outer) as $elementXml) {
                            $locationElements[] = $elementXml;
                        }
                    } elseif (str_contains($type, 'EPCClass')) {
                        foreach ($this->vocabularyElements($outer) as $elementXml) {
                            $epcClassElements[] = $elementXml;
                        }
                    }

                    continue;
                }

                if (! in_array($local, self::EVENT_LOCAL_NAMES, true)) {
                    continue;
                }

                $outer = $reader->readOuterXml();
                if ($outer === '' || ! $this->eventMatchesTree($outer, $local, $uriSet)) {
                    continue;
                }

                $events[] = [
                    'event_time' => $this->eventTimeFromXml($outer) ?? sprintf('%020d', $seq),
                    'seq' => $seq++,
                    'xml' => trim($outer),
                ];
            }
        } finally {
            $reader->close();
        }

        return [
            'events' => $events,
            'location_elements' => $locationElements,
            'epc_class_elements' => $epcClassElements,
        ];
    }

    /**
     * @param  array<string, true>  $uriSet
     */
    private function eventMatchesTree(string $eventXml, string $eventType, array $uriSet): bool
    {
        if ($eventType === 'ObjectEvent') {
            if (! str_contains($eventXml, 'bizstep:commissioning')) {
                return false;
            }

            return $this->xmlContainsAnyUri($eventXml, $uriSet);
        }

        if ($eventType === 'AggregationEvent') {
            if (! str_contains($eventXml, 'bizstep:packing')) {
                return false;
            }
            if (preg_match('/<action>\s*DELETE\s*<\/action>/i', $eventXml)) {
                return false;
            }

            return $this->xmlContainsAnyUri($eventXml, $uriSet);
        }

        return false;
    }

    /**
     * @param  array<string, true>  $uriSet
     */
    private function xmlContainsAnyUri(string $xml, array $uriSet): bool
    {
        if (! preg_match_all('/<epc>([^<]+)<\/epc>|<parentID>([^<]+)<\/parentID>/i', $xml, $matches, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($matches as $match) {
            $raw = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
            $uri = trim(html_entity_decode($raw, ENT_XML1 | ENT_QUOTES));
            if ($uri !== '' && isset($uriSet[$uri])) {
                return true;
            }
        }

        return false;
    }

    private function eventTimeFromXml(string $xml): ?string
    {
        if (! preg_match('/<eventTime>([^<]+)<\/eventTime>/', $xml, $match)) {
            return null;
        }

        return trim($match[1]);
    }

    /**
     * @return list<string>
     */
    private function vocabularyElements(string $vocabularyOuterXml): array
    {
        if (! preg_match_all('/<VocabularyElement\b[^>]*>.*?<\/VocabularyElement>/s', $vocabularyOuterXml, $matches)) {
            return [];
        }

        return array_values($matches[0]);
    }
}
