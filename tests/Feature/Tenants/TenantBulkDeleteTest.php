<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Actions\Tenants\DeleteTenantPair;
use App\Actions\Tenants\ProvisionTenantOnEnvironment;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\AdminRole;
use App\Enums\TenantProfile;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Models\Admin;
use App\Models\Tenant;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\TenantHostname;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class TenantBulkDeleteTest extends TestCase
{
    use DatabaseTransactions;

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
        foreach ($this->slugs as $slug) {
            $this->destroyPair($slug);
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
    public function bulk_delete_is_blocked_without_export_acknowledgement_when_export_is_missing(): void
    {
        $slug = 'bulk-del-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        $prod = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'Bulk Delete '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        $deletePair = app(DeleteTenantPair::class);
        $this->assertTrue($deletePair->requiresExportAcknowledgement($prod));

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(ListTenants::class)
            ->callTableBulkAction('delete', [$prod])
            ->assertHasFormErrors(['acknowledge_missing_export' => 'accepted']);

        $this->assertNotNull(Tenant::query()->find($prod->id));
    }

    #[Test]
    public function bulk_delete_reports_per_tenant_failures_and_keeps_remaining_tenants(): void
    {
        $slugA = 'bulk-ok-'.Str::lower(Str::random(6));
        $slugB = 'bulk-fail-'.Str::lower(Str::random(6));
        $this->slugs[] = $slugA;

        $tenantA = app(ProvisionTenantPair::class)->create($slugA, [
            'name' => 'Bulk OK '.$slugA,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        $tenantB = app(ProvisionTenantPair::class)->create($slugB, [
            'name' => 'Bulk Fail '.$slugB,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        $failTenantId = (string) $tenantB->id;

        $this->app->bind(DeleteTenantPair::class, function () use ($failTenantId): DeleteTenantPair {
            return new class(app(ProvisionTenantOnEnvironment::class), $failTenantId) extends DeleteTenantPair
            {
                public function __construct(
                    ProvisionTenantOnEnvironment $onEnvironment,
                    private readonly string $failTenantId,
                ) {
                    parent::__construct($onEnvironment);
                }

                public function deleteWithSibling(Tenant $tenant, array $selectedTenantIds = []): array
                {
                    if ((string) $tenant->id === $this->failTenantId) {
                        throw new RuntimeException('Simulated delete failure for '.$tenant->name);
                    }

                    return parent::deleteWithSibling($tenant, $selectedTenantIds);
                }
            };
        });

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(ListTenants::class)
            ->callTableBulkAction('delete', [$tenantA, $tenantB], data: [
                'acknowledge_missing_export' => true,
            ]);

        $notification = $this->lastSentNotification();
        $this->assertStringContainsString('1 of 2', (string) $notification->getTitle());

        $body = strip_tags((string) $notification->getBody());
        $this->assertStringContainsString('Deleted 1 of 2 selected tenant(s).', $body);
        $this->assertStringContainsString(
            'Simulated delete failure for Bulk Fail '.$slugB,
            $body,
        );

        $this->assertNull(Tenant::query()->find($tenantA->id));
        $this->assertNotNull(Tenant::query()->find($tenantB->id));
    }

    private function lastSentNotification(): \Filament\Notifications\Notification
    {
        $component = new Notifications;
        $component->mount();

        $notification = $component->notifications->last();
        $this->assertNotNull($notification, 'Expected a Filament notification after bulk delete.');

        return $notification;
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

    private function destroyPair(string $slug): void
    {
        foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
            $domain = Domain::query()
                ->where('domain', TenantHostname::forSlug($slug, $environment))
                ->first();

            if ($domain === null) {
                continue;
            }

            Tenant::query()->find($domain->tenant_id)?->delete();
        }
    }
}
