<?php

declare(strict_types=1);

namespace Tests\Feature\Exceptions;

use App\Actions\Exceptions\SendDscsaExceptionEmail;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Notifications\DscsaExceptionSupplierMail;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendDscsaExceptionEmailTest extends TestCase
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
    public function send_sets_share_uuid_logs_activity_and_emails_partner(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            \Illuminate\Support\Facades\URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);

            $partner = TradingPartner::query()->create([
                'name' => 'Email Share Partner '.substr((string) str()->uuid(), 0, 8),
                'partner_type' => 'manufacturer',
                'email' => 'supplier-collab-'.substr((string) str()->uuid(), 0, 8).'@demo.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $type = ExceptionType::query()->first();
            if ($type === null) {
                (new ExceptionTypeSeeder)->run();
                $type = ExceptionType::query()->first();
            }
            $this->assertNotNull($type);

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'trading_partner_id' => $partner->getKey(),
                'title' => 'Collab email share uuid',
                'status' => ExceptionStatus::New,
                'severity' => ExceptionSeverity::Medium,
            ]);
            $this->caseIds[] = (int) $case->getKey();
            $this->assertNull($case->share_uuid);

            $user = User::factory()->create();
            $result = app(SendDscsaExceptionEmail::class)->execute($case->fresh(), $user);

            $this->assertTrue($result['sent'], $result['error'] ?? 'send failed');
            $this->assertNotNull($case->fresh()->share_uuid);
            $this->assertTrue(
                $case->fresh()->activities()
                    ->where('body', 'like', 'DSCSA exception email sent%')
                    ->exists(),
            );

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

        return $tenant;
    }

    private function cleanup(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
        if ($tenant !== null && ! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }

        if (! tenancy()->initialized) {
            return;
        }

        if ($this->caseIds !== []) {
            ExceptionCase::query()->whereIn('id', $this->caseIds)->each(function (ExceptionCase $case): void {
                $case->activities()->delete();
                $case->delete();
            });
            $this->caseIds = [];
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            $this->partnerIds = [];
        }

        tenancy()->end();
    }
}
