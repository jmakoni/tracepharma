<?php

namespace App\Support\Tracing;

use Illuminate\Support\Str;

/**
 * Humanize EPCIS CBV bizTransaction type URIs (urn:epcglobal:cbv:btt:*) for display.
 */
final class BizTransactionLabel
{
    /**
     * Known CBV business transaction type local names => display labels.
     *
     * @var array<string, string>
     */
    private const KNOWN = [
        'po' => 'Purchase Order',
        'poc' => 'Purchase Order Confirmation',
        'desadv' => '(ASN) Despatch Advice',
        'recadv' => 'Receiving Advice',
        'pod' => 'Proof of Delivery',
        'inv' => 'Invoice',
        'bol' => 'Bill of Lading',
        'rma' => 'Return Merchandise Authorization',
        'prodorder' => 'Production Order',
        'pedigree' => 'Pedigree',
    ];

    /**
     * @param  string  $typeUri  e.g. "urn:epcglobal:cbv:btt:desadv" or a bare local name.
     */
    public static function forTypeUri(?string $typeUri): string
    {
        if (! filled($typeUri)) {
            return '—';
        }

        $segment = self::lastSegment(trim($typeUri));

        if ($segment === null || $segment === '') {
            return $typeUri;
        }

        $known = self::KNOWN[strtolower($segment)] ?? null;

        return $known ?? self::titleCase($segment);
    }

    private static function lastSegment(string $uri): ?string
    {
        if ($uri === '') {
            return null;
        }

        if (! str_contains($uri, ':')) {
            return $uri;
        }

        $parts = explode(':', $uri);
        $segment = (string) end($parts);

        return $segment !== '' ? $segment : null;
    }

    private static function titleCase(string $segment): string
    {
        $spaced = str_replace(['_', '-'], ' ', $segment);

        return Str::title($spaced);
    }
}
