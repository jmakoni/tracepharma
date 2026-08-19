<?php

namespace Tests\Feature\Quarantine;

use App\Actions\Quarantine\OpenQuarantineHold;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenQuarantineHoldTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $holdIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    #[Test]
    public function opening_hold_twice_for_same_epc_returns_existing_open_hold(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.q{$suffix}",
                'gtin14' => '00301162001162',
                'serial_number' => "q{$suffix}",
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $first = app(OpenQuarantineHold::class)->handle(
                reason: 'Opened from Find / Recall',
                epc: $epc,
            );
            $this->holdIds[] = (int) $first->id;

            $second = app(OpenQuarantineHold::class)->handle(
                reason: 'Second attempt',
                epc: $epc,
            );

            $this->assertTrue($first->wasRecentlyCreated);
            $this->assertFalse($second->wasRecentlyCreated);
            $this->assertSame((int) $first->id, (int) $second->id);
            $this->assertSame(1, QuarantineHold::query()->where('epc_id', $epc->id)->where('status', 'open')->count());
            $this->assertSame('Opened from Find / Recall', $second->reason);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function second_concurrent_open_returns_existing_open_hold(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.c{$suffix}",
                'gtin14' => '00301162001162',
                'serial_number' => "c{$suffix}",
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $action = app(OpenQuarantineHold::class);

            DB::transaction(function () use ($action, $epc): void {
                $first = $action->handle(
                    reason: 'First concurrent opener',
                    epc: $epc,
                );
                $this->holdIds[] = (int) $first->id;

                $second = $action->handle(
                    reason: 'Second concurrent opener',
                    epc: $epc,
                );

                $this->assertTrue($first->wasRecentlyCreated);
                $this->assertFalse($second->wasRecentlyCreated);
                $this->assertSame((int) $first->id, (int) $second->id);
                $this->assertSame(1, QuarantineHold::query()->where('epc_id', $epc->id)->where('status', 'open')->count());
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function opening_hold_for_case_binds_orphan_open_hold_exception_id(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->seed(ExceptionCaseSeeder::class);

            $suffix = substr((string) str()->uuid(), 0, 8);
            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.o{$suffix}",
                'gtin14' => '00301162001162',
                'serial_number' => "o{$suffix}",
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $orphan = app(OpenQuarantineHold::class)->handle(
                reason: 'Opened without case',
                epc: $epc,
            );
            $this->holdIds[] = (int) $orphan->id;
            $this->assertNull($orphan->exception_id);

            $typeId = ExceptionType::query()
                ->where('code', 'SUSPECT_PRODUCT')
                ->value('id');
            $case = ExceptionCase::query()->create([
                'exception_type_id' => $typeId,
                'title' => 'Orphan hold bind test',
                'description' => 'Bind quarantine hold exception_id',
                'severity' => ExceptionSeverity::Critical->value,
                'status' => ExceptionStatus::New->value,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $bound = app(OpenQuarantineHold::class)->handle(
                reason: 'Bind to case',
                epc: $epc,
                exception: $case,
            );

            $this->assertSame((int) $orphan->id, (int) $bound->id);
            $this->assertSame((int) $case->getKey(), (int) $bound->fresh()->exception_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function site_restricted_user_cannot_open_hold_for_out_of_scope_epc(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

            $siteA = Site::factory()->owned()->create([
                'name' => 'Hold Site A',
                'gln' => $this->uniqueGln(),
                'is_active' => true,
            ]);
            $siteB = Site::factory()->owned()->create([
                'name' => 'Hold Site B',
                'gln' => $this->uniqueGln(),
                'is_active' => true,
            ]);

            $suffix = substr((string) str()->uuid(), 0, 8);
            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.s{$suffix}",
                'gtin14' => '00301162001162',
                'serial_number' => "s{$suffix}",
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $document = \App\Models\Epcis\EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now(),
                'received_at' => now(),
                'ship_to_site_id' => $siteB->getKey(),
            ]);

            $user = User::factory()->create();
            $user->syncSites([(int) $siteA->getKey()]);
            $user->givePermissionTo(Permissions::NavExceptions);
            $this->actingAs($user);

            $this->expectException(AuthorizationException::class);
            $this->expectExceptionMessage('You do not have access to open holds for this site.');

            app(OpenQuarantineHold::class)->handle(
                reason: 'Out-of-scope open attempt',
                epc: $epc,
                document: $document,
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function null_actor_bypasses_site_access_for_machine_open(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = Site::factory()->owned()->create([
                'name' => 'Machine hold site',
                'gln' => $this->uniqueGln(),
                'is_active' => true,
            ]);

            $suffix = substr((string) str()->uuid(), 0, 8);
            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.m{$suffix}",
                'gtin14' => '00301162001162',
                'serial_number' => "m{$suffix}",
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $document = \App\Models\Epcis\EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now(),
                'received_at' => now(),
                'ship_to_site_id' => $site->getKey(),
            ]);

            $hold = app(OpenQuarantineHold::class)->handle(
                reason: 'Machine-initiated hold',
                epc: $epc,
                document: $document,
                actor: null,
            );
            $this->holdIds[] = (int) $hold->id;

            $this->assertTrue($hold->wasRecentlyCreated);
            $this->assertSame('open', $hold->status);
        } finally {
            $this->cleanup();
        }
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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

        TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
        $tenant->save();

        $user = User::query()->first() ?? User::factory()->create();
        $this->actingAs($user);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->holdIds as $id) {
            QuarantineHold::query()->whereKey($id)->delete();
        }
        $this->holdIds = [];

        if ($this->caseIds !== []) {
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        foreach ($this->epcIds as $id) {
            QuarantineHold::query()->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        tenancy()->end();
    }
}
