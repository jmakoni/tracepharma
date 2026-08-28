<?php

declare(strict_types=1);

namespace App\Support\Epcis;

/**
 * Write inbound EPCIS payloads to a temp path with the correct extension (.xml or .json).
 */
final class EpcisTempFile
{
    public static function write(string $content, ?string $originalFilename = null, string $prefix = 'epcis_'): string
    {
        $extension = self::guessExtension($content, $originalFilename);

        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temporary EPCIS file.');
        }

        $path = $tmp.'.'.$extension;
        rename($tmp, $path);
        file_put_contents($path, $content);

        return $path;
    }

    public static function guessExtension(string $content, ?string $originalFilename = null): string
    {
        if ($originalFilename !== null) {
            $fromName = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            if (in_array($fromName, ['xml', 'json'], true)) {
                return $fromName;
            }
        }

        $trimmed = ltrim($content);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return EpcisSchemaVersion::FORMAT_JSON;
        }

        return EpcisSchemaVersion::FORMAT_XML;
    }
}
