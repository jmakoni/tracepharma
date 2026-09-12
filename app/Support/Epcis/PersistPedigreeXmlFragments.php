<?php

namespace App\Support\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisPedigreeEventFragment;
use App\Models\Epcis\EpcisPedigreeVocabFragment;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use XMLReader;

/**
 * Persist lossless commissioning/packing event XML and Location/EPCClass vocabulary
 * element XML for a document generation — used for outbound TI rebuild when the
 * original payload file is missing.
 */
final class PersistPedigreeXmlFragments
{
    private const EVENT_LOCAL_NAMES = [
        'ObjectEvent',
        'AggregationEvent',
        'TransactionEvent',
        'TransformationEvent',
        'AssociationEvent',
    ];

    /**
     * @return array{events: int, vocab: int}
     */
    public function forDocument(EpcisDocument $document, ?string $absolutePayloadPath = null): array
    {
        if (! Schema::hasTable('epcis_pedigree_event_fragments')) {
            return ['events' => 0, 'vocab' => 0];
        }

        $generation = (int) ($document->ingest_generation ?? 1);
        $documentId = (int) $document->getKey();

        $path = $absolutePayloadPath;
        $shouldUnlink = false;
        if ($path === null || $path === '' || ! is_file($path)) {
            if (blank($document->payload_path)) {
                return ['events' => 0, 'vocab' => 0];
            }
            $path = $document->materializePayloadPath();
            $shouldUnlink = str_contains($path, DIRECTORY_SEPARATOR.'epcis_payload_');
        }

        try {
            $extracted = $this->extractAllPedigreePieces($path);
        } finally {
            if ($shouldUnlink && is_file($path)) {
                @unlink($path);
            }
        }

        return DB::transaction(function () use ($documentId, $generation, $extracted): array {
            DB::table('epcis_pedigree_event_fragments')
                ->where('document_id', $documentId)
                ->where('ingest_generation', $generation)
                ->delete();
            DB::table('epcis_pedigree_vocab_fragments')
                ->where('document_id', $documentId)
                ->where('ingest_generation', $generation)
                ->delete();

            $eventRows = [];
            foreach ($extracted['events'] as $i => $row) {
                $eventRows[] = [
                    'document_id' => $documentId,
                    'ingest_generation' => $generation,
                    'event_local_name' => $row['local_name'],
                    'biz_step' => $row['biz_step'],
                    'event_time' => $row['event_time'],
                    'seq' => $i,
                    'xml_sha256' => hash('sha256', $row['xml']),
                    'event_xml' => $row['xml'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($eventRows, 50) as $chunk) {
                EpcisPedigreeEventFragment::query()->insert($chunk);
            }

            $vocabRows = [];
            foreach ($extracted['location_elements'] as $xml) {
                $vocabRows[] = $this->vocabRow($documentId, $generation, 'Location', $xml);
            }
            foreach ($extracted['epc_class_elements'] as $xml) {
                $vocabRows[] = $this->vocabRow($documentId, $generation, 'EPCClass', $xml);
            }

            foreach (array_chunk($vocabRows, 50) as $chunk) {
                EpcisPedigreeVocabFragment::query()->insert($chunk);
            }

            return [
                'events' => count($eventRows),
                'vocab' => count($vocabRows),
            ];
        });
    }

    /**
     * @return array{
     *     events: list<array{local_name: string, biz_step: ?string, event_time: ?string, xml: string}>,
     *     location_elements: list<string>,
     *     epc_class_elements: list<string>
     * }
     */
    private function extractAllPedigreePieces(string $absolutePath): array
    {
        $reader = new XMLReader;
        if (! @$reader->open($absolutePath)) {
            throw new DomainException('Unable to open EPCIS payload for pedigree fragment persist: '.$absolutePath);
        }

        $events = [];
        $locationElements = [];
        $epcClassElements = [];

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

                $outer = trim($reader->readOuterXml());
                if ($outer === '') {
                    continue;
                }

                $isCommission = $local === 'ObjectEvent' && str_contains($outer, 'bizstep:commissioning');
                $isPack = $local === 'AggregationEvent'
                    && str_contains($outer, 'bizstep:packing')
                    && ! preg_match('/<action>\s*DELETE\s*<\/action>/i', $outer);

                if (! $isCommission && ! $isPack) {
                    continue;
                }

                $events[] = [
                    'local_name' => $local,
                    'biz_step' => $this->bizStepFromXml($outer),
                    'event_time' => $this->eventTimeFromXml($outer),
                    'xml' => $outer,
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
     * @return array{
     *     document_id: int,
     *     ingest_generation: int,
     *     vocabulary_type: string,
     *     element_id: ?string,
     *     xml_sha256: string,
     *     element_xml: string,
     *     created_at: \Illuminate\Support\Carbon,
     *     updated_at: \Illuminate\Support\Carbon
     * }
     */
    private function vocabRow(int $documentId, int $generation, string $type, string $xml): array
    {
        $elementId = null;
        if (preg_match('/\bid="([^"]+)"/', $xml, $m)) {
            $elementId = $m[1];
        }

        return [
            'document_id' => $documentId,
            'ingest_generation' => $generation,
            'vocabulary_type' => $type,
            'element_id' => $elementId,
            'xml_sha256' => hash('sha256', $xml),
            'element_xml' => $xml,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function eventTimeFromXml(string $xml): ?string
    {
        if (! preg_match('/<eventTime>([^<]+)<\/eventTime>/', $xml, $match)) {
            return null;
        }

        return trim($match[1]);
    }

    private function bizStepFromXml(string $xml): ?string
    {
        if (! preg_match('/<bizStep>([^<]+)<\/bizStep>/', $xml, $match)) {
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
