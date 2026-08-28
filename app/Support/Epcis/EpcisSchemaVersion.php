<?php

namespace App\Support\Epcis;

use App\Models\Tenant;
use App\Support\TenantSettings;

/**
 * Accepted EPCIS schemaVersion values.
 *
 * - 1.2 / 1.3: XML on urn:epcglobal:epcis:xsd:1 (shipped default)
 * - 2.0: JSON-LD EPCIS 2.0 (opt-in via config tracepharma.epcis.accept_20)
 *
 * GS1 US guideline R1.3 is orthogonal — see validation.force_r13.
 */
final class EpcisSchemaVersion
{
    public const V12 = '1.2';

    public const V13 = '1.3';

    public const V20 = '2.0';

    public const FORMAT_XML = 'xml';

    public const FORMAT_JSON = 'json';

    /**
     * @return list<string>
     */
    public static function accepted(): array
    {
        $versions = [self::V12, self::V13];

        if (self::accepts20()) {
            $versions[] = self::V20;
        }

        return $versions;
    }

    public static function accepts20(?Tenant $tenant = null): bool
    {
        if (! (bool) config('tracepharma.epcis.accept_20', false)) {
            return false;
        }

        $tenant = $tenant ?? (tenancy()->initialized ? tenant() : null);

        if ($tenant === null) {
            return true;
        }

        return TenantSettings::forTenant($tenant)->epcisAccept20();
    }

    public static function isAccepted(?string $version): bool
    {
        return in_array($version, self::accepted(), true);
    }

    public static function isXmlFamily(?string $version): bool
    {
        return in_array($version, [self::V12, self::V13], true);
    }

    /**
     * Peek schemaVersion from an XML document head (1.2 / 1.3 / 2.0 attribute).
     */
    public static function peek(string $xmlHead): ?string
    {
        if (preg_match('/\bschemaVersion\s*=\s*["\'](1\.[23]|2\.0)["\']/', $xmlHead, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Peek version from file contents (XML schemaVersion or JSON-LD EPCIS 2.0).
     */
    public static function peekFile(string $absolutePath): ?string
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        $handle = fopen($absolutePath, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 8192);
        if (is_resource($handle)) {
            fclose($handle);
        }

        $trimmed = ltrim($head);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return self::peekJson($head);
        }

        return self::peek($head);
    }

    public static function peekJson(string $jsonHead): ?string
    {
        if (preg_match('/"type"\s*:\s*"EPCISDocument"/', $jsonHead) !== 1
            && preg_match('/"@type"\s*:\s*"EPCISDocument"/', $jsonHead) !== 1) {
            // Still allow schemaVersion alone for compact docs
            if (preg_match('/"schemaVersion"\s*:\s*"2\.0"/', $jsonHead) === 1) {
                return self::V20;
            }

            return null;
        }

        if (preg_match('/"schemaVersion"\s*:\s*"(1\.[23]|2\.0)"/', $jsonHead, $matches) === 1) {
            return $matches[1];
        }

        // EPCIS 2.0 JSON-LD without explicit schemaVersion defaults to 2.0
        return self::V20;
    }

    public static function detectFormat(string $absolutePath): string
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return self::FORMAT_XML;
        }

        $handle = fopen($absolutePath, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 256);
        if (is_resource($handle)) {
            fclose($handle);
        }

        $trimmed = ltrim($head);

        return ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '['))
            ? self::FORMAT_JSON
            : self::FORMAT_XML;
    }

    /**
     * Fail closed when version is missing or not in the accept list.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertAccepted(?string $version, string $format = self::FORMAT_XML): string
    {
        $resolved = $version;

        if ($resolved === null && $format === self::FORMAT_XML) {
            $resolved = self::V12;
        }

        if (! self::isAccepted($resolved)) {
            $accepted = implode(', ', self::accepted());
            $hint = self::accepts20()
                ? ''
                : ' EPCIS 2.0 is disabled (set TRACEPHARMA_EPCIS_ACCEPT_20=true to enable).';

            throw new \InvalidArgumentException(
                "EPCIS schema version [".($version ?? 'missing')."] is not accepted. Allowed: {$accepted}.{$hint}"
            );
        }

        return (string) $resolved;
    }

    /**
     * Platform flag alone — true when TRACEPHARMA_EPCIS_ACCEPT_20 is enabled.
     */
    public static function accepts20PlatformOnly(): bool
    {
        return (bool) config('tracepharma.epcis.accept_20', false);
    }
}
