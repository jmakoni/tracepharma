<?php

namespace Tests\Feature\Shipping;

use App\Domain\Gs1\CheckDigit;
use App\Enums\TenantProfile;
use App\Models\Principal;
use App\Models\Tenant;
use App\Support\Epcis\BuildFullHistoryShippingEpcisXml;
use App\Support\Gs1\Sgln;
use App\Support\TenantSettings;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class FullHistoryPrincipalSglnTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $principalIds = [];

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function party_from_principal_throws_when_gln_has_no_recorded_sgln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)
                ->setGln('0399991000008')
                ->setCompanyPrefix('0399991')
                ->setAllowAssignPartnerGlnsFromPrefix(false);
            $tenant->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $locationRef = str_pad((string) random_int(200000, 899999), 6, '0', STR_PAD_LEFT);
            $gln = $this->glnUnderPrefix('555555', $locationRef);
            $guessed = Sgln::toUrn($gln, 6, '0');
            $this->assertNotNull($guessed);

            $principal = Principal::query()->create([
                'name' => 'Full-history principal '.uniqid(),
                'gln' => $gln,
                'is_active' => true,
            ]);
            $this->principalIds[] = (int) $principal->getKey();

            try {
                $this->partyFromPrincipal($principal);
                $this->fail('Expected DomainException when principal SGLN cannot be resolved.');
            } catch (DomainException $e) {
                $this->assertStringContainsString($gln, $e->getMessage());
                $this->assertStringContainsString('theirs to state', $e->getMessage());
                $this->assertStringNotContainsString((string) $guessed, $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function full_history_authoring_does_not_walk_prefix_lengths(): void
    {
        $source = (string) file_get_contents(base_path('app/Support/Epcis/BuildFullHistoryShippingEpcisXml.php'));

        $this->assertStringNotContainsString('foreach ([6, 7, 8, 9, 10, 11, 12]', $source);
        $this->assertStringNotContainsString('Sgln::toUrn($gln, $prefixLength', $source);
    }

    /**
     * @return array{gln: string, sgln: string, name: string, street: string, city: string, state: string, postal: string, country: string}
     */
    private function partyFromPrincipal(Principal $principal): array
    {
        $method = new ReflectionMethod(BuildFullHistoryShippingEpcisXml::class, 'partyFromPrincipal');

        return $method->invoke(
            app(BuildFullHistoryShippingEpcisXml::class),
            $principal,
            'Principal owning party',
        );
    }

    private function glnUnderPrefix(string $prefix, string $locationRef): string
    {
        $body12 = $prefix.$locationRef;
        $this->assertSame(12, strlen($body12));

        return $body12.CheckDigit::mod10($body12);
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

        $settings = TenantSettings::forTenant($tenant);
        $this->priorGln = $settings->gln();
        $this->priorCompanyPrefix = $settings->companyPrefix();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant->fresh() ?? $tenant);
        }

        if ($this->principalIds !== []) {
            Principal::query()->whereIn('id', $this->principalIds)->delete();
            $this->principalIds = [];
        }

        $current = $tenant->fresh() ?? $tenant;
        $current->forceFill([
            'gln' => $this->priorGln,
            'company_prefix' => $this->priorCompanyPrefix,
        ])->save();

        tenancy()->end();
    }
}
