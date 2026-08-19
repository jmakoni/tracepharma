<?php

declare(strict_types=1);

namespace App\Actions\Epcis;

use App\Domain\Epcis\Validation\ValidationFailure;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Support\Epcis\Validation\EpcisValidationCatalog;

/**
 * Maps a Domain hard-gate failure into the existing epcis_exceptions ledger (DLQ surface).
 */
final class RecordEpcisValidationFailure
{
    /**
     * Domain stage codes → catalog codes cleared/rewritten by ValidateEpcis12Document.
     *
     * @var array<string, string>
     */
    private const CODE_MAP = [
        'AGGREGATION_MISSING_PARENT' => 'MISSING_PARENT',
        'AGGREGATION_MISSING_CHILDREN' => 'MISSING_CHILDREN',
        'AGGREGATION_PARENT_IN_CHILDREN' => 'BROKEN_AGGREGATION',
        'INVALID_EPC_URI' => 'INVALID_EPC_URI',
        'INVALID_ACTION' => 'INVALID_ACTION',
        'MISSING_EVENT_TIME' => 'MISSING_MANDATORY_FIELD',
        'MISSING_EVENT_TYPE' => 'MISSING_MANDATORY_FIELD',
        'MALFORMED_XML' => 'INGESTION_PARSE_ERROR',
        'EMPTY_EVENT_LIST' => 'MISSING_MANDATORY_FIELD',
        'OBJECT_EVENT_EMPTY_EPC_LIST' => 'MISSING_MANDATORY_FIELD',
    ];

    public function __construct(
        private readonly RecordOperationalEpcisException $recorder,
    ) {}

    public function handle(EpcisDocument $document, ValidationFailure $failure): EpcisException
    {
        $catalogCode = $this->toCatalogCode($failure->code);
        $description = "[{$failure->stage}] {$failure->code}: {$failure->message}";

        $document->forceFill([
            'status' => 'error',
            'error_message' => mb_substr($description, 0, 2000),
        ])->save();

        $existing = EpcisException::query()
            ->where('document_id', $document->getKey())
            ->where('exception_type', $catalogCode)
            ->where('status', 'open')
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'description' => $description,
                'severity' => 'error',
            ])->save();

            return $existing;
        }

        return $this->recorder->handle(
            document: $document,
            exceptionType: $catalogCode,
            description: $description,
            severity: 'error',
        );
    }

    private function toCatalogCode(string $domainCode): string
    {
        $mapped = self::CODE_MAP[$domainCode] ?? $domainCode;

        if (in_array($mapped, EpcisValidationCatalog::CODES, true)) {
            return $mapped;
        }

        return 'INTERNAL_VALIDATION_FAILED';
    }
}
