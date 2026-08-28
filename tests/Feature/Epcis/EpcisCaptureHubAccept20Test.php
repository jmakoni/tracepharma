<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisCaptureHubAccept20Test extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function accepts_json_when_platform_and_tenant_flags_on(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        config(['tracepharma.epcis.accept_20' => true]);
        TenantSettings::forTenant($tenant)->setEpcisAccept20(true);
        $tenant->save();

        try {
            $tmp = $this->uniqueJsonFixture();
            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_packing_2.0.json',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame(EpcisSchemaVersion::V20, $document->schema_version);
            $this->assertSame(EpcisSchemaVersion::FORMAT_JSON, $document->format);
            @unlink($tmp);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function rejects_json_when_tenant_opts_out(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        config(['tracepharma.epcis.accept_20' => true]);
        TenantSettings::forTenant($tenant)->setEpcisAccept20(false);
        $tenant->save();

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('disabled for this tenant');

            app(ReceiveEpcisUpload::class)->handle(
                base_path('tests/Fixtures/epcis/minimal_object_packing_2.0.json'),
                [
                    'direction' => 'inbound',
                    'original_filename' => 'minimal_object_packing_2.0.json',
                    'dispatch' => false,
                ],
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function uniqueJsonFixture(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epcis20_');
        $this->assertNotFalse($tmp);
        $dest = $tmp.'.json';
        rename($tmp, $dest);
        $json = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_packing_2.0.json'));
        $this->assertNotFalse($json);
        $json = str_replace('22222222-3333-4444-5555-666666666666', (string) str()->uuid(), $json);
        file_put_contents($dest, $json);

        return $dest;
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
        $this->assertTrue(Schema::hasTable('epcis_documents'));

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            foreach ($this->documentIds as $id) {
                EpcisDocument::query()->whereKey($id)->delete();
            }
            $this->documentIds = [];
            TenantSettings::forTenant($tenant)->setEpcisAccept20(null);
            $tenant->save();
            tenancy()->end();
        }
    }
}
