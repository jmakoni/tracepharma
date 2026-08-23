<?php

namespace Tests\Unit\Support\Recalls;

use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Support\Recalls\OpenRecallFlag;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenRecallFlagTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $requestIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function open_lot_recall_blocks_matching_epc(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.RF'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            EpcIlmd::query()->create([
                'epc_id' => $epc->getKey(),
                'gtin14' => $epc->gtin14,
                'lot_number' => 'LOT-FLAG-1',
            ]);

            $request = TracingRequest::query()->create([
                'title' => 'Recall LOT-FLAG-1',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => $epc->gtin14,
                'lot' => 'LOT-FLAG-1',
                'is_recall' => true,
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $message = app(OpenRecallFlag::class)->blocks($epc->fresh());

            $this->assertSame('Open recall for this product — do not receive or ship.', $message);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function completed_recall_does_not_block(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.RC'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Closed recall',
                'status' => TracingRequestStatus::Completed,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => $epc->gtin14,
                'is_recall' => true,
                'requested_at' => now(),
                'completed_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $this->assertNull(app(OpenRecallFlag::class)->blocks($epc->fresh()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function serial_only_recall_does_not_block_an_sscc_with_the_same_serial_reference(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $serialRef = '0'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $sscc = Epc::query()->create(Epc::materializeAttributesFromUri(
                'urn:epc:id:sscc:030116.'.$serialRef,
            ));
            $this->epcIds[] = (int) $sscc->getKey();

            $this->assertSame('sscc', $sscc->epc_type);
            $this->assertSame($serialRef, $sscc->serial_number);

            $request = TracingRequest::query()->create([
                'title' => 'Serial-only recall',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::SingleProduct,
                'serial' => $sscc->serial_number,
                'is_recall' => true,
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $this->assertNull(app(OpenRecallFlag::class)->blocks($sscc->fresh()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function empty_identity_open_recall_does_not_block(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.EM'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Empty identity recall',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'is_recall' => true,
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $this->assertNull(app(OpenRecallFlag::class)->blocks($epc->fresh()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function epc_without_product_identity_is_not_blocked_by_an_open_recall(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.EI'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $epc->forceFill(['gtin14' => null, 'serial_number' => null])->save();
            $this->epcIds[] = (int) $epc->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'GTIN recall against empty EPC',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => '00301163000014',
                'is_recall' => true,
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $this->assertNull(app(OpenRecallFlag::class)->blocks($epc->fresh()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function gtin_and_serial_recall_still_blocks_the_matching_sgtin(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.GS'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Serial+GTIN recall',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::SingleProduct,
                'gtin' => $epc->gtin14,
                'serial' => $epc->serial_number,
                'is_recall' => true,
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $this->assertSame(
                'Open recall for this product — do not receive or ship.',
                app(OpenRecallFlag::class)->blocks($epc->fresh()),
            );
        } finally {
            $this->cleanup();
        }
    }

    private function initializeDemo2Tenant(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
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

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->requestIds as $id) {
            TracingRequest::query()->whereKey($id)->delete();
        }
        $this->requestIds = [];

        foreach ($this->epcIds as $epcId) {
            EpcIlmd::query()->where('epc_id', $epcId)->delete();
            if (! DB::table('event_epcs')->where('epc_id', $epcId)->exists()
                && ! DB::table('document_epcs')->where('epc_id', $epcId)->exists()) {
                Epc::query()->whereKey($epcId)->delete();
            }
        }
        $this->epcIds = [];

        tenancy()->end();
    }
}
