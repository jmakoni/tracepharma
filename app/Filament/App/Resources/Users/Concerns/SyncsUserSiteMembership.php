<?php

namespace App\Filament\App\Resources\Users\Concerns;

use App\Enums\TenantRole;
use App\Models\User;

trait SyncsUserSiteMembership
{
    /** @var list<int> */
    protected array $siteIds = [];

    protected ?int $defaultSiteId = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractSiteMembershipFromFormData(array $data): array
    {
        $this->siteIds = array_map(intval(...), $data['site_ids'] ?? []);
        $this->defaultSiteId = isset($data['default_site_id'])
            ? (int) $data['default_site_id']
            : null;

        unset($data['site_ids'], $data['default_site_id']);

        return $data;
    }

    protected function syncSiteMembershipIfNeeded(User $user): void
    {
        if ($user->hasRole(TenantRole::Owner->value)) {
            return;
        }

        $user->syncSites($this->siteIds, $this->defaultSiteId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function siteMembershipFormDefaults(User $user): array
    {
        if ($user->hasRole(TenantRole::Owner->value)) {
            return [];
        }

        $siteIds = $user->sites()
            ->pluck('sites.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $defaultSiteId = $user->sites()
            ->wherePivot('is_default', true)
            ->value('sites.id');

        return [
            'site_ids' => $siteIds,
            'default_site_id' => $defaultSiteId !== null ? (int) $defaultSiteId : null,
        ];
    }
}
