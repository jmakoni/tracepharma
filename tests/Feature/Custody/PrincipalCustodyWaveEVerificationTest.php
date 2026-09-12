<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Principal;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Custody\PrincipalCustody;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

/**
 * Wave E GTM freeze smoke — flag-OFF regression + lean flag-ON gate.
 *
 * Full happy-path chain (receive stamp, cross-principal ship deny, exception
 * principal inherit, agent ship seller GLN) is covered by:
 * - PrincipalCustodyEnforcementTest
 * - PrincipalAgentWaveCTest
 */
class PrincipalCustodyWaveEVerificationTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    private ?bool $priorEnforced = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $principalIds = [];

    /** @var list<int> */
    private array $receiveSessionIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function flag_off_scan_in_opens_without_principal(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            $this->actingAs($this->createOwner());
            TenantSettings::forTenant($tenant)->setPrincipalCustodyEnforced(false);
            $tenant->save();

            $this->assertFalse(PrincipalCustody::forTenant()->isEnforced());

            [$site] = $this->createSites($tenant);

            $receive = app(OpenScanFirstReceivingSession::class)->handle(
                siteId: (int) $site->getKey(),
                openedBy: auth()->id(),
            );
            $this->receiveSessionIds[] = (int) $receive->getKey();

            $this->assertNull($receive->principal_id);
            $this->assertSame((int) $site->getKey(), (int) $receive->site_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function flag_on_requires_principal_for_ship_open(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            $this->actingAs($this->createOwner());
            TenantSettings::forTenant($tenant)->setPrincipalCustodyEnforced(true);
            $tenant->save();

            $this->assertTrue(PrincipalCustody::forTenant()->isEnforced());

            [$site] = $this->createSites($tenant);

            try {
                app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
                $this->fail('Expected ship open to require a principal when custody is enforced.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Principal custody is enforced', $e->getMessage());
            }

            $principal = $this->createPrincipal('WaveE Client');
            $ship = app(OpenOutboundShippingSession::class)->handle(
                siteId: (int) $site->getKey(),
                principalId: (int) $principal->getKey(),
            );
            $this->sessionIds[] = (int) $ship->getKey();
            $this->assertSame((int) $principal->getKey(), (int) $ship->principal_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Logistics3pl);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function createPrincipal(string $name): Principal
    {
        $principal = Principal::query()->create([
            'name' => $name.' '.Str::random(4),
            'gln' => null,
            'is_active' => true,
        ]);
        $this->principalIds[] = (int) $principal->getKey();

        return $principal;
    }

    /**
     * @return array{0: Site}
     */
    private function createSites(Tenant $tenant): array
    {
        $site = Site::query()->create([
            'name' => 'WaveE Custody Site '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
            'principal_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $settings->setDefaultShipFromSiteId((int) $site->getKey());
        $settings->setDefaultReceiveSiteId((int) $site->getKey());
        $tenant->save();

        return [$site];
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo 2',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $this->priorProfile = $tenant->profile instanceof TenantProfile
                ? $tenant->profile
                : TenantProfile::tryFrom((string) $tenant->profile);
            $tenant->forceFill(['profile' => $profile])->save();
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
        $this->priorEnforced = TenantSettings::forTenant($tenant)->principalCustodyEnforced();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            try {
                $settings = TenantSettings::forTenant($tenant);
                if ($this->priorEnforced !== null) {
                    $settings->setPrincipalCustodyEnforced($this->priorEnforced);
                }
                if ($this->priorDefaultShipFromSiteId !== null) {
                    $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
                }
                if ($this->priorDefaultReceiveSiteId !== null) {
                    $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
                }
                $tenant->save();

                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->each(
                    function (OutboundShippingSession $session): void {
                        $session->scanLines()->delete();
                        $session->delete();
                    },
                );
                $this->sessionIds = [];

                foreach ($this->receiveSessionIds as $id) {
                    $this->deleteReceivingSessionForIsolation($id);
                }
                $this->receiveSessionIds = [];

                if ($this->siteIds !== []) {
                    Site::query()->whereIn('id', $this->siteIds)->update(['principal_id' => null]);
                    Site::query()->whereIn('id', $this->siteIds)->delete();
                    $this->siteIds = [];
                }

                if ($this->principalIds !== []) {
                    Principal::query()->whereIn('id', $this->principalIds)->delete();
                    $this->principalIds = [];
                }

                if ($this->userIds !== []) {
                    User::query()->whereIn('id', $this->userIds)->delete();
                    $this->userIds = [];
                }
            } finally {
                $this->priorEnforced = null;
                $this->priorDefaultShipFromSiteId = null;
                $this->priorDefaultReceiveSiteId = null;
            }
        }

        tenancy()->end();

        if ($this->priorProfile !== null) {
            Tenant::query()->whereKey(self::DEMO2_TENANT_ID)->update([
                'profile' => $this->priorProfile,
            ]);
            $this->priorProfile = null;
        }
    }
}
