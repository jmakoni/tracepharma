<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Sites\Pages\CreateSite;
use App\Filament\App\Resources\TradingPartners\Pages\ViewTradingPartner;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteTradingPartnerIdentifierPersistTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094229';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function create_site_persists_duns_dea_and_hin(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            $suffix = Str::lower(Str::random(6));
            $gln = $this->uniqueGln('40');

            Livewire::test(CreateSite::class)
                ->fillForm([
                    'name' => 'Identifier Site '.$suffix,
                    'gln' => $gln,
                    'duns_number' => '803736404',
                    'dea_number' => 'RS1234563',
                    'hin_number' => 'H123456789',
                    'is_active' => true,
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $site = Site::query()->where('name', 'Identifier Site '.$suffix)->first();
            $this->assertNotNull($site);
            $this->siteIds[] = (int) $site->id;

            $this->assertSame('803736404', $site->duns_number);
            $this->assertSame('RS1234563', $site->dea_number);
            $this->assertSame('H123456789', $site->hin_number);
            $this->assertSame($gln, $site->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function edit_trading_partner_persists_duns_dea_and_hin(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            $suffix = Str::lower(Str::random(6));
            $gln = $this->uniqueGln('41');

            $partner = TradingPartner::query()->create([
                'name' => 'Identifier Partner '.$suffix,
                'gln' => $gln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->id;

            Livewire::test(ViewTradingPartner::class, ['record' => $partner->getKey()])
                ->mountAction('edit')
                ->fillForm([
                    'duns_number' => '012430880',
                    'dea_number' => 'RW9876543',
                    'hin_number' => 'H987654321',
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner->refresh();
            $this->assertSame('012430880', $partner->duns_number);
            $this->assertSame('RW9876543', $partner->dea_number);
            $this->assertSame('H987654321', $partner->hin_number);
            $this->assertSame($gln, $partner->gln);
        } finally {
            $this->cleanup();
        }
    }

    private function actAsOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'id-persist-'.Str::lower(Str::random(10)).'@example.test',
        ]);
        $this->userIds[] = (int) $user->id;
        $user->syncRoles([TenantRole::Owner->value]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $user;
    }

    private function uniqueGln(string $marker): string
    {
        do {
            $body = self::GLN_PREFIX.$marker.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists()
            || TradingPartner::query()->where('gln', $gln)->exists());

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
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }
            if ($this->partnerIds !== []) {
                Site::query()->whereIn('trading_partner_id', $this->partnerIds)->delete();
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            }
            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }
        }

        $this->siteIds = [];
        $this->partnerIds = [];
        $this->userIds = [];

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
