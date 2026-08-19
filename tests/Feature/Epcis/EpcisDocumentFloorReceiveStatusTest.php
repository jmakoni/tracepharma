<?php

namespace Tests\Feature\Epcis;

use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisDocumentFloorReceiveStatusTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    #[Test]
    public function floor_receive_label_overlays_ingest_status_when_receiving_progresses(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('receiving_sessions'));

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now(),
                'received_at' => now(),
                'status' => 'validated',
                'dscsa_affirm' => true,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $document->load('receivingSession');
            $this->assertNull($document->floorReceiveStatusLabel());
            $this->assertNull($document->floorReceiveStatusColor());
            $this->assertFalse($document->isFloorReceived());
            $this->assertNull($document->openReceivingSession());

            $open = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'status' => 'open',
                'expected_parent_count' => 2,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 10,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionIds[] = (int) $open->getKey();

            $document->unsetRelation('receivingSession');
            $document->load('receivingSession');
            $this->assertNull($document->floorReceiveStatusLabel());
            $this->assertFalse($document->isFloorReceived());
            $this->assertNotNull($document->openReceivingSession());
            $this->assertSame((int) $open->getKey(), (int) $document->openReceivingSession()->getKey());

            $open->forceFill([
                'status' => 'in_progress',
                'confirmed_parent_count' => 1,
                'confirmed_child_count' => 3,
            ])->save();

            $document->unsetRelation('receivingSession');
            $document->load('receivingSession');
            $this->assertSame('Partially Received', $document->floorReceiveStatusLabel());
            $this->assertSame('warning', $document->floorReceiveStatusColor());
            $this->assertFalse($document->isFloorReceived());
            $this->assertNotNull($document->openReceivingSession());

            $open->forceFill([
                'status' => 'completed',
                'confirmed_parent_count' => 2,
                'confirmed_child_count' => 10,
                'completed_at' => now(),
            ])->save();

            $document->unsetRelation('receivingSession');
            $document->load('receivingSession');
            $this->assertSame('Received', $document->floorReceiveStatusLabel());
            $this->assertSame('success', $document->floorReceiveStatusColor());
            $this->assertTrue($document->isFloorReceived());
            $this->assertNull($document->openReceivingSession());
            $this->assertSame('validated', $document->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function floor_receive_label_shows_receive_blocked_for_blocking_exceptions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasColumn('exception_types', 'receive_impact'));

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now(),
                'received_at' => now(),
                'status' => 'validated',
                'dscsa_affirm' => true,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertNull($document->fresh()->floorReceiveStatusLabel());

            $unknownGtin = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();
            $unknownGtin->forceFill(['receive_impact' => ExceptionReceiveImpact::BusinessRule])->save();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $unknownGtin->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Unknown GTIN blocks receive',
                'description' => 'GTIN not found in product master: 30301164005087',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $document->unsetRelation('receivingSession');
            $this->assertSame('Receive Blocked', $document->fresh()->floorReceiveStatusLabel());
            $this->assertSame('danger', $document->fresh()->floorReceiveStatusColor());
            $this->assertSame('validated', $document->status);
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

            tenancy()->initialize($tenant);
            $this->seed(ExceptionTypeSeeder::class);
            self::$demo2TenantReady = true;
        } else {
            tenancy()->initialize($tenant);
        }

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionIds !== []) {
            ReceivingSession::query()->whereIn('id', $this->sessionIds)->delete();
            $this->sessionIds = [];
        }

        if ($this->caseIds !== []) {
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        tenancy()->end();
    }
}
