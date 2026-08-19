<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Actions\Tenants\QueueTenantComplianceExport;
use App\Enums\AdminRole;
use App\Enums\TenantProfile;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Jobs\Tenants\ExportTenantComplianceArchive;
use App\Models\Admin;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Models\Product;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\TenantHostname;
use App\Support\TenantSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;
use ZipArchive;

class TenantComplianceExportTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $exportPaths = [];

    /** @var list<string> */
    private array $slugs = [];

    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->exportPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        foreach ($this->slugs as $slug) {
            foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
                $domain = Domain::query()
                    ->where('domain', TenantHostname::forSlug($slug, $environment))
                    ->first();

                if ($domain === null) {
                    continue;
                }

                Tenant::withoutEvents(
                    fn () => Tenant::query()->find($domain->tenant_id)?->delete(),
                );
            }
        }

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function export_job_creates_zip_with_expected_files_and_sets_last_export_at(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->provisionTenantWithSampleData();

        ExportTenantComplianceArchive::dispatchSync($tenant, (int) $admin->getKey());

        $tenant->refresh();
        $settings = TenantSettings::forTenant($tenant);

        $this->assertNotNull($settings->complianceLastExportAt());
        $this->assertNotNull($settings->complianceLastExportPath());
        $this->assertSame((int) $admin->getKey(), $settings->complianceLastExportAdminId());

        $zipPath = storage_path('app/tenant-exports/'.$settings->complianceLastExportPath());
        $this->exportPaths[] = $zipPath;

        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);

        $expectedFiles = [
            'manifest.json',
            'activity_log.csv',
            'products.csv',
            'trading_partners.csv',
            'sites.csv',
            'epcis_documents_index.csv',
        ];

        foreach ($expectedFiles as $file) {
            $this->assertNotFalse($zip->locateName($file), "Missing {$file} in compliance export ZIP.");
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame($tenant->getKey(), $manifest['tenant_id'] ?? null);
        $this->assertSame($tenant->tenant_pair_slug, $manifest['tenant_pair_slug'] ?? null);
        $this->assertSame(TenantProfile::Pharmacy->value, $manifest['profile'] ?? null);
        $this->assertSame((int) $admin->getKey(), $manifest['exported_by_admin_id'] ?? null);
        $this->assertSame(90, $manifest['activity_log']['days'] ?? null);
        $this->assertSame(50_000, $manifest['activity_log']['max_rows'] ?? null);
    }

    #[Test]
    public function support_cannot_queue_compliance_export(): void
    {
        ['tenant' => $tenant] = $this->provisionTenantWithSampleData();
        $support = $this->actAsAdmin(AdminRole::Support);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(QueueTenantComplianceExport::class)->execute($support, $tenant);
    }

    #[Test]
    public function platform_admin_can_queue_export_from_edit_tenant(): void
    {
        ['tenant' => $tenant] = $this->provisionTenantWithSampleData();
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
            ->assertActionVisible(TestAction::make('exportComplianceArchive'))
            ->callAction(TestAction::make('exportComplianceArchive'))
            ->assertNotified();

        $tenant->refresh();

        $this->assertNotNull(TenantSettings::forTenant($tenant)->complianceLastExportAt());

        $path = TenantSettings::forTenant($tenant)->complianceLastExportPath();
        if (is_string($path) && $path !== '') {
            $this->exportPaths[] = storage_path('app/tenant-exports/'.$path);
        }
    }

    /**
     * @return array{tenant: Tenant, admin: Admin}
     */
    private function provisionTenantWithSampleData(): array
    {
        $slug = 'cex-'.Str::lower(Str::random(8));
        $this->slugs[] = $slug;

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);
        $this->adminIds[] = (int) $admin->getKey();

        $tenant = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'Compliance Export '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        $tenant->run(function (): void {
            $partner = TradingPartner::factory()->create([
                'name' => 'Export Partner',
                'gln' => '0366159000010',
            ]);

            Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Export Site',
                'gln' => '0366159000011',
            ]);

            Product::factory()->create([
                'trading_partner_id' => $partner->id,
                'gtin' => '00361590000105',
                'name' => 'Export Product',
            ]);

            activity()
                ->causedByAnonymous()
                ->log('compliance_export_test_activity');
        });

        return [
            'tenant' => $tenant->fresh(),
            'admin' => $admin,
        ];
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}
