<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Enums\EpcisGuideline;
use App\Models\Epcis\EpcisDocument;
use Throwable;

/**
 * Per-document GS1 US DSCSA guideline detection (R1.2 vs R1.3).
 *
 * Mixed only when exclusive constructs collide: both NDC type codes, or a
 * boolean directPurchase together with an R1.3 @qualifier. A transitional
 * R1.3 header plus FDA_NDC_11 vocabulary is R1.3, not mixed.
 * Missing guidelineVersion is R1.2, never AMBIGUOUS.
 */
final class DetectDscsaGuidelineRelease
{
    public static function fromPath(?string $absolutePath): DscsaGuidelineDetection
    {
        if ($absolutePath === null || $absolutePath === '' || ! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return DscsaGuidelineDetection::r12();
        }

        $payload = @file_get_contents($absolutePath);

        return self::fromPayload($payload === false ? '' : $payload);
    }

    public static function fromPayload(string $payload): DscsaGuidelineDetection
    {
        $trimmed = ltrim($payload);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return self::fromJson($payload);
        }

        return self::fromXml($payload);
    }

    public static function fromJson(string $json): DscsaGuidelineDetection
    {
        $r12 = [];
        $r13 = [];

        if (self::jsonHasGuidelineVersionR13($json)) {
            $r13[] = 'guidelineVersion';
        }

        if (self::jsonHasTypeCode($json, 'US_FDA_NDC')) {
            $r13[] = 'US_FDA_NDC';
        }

        if (self::jsonHasTypeCode($json, 'FDA_NDC_11')) {
            $r12[] = 'FDA_NDC_11';
        }

        if (preg_match('/"?dropShipment"?\s*:/i', $json) === 1) {
            $r13[] = 'dropShipment';
        }

        $hasQualifier = (bool) preg_match(
            '/"qualifier"\s*:\s*"(?:ENTIRELY_DIRECT|ENTIRELY_INDIRECT|PARTIALLY_DIRECT)"/i',
            $json,
        );
        $hasBoolean = self::jsonHasBooleanDirectPurchase($json);

        if ($hasQualifier) {
            $r13[] = 'directPurchase@qualifier';
        }

        if ($hasBoolean) {
            $r12[] = 'directPurchase@boolean';
        }

        $exclusiveNdc = in_array('FDA_NDC_11', $r12, true) && in_array('US_FDA_NDC', $r13, true);
        $exclusivePurchase = $hasQualifier && $hasBoolean;

        if ($exclusiveNdc || $exclusivePurchase) {
            return DscsaGuidelineDetection::mixed($r12, $r13);
        }

        if ($r13 !== []) {
            return DscsaGuidelineDetection::r13($r12, $r13);
        }

        return DscsaGuidelineDetection::r12($r12, $r13, self::isLotLevelPayload($json));
    }

    public static function isR12LotOnlyShape(?DscsaGuidelineDetection $detection): bool
    {
        return $detection !== null
            && ! $detection->mixed
            && $detection->release === EpcisGuideline::R12
            && $detection->lotOnly;
    }

    /**
     * Payload detection first. Missing header / unreadable payload = R1.2.
     * Mixed exclusive constructs are not R1.2 lot-only.
     */
    public static function documentIsR12LotOnly(?EpcisDocument $document): bool
    {
        if ($document === null) {
            return false;
        }

        return self::isR12LotOnlyShape(self::detectionForDocument($document));
    }

    public static function detectionForDocument(EpcisDocument $document): DscsaGuidelineDetection
    {
        $path = null;
        try {
            if (filled($document->payload_path)) {
                $path = $document->materializePayloadPath();
            }
        } catch (Throwable) {
            $path = null;
        }

        if ($path !== null && $path !== '' && is_file($path) && is_readable($path)) {
            return self::fromPath($path);
        }

        return match ($document->dscsa_guideline_release) {
            EpcisGuideline::R13 => DscsaGuidelineDetection::r13(),
            EpcisGuideline::R12 => DscsaGuidelineDetection::r12(),
            default => DscsaGuidelineDetection::r12(),
        };
    }

    public static function fromXml(string $xml): DscsaGuidelineDetection
    {
        $r12 = [];
        $r13 = [];

        if (self::hasGuidelineVersionR13($xml)) {
            $r13[] = 'guidelineVersion';
        }

        if (self::hasTypeCode($xml, 'US_FDA_NDC')) {
            $r13[] = 'US_FDA_NDC';
        }

        if (self::hasTypeCode($xml, 'FDA_NDC_11')) {
            $r12[] = 'FDA_NDC_11';
        }

        if (self::hasDropShipment($xml)) {
            $r13[] = 'dropShipment';
        }

        $hasQualifier = self::hasDirectPurchaseQualifier($xml);
        $hasBoolean = self::hasBooleanDirectPurchase($xml);

        if ($hasQualifier) {
            $r13[] = 'directPurchase@qualifier';
        }

        if ($hasBoolean) {
            $r12[] = 'directPurchase@boolean';
        }

        $exclusiveNdc = in_array('FDA_NDC_11', $r12, true) && in_array('US_FDA_NDC', $r13, true);
        $exclusivePurchase = $hasQualifier && $hasBoolean;

        if ($exclusiveNdc || $exclusivePurchase) {
            return DscsaGuidelineDetection::mixed($r12, $r13);
        }

        if ($r13 !== []) {
            return DscsaGuidelineDetection::r13($r12, $r13);
        }

        return DscsaGuidelineDetection::r12($r12, $r13, self::isLotLevelPayload($xml));
    }

    /**
     * R1.2 lot-level exchange: quantity / EPCClass, no instance SGTINs.
     * Serialized R1.2 (SGTIN in epcList) is not lot-only.
     */
    private static function isLotLevelPayload(string $xml): bool
    {
        if ($xml === '' || preg_match('/urn:epc:id:sgtin:/i', $xml) === 1) {
            return false;
        }

        return preg_match('/<(?:[\w.-]+:)?quantityList\b/i', $xml) === 1
            || preg_match('/<(?:[\w.-]+:)?epcClass\b/i', $xml) === 1
            || preg_match('/urn:epc:class:/i', $xml) === 1;
    }

    private static function hasGuidelineVersionR13(string $xml): bool
    {
        if (! preg_match('/<(?:[\w.-]+:)?guidelineVersion[^>]*>\s*([^<]+)/i', $xml, $match)) {
            return false;
        }

        $text = strtoupper(trim($match[1]));

        return (bool) preg_match('/\bR1\.3\b/', $text);
    }

    private static function hasTypeCode(string $xml, string $code): bool
    {
        return (bool) preg_match(
            '/additionalTradeItemIdentificationTypeCode[^>]*>\s*'.preg_quote($code, '/').'\s*</i',
            $xml,
        ) || (bool) preg_match(
            '/additionalTradeItemIdentificationTypeCode["\']?\s*>\s*'.preg_quote($code, '/').'\s*</i',
            $xml,
        );
    }

    private static function hasDropShipment(string $xml): bool
    {
        return (bool) preg_match('/<(?:[\w.-]+:)?dropShipment(?:\s[^>]*)?>/i', $xml);
    }

    private static function hasDirectPurchaseQualifier(string $xml): bool
    {
        return (bool) preg_match(
            '/<(?:[\w.-]+:)?(?:directPurchase|receivedDirectPurchaseFromPrevWhlsDist)\b[^>]*\bqualifier\s*=\s*"(?:ENTIRELY_DIRECT|ENTIRELY_INDIRECT|PARTIALLY_DIRECT)"/i',
            $xml,
        );
    }

    private static function hasBooleanDirectPurchase(string $xml): bool
    {
        if (preg_match_all('/<(?:[\w.-]+:)?directPurchase\b([^>]*)>([^<]*)</i', $xml, $matches, PREG_SET_ORDER) === false) {
            return false;
        }

        foreach ($matches as $match) {
            $attributes = $match[1];
            $body = strtolower(trim($match[2]));
            if (preg_match('/\bqualifier\s*=/i', $attributes)) {
                continue;
            }

            if (in_array($body, ['true', 'false', '1', '0'], true)) {
                return true;
            }

            if (preg_match('/\bvalue\s*=\s*"(true|false|1|0)"/i', $attributes)) {
                return true;
            }
        }

        return false;
    }

    private static function jsonHasGuidelineVersionR13(string $json): bool
    {
        if (! preg_match('/guidelineVersion"\s*:\s*"([^"]+)"/i', $json, $match)) {
            return false;
        }

        return (bool) preg_match('/\bR1\.3\b/', strtoupper($match[1]));
    }

    private static function jsonHasTypeCode(string $json, string $code): bool
    {
        return (bool) preg_match(
            '/additionalTradeItemIdentificationTypeCode"\s*:\s*"'.preg_quote($code, '/').'"/i',
            $json,
        );
    }

    private static function jsonHasBooleanDirectPurchase(string $json): bool
    {
        if (preg_match('/"qualifier"\s*:\s*"(?:ENTIRELY_DIRECT|ENTIRELY_INDIRECT|PARTIALLY_DIRECT)"/i', $json) === 1) {
            return false;
        }

        return (bool) preg_match('/directPurchase"\s*:\s*(true|false|1|0)\b/i', $json);
    }
}
