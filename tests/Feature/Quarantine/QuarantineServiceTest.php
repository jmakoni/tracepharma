<?php

namespace Tests\Feature\Quarantine;

use App\Actions\Quarantine\OpenQuarantineHold;
use App\Enums\ExceptionDisposition;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuarantineServiceTest extends TestCase
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
    public function quarantine_from_find_recall_creates_suspect_product_case_with_linked_open_holds(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $suffix = substr((string) str()->uuid(), 0, 8);
            $epc = $this->createEpc($suffix);
            $reason = "Recall match {$suffix}";

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: $reason,
                actor: $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame('SUSPECT_PRODUCT', $case->type->code);
            $this->assertSame(ExceptionSeverity::Critical, $case->severity);
            $this->assertSame(ExceptionStatus::Investigating, $case->status);
            $this->assertStringContainsString('Quarantine ·', $case->title);
            $this->assertSame($reason, $case->description);

            $holds = QuarantineHold::query()
                ->open()
                ->where('exception_id', $case->getKey())
                ->where('epc_id', $epc->id)
                ->get();

            $this->assertCount(1, $holds);
            $this->assertSame($reason, $holds->first()->reason);
            $this->assertTrue($case->epcs->contains('id', $epc->id));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function clear_for_distribution_releases_holds_and_sets_disposition_cleared(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $service = app(QuarantineService::class);

            $case = $service->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Pending supplier review',
            );
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame(1, QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count());

            $updated = $service->clearForDistribution($case, $user, 'Lot verified authentic by manufacturer.');

            $this->assertSame(ExceptionDisposition::Cleared, $updated->disposition);
            $this->assertSame(0, QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count());
            $this->assertSame(
                1,
                QuarantineHold::query()
                    ->where('exception_id', $case->getKey())
                    ->where('status', 'released')
                    ->count(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function mark_illegitimate_keeps_holds_open_and_sets_disposition_illegitimate(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $service = app(QuarantineService::class);

            $case = $service->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Suspect serial from secondary market',
            );
            $this->caseIds[] = (int) $case->getKey();

            $updated = $service->markIllegitimate($case, $user, 'Confirmed counterfeit packaging.');

            $this->assertSame(ExceptionDisposition::Illegitimate, $updated->disposition);
            $this->assertSame(1, QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function release_for_case_rejects_when_disposition_is_illegitimate(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $service = app(QuarantineService::class);

            $case = $service->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Illegitimate hold must stay open',
            );
            $this->caseIds[] = (int) $case->getKey();
            $service->markIllegitimate($case, $user, 'Confirmed counterfeit.');

            try {
                $service->releaseForCase($case->fresh(), $user, 'Attempted release after illegitimate');
                $this->fail('Expected ValidationException when releasing an illegitimate case.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('disposition', $e->errors());
                $this->assertSame(1, QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count());
            }
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Two cases can flag the same EPC; {@see OpenQuarantineHold}
     * reuses the single open hold rather than opening a second one, so the hold's
     * `exception_id` only names whichever case got there first. Releasing for that
     * first case must not free the EPC while the second case is still open on it.
     */
    #[Test]
    public function releasing_a_shared_hold_for_one_case_keeps_it_open_while_another_case_is_still_open(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $service = app(QuarantineService::class);

            $caseA = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case A suspects this unit');
            $this->caseIds[] = (int) $caseA->getKey();

            $caseB = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case B also suspects this unit');
            $this->caseIds[] = (int) $caseB->getKey();

            // Both cases reference the same EPC, but only one physical hold exists.
            $this->assertSame(1, QuarantineHold::query()->open()->where('epc_id', $epc->id)->count());
            $hold = QuarantineHold::query()->open()->where('epc_id', $epc->id)->firstOrFail();
            $this->assertSame((int) $caseA->getKey(), (int) $hold->exception_id);
            $this->assertTrue($caseB->fresh()->epcs->contains('id', $epc->id));

            // Case B does not own the hold row, but it is still blocked by it.
            $this->assertTrue($caseB->fresh()->hasBlockingOpenQuarantineHolds());

            // Case A releases while case B is still open: the shared hold must stay open.
            $releasedForA = $service->releaseForCase($caseA->fresh(), $user, 'Case A cleared');
            $this->assertSame(0, $releasedForA);
            $this->assertSame('open', $hold->fresh()->status);
            $this->assertTrue($caseB->fresh()->hasBlockingOpenQuarantineHolds());

            // Once case B is no longer open, releasing frees the hold for real.
            $caseB->fresh()->forceFill(['status' => ExceptionStatus::Resolved->value])->save();
            $releasedAgain = $service->releaseForCase($caseA->fresh(), $user, 'Case A cleared, case B resolved');
            $this->assertSame(1, $releasedAgain);
            $this->assertSame('released', $hold->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function releasing_a_shared_hold_keeps_it_open_when_sibling_is_illegitimate_even_if_resolved(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $service = app(QuarantineService::class);

            $caseA = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case A suspects this unit');
            $this->caseIds[] = (int) $caseA->getKey();

            $caseB = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case B also suspects this unit');
            $this->caseIds[] = (int) $caseB->getKey();

            $hold = QuarantineHold::query()->open()->where('epc_id', $epc->id)->firstOrFail();

            $service->markIllegitimate($caseB->fresh(), $user, 'Sibling confirmed illegitimate.');
            $caseB->fresh()->forceFill(['status' => ExceptionStatus::Resolved->value])->save();

            $released = $service->releaseForCase($caseA->fresh(), $user, 'Case A cleared after sibling illegitimate');
            $this->assertSame(0, $released);
            $this->assertSame('open', $hold->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Closing/resolving a case must still be blocked by a hold it does not directly
     * own, when that hold is open on an EPC the case shares with another case.
     */
    #[Test]
    public function has_blocking_open_quarantine_holds_considers_holds_shared_via_other_cases(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $service = app(QuarantineService::class);

            $caseA = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case A suspects this unit');
            $this->caseIds[] = (int) $caseA->getKey();

            $caseB = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case B also suspects this unit');
            $this->caseIds[] = (int) $caseB->getKey();

            $this->assertTrue($caseA->fresh()->hasBlockingOpenQuarantineHolds());
            $this->assertTrue($caseB->fresh()->hasBlockingOpenQuarantineHolds());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function clear_for_distribution_rejects_when_sibling_case_keeps_shared_hold_open(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $service = app(QuarantineService::class);

            $caseA = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case A suspects this unit');
            $this->caseIds[] = (int) $caseA->getKey();

            $caseB = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case B also suspects this unit');
            $this->caseIds[] = (int) $caseB->getKey();

            $this->assertTrue($caseA->fresh()->hasBlockingOpenQuarantineHolds());

            try {
                $service->clearForDistribution($caseA->fresh(), $user, 'Attempted clearance with sibling hold.');
                $this->fail('Expected ValidationException when sibling case keeps a shared hold open.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('quarantine', $e->errors());
            }

            $this->assertNull($caseA->fresh()->disposition);
            $this->assertSame(1, QuarantineHold::query()->open()->where('epc_id', $epc->id)->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function mark_illegitimate_opens_missing_holds_before_disposition(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setJobRolesEnabled(false);
            tenant()->save();

            $user = User::query()->first() ?? User::factory()->create();
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $type = \App\Models\Exceptions\ExceptionType::query()->where('code', 'SUSPECT_PRODUCT')->firstOrFail();

            $case = app(\App\Services\Exceptions\ExceptionService::class)->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Suspect without holds',
                'description' => 'EPC linked but no hold yet',
                'severity' => ExceptionSeverity::Critical->value,
                'status' => ExceptionStatus::Investigating->value,
            ], [$epc->id], $user);
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame(0, QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count());

            $updated = app(QuarantineService::class)->markIllegitimate($case, $user, 'Confirmed counterfeit packaging.');

            $this->assertSame(ExceptionDisposition::Illegitimate, $updated->disposition);
            $this->assertSame(1, QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count());
            $this->assertSame(1, (int) $updated->fresh()->serials_affected);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function serials_affected_reflects_open_hold_count_not_pivot_size_when_holds_are_shared(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setJobRolesEnabled(false);
            tenant()->save();

            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $service = app(QuarantineService::class);

            $caseA = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case A');
            $this->caseIds[] = (int) $caseA->getKey();

            $caseB = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case B');
            $this->caseIds[] = (int) $caseB->getKey();

            $this->assertSame(1, QuarantineHold::query()->open()->where('epc_id', $epc->id)->count());
            $this->assertTrue($caseB->fresh()->epcs->contains('id', $epc->id));
            $this->assertSame(1, (int) $caseB->fresh()->serials_affected);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function release_for_case_decrements_serials_affected_when_holds_are_released(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $epcA = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $epcB = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $service = app(QuarantineService::class);

            $case = $service->quarantineFromFindRecall(
                epcIds: [$epcA->id, $epcB->id],
                reason: 'Two-unit quarantine',
                actor: $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame(2, (int) $case->fresh()->serials_affected);

            $hold = QuarantineHold::query()
                ->open()
                ->where('exception_id', $case->getKey())
                ->where('epc_id', $epcA->id)
                ->firstOrFail();

            app(\App\Actions\Quarantine\ReleaseQuarantineHold::class)->handle($hold, 'Partial release', $user);

            $this->assertSame(1, (int) $case->fresh()->serials_affected);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function signed_supplier_url_returns_durable_signed_url_containing_share_uuid(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Share link test',
            );
            $this->caseIds[] = (int) $case->getKey();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);

            $url = app(QuarantineService::class)->signedSupplierUrl($case->fresh());
            $case->refresh();

            $this->assertNotNull($case->share_uuid);
            $this->assertNotNull($case->share_expires_at);
            $this->assertStringContainsString($case->share_uuid, $url);
            $this->assertStringContainsString('signature=', $url);
            $this->assertStringContainsString('expires=', $url);
            $this->assertStringContainsString('/supplier-quarantine/', $url);
        } finally {
            $this->cleanup();
        }
    }

    private function createEpc(string $suffix): Epc
    {
        $epc = Epc::query()->create([
            'epc_type' => 'sgtin',
            'epc_uri' => "urn:epc:id:sgtin:030116.0200116.q{$suffix}",
            'gtin14' => '00301162001162',
            'serial_number' => "q{$suffix}",
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
