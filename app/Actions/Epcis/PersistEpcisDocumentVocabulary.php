<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Support\Gs1\Sgtin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist EPCISMasterData vocabulary for a document ingest generation.
 *
 * Identifiers that form the upsert unique keys — idpat, gln_uri, vocabulary type
 * and element id — are never truncated: a shortened identifier collides with the
 * row it was cut down from and would overwrite it. Over-length values are skipped.
 */
final class PersistEpcisDocumentVocabulary
{
    private const CHUNK = 500;

    private const IDPAT_MAX = 191;

    private const GLN_URI_MAX = 128;

    private const ELEMENT_MAX = 191;

    /**
     * @param  list<array<string, mixed>>  $productClasses
     * @param  list<array<string, mixed>>  $locations
     * @param  list<array<string, mixed>>  $otherVocabulary
     * @return array{product_classes: int, locations: int, other_vocabulary: int}
     */
    public function handle(
        EpcisDocument $document,
        int $generation,
        array $productClasses,
        array $locations,
        array $otherVocabulary = [],
    ): array {
        return [
            'product_classes' => $this->persistProductClasses($document, $generation, $productClasses),
            'locations' => $this->persistLocations($document, $generation, $locations),
            'other_vocabulary' => $this->persistOtherVocabulary($document, $generation, $otherVocabulary),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $productClasses
     */
    private function persistProductClasses(EpcisDocument $document, int $generation, array $productClasses): int
    {
        if (! Schema::hasTable('epcis_document_product_classes') || $productClasses === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($productClasses as $class) {
            $idpat = trim((string) ($class['idpat'] ?? ''));
            if ($idpat === '' || mb_strlen($idpat) > self::IDPAT_MAX) {
                continue;
            }

            $gtin14 = null;
            if (preg_match('/^urn:epc:idpat:sgtin:(\d+)\.(\d+)\.\*$/', $idpat, $matches) === 1) {
                $parsed = Sgtin::fromUrn('urn:epc:id:sgtin:'.$matches[1].'.'.$matches[2].'.0');
                $gtin14 = $parsed['gtin14'] ?? null;
            }

            $attrs = $class['attributes_json'] ?? [];
            if (! is_array($attrs)) {
                $attrs = [];
            }

            $rows[] = [
                'document_id' => $document->getKey(),
                'ingest_generation' => $generation,
                'idpat' => $idpat,
                'gtin14' => $gtin14,
                'ndc_raw' => $this->nullableString($class['ndc_raw'] ?? null, 64),
                'ndc11' => $this->nullableString($class['ndc11'] ?? null, 11),
                'name' => $this->nullableString($class['name'] ?? null, 255),
                'dosage_form' => $this->nullableString($class['dosage_form'] ?? null, 128),
                'strength' => $this->nullableString($class['strength'] ?? null, 128),
                'manufacturer' => $this->nullableString($class['manufacturer'] ?? null, 255),
                'net_content' => $this->nullableString($class['net_content'] ?? null, 255),
                'attributes_json' => json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('epcis_document_product_classes')->upsert(
                $chunk,
                ['document_id', 'ingest_generation', 'idpat'],
                [
                    'gtin14',
                    'ndc_raw',
                    'ndc11',
                    'name',
                    'dosage_form',
                    'strength',
                    'manufacturer',
                    'net_content',
                    'attributes_json',
                    'updated_at',
                ],
            );
        }

        return count($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     */
    private function persistLocations(EpcisDocument $document, int $generation, array $locations): int
    {
        if (! Schema::hasTable('epcis_document_locations') || $locations === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($locations as $location) {
            $glnUri = trim((string) ($location['gln_uri'] ?? ''));
            if ($glnUri === '' || mb_strlen($glnUri) > self::GLN_URI_MAX) {
                continue;
            }

            $attrs = $location['attributes_json'] ?? [];
            if (! is_array($attrs)) {
                $attrs = [];
            }

            $rows[] = [
                'document_id' => $document->getKey(),
                'ingest_generation' => $generation,
                'gln_uri' => $glnUri,
                'gln' => $this->nullableString($location['gln'] ?? null, 13),
                'name' => $this->nullableString($location['name'] ?? null, 255),
                'street_address' => $this->nullableString($location['street_address'] ?? null, 255),
                'city' => $this->nullableString($location['city'] ?? null, 255),
                'state' => $this->nullableString($location['state'] ?? null, 255),
                'postal_code' => $this->nullableString($location['postal_code'] ?? null, 255),
                'country_code' => $this->nullableString($location['country_code'] ?? null, 255),
                'attributes_json' => json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('epcis_document_locations')->upsert(
                $chunk,
                ['document_id', 'ingest_generation', 'gln_uri'],
                [
                    'gln',
                    'name',
                    'street_address',
                    'city',
                    'state',
                    'postal_code',
                    'country_code',
                    'attributes_json',
                    'updated_at',
                ],
            );
        }

        return count($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $otherVocabulary
     */
    private function persistOtherVocabulary(EpcisDocument $document, int $generation, array $otherVocabulary): int
    {
        if (! Schema::hasTable('epcis_document_vocabulary_elements') || $otherVocabulary === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($otherVocabulary as $element) {
            $vocabularyType = trim((string) ($element['vocabulary_type'] ?? ''));
            $elementId = trim((string) ($element['element_id'] ?? ''));
            if ($vocabularyType === '' || $elementId === '') {
                continue;
            }

            if (mb_strlen($vocabularyType) > self::ELEMENT_MAX || mb_strlen($elementId) > self::ELEMENT_MAX) {
                continue;
            }

            $attrs = $element['attributes_json'] ?? [];
            if (! is_array($attrs)) {
                $attrs = [];
            }

            $rows[] = [
                'document_id' => $document->getKey(),
                'ingest_generation' => $generation,
                'vocabulary_type' => $vocabularyType,
                'element_id' => $elementId,
                'attributes_json' => json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('epcis_document_vocabulary_elements')->upsert(
                $chunk,
                ['document_id', 'ingest_generation', 'vocabulary_type', 'element_id'],
                [
                    'attributes_json',
                    'updated_at',
                ],
            );
        }

        return count($rows);
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return mb_substr($string, 0, $max);
    }
}
