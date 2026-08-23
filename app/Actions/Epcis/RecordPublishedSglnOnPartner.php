<?php

namespace App\Actions\Epcis;

use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Gs1\Sgln;
use Illuminate\Database\Eloquent\Model;

/**
 * Stamp a partner-published SGLN onto matching partner sites / trading partners.
 *
 * Never invents a company-prefix split. Only stores a URN that encodes the same GLN.
 * A different valid recorded URN is left alone.
 */
final class RecordPublishedSglnOnPartner
{
    public function handle(?string $gln, ?string $sglnUrn): void
    {
        $normalized = Sgln::normalizeGln($gln);
        $parsed = is_string($sglnUrn) && $sglnUrn !== '' ? Sgln::fromUrn($sglnUrn) : null;

        if ($normalized === null || $parsed === null || $parsed['gln'] !== $normalized) {
            return;
        }

        $urn = $parsed['gln_uri'];

        Site::query()
            ->where('gln', $normalized)
            ->whereNotNull('trading_partner_id')
            ->where('trading_partner_id', '!=', 0)
            ->get()
            ->each(fn (Site $site) => $this->fillIfBlank($site, $urn, $normalized));

        TradingPartner::query()
            ->where('gln', $normalized)
            ->get()
            ->each(fn (TradingPartner $partner) => $this->fillIfBlank($partner, $urn, $normalized));
    }

    private function fillIfBlank(Model $model, string $urn, string $gln): void
    {
        $current = $model->getAttribute('sgln');
        if (is_string($current) && $current !== '') {
            $existing = Sgln::fromUrn($current);
            if ($existing !== null && $existing['gln'] === $gln) {
                return;
            }
        }

        $model->forceFill(['sgln' => $urn])->save();
    }
}
