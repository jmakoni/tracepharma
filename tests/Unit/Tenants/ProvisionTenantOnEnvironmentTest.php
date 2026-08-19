<?php

namespace Tests\Unit\Tenants;

use App\Actions\Tenants\ProvisionTenantOnEnvironment;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\TenantHostname;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class ProvisionTenantOnEnvironmentTest extends TestCase
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
    public function provision_refuses_stage_when_prod_is_missing(): void
    {
        $slug = 'prov-'.Str::lower(Str::random(6));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('before stage');

        app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'Stage first '.$slug,
            'profile' => TenantProfile::Pharmacy,
        ], 'stage');
    }

    #[Test]
    public function provision_refuses_stage_when_prod_host_is_foreign(): void
    {
        $slug = 'prov-'.Str::lower(Str::random(6));
        $foreignProd = $this->orphanTenant('other-'.$slug, 'prod');
        $foreignProd->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already taken');

        app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'Foreign prod '.$slug,
            'profile' => TenantProfile::Pharmacy,
        ], 'stage');
    }

    #[Test]
    public function provision_refuses_a_host_owned_by_an_unrelated_tenant(): void
    {
        $slug = 'prov-'.Str::lower(Str::random(6));
        $unrelated = $this->orphanTenant('other-'.$slug, 'stage');
        $unrelated->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        try {
            app(ProvisionTenantOnEnvironment::class)->provision($slug, [
                'name' => 'Foreign host '.$slug,
                'profile' => TenantProfile::Pharmacy,
            ], 'stage');
            $this->fail('A foreign stage host must not be adopted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already taken', $exception->getMessage());
        }

        $this->assertSame($unrelated->id, Domain::query()
            ->where('domain', TenantHostname::forSlug($slug, 'stage'))
            ->value('tenant_id'));
    }

    #[Test]
    public function pair_create_refuses_a_stage_only_slug(): void
    {
        $slug = 'prov-'.Str::lower(Str::random(6));
        $stage = $this->orphanTenant($slug, 'stage');
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        try {
            app(ProvisionTenantPair::class)->create($slug, [
                'name' => 'Stage only '.$slug,
                'profile' => TenantProfile::Pharmacy,
            ]);
            $this->fail('Pair create must not mint prod onto a leftover stage host.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('without a matching prod host', $exception->getMessage());
        }

        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'prod'))->first());
        $this->assertSame($stage->id, Domain::query()
            ->where('domain', TenantHostname::forSlug($slug, 'stage'))
            ->value('tenant_id'));
    }

    #[Test]
    public function provision_refuses_to_adopt_an_empty_pair_meta_host(): void
    {
        $slug = 'prov-'.Str::lower(Str::random(6));
        $squatter = $this->orphanTenant($slug, 'prod', withPairMeta: false);
        $squatter->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        try {
            app(ProvisionTenantOnEnvironment::class)->provision($slug, [
                'name' => 'Adopt '.$slug,
                'profile' => TenantProfile::Pharmacy,
            ], 'prod');
            $this->fail('Empty pair meta must not be adopted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already taken', $exception->getMessage());
        }

        $this->assertSame($squatter->id, Domain::query()
            ->where('domain', TenantHostname::forSlug($slug, 'prod'))
            ->value('tenant_id'));
    }

    private function orphanTenant(string $pairSlug, string $environment, bool $withPairMeta = true): Tenant
    {
        $id = (string) Str::uuid();
        $this->orphanTenantIds[] = $id;

        $attributes = [
            'id' => $id,
            'name' => 'Provision orphan',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_prov_'.substr(str_replace('-', '', $id), 0, 16),
            'inbound_environment' => $environment,
        ];

        if ($withPairMeta) {
            $attributes['tenant_pair_slug'] = $pairSlug;
            $attributes['tenant_pair_environment'] = $environment;
        }

        return Tenant::withoutEvents(fn () => Tenant::query()->create($attributes));
    }
}
