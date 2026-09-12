<?php

namespace Tests\Feature\Vrs;

use App\Enums\TenantProfile;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Pages\VrsLookupDirectory;
use App\Filament\App\Resources\Verifications\VerificationResource;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Services\Vrs\Contracts\VrsClient;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyProductPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $exceptionIds = [];

    #[Test]
    public function pharmacy_can_access_verify_product_page(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsVrs());
            $this->assertTrue(VerifyProduct::canAccess());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function verify_product_page_renders_todays_verification_table_rows(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = User::factory()->create();
            $this->actingAs($user);

            $verification = Verification::query()->create([
                'gtin14' => '30301164005162',
                'serial' => 'VP-TABLE-'.uniqid(),
                'lot' => 'LOT1',
                'status' => 'verified',
                'scanned_barcode' => '(01)30301164005162(21)TABLE1',
                'verified_by' => $user->getKey(),
                'created_at' => now(),
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            Livewire::test(VerifyProduct::class)
                ->assertOk()
                ->assertSee("Today's verifications")
                ->assertSee('(01)30301164005162(21)TABLE1')
                ->assertSee('Verified');

            $rows = Livewire::test(VerifyProduct::class)->instance()->verificationTableRows();
            $this->assertTrue($rows->contains(
                fn (array $row): bool => $row['line_id'] === (int) $verification->getKey()
                    && $row['status_label'] === 'Verified'
                    && str_contains($row['urn'], '30301164005162'),
            ));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function manufacturer_cannot_access_verify_product_requestor_ui(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $priorProfile = $tenant->profile;

        try {
            $tenant->forceFill(['profile' => TenantProfile::Manufacturer])->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            TenantSettings::forTenant(tenant())->setManufacturerVrsRequestorEnabled(false);
            tenant()?->save();

            $this->assertFalse(TenantSettings::forTenant(tenant())->manufacturerVrsRequestorEnabled());
            $this->assertFalse(TenantFeatures::forTenant(tenant())->supportsVrs());
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsVrsResponder());
            $this->assertFalse(VerifyProduct::canAccess());
        } finally {
            $restore = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($restore !== null) {
                tenancy()->end();
                tenancy()->initialize($restore->fresh());
                TenantSettings::forTenant(tenant())->setManufacturerVrsRequestorEnabled(false);
                tenant()?->save();
                $restore->forceFill(['profile' => $priorProfile])->save();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function manufacturer_vrs_requestor_flag_unlocks_verify_product_ui(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $priorProfile = $tenant->profile;

        try {
            $tenant->forceFill(['profile' => TenantProfile::Manufacturer])->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            TenantSettings::forTenant(tenant())->setManufacturerVrsRequestorEnabled(true);
            tenant()?->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = User::factory()->create();
            $this->actingAs($user);

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsVrs());
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsVrsResponder());
            $this->assertTrue(VerifyProduct::canAccess());
            $this->assertTrue(VerificationResource::canAccess());
            $this->assertTrue(VrsLookupDirectory::canAccess());
        } finally {
            $restore = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($restore !== null) {
                tenancy()->end();
                tenancy()->initialize($restore->fresh());
                TenantSettings::forTenant(tenant())->setManufacturerVrsRequestorEnabled(false);
                tenant()?->save();
                $restore->forceFill(['profile' => $priorProfile])->save();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function verify_scan_persists_verified_record_with_fake_vrs(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->assertTrue(Schema::hasTable('verifications'));

            $user = User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $barcode = '(01)30301164005162(21)GOOD123';

            Livewire::test(VerifyProduct::class)
                ->set('scan', $barcode)
                ->call('verifyScan')
                ->assertSet('lastScanTone', 'ok');

            $verification = Verification::query()->orderByDesc('id')->first();
            $this->assertNotNull($verification);
            $this->verificationIds[] = (int) $verification->getKey();
            $this->assertSame('verified', $verification->status);
            $this->assertSame('30301164005162', $verification->gtin14);
            $this->assertSame('GOOD123', $verification->serial);
            $this->assertNull($verification->exception_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_verification_opens_exception_case(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(VerifyProduct::class)
                ->set('scan', '(01)30301164005162(21)FAIL-001')
                ->call('verifyScan')
                ->assertSet('lastScanTone', 'error');

            $verification = Verification::query()->orderByDesc('id')->first();
            $this->assertNotNull($verification);
            $this->verificationIds[] = (int) $verification->getKey();
            $this->assertSame('failed', $verification->status);
            $this->assertNotNull($verification->exception_id);
            $this->exceptionIds[] = (int) $verification->exception_id;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * DSCSA: an unreachable VRS says nothing about the product, so the scan is recorded for
     * the audit trail and handed back to the operator to retry — never quarantined.
     */
    #[Test]
    public function unreachable_vrs_records_the_attempt_without_opening_a_case(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->app->bind(VrsClient::class, fn (): VrsClient => new class implements VrsClient
            {
                public function verify(
                    string $gtin14,
                    string $serial,
                    ?string $lot = null,
                    ?string $expiryYymmdd = null,
                ): array {
                    return [
                        'gtin14' => $gtin14,
                        'serial' => $serial,
                        'lot' => $lot,
                        'expiry_yymmdd' => $expiryYymmdd,
                        'status' => 'unavailable',
                        'message' => 'VRS unreachable: cURL error 6: Could not resolve host',
                    ];
                }
            });

            $holdsBefore = QuarantineHold::query()->count();

            $user = User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(VerifyProduct::class)
                ->set('scan', '(01)30301164005162(21)NETDOWN-001')
                ->call('verifyScan')
                ->assertSet('lastScanTone', 'warn');

            $verification = Verification::query()->orderByDesc('id')->first();
            $this->assertNotNull($verification);
            $this->verificationIds[] = (int) $verification->getKey();

            $this->assertSame('unavailable', $verification->status);
            $this->assertNull($verification->exception_id);
            $this->assertNull($verification->verified_at);
            $this->assertSame($holdsBefore, QuarantineHold::query()->count());
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

        if ($this->exceptionIds !== []) {
            ExceptionCase::query()->whereKey($this->exceptionIds)->delete();
            $this->exceptionIds = [];
        }

        tenancy()->end();
    }
}
