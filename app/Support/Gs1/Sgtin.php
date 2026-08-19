<?php

namespace App\Support\Gs1;

use App\Domain\Gs1\SgtinUri;
use InvalidArgumentException;

/**
 * GS1 SGTIN helpers for EPCIS serialized trade item identity.
 *
 * Encodes urn:epc:id:sgtin:{companyPrefix}.{indicatorItemRef}.{serial}
 * into GTIN-14 and the concatenated AI (01)+(21) element string.
 */
final class Sgtin
{
    /**
     * Parse an SGTIN Pure Identity URN into barcode fields.
     *
     * @return array{
     *     epc_uri: string,
     *     company_prefix: string,
     *     indicator_digit: string,
     *     item_reference: string,
     *     serial_number: string,
     *     gtin14: string,
     *     ai_01_21: string
     * }|null
     */
    public static function fromUrn(string $uri): ?array
    {
        try {
            $parsed = SgtinUri::fromUrn($uri);
        } catch (InvalidArgumentException) {
            return null;
        }

        $gtin14 = $parsed->gtin()->toString();
        $companyPrefix = $parsed->companyPrefix();
        $body = $parsed->gtin()->body();
        $indicatorDigit = $body[0];
        $itemReference = substr($body, 1 + strlen($companyPrefix));

        return [
            'epc_uri' => $parsed->toString(),
            'company_prefix' => $companyPrefix,
            'indicator_digit' => $indicatorDigit,
            'item_reference' => $itemReference,
            'serial_number' => $parsed->serial(),
            'gtin14' => $gtin14,
            'ai_01_21' => '01'.$gtin14.'21'.$parsed->serial(),
        ];
    }
}
