<?php

declare(strict_types=1);

namespace Tests\Feature\Labeling;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\L3ForwardLog;
use App\Jobs\Labeling\ForwardCommissioningToL3;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class L3ForwardLogPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $exceptionIds = [];

    private ?bool $priorL3Enabled = null;

    private ?string $priorL3Endpoint = null;

    private ?TenantProfile $priorProfile = null;

    protected function tearDown(): void
    {
        $this->cleanupTenantRows();
        parent::tearDown();
    }

    #[Test]
    public function manufacturer_can_access_l3_forward_log(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->asManufacturerWithL3();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertTrue(L3ForwardLog::canAccess());

            Livewire::test(L3ForwardLog::class)
                ->assertSuccessful()
                ->assertSee('L3 forward log')
                ->assertSee('forward status only')
                ->assertSee('not allocation');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function pharmacy_cannot_access_l3_forward_log(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $tenant = tenant();
            $this->priorProfile = $tenant->profile instanceof TenantProfile
                ? $tenant->profile
                : TenantProfile::from((string) $tenant->profile);
            $tenant->setAttribute('profile', TenantProfile::Pharmacy);

            $this->assertFalse(L3ForwardLog::canAccess());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function retry_dispatches_forward_commissioning_to_l3_job(): void
    {
        Bus::fake([ForwardCommissioningToL3::class]);

        $this->initializeDemo2Tenant();

        try {
            $this->asManufacturerWithL3();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'direction' => 'outbound',
                'status' => 'validated',
                'original_filename' => 'l3-forward-retry-'.Str::uuid().'.xml',
                'file_sha256' => hash('sha256', 'l3-forward-retry-'.uniqid()),
                'payload_path' => 'epcis/outbound/l3-retry-'.Str::uuid().'.xml',
                'creation_date' => now(),
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $exception = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'L3_TRANSMISSION_FAILURE',
                'severity' => 'error',
                'description' => 'L3 POST failed (HTTP 500): upstream timeout for retry test.',
                'status' => 'open',
            ]);
            $this->exceptionIds[] = (int) $exception->getKey();

            Livewire::test(L3ForwardLog::class)
                ->assertSuccessful()
                ->assertSee('Failed')
                ->assertSee('upstream timeout')
                ->callTableAction('retry', $document)
                ->assertNotified();

            Bus::assertDispatched(
                ForwardCommissioningToL3::class,
                fn (ForwardCommissioningToL3 $job): bool => $job->tenantId === self::DEMO2_TENANT_ID
                    && $job->documentId === (int) $document->getKey(),
            );
        } finally {
            $this->cleanup();
        }
    }

    private function asManufacturerWithL3(): void
    {
        $tenant = tenant();
        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::from((string) $tenant->profile);
        $tenant->setAttribute('profile', TenantProfile::Manufacturer);

        $settings = TenantSettings::forTenant($tenant);
        $this->priorL3Enabled = $settings->l3Enabled();
        $this->priorL3Endpoint = $settings->l3EndpointUrl();

        $settings->saveOrganization([
            'l3_enabled' => true,
            'l3_endpoint_url' => 'https://l3.example.test/commission',
        ]);
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
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function cleanup(): void
    {
        $this->cleanupTenantRows();

        if (! tenancy()->initialized) {
            return;
        }

        $tenant = tenant();

        if ($this->priorL3Enabled !== null || $this->priorL3Endpoint !== null) {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'l3_enabled' => $this->priorL3Enabled ?? false,
                'l3_endpoint_url' => $this->priorL3Endpoint,
            ]);
            $this->priorL3Enabled = null;
            $this->priorL3Endpoint = null;
        }

        if ($this->priorProfile !== null) {
            $tenant->setAttribute('profile', $this->priorProfile);
            $this->priorProfile = null;
        }

        tenancy()->end();
    }

    private function cleanupTenantRows(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->exceptionIds !== []) {
            EpcisException::query()->whereIn('id', $this->exceptionIds)->delete();
            $this->exceptionIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }
    }
}
