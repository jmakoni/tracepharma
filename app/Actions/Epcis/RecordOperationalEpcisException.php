<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Support\Epcis\Validation\EpcisValidationProfileResolver;
use App\Support\Epcis\Validation\EpcisValidationSeverityMap;

/**
 * Record a single open EPCIS exception for a document, resolving severity via
 * the GS1/DSCSA validation profile when not explicitly given. Intended for use
 * by operational hooks (MDN, VRS, L3 ingest) outside the aggressive validator.
 */
final class RecordOperationalEpcisException
{
    public function __construct(
        private readonly EpcisValidationProfileResolver $profileResolver,
    ) {}

    public function handle(
        EpcisDocument $document,
        string $exceptionType,
        string $description,
        ?string $severity = null,
        ?int $eventId = null,
        ?int $epcId = null,
    ): EpcisException {
        $code = strtoupper(trim($exceptionType));

        $existing = EpcisException::query()
            ->where('document_id', $document->getKey())
            ->where('exception_type', $code)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $resolvedSeverity = $severity ?? EpcisValidationSeverityMap::severityFor(
            $code,
            $this->profileResolver->resolve($document, (string) $document->direction),
        );

        return EpcisException::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => $eventId,
            'epc_id' => $epcId,
            'exception_type' => $code,
            'severity' => $resolvedSeverity,
            'description' => $description,
            'status' => 'open',
        ]);
    }
}
