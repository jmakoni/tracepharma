<?php

namespace Tests\Feature\Receiving;

use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Services\Receiving\ReceivingGate;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceivingGateReceiveImpactTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $holdIds = [];

    #[Test]
    public function document_blocking_case_still_blocks_when_exception_has_quarantine_hold(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now(),
                'received_at' => now(),
                'status' => 'validated',
                'dscsa_affirm' => true,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $unknownGtin = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();
            $unknownGtin->forceFill(['receive_impact' => ExceptionReceiveImpact::BusinessRule])->save();

            $blockingCase = ExceptionCase::query()->create([
                'exception_type_id' => $unknownGtin->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Unknown GTIN blocks even with hold',
                'description' => 'GTIN not found in product master: 30301164005087',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $blockingCase->getKey();

            $hold = QuarantineHold::query()->create([
                'document_id' => $document->getKey(),
                'exception_id' => $blockingCase->getKey(),
                'reason' => 'Document hold linked to blocking case',
                'status' => 'open',
                'severity' => 'error',
                'opened_at' => now(),
            ]);
            $this->holdIds[] = (int) $hold->getKey();

            $blocked = app(ReceivingGate::class)->documentBlockedByOpenException($document->fresh());
            $this->assertNotNull($blocked);
            $this->assertSame($blockingCase->getKey(), $blocked->getKey());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function business_rule_document_case_blocks_receive_but_warning_does_not(): void
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

            $unknownGtin = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();
            $unknownGtin->forceFill(['receive_impact' => ExceptionReceiveImpact::BusinessRule])->save();

            $mixed = ExceptionType::query()->where('code', 'MIXED_PACKAGING_LEVELS')->firstOrFail();
            $mixed->forceFill(['receive_impact' => ExceptionReceiveImpact::Warning])->save();

            $gate = app(ReceivingGate::class);

            $warningCase = ExceptionCase::query()->create([
                'exception_type_id' => $mixed->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Warning only',
                'description' => 'ObjectEvent epcList mixes SGTIN and SSCC packaging levels.',
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $warningCase->getKey();

            $this->assertNull($gate->documentBlockedByOpenException($document->fresh()));

            $blockingCase = ExceptionCase::query()->create([
                'exception_type_id' => $unknownGtin->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Unknown GTIN blocks',
                'description' => 'GTIN not found in product master: 30301164005087',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $blockingCase->getKey();

            $blocked = $gate->documentBlockedByOpenException($document->fresh());
            $this->assertNotNull($blocked);
            $this->assertSame($blockingCase->getKey(), $blocked->getKey());
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
            $this->seed(ExceptionCaseSeeder::class);
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

        if ($this->caseIds !== []) {
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        if ($this->holdIds !== []) {
            QuarantineHold::query()->whereIn('id', $this->holdIds)->delete();
            $this->holdIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        tenancy()->end();
    }
}
