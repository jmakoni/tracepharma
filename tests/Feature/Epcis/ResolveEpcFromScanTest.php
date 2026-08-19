<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\EnsureEpcFromUri;
use App\Actions\Epcis\ResolveEpcFromScan;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveEpcFromScanTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SGTIN_URN = 'urn:epc:id:sgtin:030116.3400516.10000002877732';

    private const SSCC_URN = 'urn:epc:id:sscc:030116.01001235403';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function it_finds_sgtin_created_from_urn_via_element_string_scans(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('epcs'));

            $epc = app(EnsureEpcFromUri::class)->handle(self::SGTIN_URN);
            $this->epcIds[] = (int) $epc->id;

            $this->assertSame('sgtin', $epc->epc_type);
            $this->assertSame('30301164005162', $epc->gtin14);
            $this->assertSame('01303011640051622110000002877732', $epc->ai_01_21);

            foreach ([
                '01303011640051622110000002877732',
                '013030116400516221100000028777321726073110LOT-A1',
                '(01)30301164005162(21)10000002877732(17)260731(10)LOT-A1',
            ] as $scan) {
                $result = app(ResolveEpcFromScan::class)->handle($scan);

                $this->assertNotNull($result['epc'], "Failed for scan: {$scan}");
                $this->assertTrue($result['epc']->is($epc), "Wrong EPC for scan: {$scan}");
                $this->assertSame('30301164005162', $result['identity']['gtin14'] ?? null);
                $this->assertSame('10000002877732', $result['identity']['serial'] ?? null);
            }
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function it_finds_sscc_created_from_urn_via_common_scan_forms(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('epcs'));

            $epc = app(EnsureEpcFromUri::class)->handle(self::SSCC_URN);
            $this->epcIds[] = (int) $epc->id;

            $this->assertSame('sscc', $epc->epc_type);
            $this->assertSame('003011610012354038', $epc->sscc18);

            foreach ([
                '003011610012354038',
                '00003011610012354038',
                '(00)003011610012354038',
            ] as $scan) {
                $result = app(ResolveEpcFromScan::class)->handle($scan);

                $this->assertNotNull($result['epc'], "Failed for scan: {$scan}");
                $this->assertTrue($result['epc']->is($epc), "Wrong EPC for scan: {$scan}");
                $this->assertSame('003011610012354038', $result['identity']['sscc18'] ?? null);
            }
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function it_resolves_sscc_via_ai_00_when_sscc18_column_empty(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $epc = app(EnsureEpcFromUri::class)->handle(self::SSCC_URN);
            $this->epcIds[] = (int) $epc->id;

            $ai00 = (string) $epc->ai_00;
            $this->assertNotSame('', $ai00);

            // Simulate a row that is only indexed on ai_00 (sequential fallback path).
            $epc->forceFill(['sscc18' => null])->save();

            $result = app(ResolveEpcFromScan::class)->handle($ai00);

            $this->assertNotNull($result['epc']);
            $this->assertTrue($result['epc']->is($epc->fresh()));
            $this->assertSame($ai00, $result['identity']['ai_00'] ?? null);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function it_reports_ilmd_soft_mismatch_but_still_returns_epc(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $epc = app(EnsureEpcFromUri::class)->handle(self::SGTIN_URN);
            $this->epcIds[] = (int) $epc->id;

            EpcIlmd::query()->create([
                'epc_id' => $epc->id,
                'lot_number' => 'LOT-DB',
                'expiry_date' => '2026-01-15',
            ]);

            $result = app(ResolveEpcFromScan::class)->handle(
                '013030116400516221100000028777321726073110LOT-A1',
            );

            $this->assertNotNull($result['epc']);
            $this->assertTrue($result['epc']->is($epc));
            $this->assertNotNull($result['ilmd_soft_mismatch']);
            $this->assertSame('LOT-A1', $result['ilmd_soft_mismatch']['lot_number']['scan']);
            $this->assertSame('LOT-DB', $result['ilmd_soft_mismatch']['lot_number']['ilmd']);
            $this->assertSame('2026-07-31', $result['ilmd_soft_mismatch']['expiry_date']['scan']);
            $this->assertSame('2026-01-15', $result['ilmd_soft_mismatch']['expiry_date']['ilmd']);
        } finally {
            $this->cleanupIntegrationFixtures();
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

    private function cleanupIntegrationFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->epcIds !== []) {
                EpcIlmd::query()->whereIn('epc_id', $this->epcIds)->delete();
                Epc::query()->whereIn('id', $this->epcIds)->delete();
            }

            tenancy()->end();
        }

        $this->epcIds = [];
    }
}
