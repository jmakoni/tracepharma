<?php

declare(strict_types=1);

namespace App\Support\Shipping;

use App\Models\Site;
use App\Support\Custody\TenantGlnSet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Autocomplete options for Ship Order customer / ship-to selection.
 *
 * Each hit is a partner site: company name + ship-to address + GLN.
 */
final class SearchShipToCustomers
{
    public const LIMIT = 25;

    /**
     * @return list<array{
     *     site_id: int,
     *     trading_partner_id: int,
     *     company: string,
     *     address: string,
     *     gln: ?string,
     *     site_name: string
     * }>
     */
    public function handle(string $search): array
    {
        $term = trim($search);

        $query = Site::query()
            ->with(['tradingPartner:id,name'])
            ->where('sites.is_active', true)
            ->whereNotNull('sites.trading_partner_id')
            ->where('sites.is_organization_facility', false)
            ->whereHas('tradingPartner', fn (Builder $q): Builder => $q->where('is_active', true))
            ->leftJoin('trading_partners', 'trading_partners.id', '=', 'sites.trading_partner_id')
            ->select([
                'sites.id',
                'sites.trading_partner_id',
                'sites.name',
                'sites.gln',
                'sites.street_address',
                'sites.street_address_2',
                'sites.city',
                'sites.state',
                'sites.zipcode',
                'sites.country_code',
            ])
            ->orderBy('trading_partners.name')
            ->orderBy('sites.name')
            ->limit(self::LIMIT);

        // We can never ship to ourselves: a partner site carrying one of our own
        // GLNs is a self-partner leftover, not a customer.
        $tenantGlns = (new TenantGlnSet)->all();

        if ($tenantGlns !== []) {
            $query->where(function (Builder $q) use ($tenantGlns): void {
                $q->whereNull('sites.gln')
                    ->orWhereNotIn('sites.gln', $tenantGlns);
            });
        }

        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('sites.name', 'like', $like)
                    ->orWhere('sites.street_address', 'like', $like)
                    ->orWhere('sites.street_address_2', 'like', $like)
                    ->orWhere('sites.city', 'like', $like)
                    ->orWhere('sites.state', 'like', $like)
                    ->orWhere('sites.zipcode', 'like', $like)
                    ->orWhere('sites.gln', 'like', $like)
                    ->orWhere('trading_partners.name', 'like', $like)
                    ->orWhere('trading_partners.doing_business_as', 'like', $like);
            });
        }

        /** @var list<Site> $sites */
        $sites = $query->get();

        return $sites->map(function (Site $site): array {
            $company = (string) ($site->tradingPartner?->name ?: $site->name);

            return [
                'site_id' => (int) $site->getKey(),
                'trading_partner_id' => (int) $site->trading_partner_id,
                'company' => $company,
                'address' => self::formatAddress($site),
                'gln' => filled($site->gln) ? (string) $site->gln : null,
                'site_name' => (string) $site->name,
            ];
        })->all();
    }

    public static function formatAddress(Site $site): string
    {
        $cityState = trim(implode(', ', array_values(array_filter([
            filled($site->city) ? (string) $site->city : null,
            filled($site->state) ? (string) $site->state : null,
        ], fn (?string $part): bool => filled($part)))));

        if (filled($site->zipcode)) {
            $cityState = trim($cityState.' '.(string) $site->zipcode);
        }

        $parts = array_values(array_filter([
            filled($site->street_address) ? (string) $site->street_address : null,
            filled($site->street_address_2) ? (string) $site->street_address_2 : null,
            $cityState !== '' ? $cityState : null,
            filled($site->country_code) ? (string) $site->country_code : null,
        ], fn (?string $part): bool => filled($part)));

        return $parts === [] ? '—' : implode(', ', $parts);
    }
}
