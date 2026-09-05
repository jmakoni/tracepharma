<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

use App\Models\OutboundConnection;
use App\Support\Epcis\EpcisSchemaVersion;

/**
 * Resolve outbound EPCIS document writer by connection version + format.
 */
final class OutboundEpcisWriterResolver
{
    public function __construct(
        private readonly Xml12Writer $xml12,
        private readonly Xml20Writer $xml20,
        private readonly JsonLd20Writer $json20,
    ) {}

    public function forConnection(?OutboundConnection $connection): OutboundEpcisDocumentWriter
    {
        $version = $this->versionForConnection($connection);
        $format = $this->formatForConnection($connection);

        return $this->resolve($version, $format);
    }

    public function forVersion(?string $version, ?string $format = null): OutboundEpcisDocumentWriter
    {
        return $this->resolve(
            $version ?? EpcisSchemaVersion::V12,
            $format ?? EpcisSchemaVersion::FORMAT_JSON,
        );
    }

    public function versionForConnection(?OutboundConnection $connection): string
    {
        $fromSettings = is_array($connection?->settings)
            ? ($connection->settings['epcis_document_version'] ?? null)
            : null;

        $configured = is_string($fromSettings) && $fromSettings !== ''
            ? $fromSettings
            : (string) config('tracepharma.epcis.default_outbound_version', EpcisSchemaVersion::V12);

        if ($configured === EpcisSchemaVersion::V20 && ! EpcisSchemaVersion::accepts20()) {
            return EpcisSchemaVersion::V12;
        }

        return in_array($configured, [EpcisSchemaVersion::V12, EpcisSchemaVersion::V20], true)
            ? $configured
            : EpcisSchemaVersion::V12;
    }

    public function formatForConnection(?OutboundConnection $connection): string
    {
        $fromSettings = is_array($connection?->settings)
            ? ($connection->settings['epcis_document_format'] ?? null)
            : null;

        if (is_string($fromSettings) && in_array($fromSettings, [EpcisSchemaVersion::FORMAT_XML, EpcisSchemaVersion::FORMAT_JSON], true)) {
            return $fromSettings;
        }

        // Default: 2.0 → JSON-LD; 1.2 → XML
        return $this->versionForConnection($connection) === EpcisSchemaVersion::V20
            ? EpcisSchemaVersion::FORMAT_JSON
            : EpcisSchemaVersion::FORMAT_XML;
    }

    private function resolve(string $version, string $format): OutboundEpcisDocumentWriter
    {
        if ($version === EpcisSchemaVersion::V20 && EpcisSchemaVersion::accepts20()) {
            // Xml20Writer is a schemaVersion retag stub — never select it for outbound.
            unset($format);

            return $this->json20;
        }

        return $this->xml12;
    }
}
