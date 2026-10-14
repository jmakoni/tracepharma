<?php

namespace Tests\Feature\Handheld;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\Floor\HandheldPwa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandheldPwaRoutesTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function central_manifest_starts_at_the_chooser(): void
    {
        $response = $this->get('http://localhost/manifest.webmanifest');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json');
        $response->assertJsonPath('name', 'TracePharma Floor');
        $response->assertJsonPath('display', 'standalone');
        $response->assertJsonPath('start_url', 'http://localhost/handheld');
        $response->assertJsonPath('scope', 'http://localhost/');
    }

    #[Test]
    public function asset_links_are_public_on_the_central_host(): void
    {
        config(['tracepharma.handheld.sha256_cert_fingerprints' => ['11:22']]);

        $response = $this->get('http://localhost/.well-known/assetlinks.json');

        $response->assertOk();
        $response->assertJsonPath('0.target.package_name', HandheldPwa::PACKAGE_NAME);
        $response->assertJsonPath('0.target.sha256_cert_fingerprints.0', '11:22');
    }

    #[Test]
    public function chooser_renders_on_the_central_host(): void
    {
        $response = $this->get('http://localhost/handheld');

        $response->assertOk();
        $response->assertSee('Open floor');
        $response->assertSee('demo2.internal.vatengi.com');
    }

    #[Test]
    public function chooser_rejects_unknown_hosts(): void
    {
        $response = $this->from('http://localhost/handheld')
            ->post('http://localhost/handheld', ['host' => 'evil.com']);

        $response->assertOk();
        $response->assertSee('Enter a TracePharma floor host');
    }

    #[Test]
    public function chooser_redirects_allowed_internal_hosts_to_login(): void
    {
        $response = $this->from('http://localhost/handheld')
            ->post('http://localhost/handheld', ['host' => 'demo2.internal.vatengi.com']);

        $response->assertRedirect('https://demo2.internal.vatengi.com/login');
    }

    #[Test]
    public function chooser_host_root_opens_the_picker(): void
    {
        $chooser = (string) config('tracepharma.floor_chooser_domain');

        $this->get('https://'.$chooser.'/')
            ->assertRedirect('https://'.$chooser.'/handheld');
    }

    #[Test]
    public function chooser_prefills_an_allowed_host_query(): void
    {
        $this->get('http://localhost/handheld?host=demo2.internal.vatengi.com')
            ->assertOk()
            ->assertSee('value="demo2.internal.vatengi.com"', false);
    }

    #[Test]
    public function tenant_manifest_starts_at_floor(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $response = $this->get('https://demo2.internal.vatengi.com/manifest.webmanifest');

            $response->assertOk();
            $response->assertJsonPath('start_url', 'https://demo2.internal.vatengi.com/floor');
            $response->assertJsonPath('display', 'standalone');
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_chooser_path_redirects_to_floor(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->get('https://demo2.internal.vatengi.com/handheld')
                ->assertRedirect('/floor');
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_login_includes_pwa_head_tags(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->get('https://demo2.internal.vatengi.com/login')
                ->assertOk()
                ->assertSee('rel="manifest"', false)
                ->assertSee('/manifest.webmanifest', false)
                ->assertSee('mobile-web-app-capable', false);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_serves_asset_links(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->get('https://demo2.internal.vatengi.com/.well-known/assetlinks.json')
                ->assertOk()
                ->assertJsonPath('0.target.package_name', HandheldPwa::PACKAGE_NAME);
        } finally {
            tenancy()->end();
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
}
