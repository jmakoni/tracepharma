<?php

namespace App\Support\Epcis\Validation;

use App\Models\Epcis\EpcisDocument;
use SimpleXMLElement;
use Throwable;

/**
 * Resolves which validation profile applies to a document: DSCSA minimum is
 * always stacked conceptually, and the "hard" enforced profile is GS1 US R1.3
 * when forced by config or detected in the payload, otherwise the tenant default.
 */
final class EpcisValidationProfileResolver
{
    public function resolve(EpcisDocument $document, string $direction, ?string $payloadPath = null): EpcisValidationContext
    {
        $tenantDefault = EpcisValidationProfile::tryFrom(
            (string) config('tracepharma.epcis.validation.default_profile', 'gs1us_r12')
        ) ?? EpcisValidationProfile::Gs1UsR12;

        $forceR13 = (bool) config('tracepharma.epcis.validation.force_r13', false);

        $resolvedPath = $payloadPath ?? $this->resolvePayloadPath($document);
        $declaredVersion = $resolvedPath !== null ? $this->detectGuidelineVersion($resolvedPath) : null;

        $r13Hard = $forceR13 || $this->indicatesR13($declaredVersion);

        return new EpcisValidationContext(
            document: $document,
            direction: $direction,
            profile: $r13Hard ? EpcisValidationProfile::Gs1UsR13 : $tenantDefault,
            tenantDefault: $tenantDefault,
            r13Hard: $r13Hard,
            payloadPath: $resolvedPath,
            declaredGuidelineVersion: $declaredVersion,
        );
    }

    private function resolvePayloadPath(EpcisDocument $document): ?string
    {
        if (blank($document->payload_path)) {
            return null;
        }

        try {
            return $document->materializePayloadPath();
        } catch (Throwable) {
            return null;
        }
    }

    private function detectGuidelineVersion(string $absolutePath): ?string
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        $previousErrorHandling = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $xml = @simplexml_load_file($absolutePath);
            if (! $xml instanceof SimpleXMLElement) {
                return null;
            }

            $nodes = $xml->xpath('//*[local-name()="guidelineVersion"]') ?: [];

            foreach ($nodes as $node) {
                $value = trim((string) $node);
                if ($value !== '') {
                    return $value;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
        }
    }

    private function indicatesR13(?string $declaredVersion): bool
    {
        if ($declaredVersion === null || $declaredVersion === '') {
            return false;
        }

        return str_contains($declaredVersion, '1.3') || str_contains(strtoupper($declaredVersion), 'R1.3');
    }
}
