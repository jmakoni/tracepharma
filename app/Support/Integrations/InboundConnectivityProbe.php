<?php

declare(strict_types=1);

namespace App\Support\Integrations;

class InboundConnectivityProbe
{
    public static function isProbe(string $content): bool
    {
        $trimmed = trim($content);

        if ($trimmed === '' || stripos($trimmed, 'connectivity test') === false) {
            return false;
        }

        if (self::isEpcisDocument($trimmed)) {
            return false;
        }

        if (preg_match('/^connectivity\s+test\s*$/i', $trimmed) === 1) {
            return true;
        }

        return self::isMinimalNonEpcisXml($trimmed);
    }

    private static function isEpcisDocument(string $content): bool
    {
        return preg_match('/(?:<[^>]*:)?EPCISDocument\b|<[^>]*epcis:|urn:epcglobal:epcis/i', $content) === 1;
    }

    private static function isMinimalNonEpcisXml(string $content): bool
    {
        $withoutDeclaration = preg_replace('/<\?xml[^?]*\?\>\s*/i', '', $content) ?? $content;

        return preg_match('/^<([a-zA-Z_][\w.-]*)>\s*connectivity\s+test\s*<\/\1>\s*$/i', trim($withoutDeclaration)) === 1;
    }
}
