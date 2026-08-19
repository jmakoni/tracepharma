<?php

namespace Tests\Feature\Quarantine;

use App\Actions\Quarantine\ReleaseQuarantineHold;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\Quarantine;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuarantineWorkstationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function workstation_lists_open_holds_and_release_action_closes_hold(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.qws'.substr((string) str()->uuid(), 0, 6),
                'gtin14' => '00301162001162',
                'serial_number' => 'qws'.substr((string) str()->uuid(), 0, 6),
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Workstation hold',
                actor: $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $hold = QuarantineHold::query()
                ->open()
                ->where('exception_id', $case->getKey())
                ->firstOrFail();

            $component = Livewire::test(Quarantine::class);
            $holds = $component->instance()->openHolds();
            $this->assertTrue($holds->contains(fn (QuarantineHold $row): bool => (int) $row->id === (int) $hold->id));

            app(ReleaseQuarantineHold::class)->handle($hold, 'QA cleared at workstation');
            $hold->refresh();

            $this->assertSame('released', $hold->status);
            $this->assertNotNull($hold->closed_at);
            $this->assertFalse(
                Quarantine::canAccess() === false,
            );
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsComplianceCases());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function workstation_release_keeps_shared_hold_open_while_sibling_case_is_still_open(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.qws'.substr((string) str()->uuid(), 0, 6),
                'gtin14' => '00301162001162',
                'serial_number' => 'qws'.substr((string) str()->uuid(), 0, 6),
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $service = app(QuarantineService::class);
            $caseA = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case A suspects this unit', actor: $user);
            $this->caseIds[] = (int) $caseA->getKey();

            $caseB = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case B also suspects this unit', actor: $user);
            $this->caseIds[] = (int) $caseB->getKey();

            $hold = QuarantineHold::query()->open()->where('epc_id', $epc->id)->firstOrFail();

            app(ReleaseQuarantineHold::class)->handle($hold, 'Workstation release while sibling open', $user);
            $hold->refresh();

            $this->assertSame('open', $hold->status);
            $this->assertTrue($caseB->fresh()->hasBlockingOpenQuarantineHolds());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function workstation_release_keeps_shared_hold_open_when_sibling_is_illegitimate_even_if_resolved(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.qil'.substr((string) str()->uuid(), 0, 6),
                'gtin14' => '00301162001162',
                'serial_number' => 'qil'.substr((string) str()->uuid(), 0, 6),
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $service = app(QuarantineService::class);
            $caseA = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case A suspects this unit', actor: $user);
            $this->caseIds[] = (int) $caseA->getKey();

            $caseB = $service->quarantineFromFindRecall(epcIds: [$epc->id], reason: 'Case B also suspects this unit', actor: $user);
            $this->caseIds[] = (int) $caseB->getKey();

            $hold = QuarantineHold::query()->open()->where('epc_id', $epc->id)->firstOrFail();

            $service->markIllegitimate($caseB->fresh(), $user, 'Sibling confirmed illegitimate.');
            $caseB->fresh()->forceFill(['status' => ExceptionStatus::Resolved->value])->save();

            app(ReleaseQuarantineHold::class)->handle($hold, 'Workstation release after sibling illegitimate', $user);
            $hold->refresh();

            $this->assertSame('open', $hold->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function release_quarantine_hold_asserts_document_ship_to_site_access(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $allowedSite = Site::factory()->owned()->create([
                'name' => 'Allowed Site '.Str::random(5),
                'is_active' => true,
            ]);
            $blockedSite = Site::factory()->owned()->create([
                'name' => 'Blocked Site '.Str::random(5),
                'is_active' => true,
            ]);
            $this->siteIds[] = (int) $allowedSite->id;
            $this->siteIds[] = (int) $blockedSite->id;

            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $owner->id;

            $restricted = User::factory()->create();
            $restricted->syncSites([(int) $allowedSite->id]);
            $this->userIds[] = (int) $restricted->id;

            $document = \App\Models\Epcis\EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'received_at' => now(),
                'direction' => 'inbound',
                'status' => 'parsed',
                'ship_to_site_id' => $blockedSite->id,
            ]);

            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.rel'.substr((string) str()->uuid(), 0, 6),
                'gtin14' => '00301162001162',
                'serial_number' => 'rel'.substr((string) str()->uuid(), 0, 6),
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Site-scoped hold',
                actor: $owner,
            );
            $this->caseIds[] = (int) $case->getKey();

            $hold = QuarantineHold::query()
                ->open()
                ->where('exception_id', $case->getKey())
                ->firstOrFail();
            $hold->forceFill(['document_id' => $document->getKey()])->save();

            $this->expectException(AuthorizationException::class);
            app(ReleaseQuarantineHold::class)->handle($hold, 'Blocked release', $restricted);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function user_without_nav_exceptions_cannot_release_when_job_roles_enabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

            $tenant = tenant();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();
            $tenant->refresh();

            $user = User::factory()->create();
            $user->assignRole(TenantRole::VrsAnalyst->value);
            $this->userIds[] = (int) $user->id;
            $this->actingAs($user);

            $page = new Quarantine;
            $this->assertFalse($page->canRelease());

            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function site_restricted_user_cannot_see_document_less_find_recall_holds(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $site = Site::factory()->owned()->create([
                'name' => 'QWS Site '.Str::random(5),
                'is_active' => true,
            ]);
            $this->siteIds[] = (int) $site->id;

            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $owner->id;

            $restricted = User::factory()->create();
            $restricted->syncSites([(int) $site->id]);
            $this->userIds[] = (int) $restricted->id;

            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.iso'.substr((string) str()->uuid(), 0, 6),
                'gtin14' => '00301162001162',
                'serial_number' => 'iso'.substr((string) str()->uuid(), 0, 6),
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Isolation hold',
                actor: $owner,
            );
            $this->caseIds[] = (int) $case->getKey();

            $hold = QuarantineHold::query()
                ->open()
                ->where('exception_id', $case->getKey())
                ->firstOrFail();

            $this->actingAs($owner);
            $ownerHolds = Livewire::test(Quarantine::class)->instance()->openHolds();
            $this->assertTrue($ownerHolds->contains(fn (QuarantineHold $row): bool => (int) $row->id === (int) $hold->id));

            $this->actingAs($restricted);
            $restrictedHolds = Livewire::test(Quarantine::class)->instance()->openHolds();
            $this->assertFalse($restrictedHolds->contains(fn (QuarantineHold $row): bool => (int) $row->id === (int) $hold->id));

            $this->actingAs($owner);
            $page = Livewire::test(Quarantine::class)->instance();
            $ownerHold = $page->openHolds()->first(fn (QuarantineHold $row): bool => (int) $row->id === (int) $hold->id);
            $this->assertNotNull($ownerHold);
            $this->assertTrue($page->canReleaseHold($ownerHold));

            $this->actingAs($restricted);
            $restrictedPage = Livewire::test(Quarantine::class)->instance();
            $this->assertFalse(
                $restrictedPage->canReleaseHold($hold->fresh(['document', 'exception']) ?? $hold),
            );
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
        $this->seed(ExceptionCaseSeeder::class);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->caseIds as $id) {
            $case = ExceptionCase::query()->find($id);
            if ($case === null) {
                continue;
            }
            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $id)->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->caseIds = [];

        foreach ($this->epcIds as $id) {
            QuarantineHold::query()->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        tenancy()->end();
    }
}
