<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\Floor\FloorShell;
use App\Support\Marketing\LegalDocumentUrls;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhoneLoginLayoutTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function phone_login_uses_floor_guest_layout_without_profile_footer_links(): void
    {
        $this->ensureDemo2Tenant();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->withUnencryptedCookie(FloorShell::VIEWPORT_COOKIE, FloorShell::VIEWPORT_PHONE)
            ->get('https://'.self::DEMO2_DOMAIN.'/login', [
                'HTTP_HOST' => self::DEMO2_DOMAIN,
            ])
            ->assertOk()
            ->assertSeeHtml('tp-login-page')
            ->assertSeeHtml('tp-login-phone-chrome')
            ->assertSeeHtml('images/brand/logo.svg', false)
            ->assertDontSeeHtml('<header class="fi-simple-header">')
            ->assertSeeHtml('btn btn-primary btn-block')
            ->assertSeeHtml('tp-floor-guest-login-footer')
            ->assertSee('Sign in')
            ->assertSee('Terms of Service')
            ->assertSee('Privacy Policy')
            ->assertSee(LegalDocumentUrls::termsUrl(), false)
            ->assertSee(LegalDocumentUrls::privacyUrl(), false)
            ->assertDontSee('My profile')
            ->assertDontSee('Getting started')
            ->assertDontSee('Settings');
    }

    #[Test]
    public function desktop_login_keeps_guest_legal_footer_and_documentation(): void
    {
        $this->ensureDemo2Tenant();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->withUnencryptedCookie(FloorShell::VIEWPORT_COOKIE, FloorShell::VIEWPORT_DESKTOP)
            ->get('https://'.self::DEMO2_DOMAIN.'/login', [
                'HTTP_HOST' => self::DEMO2_DOMAIN,
            ])
            ->assertOk()
            ->assertSeeHtml('tp-login-page')
            ->assertSeeHtml('fi-logo')
            ->assertSeeHtml('tp-floor-guest-footer-desktop')
            ->assertSee('Documentation')
            ->assertSee('Terms of Service')
            ->assertSee('Privacy Policy')
            ->assertDontSee('My profile')
            ->assertDontSee('Getting started');
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
