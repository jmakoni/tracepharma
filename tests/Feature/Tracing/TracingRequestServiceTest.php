<?php

namespace Tests\Feature\Tracing;

use App\Enums\TenantProfile;
use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Models\User;
use App\Services\Tracing\TracingRequestService;
use App\Services\Tracing\TracingSlaService;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TracingRequestServiceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $requestIds = [];

    #[Test]
    public function create_request_sets_open_status_and_sla_due_for_supplier(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'UTC'));

            $user = User::query()->first() ?? User::factory()->create();
            $request = app(TracingRequestService::class)->create([
                'title' => 'Supplier lot trace',
                'requestor_type' => TracingRequestorType::Supplier,
                'scope' => TracingRequestScope::Lot,
                'gtin' => '00300012345678',
                'lot' => 'LOT-SLA-1',
            ], $user);
            $this->requestIds[] = (int) $request->getKey();

            $this->assertSame(TracingRequestStatus::Open, $request->status);
            $this->assertSame(TracingRequestorType::Supplier, $request->requestor_type);
            $this->assertSame($user->id, $request->requested_by);
            $this->assertNotNull($request->requested_at);
            $this->assertNotNull($request->due_at);

            $expectedHours = app(TracingSlaService::class)->slaHoursFor(TracingRequestorType::Supplier);
            $this->assertSame(48, $expectedHours);
            $this->assertTrue(
                $request->due_at->equalTo($request->requested_at->copy()->addHours($expectedHours)),
            );
        } finally {
            Carbon::setTestNow();
            $this->cleanup();
        }
    }

    #[Test]
    public function mark_responded_stores_evidence_metadata_and_responded_at(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'UTC'));

            $user = User::query()->first() ?? User::factory()->create();
            $request = app(TracingRequestService::class)->create([
                'title' => 'Regulator serial trace',
                'requestor_type' => TracingRequestorType::Regulator,
                'scope' => TracingRequestScope::SingleProduct,
                'gtin' => '00300012345678',
                'serial' => 'SER-RESP-1',
            ], $user);
            $this->requestIds[] = (int) $request->getKey();

            $updated = app(TracingSlaService::class)->markResponded($request, [
                'summary' => 'Provided TI for serial SER-RESP-1',
                'evidence_reference' => 'EPCIS #42',
                'evidence_notes' => 'Attached inbound ASN PDF',
            ]);

            $this->assertNotNull($updated->responded_at);
            $this->assertFalse((bool) $updated->sla_breached);
            $this->assertSame('Provided TI for serial SER-RESP-1', $updated->response_metadata['summary'] ?? null);
            $this->assertSame('EPCIS #42', $updated->response_metadata['evidence_reference'] ?? null);
        } finally {
            Carbon::setTestNow();
            $this->cleanup();
        }
    }


    #[Test]
    public function status_transitions_open_to_in_progress_to_completed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00', 'UTC'));

            $service = app(TracingRequestService::class);
            $request = $service->create([
                'title' => 'Regulator serial trace',
                'requestor_type' => TracingRequestorType::Regulator,
                'scope' => TracingRequestScope::SingleProduct,
                'gtin' => '00300012345678',
                'serial' => 'SN-TRACE-1',
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $started = $service->start($request);
            $this->assertSame(TracingRequestStatus::InProgress, $started->status);

            Carbon::setTestNow(Carbon::parse('2026-08-10 11:00:00', 'UTC'));
            $responded = app(TracingSlaService::class)->markResponded($started, [
                'summary' => 'TI provided to regulator',
            ]);
            $this->assertNotNull($responded->responded_at);

            $completed = $service->complete($responded);

            $this->assertSame(TracingRequestStatus::Completed, $completed->status);
            $this->assertNotNull($completed->completed_at);
            $this->assertNotNull($completed->responded_at);
            $this->assertFalse($completed->sla_breached);
            $this->assertSame('TI provided to regulator', $completed->response_metadata['summary'] ?? null);
        } finally {
            Carbon::setTestNow();
            $this->cleanup();
        }
    }

    #[Test]
    public function complete_without_prior_response_is_rejected(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $service = app(TracingRequestService::class);
            $request = $service->create([
                'title' => 'Complete without respond',
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'lot' => 'LOT-NO-RESP',
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $started = $service->start($request);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Cannot complete tracing request without a recorded response');
            $service->complete($started);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function mark_responded_does_not_overwrite_existing_responded_at(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00', 'UTC'));

            $request = app(TracingRequestService::class)->create([
                'title' => 'Preserve first response',
                'requestor_type' => TracingRequestorType::Regulator,
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $first = app(TracingSlaService::class)->markResponded($request, ['summary' => 'First response']);
            $firstRespondedAt = $first->responded_at;

            Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

            $second = app(TracingSlaService::class)->markResponded($first, ['summary' => 'Second response']);

            $this->assertTrue($second->responded_at->equalTo($firstRespondedAt));
            $this->assertSame('Second response', $second->response_metadata['summary'] ?? null);
        } finally {
            Carbon::setTestNow();
            $this->cleanup();
        }
    }

    #[Test]
    public function invalid_status_transition_is_rejected(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $service = app(TracingRequestService::class);
            $request = $service->create([
                'title' => 'Cancel-only path',
                'requestor_type' => TracingRequestorType::Internal,
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $this->expectException(InvalidArgumentException::class);
            $service->complete($request);
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
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->requestIds as $id) {
            TracingRequest::query()->whereKey($id)->delete();
        }

        $this->requestIds = [];
        tenancy()->end();
    }
}
