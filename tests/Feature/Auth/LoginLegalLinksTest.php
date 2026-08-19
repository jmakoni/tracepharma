<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\Marketing\LegalDocumentUrls;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginLegalLinksTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function app_login_shows_terms_and_privacy_links(): void
    {
        $this->ensureDemo2Tenant();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->get('https://'.self::DEMO2_DOMAIN.'/login', [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
        ])
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Privacy Policy')
            ->assertSee(LegalDocumentUrls::termsUrl(), false)
            ->assertSee(LegalDocumentUrls::privacyUrl(), false);
    }

    #[Test]
    public function admin_login_shows_terms_and_privacy_links(): void
    {
        $adminHost = (string) config('tracepharma.admin_domain');

        $this->get('http://'.$adminHost.'/login', [
            'HTTP_HOST' => $adminHost,
        ])
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Privacy Policy')
            ->assertSee(LegalDocumentUrls::termsUrl(), false)
            ->assertSee(LegalDocumentUrls::privacyUrl(), false);
    }

    private function ensureDemo2Tenant(): Tenant
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

        return $tenant;
    }
}
