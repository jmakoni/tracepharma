<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\ApiTokens;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\SanctumAbilities;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiTokensPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $tokenIds = [];

    #[Test]
    public function valid_abilities_create_a_token(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $this->actingAs($owner);

            Livewire::test(ApiTokens::class)
                ->callTableAction('createToken', data: [
                    'token_name' => 'Allowed token',
                    'abilities' => [SanctumAbilities::EPCIS_VIEW],
                    'expires_at' => now()->addDays(30)->toDateString(),
                ])
                ->assertNotified();

            $token = PersonalAccessToken::query()->latest('id')->first();
            $this->assertNotNull($token);
            $this->tokenIds[] = (int) $token->getKey();
            $this->assertSame([SanctumAbilities::EPCIS_VIEW], $token->abilities);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function api_tokens_page_documents_dispense_check_grant_command(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $this->actingAs($owner);

            Livewire::test(ApiTokens::class)
                ->assertSee('tracepharma:grant-dispense-check-ability')
                ->assertSee('vrs:dispense-check')
                ->assertSee('--dry-run');
        } finally {
            $this->cleanup();
        }
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

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

        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->tokenIds !== []) {
            PersonalAccessToken::query()->whereKey($this->tokenIds)->delete();
        }

        $this->tokenIds = [];

        Auth::logout();
        tenancy()->end();
    }
}
