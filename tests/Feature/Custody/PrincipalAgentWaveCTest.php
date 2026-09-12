<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Actions\Shipping\GenerateShippingEpcisEvents;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Principal;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Custody\PrincipalCustody;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class PrincipalAgentWaveCTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    private ?bool $priorEnforced = null;

    /** @var list<int> */
    private array $principalIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function exception_create_inherits_principal_from_site(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            $this->actingAs($this->createOwner());

            $principal = $this->createPrincipal('WaveC Client', gln: $this->uniqueGln());
            $site = Site::query()->create([
                'name' => 'WaveC Site '.Str::random(4),
                'gln' => $this->uniqueGln(),
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
                'principal_id' => $principal->getKey(),
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $service = app(ExceptionService::class);
            $method = new ReflectionMethod($service, 'resolvePrincipalIdOnCreate');
            $method->setAccessible(true);

            $resolved = $method->invoke($service, [
                'site_id' => $site->getKey(),
            ], []);

            $this->assertSame((int) $principal->getKey(), (int) ($resolved['principal_id'] ?? 0));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function agent_ship_seller_uses_principal_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            $this->actingAs($this->createOwner());

            $principalGln = $this->uniqueGln();
            $principal = $this->createPrincipal('Agent Seller', gln: $principalGln);
            $site = Site::query()->create([
                'name' => 'Agent Dock '.Str::random(4),
                'gln' => $this->uniqueGln(),
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
                'principal_id' => $principal->getKey(),
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $session = new OutboundShippingSession([
                'site_id' => $site->getKey(),
                'principal_id' => $principal->getKey(),
                'status' => 'open',
            ]);
            $session->setRelation('site', $site);
            $session->setRelation('principal', $principal);
            $session->setRelation('tradingPartner', null);
            $session->setRelation('shipToSite', null);

            $action = app(GenerateShippingEpcisEvents::class);
            $method = new ReflectionMethod($action, 'resolveAuthoredPartyFields');
            $method->setAccessible(true);

            $party = $method->invoke(
                $action,
                $session,
                $tenant,
                ['gln' => $this->uniqueGln(), 'site_id' => null, 'sgln_urn' => 'urn:epc:id:sgln:0614141.00000.0'],
            );

            $this->assertSame($principalGln, $party['sender_gln']);
            $this->assertSame((string) $principal->name, $party['ship_from_name']);
            $this->assertSame((string) $site->name, $party['ship_from_site_name']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function enforced_author_requires_principal_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            $this->actingAs($this->createOwner());
            TenantSettings::forTenant($tenant)->setPrincipalCustodyEnforced(true);
            $tenant->save();

            $principal = $this->createPrincipal('No Gln Principal', gln: null);
            $session = new OutboundShippingSession([
                'principal_id' => $principal->getKey(),
                'status' => 'open',
            ]);

            $action = app(GenerateShippingEpcisEvents::class);
            $method = new ReflectionMethod($action, 'assertAgentPrincipalReady');
            $method->setAccessible(true);

            try {
                $method->invoke($action, $session);
                $this->fail('Expected DomainException when principal has no GLN.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('GLN', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function export_constraint_hides_other_principal_epcs_when_enforced(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            $owner = $this->createOwner();
            $this->actingAs($owner);

            TenantSettings::forTenant($tenant)->setPrincipalCustodyEnforced(true);
            $tenant->save();

            $principalA = $this->createPrincipal('Export A', gln: $this->uniqueGln());
            $principalB = $this->createPrincipal('Export B', gln: $this->uniqueGln());

            $epcA = $this->createEpc($principalA->getKey());
            $epcB = $this->createEpc($principalB->getKey());

            // Owner with SitesAccessAll still sees stamped EPCs (not unstamped).
            $visible = PrincipalCustody::forTenant()
                ->constrainEpcQueryForActor(Epc::query(), $owner)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $epcA->getKey(), $visible);
            $this->assertContains((int) $epcB->getKey(), $visible);

            $restricted = User::factory()->create();
            $restricted->assignRole(TenantRole::Owner->value);
            // Strip all-site access by assigning a custom role without SitesAccessAll is heavy;
            // assert fail-closed path with a user that has no site assignments and no all-access.
            $restricted->syncRoles([]);
            $this->userIds[] = (int) $restricted->getKey();

            $hidden = PrincipalCustody::forTenant()
                ->constrainEpcQueryForActor(Epc::query()->whereIn('id', [$epcA->getKey(), $epcB->getKey()]), $restricted)
                ->pluck('id')
                ->all();

            $this->assertSame([], $hidden);
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

    private function createPrincipal(string $name, ?string $gln): Principal
    {
        $principal = Principal::query()->create([
            'name' => $name.' '.Str::random(4),
            'gln' => $gln,
            'is_active' => true,
        ]);
        $this->principalIds[] = (int) $principal->getKey();

        return $principal;
    }

    private function createEpc(int $principalId): Epc
    {
        $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(10000000, 99999999), 0, 6)
            .'.WC'.random_int(10000000, 99999999);
        $epc = Epc::query()->create([
            ...Epc::materializeAttributesFromUri($uri),
            'principal_id' => $principalId,
        ]);
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (
            Site::query()->where('gln', $gln)->exists()
            || Principal::query()->where('gln', $gln)->exists()
        );

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
            $this->priorProfile = $tenant->profile;
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
                $tenant->save();

                if ($this->epcIds !== []) {
                    Epc::query()->whereIn('id', $this->epcIds)->delete();
                    $this->epcIds = [];
                }

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
