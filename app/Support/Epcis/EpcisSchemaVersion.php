<?php

namespace App\Support\Epcis;

/**
 * Accepted EPCIS XML schemaVersion values. 1.3 is 1.2-plus XML on the same
 * urn:epcglobal:epcis:xsd:1 spine — not GS1 US guideline R1.3 and not EPCIS 2.0.
 */
final class EpcisSchemaVersion
{
    public const V12 = '1.2';

    public const V13 = '1.3';

    /**
     * @return list<string>
     */
    public static function accepted(): array
    {
        return [self::V12, self::V13];
    }

    public static function isAccepted(?string $version): bool
    {
        return in_array($version, self::accepted(), true);
    }

    public static function peek(string $xmlHead): ?string
    {
        if (preg_match('/\bschemaVersion\s*=\s*["\'](1\.[23])["\']/', $xmlHead, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

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

        return self::peek($head);
    }
}
