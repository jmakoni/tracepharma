<?php

namespace Tests\Feature\Quarantine;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Services\Quarantine\QuarantineService;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierPortalApplyFormTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    #[Test]
    public function apply_form_from_waiting_partner_transitions_to_investigating_and_logs_activity(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Partner apply WaitingPartner transition',
            );
            $this->caseIds[] = (int) $case->getKey();

            $case->forceFill(['status' => ExceptionStatus::WaitingPartner])->save();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            app(QuarantineService::class)->ensureShareLink($case->fresh());
            $case->refresh();

            $applyUrl = URL::temporarySignedRoute(
                'tenant.supplier-quarantine.apply',
                now()->addDays(30),
                ['shareUuid' => $case->share_uuid],
            );

            $showUrl = app(QuarantineService::class)->signedSupplierUrl($case->fresh());

            tenancy()->end();

            $this->get($showUrl)
                ->assertOk()
                ->assertSee('Apply correction', false);

            $response = $this->post($applyUrl, [
                'acknowledged' => '1',
                'corrected_reference' => 'ASN-APPLY-001',
                'gtin' => '00301162001162',
                'serial' => 's-apply-1',
                'lot' => 'LOT-APPLY',
                'expiry' => '2027-12-31',
                'notes' => 'Corrected lot and serial on reship.',
                'supplier_name' => 'Acme Wholesale',
            ]);

            $response->assertRedirect();
            $response->assertSessionHas('status');

            tenancy()->initialize($tenant);

            $case->refresh();
            $this->assertSame(ExceptionStatus::Investigating, $case->status);

            $activity = ExceptionActivity::query()
                ->where('exception_id', $case->getKey())
                ->where('visibility', ExceptionActivityVisibility::Partner->value)
                ->where('kind', ExceptionActivityKind::Comment->value)
                ->where('meta->source', 'supplier_apply_form')
                ->latest('id')
                ->first();

            $this->assertNotNull($activity);
            $this->assertSame('supplier_apply_form', $activity->meta['source'] ?? null);
            $this->assertSame('ASN-APPLY-001', $activity->meta['corrected_reference'] ?? null);
            $this->assertSame('00301162001162', $activity->meta['gtin'] ?? null);
            $this->assertSame('s-apply-1', $activity->meta['serial'] ?? null);
            $this->assertSame('LOT-APPLY', $activity->meta['lot'] ?? null);
            $this->assertSame('2027-12-31', $activity->meta['expiry'] ?? null);
            $this->assertSame('Corrected lot and serial on reship.', $activity->meta['notes'] ?? null);
            $this->assertTrue((bool) ($activity->meta['acknowledged'] ?? false));
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function apply_form_when_not_waiting_partner_logs_activity_without_status_change(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Partner apply non-WaitingPartner',
            );
            $this->caseIds[] = (int) $case->getKey();

            $case->forceFill(['status' => ExceptionStatus::Investigating])->save();
            $statusBefore = $case->fresh()->status;

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            app(QuarantineService::class)->ensureShareLink($case->fresh());
            $case->refresh();

            $applyUrl = URL::temporarySignedRoute(
                'tenant.supplier-quarantine.apply',
                now()->addDays(30),
                ['shareUuid' => $case->share_uuid],
            );

            tenancy()->end();

            $response = $this->post($applyUrl, [
                'acknowledged' => '1',
                'notes' => 'Acknowledged while already investigating.',
                'supplier_name' => 'Acme Wholesale',
            ]);

            $response->assertRedirect();
            $response->assertSessionHas('status');

            tenancy()->initialize($tenant);

            $case->refresh();
            $this->assertSame($statusBefore, $case->status);
            $this->assertSame(ExceptionStatus::Investigating, $case->status);

            $activity = ExceptionActivity::query()
                ->where('exception_id', $case->getKey())
                ->where('visibility', ExceptionActivityVisibility::Partner->value)
                ->where('meta->source', 'supplier_apply_form')
                ->latest('id')
                ->first();

            $this->assertNotNull($activity);
            $this->assertSame('supplier_apply_form', $activity->meta['source'] ?? null);
            $this->assertSame('Acknowledged while already investigating.', $activity->meta['notes'] ?? null);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    private function createEpc(string $suffix): Epc
    {
        $epc = Epc::query()->create([
            'epc_type' => 'sgtin',
            'epc_uri' => "urn:epc:id:sgtin:030116.0200116.a{$suffix}",
            'gtin14' => '00301162001162',
            'serial_number' => "a{$suffix}",
            'company_prefix' => '030116',
            'first_seen_at' => now(),
        ]);
        $this->epcIds[] = (int) $epc->id;

        return $epc;
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

        $this->seed(ExceptionCaseSeeder::class);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->caseIds as $caseId) {
            $case = ExceptionCase::query()->find($caseId);
            if ($case === null) {
                continue;
            }

            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $caseId)->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->caseIds = [];

        foreach ($this->epcIds as $id) {
            QuarantineHold::query()->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        tenancy()->end();
    }
}
