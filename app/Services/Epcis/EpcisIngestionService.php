<?php

namespace App\Services\Epcis;

use App\Actions\Epcis\ProcessEpcisDocument;
use App\Models\Epcis\EpcisDocument;
use DomainException;

/**
 * Process an existing EPCIS document through the parse/persist pipeline.
 */
final class EpcisIngestionService
{
    public function __construct(
        private readonly ProcessEpcisDocument $processEpcisDocument,
    ) {}

    public function process(EpcisDocument $document): EpcisDocument
    {
        if (! in_array($document->status, ['received', 'error', 'parsed', 'parsing'], true)) {
            throw new DomainException(
                "EPCIS document {$document->getKey()} cannot be processed from status [{$document->status}].",
            );
        }

        return $this->processEpcisDocument->handle($document);
    }
}
