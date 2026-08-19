<?php

namespace Tests\Unit\Support\Labeling;

use App\Enums\ClientPrintBridge;
use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\Labeling\ResolveClientPrintBridge;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveClientPrintBridgeTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?ClientPrintBridge $priorTenantBridge = null;

    #[Test]
    public function client_print_session_override_wins_over_tenant_default(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)
                ->setClientPrintBridge(ClientPrintBridge::NetworkTcp);
            $tenant->save();

            $resolve = app(ResolveClientPrintBridge::class);
            $resolve->setSessionOverride(ClientPrintBridge::QzTray);

            $this->assertSame(ClientPrintBridge::QzTray, $resolve->handle());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_clear_session_restores_tenant_default(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)
                ->setClientPrintBridge(ClientPrintBridge::ZebraBrowserPrint);
            $tenant->save();

            $resolve = app(ResolveClientPrintBridge::class);
            $resolve->setSessionOverride(ClientPrintBridge::QzTray);
            $resolve->setSessionOverride(null);

            $this->assertSame(ClientPrintBridge::ZebraBrowserPrint, $resolve->handle());
            $this->assertNull(Session::get(ResolveClientPrintBridge::SESSION_KEY));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_tenant_settings_bridge_round_trips(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)
                ->setClientPrintBridge(ClientPrintBridge::QzTray);
            $tenant->save();

            $settings = TenantSettings::forTenant($tenant->fresh());

            $this->assertSame(ClientPrintBridge::QzTray, $settings->clientPrintBridge());

            $modifiedTenant = $settings->tenant();
            $settings->setClientPrintBridge('zebra_browser_print');
            $modifiedTenant?->save();

            $this->assertSame(
                ClientPrintBridge::ZebraBrowserPrint,
                TenantSettings::forTenant($tenant->fresh())->clientPrintBridge(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_explicit_override_wins_over_session_and_tenant(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)
                ->setClientPrintBridge(ClientPrintBridge::NetworkTcp);
            $tenant->save();

            $resolve = app(ResolveClientPrintBridge::class);
            $resolve->setSessionOverride(ClientPrintBridge::QzTray);

            $this->assertSame(
                ClientPrintBridge::ZebraBrowserPrint,
                $resolve->handle(ClientPrintBridge::ZebraBrowserPrint),
            );
        } finally {
            $this->cleanup($tenant);
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

        $this->priorTenantBridge = TenantSettings::forTenant($tenant)->clientPrintBridge();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        Session::forget(ResolveClientPrintBridge::SESSION_KEY);

        if (tenancy()->initialized && $this->priorTenantBridge !== null) {
            TenantSettings::forTenant($tenant)
                ->setClientPrintBridge($this->priorTenantBridge);
            $tenant->save();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorTenantBridge = null;
    }
}
