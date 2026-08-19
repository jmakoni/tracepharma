<?php

namespace App\Support\Epcis\Validation;

use DOMDocument;
use LibXMLError;

/**
 * Validate an EPCIS XML payload against vendored GS1 EPCIS 1.2 XSD.
 */
final class EpcisXsdValidator
{
    public function schemaPath(): string
    {
        return resource_path('xsd/epcis-1.2/EPCglobal-epcis-1_2.xsd');
    }

    /**
     * @return list<EpcisValidationFinding>
     */
    public function validateFile(string $absoluteXmlPath): array
    {
        if (! is_file($absoluteXmlPath) || ! is_readable($absoluteXmlPath)) {
            return [
                new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: 'EPCIS payload missing or unreadable for XSD validation.',
                ),
            ];
        }

        $schema = $this->schemaPath();
        if (! is_file($schema)) {
            return [
                new EpcisValidationFinding(
                    exceptionType: 'INTERNAL_VALIDATION_FAILED',
                    severity: 'error',
                    description: 'EPCIS 1.2 XSD schema file is not installed at resources/xsd/epcis-1.2/.',
                ),
            ];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $loaded = @$document->load($absoluteXmlPath);
            if ($loaded === false) {
                return $this->findingsFromLibxml('Failed to load XML for XSD validation.');
            }

            $ok = @$document->schemaValidate($schema);
            if ($ok === true) {
                return [];
            }

            return $this->findingsFromLibxml('EPCIS document failed GS1 EPCIS 1.2 XSD validation.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<EpcisValidationFinding>
     */
    private function findingsFromLibxml(string $fallback): array
    {
        /** @var list<LibXMLError> $errors */
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if ($errors === []) {
            return [
                new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: $fallback,
                ),
            ];
        }

        $findings = [];
        foreach (array_slice($errors, 0, 25) as $error) {
            $line = $error->line > 0 ? ' (line '.$error->line.')' : '';
            $findings[] = new EpcisValidationFinding(
                exceptionType: 'INGESTION_PARSE_ERROR',
                severity: 'error',
                description: trim($error->message).$line,
            );
        }

        return $findings;
    }
}
