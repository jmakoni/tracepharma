<?php

namespace App\Support\Filesystem;

/**
 * Strip path traversal and control characters from user-supplied filenames.
 */
final class SafeFilename
{
    public static function forUpload(?string $originalFilename, string $fallback): string
    {
        $filename = self::basenameWithoutControls($originalFilename);

        if ($filename === '') {
            $filename = $fallback;
        }

        if (! str_ends_with(strtolower($filename), '.xml')) {
            $filename .= '.xml';
        }

        return $filename;
    }

    public static function forDownload(?string $originalFilename, ?string $payloadPath): string
    {
        if (filled($originalFilename)) {
            $filename = self::basenameWithoutControls((string) $originalFilename);

            if ($filename !== '') {
                return $filename;
            }
        }

        $basename = self::basenameWithoutControls((string) $payloadPath);

        return filled($basename) ? $basename : 'epcis-document.xml';
    }

    private static function basenameWithoutControls(?string $filename): string
    {
        if (! filled($filename)) {
            return '';
        }

        $basename = basename(str_replace('\\', '/', (string) $filename));
        $basename = preg_replace('/[\x00-\x1F\x7F]/u', '', $basename) ?? '';

        $basename = trim($basename);

        if ($basename === '' || $basename === '.' || $basename === '..') {
            return '';
        }

        return $basename;
    }
}
