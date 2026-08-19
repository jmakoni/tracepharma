<?php

namespace Tests\Feature\Exceptions;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExceptionCreated;
use App\Services\Exceptions\ExceptionService;
use App\Support\Auth\TenantRoleSeeder;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExceptionCreatedMailTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $signalIds = [];

    #[Test]
    public function create_from_signal_sends_exception_created_mail_to_owners(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $owner = User::query()->first() ?? User::factory()->create([
                'email' => 'owner-exception-mail@demo.test',
            ]);
            $owner->syncRoles([TenantRole::Owner->value]);

            Notification::fake();

            $signal = EpcisException::query()->create([
                'exception_type' => 'ingest_failure',
                'severity' => 'error',
                'description' => 'Parse failed',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = app(ExceptionService::class)->createFromSignal($signal);
            $this->caseIds[] = (int) $case->getKey();

            Notification::assertSentTo($owner, ExceptionCreated::class);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function created_mail_cta_uses_the_tenant_host(): void
    {
        config(['app.url' => 'https://admin2.localhost']);

        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $owner = User::query()->first() ?? User::factory()->create([
                'email' => 'owner-exception-mail-cta@demo.test',
            ]);
            $owner->syncRoles([TenantRole::Owner->value]);

            $signal = EpcisException::query()->create([
                'exception_type' => 'ingest_failure',
                'severity' => 'error',
                'description' => 'Parse failed',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = app(ExceptionService::class)->createFromSignal($signal);
            $this->caseIds[] = (int) $case->getKey();

            $mail = (new ExceptionCreated($case))->toMail($owner);

            $this->assertSame(
                'https://'.self::DEMO2_DOMAIN.'/exceptions/'.$case->id,
                $mail->actionUrl,
            );
            $this->assertStringNotContainsString('admin2.localhost', (string) $mail->actionUrl);
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

        foreach ($this->signalIds as $id) {
            EpcisException::query()->whereKey($id)->update(['case_id' => null]);
            EpcisException::query()->whereKey($id)->delete();
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

        $this->signalIds = [];
        $this->caseIds = [];
        tenancy()->end();
    }
}
