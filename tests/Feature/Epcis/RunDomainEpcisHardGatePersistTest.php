<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\RunDomainEpcisHardGate;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunDomainEpcisHardGatePersistTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function handle_rejects_invalid_epc_uri_from_persisted_graph(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;

        try {
            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => 'bad-uri',
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999999',
                'serial_number' => 'hardgate-'.fake()->unique()->numerify('#####'),
                'gtin14' => '00301169999995',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'received',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'hard-gate-lean.xml',
            ]);
            $docId = (int) $doc->getKey();

            $eventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2026-08-12 16:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('event_epcs')->insert([
                'event_id' => $eventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            $result = RunDomainEpcisHardGate::withDefaultPipeline()->handle(
                EpcisDocument::query()->findOrFail($docId),
            );

            $this->assertTrue($result->isFailed());
            $this->assertSame('INVALID_EPC_URI', $result->failure?->code);
        } finally {
            if ($docId !== null) {
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            tenancy()->end();
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
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }
}
