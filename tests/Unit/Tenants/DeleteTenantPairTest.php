<?php

namespace Tests\Unit\Tenants;

use App\Actions\Tenants\DeleteTenantPair;
use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\TenantHostname;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class DeleteTenantPairTest extends TestCase
{
    /** @var list<string> */
    private array $orphanTenantIds = [];

    protected function tearDown(): void
    {
        if ($this->orphanTenantIds !== []) {
            Domain::query()->whereIn('tenant_id', $this->orphanTenantIds)->delete();
            Tenant::withoutEvents(fn () => Tenant::query()->whereIn('id', $this->orphanTenantIds)->delete());
        }

        parent::tearDown();
    }

    #[Test]
    public function sibling_is_the_opposite_pair_environment(): void
    {
        $slug = 'del-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $stage = $this->orphanTenant($slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $action = app(DeleteTenantPair::class);

        $this->assertSame($stage->id, $action->sibling($prod)?->id);
        $this->assertSame($prod->id, $action->sibling($stage)?->id);
        $this->assertStringContainsString(TenantHostname::forSlug($slug, 'prod'), $action->confirmation($prod));
        $this->assertStringContainsString(TenantHostname::forSlug($slug, 'stage'), $action->confirmation($prod));
    }

    #[Test]
    public function empty_pair_meta_does_not_treat_a_matching_hostname_as_a_sibling(): void
    {
        $slug = 'del-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        $squatter = $this->orphanTenant('other-'.$slug, 'stage', withPairMeta: false);
        $squatter->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $action = app(DeleteTenantPair::class);

        $this->assertNull($action->sibling($squatter));
        $this->assertNull($action->sibling($prod));

        Tenant::withoutEvents(fn (): array => $action->deleteWithSibling($prod, [(string) $prod->id]));

        $this->assertNull(Tenant::query()->find($prod->id));
        $this->assertNull(Tenant::query()->find($squatter->id));
        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'stage'))->first());
        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'prod'))->first());
        $this->orphanTenantIds = array_values(array_diff($this->orphanTenantIds, [$prod->id, $squatter->id]));
    }

    #[Test]
    public function a_tenant_without_pair_hosts_has_no_sibling(): void
    {
        $tenant = $this->orphanTenant('solo-'.Str::lower(Str::random(6)), 'prod', withPairMeta: false);

        $this->assertNull(app(DeleteTenantPair::class)->sibling($tenant));
    }

    #[Test]
    public function delete_with_sibling_removes_unselected_pair_member(): void
    {
        $slug = 'del-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $stage = $this->orphanTenant($slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $action = app(DeleteTenantPair::class);
        $deleted = Tenant::withoutEvents(fn (): array => $action->deleteWithSibling($prod, [(string) $prod->id]));

        $this->assertSame([(string) $stage->id, (string) $prod->id], $deleted);
        $this->assertNull(Tenant::query()->find($prod->id));
        $this->assertNull(Tenant::query()->find($stage->id));
        $this->orphanTenantIds = array_values(array_diff($this->orphanTenantIds, [$prod->id, $stage->id]));
    }

    #[Test]
    public function delete_with_sibling_skips_sibling_when_it_is_also_selected(): void
    {
        $slug = 'del-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $stage = $this->orphanTenant($slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $selectedIds = [(string) $prod->id, (string) $stage->id];
        $action = app(DeleteTenantPair::class);

        $deletedProd = Tenant::withoutEvents(fn (): array => $action->deleteWithSibling($prod, $selectedIds));
        $deletedStage = Tenant::withoutEvents(fn (): array => $action->deleteWithSibling($stage, $selectedIds));

        $this->assertSame([(string) $prod->id], $deletedProd);
        $this->assertSame([(string) $stage->id], $deletedStage);
        $this->assertNull(Tenant::query()->find($prod->id));
        $this->assertNull(Tenant::query()->find($stage->id));
        $this->orphanTenantIds = array_values(array_diff($this->orphanTenantIds, [$prod->id, $stage->id]));
    }

    private function orphanTenant(string $pairSlug, string $environment, bool $withPairMeta = true): Tenant
    {
        $id = (string) Str::uuid();
        $this->orphanTenantIds[] = $id;

        $attributes = [
            'id' => $id,
            'name' => 'Delete pair orphan',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_del_'.substr(str_replace('-', '', $id), 0, 16),
            'inbound_environment' => $environment,
        ];

        if ($withPairMeta) {
            $attributes['tenant_pair_slug'] = $pairSlug;
            $attributes['tenant_pair_environment'] = $environment;
        }

        return Tenant::withoutEvents(fn () => Tenant::query()->create($attributes));
    }
}
