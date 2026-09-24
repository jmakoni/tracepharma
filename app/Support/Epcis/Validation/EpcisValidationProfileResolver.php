<?php

namespace App\Support\Epcis\Validation;

use App\Enums\EpcisGuideline;
use App\Models\Epcis\EpcisDocument;
use App\Support\Epcis\DetectDscsaGuidelineRelease;
use SimpleXMLElement;
use Throwable;

/**
 * Resolves which validation profile applies to a document.
 *
 * The hard R1.3 profile follows per-document detection only. Tenant
 * {@see config('tracepharma.epcis.validation.force_r13')} does not store or
 * override release — stored {@see EpcisDocument::$dscsa_guideline_release}
 * is always payload detection in ProcessEpcisDocument.
 */
final class EpcisValidationProfileResolver
{
    public function resolve(EpcisDocument $document, string $direction, ?string $payloadPath = null): EpcisValidationContext
    {
        $tenantDefault = EpcisValidationProfile::tryFrom(
            (string) config('tracepharma.epcis.validation.default_profile', 'gs1us_r12')
        ) ?? EpcisValidationProfile::Gs1UsR12;

        $resolvedPath = $payloadPath ?? $this->resolvePayloadPath($document);
        $declaredVersion = $resolvedPath !== null ? $this->detectGuidelineVersion($resolvedPath) : null;
        $detection = $resolvedPath !== null
            ? DetectDscsaGuidelineRelease::fromPath($resolvedPath)
            : ($document->dscsa_guideline_release !== null
                ? DetectDscsaGuidelineRelease::detectionForDocument($document)
                : null);
        $detectedR13 = $detection !== null
            && ! $detection->mixed
            && $detection->release === EpcisGuideline::R13;

        $r13Hard = $detectedR13;

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
}
