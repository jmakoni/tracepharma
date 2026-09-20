<?php

namespace Tests\Feature\Exceptions;

use App\Domain\Gs1\CheckDigit;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gs1IdentityStatus;
use Database\Seeders\ExceptionCaseSeeder;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnknownGlnRegisterSglnCopyTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function register_unknown_gln_as_partner_says_sgln_is_still_missing(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $body12 = '555555'.str_pad((string) random_int(200000, 899999), 6, '0', STR_PAD_LEFT);
            $gln = $body12.CheckDigit::mod10($body12);
            $type = ExceptionType::query()->where('code', 'UNKNOWN_GLN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GLN encountered',
                'description' => "Unknown GLN: {$gln}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertActionVisible('registerGln')
                ->callAction('registerGln', [
                    'register_as' => 'trading_partner',
                    'gln' => $gln,
                    'name' => 'Unknown GLN partner '.uniqid(),
                    'partner_type' => PartnerType::Other->value,
                    'also_resolve' => false,
                    'resolution_notes' => 'Registered missing GLN.',
                ])
                ->assertHasNoActionErrors()
                ->assertNotified();

            $partner = TradingPartner::query()->where('gln', $gln)->first();
            $this->assertNotNull($partner);
            $this->partnerIds[] = (int) $partner->getKey();
            $this->assertNull($partner->sgln);
            $this->assertStringContainsString(
                'missingSglnAfterRegisterBody',
                (string) file_get_contents(base_path('app/Filament/App/Resources/Exceptions/Actions/CorrectUnknownGlnAction.php')),
            );
            $this->assertSame(
                'SGLN is still missing — ship/receive authoring will wait for an inbound EPCIS or a pasted URN.',
                Gs1IdentityStatus::missingSglnAfterRegisterBody(),
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

        tenancy()->initialize($tenant);

        if (! self::$demo2TenantReady) {
            app(ExceptionTypeSeeder::class)->run();
            ExceptionCaseSeeder::ensureResolutionCatalog();
            self::$demo2TenantReady = true;
        }

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->caseIds !== []) {
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            $this->partnerIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();
    }
}
