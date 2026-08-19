<?php

namespace Tests\Feature\Vrs;

use App\Actions\Vrs\RunProductVerification;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VrsExceptionSiteAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GTIN14 = '30301164005162';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function site_restricted_exceptions_user_sees_vrs_triggered_hold_at_their_site(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            [$siteA, $siteB] = $this->createOwnedSites();
            $serial = 'FAIL-SITE-'.Str::random(4);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(
                'urn:epc:id:sgtin:030116.3400516.'.$serial,
            ));
            $this->epcIds[] = (int) $epc->getKey();
            $this->receiveAtSite($siteA, $epc);

            $result = app(RunProductVerification::class)->handle(
                '(01)'.self::GTIN14.'(21)'.$serial,
            );

            $case = ExceptionCase::query()->findOrFail($result['exception_id']);
            $this->caseIds[] = (int) $case->getKey();
            $this->verificationIds[] = (int) $result['verification']->getKey();

            $this->assertSame((int) $siteA->getKey(), (int) $case->site_id);
            $this->assertSame(1, QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count());

            $restricted = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($restricted);
            $this->assertFalse($restricted->can(Permissions::SitesAccessAll));

            $visible = ExceptionResource::getEloquentQuery()
                ->whereKey($case->getKey())
                ->exists();
            $this->assertTrue($visible);

            $otherSiteUser = $this->createUserWithSites([(int) $siteB->getKey()]);
            $this->actingAs($otherSiteUser);

            $hidden = ExceptionResource::getEloquentQuery()
                ->whereKey($case->getKey())
                ->exists();
            $this->assertFalse($hidden);

            $scoped = SiteAccess::constrainExceptionCases(ExceptionCase::query(), $restricted)
                ->whereKey($case->getKey())
                ->exists();
            $this->assertTrue($scoped);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'VRS Site A '.Str::random(4),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'VRS Site B '.Str::random(4),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

        return [$siteA, $siteB];
    }

    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'vrs-site-custody.xml',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinute(),
            'record_time' => now()->subMinute(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insert([
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]);
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $user->givePermissionTo([
            Permissions::NavExceptions,
            Permissions::NavVerify,
        ]);
        $this->userIds[] = (int) $user->getKey();

        return $user;
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
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
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

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->verificationIds !== []) {
            \App\Models\Verification::query()->whereKey($this->verificationIds)->delete();
            $this->verificationIds = [];
        }

        foreach ($this->caseIds as $caseId) {
            QuarantineHold::query()->where('exception_id', $caseId)->delete();
            $case = ExceptionCase::query()->find($caseId);
            $case?->epcs()->detach();
            $case?->delete();
        }
        $this->caseIds = [];

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        foreach ($this->epcIds as $epcId) {
            QuarantineHold::query()->where('epc_id', $epcId)->delete();
            Epc::query()->whereKey($epcId)->delete();
        }
        $this->epcIds = [];

        foreach ($this->siteIds as $siteId) {
            Site::query()->whereKey($siteId)->delete();
        }
        $this->siteIds = [];

        if ($this->userIds !== []) {
            User::query()->whereKey($this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();
    }
}
