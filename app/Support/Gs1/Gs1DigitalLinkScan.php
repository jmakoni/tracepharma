<?php

namespace App\Support\Gs1;

/**
 * Normalize GS1 Digital Link scan URLs to element-string form for {@see ResolveEpcFromScan}.
 */
final class Gs1DigitalLinkScan
{
    /**
     * Convert id.gs1.org (or compatible) Digital Link to element string / scan token.
     */
    public static function toElementString(string $input): ?string
    {
        $trimmed = trim($input);

        if ($trimmed === '' || ! str_contains($trimmed, '://')) {
            return null;
        }

        $path = parse_url($trimmed, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn (string $s): bool => $s !== ''));

        if ($segments === []) {
            return null;
        }

        // /00/{sscc18}
        if (($segments[0] ?? '') === '00' && isset($segments[1]) && preg_match('/^\d{18}$/', $segments[1])) {
            return '(00)'.$segments[1];
        }

        // /01/{gtin14}/21/{serial}[/10/{lot}][/17/{yymmdd}]
        if (($segments[0] ?? '') === '01' && isset($segments[1], $segments[2]) && $segments[2] === '21' && isset($segments[3])) {
            $encoded = '01'.$segments[1].'21'.$segments[3];
            $cursor = 4;
            while ($cursor < count($segments)) {
                $ai = $segments[$cursor];
                $value = $segments[$cursor + 1] ?? '';
                if ($value === '') {
                    break;
                }
                if (in_array($ai, ['10', '17'], true)) {
                    $encoded .= $ai.$value;
                }
                $cursor += 2;
            }

            return $encoded;
        }

        return null;
    }
}
