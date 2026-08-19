<?php

namespace App\Actions\Tenants;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\PairSiblingCentral;
use App\Support\TenantDatabaseName;
use App\Support\TenantHostname;
use App\Support\TenantPairAvailability;
use App\Support\TenantSettings;
use RuntimeException;
use Stancl\Tenancy\Database\Models\Domain;

class ProvisionTenantOnEnvironment
{
    /**
     * @param  array{
     *     name: string,
     *     profile?: TenantProfile|string,
     *     status?: string,
     *     gln?: ?string,
     *     company_prefix?: ?string,
     *     receiving_state?: ?string,
     *     hub_providers?: ?array<int, string>
     * }  $attributes
     * @param  array<string, mixed>  $address
     */
    public function provision(
        string $slug,
        array $attributes,
        string $environment,
        array $address = [],
    ): Tenant {
        $slug = strtolower($slug);
        TenantHostname::assertProvisionableSlug($slug);
        $environment = TenantHostname::assertPairEnvironment($environment);
        $domain = TenantHostname::forSlug($slug, $environment);

        $existingDomain = Domain::query()->where('domain', $domain)->first();

        if ($existingDomain === null && $environment === 'stage') {
            $prodHost = TenantHostname::forSlug($slug, 'prod');
            $prodDomain = Domain::query()->where('domain', $prodHost)->first();

            if ($prodDomain === null) {
                throw new RuntimeException(
                    'Provision the prod host for '.$slug.' before stage. Use the pair command or --environment=prod first.'
                );
            }

            $prod = Tenant::query()->find($prodDomain->tenant_id);

            if (! $prod instanceof Tenant || ! TenantPairAvailability::ownsSlug($prod, $slug)) {
                throw new RuntimeException("The host {$prodHost} is already taken.");
            }
        }

        if ($existingDomain !== null) {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->findOrFail($existingDomain->tenant_id);

            if (! TenantPairAvailability::ownsSlug($tenant, $slug)) {
                throw new RuntimeException("The host {$domain} is already taken.");
            }

            $tenant = $tenant->load('domains');
            app(PairSiblingCentral::class)->replicateIfAway($tenant, $environment);

            return $tenant;
        }

        $profile = $attributes['profile'] ?? TenantProfile::Pharmacy;
        $profile = $profile instanceof TenantProfile
            ? $profile
            : TenantProfile::from((string) $profile);

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->create([
            'name' => $attributes['name'],
            'profile' => $profile,
            'status' => $attributes['status'] ?? 'active',
            'gln' => $attributes['gln'] ?? null,
            'company_prefix' => $attributes['company_prefix'] ?? null,
            'receiving_state' => $attributes['receiving_state'] ?? null,
            'hub_providers' => $attributes['hub_providers'] ?? null,
            'inbound_environment' => $environment,
            'tenancy_db_name' => TenantDatabaseName::fromDomain($domain),
            'tenant_pair_slug' => $slug,
            'tenant_pair_environment' => $environment,
        ]);

        $tenant->domains()->create(['domain' => $domain]);

        if ($address !== []) {
            TenantSettings::forTenant($tenant)->saveOrganization($address);
        }

        $tenant = $tenant->load('domains');
        app(PairSiblingCentral::class)->replicateIfAway($tenant, $environment);

        return $tenant;
    }

    public function findBySlugAndEnvironment(string $slug, string $environment): ?Tenant
    {
        $domain = Domain::query()
            ->where('domain', TenantHostname::forSlug($slug, $environment))
            ->first();

        if ($domain === null) {
            return null;
        }

        $tenant = Tenant::query()->find($domain->tenant_id);

        return $tenant instanceof Tenant ? $tenant->load('domains') : null;
    }
}
