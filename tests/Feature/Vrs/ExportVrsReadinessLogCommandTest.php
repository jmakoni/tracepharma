<?php

declare(strict_types=1);

namespace Tests\Feature\Vrs;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Models\Verification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportVrsReadinessLogCommandTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    private ?string $outputPath = null;

    protected function tearDown(): void
    {
        if ($this->outputPath !== null && is_file($this->outputPath)) {
            @unlink($this->outputPath);
        }

        $this->cleanupVerifications();

        parent::tearDown();
    }

    #[Test]
    public function export_command_writes_json_with_certification_claim_and_honesty(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $verification = Verification::query()->create([
                'gtin14' => '30301164005162',
                'serial' => 'VRS-READY-001',
                'lot' => 'LOT-READY',
                'status' => 'verified',
                'message' => 'Product verified (readiness export test).',
                'verified_at' => now(),
            ]);
            $this->verificationIds[] = (int) $verification->getKey();
        } finally {
            tenancy()->end();
        }

        $this->outputPath = storage_path('app/evidence/vrs-readiness-test-'.uniqid('', true).'.json');

        $this->artisan('vrs:export-readiness-log', [
            '--tenant' => self::DEMO2_TENANT_ID,
            '--limit' => 10,
            '--output' => $this->outputPath,
        ])->assertSuccessful();

        $this->assertFileExists($this->outputPath);

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) file_get_contents($this->outputPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('certification_claim', $payload);
        $this->assertStringContainsStringIgnoringCase('not Gateway Certified', (string) $payload['certification_claim']);
        $this->assertArrayHasKey('honesty', $payload);
        $this->assertNotSame('', trim((string) $payload['honesty']));
        $this->assertArrayHasKey('generated_at', $payload);
        $this->assertIsArray($payload['tenants'] ?? null);

        $tenantRow = collect($payload['tenants'])->first(
            fn (array $row): bool => ($row['tenant_id'] ?? null) === self::DEMO2_TENANT_ID,
        );

        $this->assertNotNull($tenantRow);
        $this->assertSame($tenant->name, $tenantRow['name']);
        $this->assertIsArray($tenantRow['verifications']);

        $exported = collect($tenantRow['verifications'])->first(
            fn (array $row): bool => ($row['serial'] ?? null) === 'VRS-READY-001',
        );

        $this->assertNotNull($exported);
        $this->assertSame('30301164005162', $exported['gtin14']);
        $this->assertSame('verified', $exported['status']);
        $this->assertArrayHasKey('id', $exported);
        $this->assertArrayHasKey('lot', $exported);
        $this->assertArrayHasKey('message', $exported);
        $this->assertArrayHasKey('verified_at', $exported);
        $this->assertArrayHasKey('created_at', $exported);
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

    private function cleanupVerifications(): void
    {
        if ($this->verificationIds === []) {
            return;
        }

        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            return;
        }

        try {
            $tenant->run(function (): void {
                Verification::query()->whereKey($this->verificationIds)->delete();
            });
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $this->verificationIds = [];
        }
    }
}
