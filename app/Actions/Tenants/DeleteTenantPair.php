<?php

namespace App\Actions\Tenants;

use App\Models\Tenant;
use App\Support\PairSiblingCentral;
use App\Support\TenantHostname;
use App\Support\TenantPairAvailability;
use App\Support\TenantSettings;
use Filament\Forms\Components\Checkbox;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Database\Models\Domain;

class DeleteTenantPair
{
    public const RECENT_EXPORT_DAYS = 7;

    public function __construct(
        private readonly ProvisionTenantOnEnvironment $onEnvironment,
    ) {}

    public function sibling(Tenant $tenant): ?Tenant
    {
        $slug = $this->pairSlug($tenant);

        if ($slug === null) {
            return null;
        }

        $otherEnvironment = $this->pairEnvironment($tenant) === 'stage' ? 'prod' : 'stage';
        $sibling = $this->onEnvironment->findBySlugAndEnvironment($slug, $otherEnvironment);

        if (! $sibling instanceof Tenant || $sibling->id === $tenant->id) {
            return null;
        }

        $tenantSlug = is_string($tenant->tenant_pair_slug) ? strtolower($tenant->tenant_pair_slug) : '';
        $siblingSlug = is_string($sibling->tenant_pair_slug) ? strtolower($sibling->tenant_pair_slug) : '';

        if ($tenantSlug === '' || $siblingSlug === '' || $tenantSlug !== $siblingSlug) {
            return null;
        }

        return $sibling;
    }

    public function deleteSibling(Tenant $tenant): void
    {
        $sibling = $this->sibling($tenant);
        $preserveTenantIds = [(string) $tenant->id];

        if ($sibling instanceof Tenant) {
            $preserveTenantIds[] = (string) $sibling->id;
        }

        $this->cleanPairSquatters($tenant, $preserveTenantIds);

        if ($sibling instanceof Tenant) {
            app(PairSiblingCentral::class)->forget((string) $sibling->id);
            $sibling->delete();
        }
    }

    /**
     * Delete a tenant and its pair sibling when the sibling is not also selected.
     *
     * Matches EditTenant delete order (sibling first, then primary). Bulk delete calls
     * this per selected row so a failure on one tenant does not pre-delete siblings
     * for tenants that have not been processed yet.
     *
     * @param  list<string>  $selectedTenantIds
     * @return list<string> Deleted tenant ids (sibling first when applicable).
     */
    public function deleteWithSibling(Tenant $tenant, array $selectedTenantIds = []): array
    {
        $deleted = [];
        $sibling = $this->sibling($tenant);
        $preserveTenantIds = [(string) $tenant->id];

        if ($sibling instanceof Tenant) {
            $preserveTenantIds[] = (string) $sibling->id;
        }

        $this->cleanPairSquatters($tenant, $preserveTenantIds);

        if ($sibling instanceof Tenant && ! in_array($sibling->id, $selectedTenantIds, true)) {
            app(PairSiblingCentral::class)->forget((string) $sibling->id);
            $sibling->delete();
            $deleted[] = $sibling->id;
        }

        app(PairSiblingCentral::class)->forget((string) $tenant->id);
        $tenant->delete();
        $deleted[] = $tenant->id;

        return $deleted;
    }

    public function confirmation(Tenant $tenant): string
    {
        $hosts = $tenant->domains->pluck('domain')->all();
        $sibling = $this->sibling($tenant);

        if ($sibling instanceof Tenant) {
            $hosts = array_merge($hosts, $sibling->domains->pluck('domain')->all());
        }

        $hosts = array_values(array_unique(array_filter($hosts)));

        if ($hosts === []) {
            return 'This deletes the tenant and its database.';
        }

        return 'This deletes '.implode(' and ', $hosts).' and their databases.';
    }

    public function requiresExportAcknowledgement(Tenant $tenant): bool
    {
        return ! TenantSettings::forTenant($tenant)->hasRecentComplianceExport(self::RECENT_EXPORT_DAYS);
    }

    /**
     * @param  Collection<int, mixed>  $records
     */
    public function bulkRequiresExportAcknowledgement(Collection $records): bool
    {
        foreach ($records as $record) {
            if ($record instanceof Tenant && $this->requiresExportAcknowledgement($record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, mixed>  $records
     */
    public function bulkDeleteModalDescription(Collection $records): string
    {
        $tenants = $records->filter(fn (mixed $record): bool => $record instanceof Tenant)->values();

        if ($tenants->count() === 1) {
            /** @var Tenant $tenant */
            $tenant = $tenants->first();

            return $this->deleteModalDescription($tenant);
        }

        $description = $tenants->isEmpty()
            ? 'This deletes the selected tenants and their databases.'
            : 'This deletes '.$tenants->count().' tenants and their databases.';

        if ($this->bulkRequiresExportAcknowledgement($records)) {
            $description .= ' No compliance export exists within the last '.self::RECENT_EXPORT_DAYS
                .' days for at least one selected tenant. Export ZIP files remain on disk after tenant deletion for operations retention.';
        }

        return $description;
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return list<\Filament\Forms\Components\Component>
     */
    public function bulkDeleteModalSchema(Collection $records): array
    {
        if (! $this->bulkRequiresExportAcknowledgement($records)) {
            return [];
        }

        $tenant = $records->first(
            fn (mixed $record): bool => $record instanceof Tenant && $this->requiresExportAcknowledgement($record),
        );

        return $tenant instanceof Tenant ? $this->deleteModalSchema($tenant) : [];
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @param  array<string, mixed>  $data
     */
    public function assertBulkDeleteAllowed(Collection $records, array $data): void
    {
        foreach ($records as $record) {
            if ($record instanceof Tenant) {
                $this->assertDeleteAllowed($record, $data);
            }
        }
    }

    public function deleteModalDescription(Tenant $tenant): string
    {
        $description = $this->confirmation($tenant);

        if (! $this->requiresExportAcknowledgement($tenant)) {
            return $description;
        }

        return $description.' No compliance export exists within the last '.self::RECENT_EXPORT_DAYS
            .' days. Export ZIP files remain on disk after tenant deletion for operations retention.';
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public function deleteModalSchema(Tenant $tenant): array
    {
        if (! $this->requiresExportAcknowledgement($tenant)) {
            return [];
        }

        return [
            Checkbox::make('acknowledge_missing_export')
                ->label('I understand no recent compliance export exists')
                ->accepted(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assertDeleteAllowed(Tenant $tenant, array $data): void
    {
        if (! $this->requiresExportAcknowledgement($tenant)) {
            return;
        }

        if (! (bool) ($data['acknowledge_missing_export'] ?? false)) {
            throw new \DomainException(
                'Confirm that you understand no recent compliance export exists before deleting this tenant.',
            );
        }
    }

    /**
     * @param  list<string>  $preserveTenantIds
     */
    public function cleanPairSquatters(Tenant $tenant, array $preserveTenantIds = []): void
    {
        $slug = $this->pairSlug($tenant);

        if ($slug === null) {
            return;
        }

        $pairHosts = TenantHostname::pairForSlug($slug);

        foreach ($pairHosts as $host) {
            $domain = Domain::query()->where('domain', $host)->first();

            if ($domain === null) {
                continue;
            }

            $ownerId = (string) $domain->tenant_id;

            if (in_array($ownerId, $preserveTenantIds, true)) {
                continue;
            }

            $owner = Tenant::query()->find($domain->tenant_id);

            if (! $owner instanceof Tenant || TenantPairAvailability::ownsSlug($owner, $slug)) {
                continue;
            }

            $this->removePairSquatter($owner, $pairHosts);
        }
    }

    /**
     * @param  list<string>  $pairHosts
     */
    private function removePairSquatter(Tenant $owner, array $pairHosts): void
    {
        $ownerHosts = $owner->domains->pluck('domain')->map(static fn (mixed $host): string => strtolower((string) $host))->all();
        $pairHostsLower = array_map(static fn (string $host): string => strtolower($host), $pairHosts);

        if (array_diff($ownerHosts, $pairHostsLower) === []) {
            $owner->delete();

            return;
        }

        Domain::query()
            ->where('tenant_id', $owner->id)
            ->whereIn('domain', $pairHosts)
            ->delete();
    }

    private function pairSlug(Tenant $tenant): ?string
    {
        if (is_string($tenant->tenant_pair_slug) && $tenant->tenant_pair_slug !== '') {
            return strtolower($tenant->tenant_pair_slug);
        }

        $base = TenantHostname::baseDomain();

        foreach ($tenant->domains as $domain) {
            $host = strtolower((string) $domain->domain);

            foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
                $suffix = '.'.$environment.'.'.$base;

                if (str_ends_with($host, $suffix)) {
                    return substr($host, 0, -strlen($suffix));
                }
            }
        }

        return null;
    }

    private function pairEnvironment(Tenant $tenant): string
    {
        if (is_string($tenant->tenant_pair_environment) && $tenant->tenant_pair_environment !== '') {
            return TenantHostname::assertPairEnvironment($tenant->tenant_pair_environment);
        }

        if (is_string($tenant->inbound_environment) && in_array($tenant->inbound_environment, TenantHostname::PAIR_ENVIRONMENTS, true)) {
            return $tenant->inbound_environment;
        }

        return 'prod';
    }
}
