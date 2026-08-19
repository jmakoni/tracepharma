<?php

namespace Tests\Unit\Support\Tracing;

use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use App\Support\Tracing\EpcContextLinks;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcContextLinksTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    private ?int $transferSessionId = null;

    private ?int $transferDocumentId = null;

    private ?int $epcId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function in_transit_transfer_offers_open_receive_and_open_transfer(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(10000000, 99999999), 0, 6).'.CL'.random_int(10000000, 99999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
                openedBy: (int) $user->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            app(ConfirmTransferringScan::class)->handle($transfer, $uri, (int) $user->getKey());
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $links = collect(app(EpcContextLinks::class)->forEpc($epc->fresh(), $uri, (int) $user->getKey()))
                ->keyBy('key');

            $this->assertTrue($links->has('open_receive'));
            $this->assertTrue($links->has('open_transfer'));
            $this->assertStringContainsString(
                (string) $this->transferSessionId,
                (string) $links['open_transfer']['url'],
            );
            $this->assertStringContainsString(
                TransferringSessionResource::getSlug(),
                (string) $links['open_transfer']['url'],
            );

            if (VerifyProduct::canAccess()) {
                $this->assertTrue($links->has('verify_product'));
                $this->assertNotNull($links['verify_product']['url']);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function bare_gtin_without_serial_or_session_returns_no_links(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $epc = new Epc([
                'epc_type' => 'sgtin',
                'gtin14' => '30301164005087',
            ]);

            $this->assertSame([], app(EpcContextLinks::class)->forEpc($epc));
        } finally {
            tenancy()->end();
        }
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();

        $fromSite = Site::query()->create([
            'name' => 'Context Links From '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Context Links To '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $toSite->getKey();

        $settings->setDefaultShipFromSiteId((int) $fromSite->getKey());
        $settings->setDefaultReceiveSiteId((int) $toSite->getKey());
        $tenant->save();

        return [$fromSite, $toSite];
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->transferDocumentId !== null) {
            EpcisDocument::query()->whereKey($this->transferDocumentId)->delete();
            $this->transferDocumentId = null;
        }

        if ($this->transferSessionId !== null) {
            TransferringSession::query()->whereKey($this->transferSessionId)->delete();
            $this->transferSessionId = null;
        }

        if ($this->epcId !== null) {
            Epc::query()->whereKey($this->epcId)->delete();
            $this->epcId = null;
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        $settings = TenantSettings::forTenant($tenant);
        $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
        $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
        $tenant->save();

        tenancy()->end();
    }
}
