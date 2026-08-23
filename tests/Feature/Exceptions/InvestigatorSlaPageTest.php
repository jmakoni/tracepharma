<?php

namespace Tests\Feature\Exceptions;

use App\Actions\Exceptions\StartInvestigatorSla;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\InvestigatorSla;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Notifications\DscsaExceptionSupplierMail;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvestigatorSlaPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    #[Test]
    public function page_is_visible_beside_exceptions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsInboundIntegrations());
            $this->assertTrue(InvestigatorSla::canAccess());
            $this->assertSame('Investigator SLA', InvestigatorSla::getNavigationLabel());
            $this->assertSame('investigator-sla', InvestigatorSla::getSlug());
            $this->assertSame('Exceptions', ExceptionResource::getNavigationLabel());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function lists_blocking_case_and_emails_existing_portal(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $type = ExceptionType::query()->where('code', 'INGESTION_PARSE_ERROR')->first();
            if ($type === null) {
                (new ExceptionTypeSeeder)->run();
                $type = ExceptionType::query()->where('code', 'INGESTION_PARSE_ERROR')->first();
            }
            $this->assertNotNull($type);
            $this->assertTrue($type->receive_impact?->blocksReceiving() ?? $type->blocksReceiving());

            $partner = TradingPartner::factory()->create([
                'email' => 'investigator-poc@example.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'trading_partner_id' => $partner->getKey(),
                'title' => 'Investigator SLA blocking case',
                'status' => ExceptionStatus::New,
                'severity' => ExceptionSeverity::High,
                'due_at' => null,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(InvestigatorSla::class)
                ->assertSuccessful()
                ->assertSee('EX-'.$case->getKey())
                ->assertSee('Investigator SLA blocking case');

            Notification::fake();

            $result = app(StartInvestigatorSla::class)->handle($case->fresh(), $user);
            $this->assertTrue($result['sent'], $result['error'] ?? 'email failed');
            $this->assertNotNull($case->fresh()->due_at);

            $laterDue = now()->addHours(120);
            $case->forceFill(['due_at' => $laterDue])->save();
            $resultKeep = app(StartInvestigatorSla::class)->handle($case->fresh(), $user);
            $this->assertTrue($resultKeep['sent']);
            $this->assertTrue($case->fresh()->due_at->greaterThan(now()->addHours(100)));

            $noEmail = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'SLA no partner email',
                'status' => ExceptionStatus::New,
                'severity' => ExceptionSeverity::Medium,
                'due_at' => $laterDue,
            ]);
            $this->caseIds[] = (int) $noEmail->getKey();
            $failed = app(StartInvestigatorSla::class)->handle($noEmail->fresh(), $user);
            $this->assertFalse($failed['sent']);
            $this->assertTrue($noEmail->fresh()->due_at->greaterThan(now()->addHours(100)));

            Notification::assertSentOnDemand(
                DscsaExceptionSupplierMail::class,
                fn (DscsaExceptionSupplierMail $mail): bool => $mail->case->is($case)
                    && str_contains($mail->portalUrl, 'supplier-exceptions'),
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
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->caseIds as $caseId) {
            ExceptionActivity::query()->where('exception_id', $caseId)->delete();
            ExceptionCase::query()->whereKey($caseId)->delete();
        }
        $this->caseIds = [];

        foreach ($this->partnerIds as $partnerId) {
            TradingPartner::query()->whereKey($partnerId)->delete();
        }
        $this->partnerIds = [];

        tenancy()->end();
    }
}
