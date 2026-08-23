<?php

namespace Tests\Unit\Support\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Receiving\ResolveLotLevelReceiveScan;
use DomainException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveLotLevelReceiveScanTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function two_expected_lines_for_the_same_gtin_and_lot_require_the_2d_serial(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $session = $this->makeAsnSession();
            $first = $this->makeExpectedLine($session, '123456', 'LOT-AMB');
            $this->makeExpectedLine($session, '123456', 'LOT-AMB');
            $scan = '(01)'.$first->gtin14.'(10)LOT-AMB';

            try {
                app(ResolveLotLevelReceiveScan::class)->handle($session, $scan);
                $this->fail('Ambiguous lot-level scan should not pick an arbitrary serial.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Scan the 2D serial', $e->getMessage());
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function a_single_expected_line_returns_that_epc_uri(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $session = $this->makeAsnSession();
            $epc = $this->makeExpectedLine($session, '654321', 'LOT-ONE');
            $scan = '(01)'.$epc->gtin14.'(10)LOT-ONE';

            $resolved = app(ResolveLotLevelReceiveScan::class)->handle($session, $scan);

            $this->assertSame($epc->epc_uri, $resolved);
        } finally {
            $this->cleanup();
        }
    }

    private function makeAsnSession(): ReceivingSession
    {
        $session = ReceivingSession::query()->create([
            'session_kind' => ReceivingSessionKind::InboundAsn,
            'status' => 'open',
            'expected_parent_count' => 0,
            'confirmed_parent_count' => 0,
            'expected_child_count' => 2,
            'confirmed_child_count' => 0,
            'opened_at' => now(),
        ]);
        $this->sessionIds[] = (int) $session->getKey();

        return $session;
    }

    private function makeExpectedLine(ReceivingSession $session, string $itemRef, string $lot): Epc
    {
        $suffix = (string) random_int(10_000_000, 99_999_999);
        $epc = Epc::query()->create(Epc::materializeAttributesFromUri(
            'urn:epc:id:sgtin:030116.3'.$itemRef.'.LL'.$suffix,
        ));
        $this->epcIds[] = (int) $epc->getKey();

        EpcIlmd::query()->create([
            'epc_id' => $epc->getKey(),
            'gtin14' => $epc->gtin14,
            'lot_number' => $lot,
        ]);

        ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $epc->getKey(),
            'parent_epc_id' => null,
            'line_role' => 'child',
            'status' => 'expected',
        ]);

        return $epc->fresh();
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

        foreach ($this->sessionIds as $id) {
            ReceivingScanLine::query()->where('receiving_session_id', $id)->delete();
            ReceivingSession::query()->whereKey($id)->delete();
        }
        $this->sessionIds = [];

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
