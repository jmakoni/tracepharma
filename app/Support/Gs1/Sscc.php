<?php

namespace App\Support\Gs1;

use App\Domain\Gs1\Sscc18;
use App\Domain\Gs1\SsccUri;
use InvalidArgumentException;

/**
 * GS1 SSCC helpers for EPCIS pallet / logistics unit identity.
 *
 * Encodes urn:epc:id:sscc:{companyPrefix}.{serialRef} into the 18-digit SSCC
 * barcode form (AI 00). Extension digit is the first character of serialRef;
 * body order is extension + companyPrefix + serialBody (not a naive concat).
 */
final class Sscc
{
    /**
     * Parse an SSCC Pure Identity URN into barcode fields.
     *
     * @return array{
     *     epc_uri: string,
     *     company_prefix: string,
     *     extension_digit: string,
     *     serial_reference: string,
     *     sscc18: string,
     *     ai_00: string
     * }|null
     */
    public static function fromUrn(string $uri): ?array
    {
        try {
            $parsed = SsccUri::fromUrn($uri);
        } catch (InvalidArgumentException) {
            return null;
        }

        $sscc18 = $parsed->sscc()->toString();
        $companyPrefix = $parsed->companyPrefix();
        $serialReference = substr($parsed->toString(), strlen('urn:epc:id:sscc:'.$companyPrefix.'.'));

        return [
            'epc_uri' => $parsed->toString(),
            'company_prefix' => $companyPrefix,
            'extension_digit' => $serialReference[0],
            'serial_reference' => $serialReference,
            'sscc18' => $sscc18,
            'ai_00' => '00'.$sscc18,
        ];
    }

    /**
     * Normalize an 18-digit SSCC and validate its check digit.
     *
     * Cannot recover the URN without knowing company-prefix length.
     *
     * @return array{sscc18: string, ai_00: string}|null
     */
    public static function fromSscc18(string $digits): ?array
    {
        try {
            $sscc = Sscc18::fromDigits($digits);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'sscc18' => $sscc->toString(),
            'ai_00' => '00'.$sscc->toString(),
        ];
    }

    /**
     * Build an SSCC Pure Identity URN.
     *
     * Serial body is the digits after the extension digit (not including it).
     * Example: companyPrefix=030116, extensionDigit=0, serialBody=1001235403
     * → urn:epc:id:sscc:030116.01001235403
     */
    public static function toUrn(string $companyPrefix, string $extensionDigit, string $serialBody): string
    {
        $companyPrefix = preg_replace('/\D+/', '', $companyPrefix) ?? '';
        $extensionDigit = preg_replace('/\D+/', '', $extensionDigit) ?? '';
        $serialBody = preg_replace('/\D+/', '', $serialBody) ?? '';

        if ($companyPrefix === '' || $extensionDigit === '' || strlen($extensionDigit) !== 1) {
            throw new InvalidArgumentException('SSCC URN requires a company prefix and a single extension digit.');
        }

        $sscc = Sscc18::fromCompanyPrefixAndSerialRef($companyPrefix, $extensionDigit, $serialBody);

        return SsccUri::fromSscc($sscc, $companyPrefix)->toString();
    }

    /**
     * Build barcode fields from company prefix, extension digit, and serial body.
     *
     * @return array{
     *     epc_uri: string,
     *     company_prefix: string,
     *     extension_digit: string,
     *     serial_reference: string,
     *     sscc18: string,
     *     ai_00: string
     * }|null
     */
    public static function build(string $companyPrefix, string $extensionDigit, string $serialBody): ?array
    {
        try {
            return self::fromUrn(self::toUrn($companyPrefix, $extensionDigit, $serialBody));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
