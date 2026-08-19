<?php

declare(strict_types=1);

namespace Tests\Feature\Labeling;

use App\Actions\Labeling\PersistAuthoredSsccEpcis;
use App\Enums\EpcisAuthoredKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersistAuthoredSsccEpcisTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function falls_back_to_local_disk_when_preferred_disk_fails(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            config(['tracepharma.epcis.payload_disk' => 'definitely_not_a_configured_disk']);

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument test="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/sscc-persist-fallback-'.Str::uuid().'.xml';

            $document = app(PersistAuthoredSsccEpcis::class)->handle($xml, $path, [
                'dispatch' => false,
                'original_filename' => 'sscc-persist-fallback.xml',
                'authored_kind' => EpcisAuthoredKind::SsccAggregation,
                'notes' => 'PersistAuthoredSsccEpcis disk fallback test.',
            ]);

            $this->documentId = (int) $document->getKey();

            $this->assertSame('local', $document->payload_disk);
            $this->assertSame($path, $document->payload_path);
            $this->assertTrue(Storage::disk('local')->exists($path));
            $this->assertSame($xml, Storage::disk('local')->get($path));
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
        if (tenancy()->initialized) {
            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            tenancy()->end();
        }
    }
}
