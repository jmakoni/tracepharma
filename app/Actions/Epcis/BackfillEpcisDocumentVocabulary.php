<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Support\Epcis\EpcisXmlReader;
use Illuminate\Support\Facades\Schema;

/**
 * Header-only vocabulary backfill for an existing document generation.
 */
final class BackfillEpcisDocumentVocabulary
{
    public function __construct(
        private readonly EpcisXmlReader $reader,
        private readonly PersistEpcisDocumentVocabulary $persistVocabulary,
    ) {}

    /**
     * @return array{product_classes: int, locations: int, other_vocabulary: int, skipped: bool, reason: ?string}
     */
    public function handle(EpcisDocument $document, bool $force = false): array
    {
        if (! Schema::hasTable('epcis_document_product_classes')) {
            return [
                'product_classes' => 0,
                'locations' => 0,
                'other_vocabulary' => 0,
                'skipped' => true,
                'reason' => 'vocabulary tables missing',
            ];
        }

        $generation = (int) ($document->ingest_generation ?? 1);

        if (! $force) {
            $existing = $document->productClasses()
                ->where('ingest_generation', $generation)
                ->exists();
            if ($existing) {
                return [
                    'product_classes' => 0,
                    'locations' => 0,
                    'other_vocabulary' => 0,
                    'skipped' => true,
                    'reason' => 'already backfilled for generation '.$generation,
                ];
            }
        }

        $path = $document->payloadAbsolutePath();
        if ($path === null) {
            return [
                'product_classes' => 0,
                'locations' => 0,
                'other_vocabulary' => 0,
                'skipped' => true,
                'reason' => 'payload unreadable',
            ];
        }

        $header = $this->reader->parseHeader($path);
        $counts = $this->persistVocabulary->handle(
            $document,
            $generation,
            $header['product_classes'] ?? [],
            $header['locations'] ?? [],
            $header['other_vocabulary'] ?? [],
        );

        if (
            Schema::hasColumn('epcis_documents', 'header_json')
            && is_array($header['header_json'] ?? null)
            && $header['header_json'] !== []
        ) {
            $document->forceFill(['header_json' => $header['header_json']])->save();
        }

        return [
            'product_classes' => $counts['product_classes'],
            'locations' => $counts['locations'],
            'other_vocabulary' => $counts['other_vocabulary'],
            'skipped' => false,
            'reason' => null,
        ];
    }
}
