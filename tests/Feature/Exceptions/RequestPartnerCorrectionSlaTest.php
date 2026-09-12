<?php

declare(strict_types=1);

namespace Tests\Feature\Exceptions;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Notifications\DscsaExceptionSupplierMail;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Exceptions\InvestigatorSlaClock;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RequestPartnerCorrectionSlaTest extends TestCase
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
    public function emailing_supplier_from_partner_correction_starts_investigator_sla_clock(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            Notification::fake();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $partner = TradingPartner::query()->create([
                'name' => 'Partner Correction SLA '.substr((string) str()->uuid(), 0, 8),
                'partner_type' => 'manufacturer',
                'email' => 'partner-sla-'.substr((string) str()->uuid(), 0, 8).'@demo.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $type = ExceptionType::query()->where('code', 'BROKEN_AGGREGATION')->first();
            if ($type === null) {
                (new ExceptionTypeSeeder)->run();
                $type = ExceptionType::query()->where('code', 'BROKEN_AGGREGATION')->first();
            }
            $this->assertNotNull($type);

            $created = now()->subHours(80);
            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'trading_partner_id' => $partner->getKey(),
                'title' => 'Partner correction must start SLA',
                'status' => ExceptionStatus::Investigating,
                'severity' => ExceptionSeverity::High,
                'due_at' => null,
            ]);
            $this->caseIds[] = (int) $case->getKey();
            $case->forceFill(['created_at' => $created])->save();

            $this->assertTrue((new InvestigatorSlaClock)->isBreached($case->fresh()));

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('requestPartnerCorrection')
                ->callAction('requestPartnerCorrection', [
                    'body' => 'Please correct the aggregation hierarchy and resend EPCIS.',
                    'email_supplier' => true,
                ])
                ->assertHasNoActionErrors()
                ->assertNotified();

            $fresh = $case->fresh();
            $this->assertNotNull($fresh?->due_at);
            $this->assertTrue($fresh->due_at->greaterThan(now()->addHours(InvestigatorSlaClock::HOURS - 1)));
            $this->assertFalse((new InvestigatorSlaClock)->isBreached($fresh));

            Notification::assertSentOnDemand(
                DscsaExceptionSupplierMail::class,
                fn (DscsaExceptionSupplierMail $mail): bool => $mail->case->is($case),
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
            ]));
        }

        if (! self::$demo2TenantReady) {
            if (! $tenant->domains()->where('domain', self::DEMO2_DOMAIN)->exists()) {
                $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
            }

            $tenant->update([
                'tenancy_db_name' => self::DEMO2_DATABASE,
                'profile' => TenantProfile::Pharmacy,
            ]);

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if ($this->caseIds !== []) {
            ExceptionCase::query()->whereIn('id', $this->caseIds)->forceDelete();
        }
        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->forceDelete();
        }
        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->forceDelete();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
