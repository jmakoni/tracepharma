<?php

declare(strict_types=1);

namespace App\Support\Epcis\Validation;

/**
 * Structural JSON Schema-style validation for EPCIS 2.0 JSON-LD documents.
 *
 * Full GS1 JSON Schema files can be dropped beside resources/xsd later; this
 * gate enforces the required document/event shape so catalog rules still run.
 */
final class EpcisJsonSchema20Validator
{
    /**
     * @return list<EpcisValidationFinding>
     */
    public function validateFile(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            return [
                new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: "EPCIS 2.0 JSON payload is not readable: {$absolutePath}",
                ),
            ];
        }

        $raw = file_get_contents($absolutePath);
        if ($raw === false || trim($raw) === '') {
            return [
                new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: 'EPCIS 2.0 JSON payload is empty.',
                ),
            ];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [
                new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: 'EPCIS 2.0 JSON is not valid JSON: '.$e->getMessage(),
                ),
            ];
        }

        if (! is_array($decoded)) {
            return [
                new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: 'EPCIS 2.0 root must be a JSON object.',
                ),
            ];
        }

        $findings = [];

        $type = (string) ($decoded['type'] ?? $decoded['@type'] ?? '');
        if ($type !== 'EPCISDocument') {
            $findings[] = new EpcisValidationFinding(
                exceptionType: 'INGESTION_PARSE_ERROR',
                severity: 'error',
                description: 'EPCIS 2.0 document type must be EPCISDocument.',
            );
        }

        $schemaVersion = (string) ($decoded['schemaVersion'] ?? '');
        if ($schemaVersion !== '' && $schemaVersion !== '2.0') {
            $findings[] = new EpcisValidationFinding(
                exceptionType: 'INGESTION_PARSE_ERROR',
                severity: 'error',
                description: "EPCIS 2.0 schemaVersion must be 2.0 (got [{$schemaVersion}]).",
            );
        }

        $eventList = $decoded['epcisBody']['eventList']
            ?? $decoded['EPCISBody']['EventList']
            ?? null;

        if (! is_array($eventList)) {
            $findings[] = new EpcisValidationFinding(
                exceptionType: 'INGESTION_PARSE_ERROR',
                severity: 'error',
                description: 'EPCIS 2.0 document requires epcisBody.eventList.',
            );

            return $findings;
        }

        if ($eventList === []) {
            $findings[] = new EpcisValidationFinding(
                exceptionType: 'INGESTION_PARSE_ERROR',
                severity: 'error',
                description: 'EPCIS 2.0 eventList must contain at least one event.',
            );
        }

        foreach ($eventList as $index => $event) {
            if (! is_array($event)) {
                $findings[] = new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: "Event #{$index} must be an object.",
                );

                continue;
            }

            $eventType = (string) ($event['type'] ?? $event['@type'] ?? '');
            if ($eventType === '') {
                $findings[] = new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: "Event #{$index} is missing type.",
                );
            }

            if (! isset($event['eventTime']) || trim((string) $event['eventTime']) === '') {
                $findings[] = new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: "Event #{$index} is missing eventTime.",
                );
            }
        }

        return $findings;
    }
}
