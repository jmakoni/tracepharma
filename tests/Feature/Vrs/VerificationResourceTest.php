<?php

namespace Tests\Feature\Vrs;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Verifications\Pages\ListVerifications;
use App\Filament\App\Resources\Verifications\Pages\ViewVerification;
use App\Filament\App\Resources\Verifications\Tables\VerificationsTable;
use App\Filament\App\Resources\Verifications\VerificationResource;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerificationResourceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    #[Test]
    public function pharmacy_can_access_verification_history_resource(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsVrs());
            $this->assertTrue(VerificationResource::canAccess());
            $this->assertTrue(VerificationResource::canView(new Verification));
            $this->assertFalse(VerificationResource::canCreate());
            $this->assertSame('Verification history', VerificationResource::getNavigationLabel());
            $this->assertSame('verifications', VerificationResource::getSlug());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function list_page_loads_for_authorized_tenant_user(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('verifications'));

            Filament::setCurrentPanel(Filament::getPanel('app'));

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $verification = Verification::query()->create([
                'gtin14' => '30301164005162',
                'serial' => 'HIST-TEST-001',
                'lot' => 'LOT-H1',
                'status' => 'verified',
                'scanned_barcode' => '(01)30301164005162(21)HIST-TEST-001',
                'verified_by' => $user->getKey(),
                'message' => 'Product verified (test).',
                'response_payload' => [
                    'status' => 'verified',
                    'message' => 'Product verified (test).',
                    'disposition' => 'active',
                ],
                'verified_at' => now(),
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            $table = VerificationsTable::configure(Table::make(new ListVerifications));
            $columnNames = collect($table->getColumns())->map(fn ($column) => $column->getName())->all();
            $this->assertContains('created_at', $columnNames);
            $this->assertContains('gtin14', $columnNames);
            $this->assertContains('serial', $columnNames);
            $this->assertContains('status', $columnNames);
            $this->assertContains('verifiedByUser.name', $columnNames);
            $this->assertContains('disposition', $columnNames);

            $filterNames = collect($table->getFilters())->map(fn ($filter) => $filter->getName())->all();
            $this->assertContains('status', $filterNames);
            $this->assertContains('created_at', $filterNames);

            Livewire::test(ListVerifications::class)
                ->assertSuccessful()
                ->assertCanSeeTableRecords([$verification])
                ->assertSee('30301164005162')
                ->assertSee('HIST-TEST-001')
                ->assertSee('Verify Product');

            $indexUrl = VerificationResource::getUrl('index', panel: 'app');
            $this->assertStringContainsString('verifications', $indexUrl);

            Livewire::test(ViewVerification::class, ['record' => $verification->getKey()])
                ->assertSuccessful()
                ->assertSee('30301164005162')
                ->assertSee('HIST-TEST-001')
                ->assertSee('Verified');
        } finally {
            $this->cleanup();
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->verificationIds !== []) {
            Verification::query()->whereKey($this->verificationIds)->delete();
            $this->verificationIds = [];
        }

        tenancy()->end();
    }
}
