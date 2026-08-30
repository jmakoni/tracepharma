<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Models\Site;
use App\Support\Gs1\Sgln;
use App\Support\TenantSettings;

/**
 * Resolve sender/receiver GLNs for authored outbound documents that emit
 * correlation headers. Self-authored SSCC / disposition docs use the same
 * org or site GLN for both parties.
 */
final class OutboundCorrelationGlns
{
    /**
     * @param  array{gln?: string}|null  $settings
     * @return array{0: ?string, 1: ?string}
     */
    public static function forSelfAuthored(
        ?string $correlationId,
        ?array $settings = null,
        ?int $siteId = null,
    ): array {
        if ($correlationId === null || trim($correlationId) === '') {
            return [null, null];
        }

        $gln = null;

        if (isset($settings['gln'])) {
            $gln = Sgln::normalizeGln((string) $settings['gln']);
        }

        if ($gln === null && $siteId !== null) {
            $gln = Sgln::normalizeGln(Site::query()->find($siteId)?->gln);
        }

        if ($gln === null) {
            $gln = Sgln::normalizeGln(TenantSettings::forTenant(tenant())->gln());
        }

        return [$gln, $gln];
    }
}
