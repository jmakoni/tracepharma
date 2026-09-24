<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateDispositionEpcisDocument;
use App\Actions\Outbound\GenerateDispositionObjectEvent;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Tenant;
use App\Support\Epcis\EpcisSchemaVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateDispositionEpcisDocumentJsonGateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function json_20_path_runs_hard_gate_before_emit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GenerateDispositionEpcisDocument::class)->execute(
            epcUris: ['urn:epc:id:sgtin:not-a-valid-uri'],
            kind: GenerateDispositionObjectEvent::KIND_COMMISSIONING,
            siteId: null,
            correlationId: null,
            settings: [
                'epcis_document_version' => EpcisSchemaVersion::V20,
                'sgln_urn' => 'urn:epc:id:sgln:030116.000001.0',
            ],
        );
    }

    #[Test]
    public function commissioning_sgtin_includes_ilmd_lot_and_expiry(): void
    {
        $this->initializeDemo2Tenant();
        $epcId = null;

        try {
            $serial = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:0399991.000001.'.$serial;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $epcId = (int) $epc->getKey();
            EpcIlmd::query()->create([
                'epc_id' => $epc->getKey(),
                'gtin14' => $epc->gtin14,
                'lot_number' => 'COMM-LOT',
                'expiry_date' => '2027-06-30',
            ]);

            $xml = app(GenerateDispositionObjectEvent::class)->execute(
                $uri,
                GenerateDispositionObjectEvent::KIND_COMMISSIONING,
                null,
                ['sgln_urn' => 'urn:epc:id:sgln:030116.000001.0'],
            );

            $this->assertStringContainsString('cbvmda:lotNumber', $xml);
            $this->assertStringContainsString('COMM-LOT', $xml);
            $this->assertStringContainsString('cbvmda:itemExpirationDate', $xml);
            $this->assertStringContainsString('2027-06-30', $xml);
            $this->assertStringContainsString('urn:epcglobal:cbv:mda', $xml);
        } finally {
            if ($epcId !== null) {
                EpcIlmd::query()->where('epc_id', $epcId)->delete();
                if (! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                    Epc::query()->whereKey($epcId)->delete();
                }
            }
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function destroyed_disposition_uses_destroying_biz_step(): void
    {
        [$bizStep, $disposition] = app(GenerateDispositionObjectEvent::class)
            ->decommissioningStepAndDisposition(['disposition' => 'destroyed']);

        $this->assertSame('destroying', $bizStep);
        $this->assertSame('destroyed', $disposition);

        $xml = app(GenerateDispositionObjectEvent::class)->execute(
            'urn:epc:id:sgtin:0399991.000001.'.random_int(100000000, 999999999),
            GenerateDispositionObjectEvent::KIND_DECOMMISSIONING,
            null,
            [
                'sgln_urn' => 'urn:epc:id:sgln:030116.000001.0',
                'disposition' => 'destroyed',
            ],
        );

        $this->assertStringContainsString('urn:epcglobal:cbv:bizstep:destroying', $xml);
        $this->assertStringContainsString('urn:epcglobal:cbv:disp:destroyed', $xml);
        $this->assertStringNotContainsString('urn:epcglobal:cbv:bizstep:decommissioning', $xml);
    }

    private function initializeDemo2Tenant(): void
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
    }
}
