<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportEpcisScenarioEvidenceCommandTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const HONESTY = 'NOT TraceReady / Gateway Checker / GS1 Trustmark certified';

    private static bool $demo2TenantReady = false;

    private ?string $outputDir = null;

    protected function tearDown(): void
    {
        if ($this->outputDir !== null && is_dir($this->outputDir)) {
            File::deleteDirectory($this->outputDir);
            $this->outputDir = null;
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function command_requires_tenant_option(): void
    {
        $this->artisan('epcis:export-scenario-evidence')
            ->assertFailed();
    }

    #[Test]
    public function command_writes_markdown_with_honesty_and_expected_fail_scenario(): void
    {
        $this->initializeDemo2Tenant();
        tenancy()->end();

        $this->outputDir = storage_path('framework/testing/epcis-scenario-evidence-'.uniqid('', true));
        File::ensureDirectoryExists($this->outputDir);

        $this->artisan('epcis:export-scenario-evidence', [
            '--tenant' => self::DEMO2_TENANT_ID,
            '--output' => $this->outputDir,
            '--format' => 'all',
        ])->assertSuccessful();

        $mdFiles = File::glob($this->outputDir.'/*.md');
        $this->assertNotEmpty($mdFiles, 'Expected a Markdown evidence file');

        $markdown = (string) file_get_contents($mdFiles[0]);
        $this->assertStringContainsString(self::HONESTY, $markdown);
        $this->assertMatchesRegularExpression(
            '/rx-r12-missing-locations.+fail/is',
            $markdown,
            'Missing-locations scenario should be marked fail as expected',
        );

        $junitFiles = File::glob($this->outputDir.'/*.xml');
        $this->assertNotEmpty($junitFiles, 'Expected a JUnit evidence file');
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
}
