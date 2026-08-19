<?php

namespace App\Services\Labeling;

use App\Support\Gs1\Gtin;
use App\Support\Gs1\Sscc;

class SsccBuilder
{
    public const MIN_GCP_LENGTH = 6;

    public const MAX_GCP_LENGTH = 12;

    /**
     * @return array{
     *     sscc_18: string,
     *     sscc_dotted: string,
     *     sscc_urn: string,
     *     extension_digit: string,
     *     company_prefix: string,
     *     serial_reference: string,
     *     serial_reference_int: int,
     *     element_string: string,
     *     hrt: string,
     *     gs1_barcode: string
     * }
     */
    public function build(string $companyPrefix, int $serialReference, int $extensionDigit = 0): array
    {
        $companyPrefix = $this->normalizeCompanyPrefix($companyPrefix);
        $extensionDigit = $this->normalizeExtensionDigit($extensionDigit);

        $serialReferenceWidth = 16 - strlen($companyPrefix);
        $maxSerialReference = (10 ** $serialReferenceWidth) - 1;

        if ($serialReference < 0 || $serialReference > $maxSerialReference) {
            throw new \InvalidArgumentException(
                "Serial reference must be between 0 and {$maxSerialReference} for company prefix length ".strlen($companyPrefix).'.'
            );
        }

        $serialReferencePadded = str_pad((string) $serialReference, $serialReferenceWidth, '0', STR_PAD_LEFT);
        $payload17 = $extensionDigit.$companyPrefix.$serialReferencePadded;

        if (strlen($payload17) !== 17) {
            throw new \RuntimeException('SSCC payload must be exactly 17 digits before the check digit.');
        }

        $checkDigit = Gtin::checkDigit($payload17);
        $sscc18 = $payload17.$checkDigit;

        // Dotted EPC serial reference includes the leading extension digit.
        $dottedSscc = $companyPrefix.'.'.$extensionDigit.$serialReferencePadded;
        $ssccUrn = Sscc::toUrn($companyPrefix, $extensionDigit, $serialReferencePadded);

        $fromHelper = Sscc::build($companyPrefix, $extensionDigit, $serialReferencePadded);
        if ($fromHelper === null || $fromHelper['sscc18'] !== $sscc18) {
            throw new \RuntimeException('SSCC builder output failed Gs1\\Sscc consistency check.');
        }

        return [
            'sscc_18' => $sscc18,
            'sscc_dotted' => $dottedSscc,
            'sscc_urn' => $ssccUrn,
            'extension_digit' => $extensionDigit,
            'company_prefix' => $companyPrefix,
            'serial_reference' => $serialReferencePadded,
            'serial_reference_int' => $serialReference,
            'element_string' => '00'.$sscc18,
            'hrt' => '00'.$sscc18,
            'gs1_barcode' => $sscc18,
        ];
    }

    public function normalizeCompanyPrefix(string $companyPrefix): string
    {
        $companyPrefix = preg_replace('/\D/', '', $companyPrefix) ?? '';
        $length = strlen($companyPrefix);

        if ($length < self::MIN_GCP_LENGTH || $length > self::MAX_GCP_LENGTH) {
            throw new \InvalidArgumentException(
                'GS1 Company Prefix must be between '.self::MIN_GCP_LENGTH.' and '.self::MAX_GCP_LENGTH.' digits.'
            );
        }

        return $companyPrefix;
    }

    public function normalizeExtensionDigit(int $extensionDigit): string
    {
        if ($extensionDigit < 0 || $extensionDigit > 9) {
            throw new \InvalidArgumentException('Extension digit must be between 0 and 9.');
        }

        return (string) $extensionDigit;
    }

    public function maxSerialReferenceForPrefix(string $companyPrefix): int
    {
        $companyPrefix = $this->normalizeCompanyPrefix($companyPrefix);

        return (10 ** (16 - strlen($companyPrefix))) - 1;
    }
}
