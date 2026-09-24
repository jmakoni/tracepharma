<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\RecordEpcisValidationFailure;
use App\Actions\Epcis\RunDomainEpcisHardGate;
use App\Domain\Epcis\Validation\ValidationFailure;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordEpcisValidationFailureSoftTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function soft_record_keeps_validated_status_and_writes_warning_exception(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;

        try {
            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'domain-soft-signal.xml',
            ]);
            $docId = (int) $doc->getKey();

            $failure = new ValidationFailure(
                stage: 'gs1_schema',
                code: 'INVALID_EPC_URI',
                message: 'soft signal fixture',
            );

            app(RecordEpcisValidationFailure::class)->handle($doc, $failure, blocking: false);

            $doc->refresh();
            $this->assertSame('validated', $doc->status);

            $exception = EpcisException::query()
                ->where('document_id', $docId)
                ->where('exception_type', 'INVALID_EPC_URI')
                ->where('status', 'open')
                ->first();

            $this->assertNotNull($exception);
            $this->assertSame('warning', $exception->severity);
            $this->assertStringContainsString('INVALID_EPC_URI', (string) $exception->description);
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                EpcisDocument::query()->whereKey($docId)->delete();
            }
            tenancy()->end();
        }
    }

    #[Test]
    public function domain_hard_gate_failure_can_be_soft_recorded_from_persisted_graph(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;

        try {
            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => 'bad-uri-soft',
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999998',
                'serial_number' => 'softgate-'.fake()->unique()->numerify('#####'),
                'gtin14' => '00301169999988',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'domain-soft-persist.xml',
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

            $result = app(RunDomainEpcisHardGate::class)->handle(
                EpcisDocument::query()->findOrFail($docId),
            );

            $this->assertTrue($result->isFailed());
            $this->assertNotNull($result->failure);

            app(RecordEpcisValidationFailure::class)->handle(
                EpcisDocument::query()->findOrFail($docId),
                $result->failure,
                blocking: false,
            );

            $doc = EpcisDocument::query()->findOrFail($docId);
            $this->assertSame('validated', $doc->status);
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $docId)
                    ->where('exception_type', 'INVALID_EPC_URI')
                    ->where('status', 'open')
                    ->exists(),
            );
        } finally {
            if ($docId !== null) {
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                EpcisException::query()->where('document_id', $docId)->delete();
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
