<?php

namespace Tests\Feature\Exceptions;

use App\Actions\Receiving\AuthorReceiveSessionException;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\ScanInWorkstation;
use App\Filament\App\Resources\Exceptions\Pages\ListExceptions;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceiveSessionExceptionQuery;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ExceptionTypeChipPlacementTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    #[Test]
    public function scan_pages_do_not_render_exception_type_chips(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());
            app(ExceptionTypeSeeder::class)->run();

            TenantSettings::forTenant(tenant())->setReceivingEdgeMode(ReceivingEdgeMode::UnitsOnly);
            tenant()->save();

            $siteId = $this->eligibleReceiveSiteId();
            $this->assertNotNull($siteId);
            $session = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionIds[] = (int) $session->getKey();

            $epc = $this->createSgtinEpc();
            $case = app(AuthorReceiveSessionException::class)->productNoData(
                $session,
                [(int) $epc->getKey()],
                auth()->user(),
            );
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame(1, ReceiveSessionExceptionQuery::floorBadgeCounts($session->fresh())['no_data']);

            Livewire::test(ScanInWorkstation::class, ['sessionId' => $session->getKey()])
                ->assertSuccessful()
                ->assertDontSee('No data 1')
                ->assertDontSee('Shortage 0')
                ->assertDontSee('Quarantine 0');

            Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertDontSee('No data 1')
                ->assertDontSee('Shortage 0');

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertDontSee('No data 1')
                ->assertDontSee('Shortage 0');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function exceptions_list_shows_nonzero_type_chips_only(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());
            app(ExceptionTypeSeeder::class)->run();

            TenantSettings::forTenant(tenant())->setReceivingEdgeMode(ReceivingEdgeMode::UnitsOnly);
            tenant()->save();

            $siteId = $this->eligibleReceiveSiteId();
            $this->assertNotNull($siteId);
            $session = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionIds[] = (int) $session->getKey();

            $epc = $this->createSgtinEpc();
            $case = app(AuthorReceiveSessionException::class)->productNoData(
                $session,
                [(int) $epc->getKey()],
                auth()->user(),
            );
            $this->caseIds[] = (int) $case->getKey();

            $inbox = Livewire::test(ListExceptions::class)->assertSuccessful();
            $noDataCount = $inbox->instance()->receiveExceptionBadgeCounts()['no_data'];
            $this->assertGreaterThanOrEqual(1, $noDataCount);

            $html = $inbox
                ->assertSee('No data '.$noDataCount)
                ->assertDontSee('Shortage 0')
                ->assertDontSee('Wrong site 0')
                ->assertDontSee('Document hold 0')
                ->html();

            $this->assertStringContainsString('PRODUCT_NO_DATA', $html);
        } finally {
            $this->cleanup();
        }
    }

    private function eligibleReceiveSiteId(): ?int
    {
        $sites = EligibleReceiveSites::organizationOptions();

        return $sites === [] ? null : (int) array_key_first($sites);
    }

    private function createSgtinEpc(): Epc
    {
        do {
            $serial = (string) random_int(10_000_000_000_000, 99_999_999_999_999);
            $uri = 'urn:epc:id:sgtin:030116.0200116.'.$serial;
        } while (Epc::query()->where('epc_uri', $uri)->exists());

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'chip-place-'.Str::uuid().'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
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
        $this->prepareDemo2ReceivingState();

        TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
        $tenant->save();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->caseIds !== []) {
            QuarantineHold::query()->whereIn('exception_id', $this->caseIds)->delete();
            ExceptionActivity::query()->whereIn('exception_id', $this->caseIds)->delete();
            foreach ($this->caseIds as $caseId) {
                ExceptionCase::query()->find($caseId)?->epcs()->detach();
            }
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        $epcIds = array_values(array_unique(array_map(intval(...), $this->epcIds)));
        if ($epcIds !== []) {
            DB::table('quarantine_holds')->whereIn('epc_id', $epcIds)->delete();
            if (Schema::hasTable('exception_epcs')) {
                DB::table('exception_epcs')->whereIn('epc_id', $epcIds)->delete();
            }
        }

        foreach ($this->sessionIds as $sessionId) {
            $this->deleteReceivingSessionForIsolation($sessionId);
        }
        $this->sessionIds = [];

        foreach ($epcIds as $epcId) {
            ReceivingScanLine::query()->where('epc_id', $epcId)->delete();
            if (! DB::table('event_epcs')->where('epc_id', $epcId)->exists()
                && ! DB::table('document_epcs')->where('epc_id', $epcId)->exists()) {
                Epc::query()->whereKey($epcId)->delete();
            }
        }
        $this->epcIds = [];

        foreach ($this->userIds as $userId) {
            User::query()->whereKey($userId)->delete();
        }
        $this->userIds = [];

        tenancy()->end();
    }
}
