<?php

namespace App\Models\Concerns;

/**
 * Keeps Scout indexes partitioned per Stancl tenant.
 *
 * Collection driver: index name scopes in-process storage when tenancy switches.
 * Meilisearch/Algolia: physical index per tenant; {@see tenantSearchMetadata()} adds
 * tenant_id for optional shared-index filtering later.
 */
trait IndexesTenantSearch
{
    public function searchableAs(): string
    {
        $base = $this->getTable();
        $prefix = (string) config('scout.prefix', '');

        if (function_exists('tenancy') && tenancy()->initialized) {
            return $prefix.tenant('id').'_'.$base;
        }

        return $prefix.$base;
    }

    public function shouldBeSearchable(): bool
    {
        return function_exists('tenancy') && tenancy()->initialized;
    }

    /**
     * @return array<string, string>
     */
    protected function tenantSearchMetadata(): array
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return [];
        }

        return [
            'tenant_id' => (string) tenant('id'),
        ];
    }
}
