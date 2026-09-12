<?php

namespace App\Support\Gs1;

use App\Exceptions\OrganizationIdentityConflictException;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Custody\TenantGlnSet;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ensures the tenant's SSCC/GLN identity is not a known inbound trading partner's.
 * Newly commissioned SSCCs must use the packing party's GCP (GS1 US DSCSA FAQ 2.2.3 / 2.3.4).
 *
 * Only active partners and their active sites count, and partners carrying one of
 * the organization's own facility GLNs are self-partners (historic auto-create
 * leakage), not suppliers.
 */
final class AssertOrganizationSsccIdentity
{
    /**
     * @throws OrganizationIdentityConflictException
     */
    public function handle(?string $gln, ?string $companyPrefix): void
    {
        $prefix = TenantSettings::normalizeCompanyPrefix($companyPrefix);
        $glnDigits = preg_replace('/\D+/', '', (string) $gln) ?? '';

        if ($prefix === null && $glnDigits === '') {
            return;
        }

        if (! tenancy()->initialized || ! Schema::hasTable('trading_partners')) {
            return;
        }

        $selfGlns = Schema::hasTable('sites')
            ? (new TenantGlnSet)->organizationFacilityGlns()
            : [];

        foreach ($this->partnerGlns() as $partnerGln => $name) {
            $partnerGln = (string) $partnerGln;

            if (in_array($partnerGln, $selfGlns, true)) {
                continue;
            }

            if ($glnDigits !== '' && $glnDigits === $partnerGln) {
                throw new OrganizationIdentityConflictException(
                    "Organization GLN matches trading partner \"{$name}\". "
                    .'Set your own GS1 identity in Organization Settings — SSCC labels must use your company prefix, not a supplier\'s.',
                    field: 'gln',
                );
            }

            if (
                $prefix !== null
                && str_starts_with(substr($partnerGln, 0, 12), $prefix)
                && ! TenantSettings::forTenant(tenant())->allowAssignPartnerGlnsFromPrefix()
            ) {
                throw new OrganizationIdentityConflictException(
                    "Organization company prefix {$prefix} matches trading partner \"{$name}\" (GLN {$partnerGln}). "
                    .'Set your own GS1 Company Prefix in Organization Settings before commissioning SSCCs, '
                    .'or enable "Allow assign partner GLNs from our prefix" when you allocate partner GLNs under your GCP.',
                    field: 'company_prefix',
                );
            }
        }
    }

    /**
     * Run the check inside the tenant's own database.
     *
     * The admin panel edits tenant identity from the central context, where no
     * trading partner table is in reach; without this the collision would only
     * surface later, on the first SSCC commissioning.
     *
     * @throws OrganizationIdentityConflictException
     */
    public function forTenant(Tenant $tenant, ?string $gln, ?string $companyPrefix): void
    {
        $current = tenancy()->initialized ? tenant() : null;

        if ($current instanceof Tenant && $current->getKey() === $tenant->getKey()) {
            $this->handle($gln, $companyPrefix);

            return;
        }

        try {
            tenancy()->initialize($tenant);
        } catch (Throwable) {
            // Tenant database not provisioned yet (fresh create): nothing to collide with.
            return;
        }

        try {
            $this->handle($gln, $companyPrefix);
        } catch (OrganizationIdentityConflictException $conflict) {
            throw $conflict;
        } catch (Throwable) {
            // The same unprovisioned database, reached one step later: initializing a
            // tenancy only swaps connection config, so a database that was never created
            // announces itself on the first query rather than here. Nothing to collide
            // with either way, and commissioning re-runs this check against a real one.
        } finally {
            tenancy()->end();

            if ($current instanceof Tenant) {
                tenancy()->initialize($current);
            }
        }
    }

    /**
     * Every GLN an active partner answers to — the partner record's own GLN and the
     * GLNs of its active sites. A partner site GLN is just as much theirs, so
     * adopting one as our identity would still author their locations as ours.
     *
     * @return array<string, string> GLN => partner name
     */
    private function partnerGlns(): array
    {
        $glns = [];

        $partners = TradingPartner::query()
            ->where('is_active', true)
            ->whereNotNull('gln')
            ->where('gln', '!=', '')
            ->get(['id', 'name', 'gln']);

        foreach ($partners as $partner) {
            $digits = preg_replace('/\D+/', '', (string) $partner->gln) ?? '';

            if ($digits !== '') {
                $glns[$digits] = $this->partnerName($partner->name);
            }
        }

        if (! Schema::hasTable('sites')) {
            return $glns;
        }

        $sites = Site::query()
            ->whereNotNull('trading_partner_id')
            ->where('is_active', true)
            ->whereNotNull('gln')
            ->where('gln', '!=', '')
            ->whereHas('tradingPartner', fn ($query) => $query->where('is_active', true))
            ->with('tradingPartner:id,name')
            ->get(['id', 'name', 'gln', 'trading_partner_id']);

        foreach ($sites as $site) {
            $digits = preg_replace('/\D+/', '', (string) $site->gln) ?? '';

            if ($digits === '' || isset($glns[$digits])) {
                continue;
            }

            $glns[$digits] = $this->partnerName($site->tradingPartner?->name);
        }

        return $glns;
    }

    private function partnerName(mixed $name): string
    {
        return filled($name) ? (string) $name : 'a trading partner';
    }
}
