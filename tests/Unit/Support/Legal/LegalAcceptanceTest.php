<?php

namespace Tests\Unit\Support\Legal;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Legal\LegalAcceptance;
use App\Support\Marketing\PrivacyPolicy;
use App\Support\Marketing\TermsOfService;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LegalAcceptanceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function floor_user_is_not_gated(): void
    {
        $this->withDemo2Tenant(function (): void {
            $technician = $this->createTechnician();

            $this->assertFalse(LegalAcceptance::isGated($technician));
            $this->assertFalse(LegalAcceptance::isHardBlocked($technician));
        });
    }

    #[Test]
    public function gated_stale_user_starts_notice_once_and_stays_in_grace(): void
    {
        $this->withDemo2Tenant(function (): void {
            $owner = $this->createOwner();
            $started = Carbon::parse('2026-08-18 10:00:00');
            Carbon::setTestNow($started);

            $this->assertTrue(LegalAcceptance::isGated($owner));
            $this->assertTrue(LegalAcceptance::isStale($owner));
            $this->assertFalse(LegalAcceptance::isHardBlocked($owner));

            LegalAcceptance::ensureNoticeStarted($owner);
            $owner->refresh();

            $this->assertTrue($owner->legal_notice_started_at->equalTo($started));
            $this->assertTrue(LegalAcceptance::graceEndsAt($owner)?->equalTo($started->copy()->addDays(14)));
            $this->assertFalse(LegalAcceptance::isHardBlocked($owner));

            Carbon::setTestNow($started->copy()->addDay());
            LegalAcceptance::ensureNoticeStarted($owner);
            $owner->refresh();

            $this->assertTrue($owner->legal_notice_started_at->equalTo($started));
        });
    }

    #[Test]
    public function gated_user_is_hard_blocked_after_grace_days(): void
    {
        $this->withDemo2Tenant(function (): void {
            $owner = $this->createOwner();
            $started = Carbon::parse('2026-08-18 10:00:00');
            Carbon::setTestNow($started);
            LegalAcceptance::ensureNoticeStarted($owner);
            $owner->refresh();

            Carbon::setTestNow($started->copy()->addDays(14));

            $this->assertTrue(LegalAcceptance::isHardBlocked($owner));
        });
    }

    #[Test]
    public function accept_matches_current_versions_clears_notice_and_logs_activity(): void
    {
        $this->withDemo2Tenant(function (): void {
            $owner = $this->createOwner();
            LegalAcceptance::ensureNoticeStarted($owner);

            LegalAcceptance::accept($owner, '203.0.113.10', 'LegalAcceptanceTest/1.0');
            $owner->refresh();

            $this->assertTrue(LegalAcceptance::hasAcceptedCurrent($owner));
            $this->assertFalse(LegalAcceptance::isStale($owner));
            $this->assertFalse(LegalAcceptance::isHardBlocked($owner));
            $this->assertNull($owner->legal_notice_started_at);
            $this->assertSame(TermsOfService::version(), $owner->terms_version);
            $this->assertSame(PrivacyPolicy::version(), $owner->privacy_version);
            $this->assertNotNull($owner->terms_accepted_at);
            $this->assertNotNull($owner->privacy_accepted_at);

            $activity = Activity::query()
                ->where('description', 'legal_terms_accepted')
                ->where('causer_id', $owner->id)
                ->latest('id')
                ->first();

            $this->assertNotNull($activity);
            $this->assertSame('203.0.113.10', $activity->properties['ip'] ?? null);
            $this->assertSame('LegalAcceptanceTest/1.0', $activity->properties['user_agent'] ?? null);
            $this->assertSame(TermsOfService::version(), $activity->properties['terms_version'] ?? null);
        });
    }

    #[Test]
    public function version_bump_makes_accepted_user_stale_and_starts_a_new_clock(): void
    {
        $this->withDemo2Tenant(function (): void {
            $owner = $this->createOwner();
            LegalAcceptance::accept($owner, '203.0.113.10', 'LegalAcceptanceTest/1.0');
            $owner->refresh();

            $this->assertFalse(LegalAcceptance::isStale($owner));
            $this->assertNull($owner->legal_notice_started_at);

            $owner->forceFill(['terms_version' => '0.9'])->save();
            $owner->refresh();

            $this->assertTrue(LegalAcceptance::isStale($owner));
            $this->assertFalse(LegalAcceptance::isHardBlocked($owner));

            $restarted = Carbon::parse('2026-09-01 09:00:00');
            Carbon::setTestNow($restarted);
            LegalAcceptance::ensureNoticeStarted($owner);
            $owner->refresh();

            $this->assertTrue($owner->legal_notice_started_at->equalTo($restarted));
        });
    }

    /**
     * @param  callable(): void  $callback
     */
    private function withDemo2Tenant(callable $callback): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $this->markTestSkipped('Demo2 tenant not provisioned.');
        }

        if ($tenant->tenancy_db_name !== self::DEMO2_DATABASE) {
            $this->markTestSkipped('Demo2 tenant database mismatch.');
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        $tenant->run(function () use ($callback): void {
            $priorJobRolesEnabled = TenantSettings::forTenant(tenant())->jobRolesEnabled();

            try {
                $callback();
            } finally {
                TenantSettings::forTenant(tenant())->setJobRolesEnabled($priorJobRolesEnabled);
                tenant()?->save();
                Carbon::setTestNow();
            }
        });
    }

    private function createOwner(): User
    {
        $this->seedPharmacyRoles();

        $owner = User::factory()->create();
        $owner->assignRole(TenantRole::Owner->value);

        return $owner->fresh() ?? $owner;
    }

    private function createTechnician(): User
    {
        $this->seedPharmacyRoles();
        TenantSettings::forTenant(tenant())->setJobRolesEnabled(true);
        tenant()?->save();

        $technician = User::factory()->create();
        $technician->assignRole(TenantRole::ReceivingTechnician->value);

        return $technician->fresh() ?? $technician;
    }

    private function seedPharmacyRoles(): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
