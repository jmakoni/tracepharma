<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenScanFirstReceivingSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $sessionId = null;

    /** @var list<int> */
    private array $extraSiteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?int $priorDefaultReceiveSiteId = null;

    /** @var list<int> */
    private array $disposableReceiveSiteIds = [];

    #[Test]
    public function it_creates_scan_first_session_with_null_epcis_document_id(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasColumn('receiving_sessions', 'session_kind'));
            $this->assertTrue(Schema::hasColumn('receiving_sessions', 'transferring_session_id'));
            $this->assertTrue(Schema::hasColumn('receiving_sessions', 'matched_epcis_document_id'));

            $this->ensureEligibleReceiveSite();
            $this->assertGreaterThanOrEqual(1, EligibleReceiveSites::forOrganization()->count());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $this->assertSame(ReceivingSessionKind::ScanFirst, $session->session_kind);
            $this->assertTrue($session->isScanFirst());
            $this->assertNull($session->epcis_document_id);
            $this->assertNull($session->transferring_session_id);
            $this->assertNotNull($session->site_id);
            $this->assertSame('open', $session->status);
            $this->assertSame(0, (int) $session->expected_parent_count);
            $this->assertSame(0, (int) $session->confirmed_parent_count);

            $site = Site::query()->find($session->site_id);
            $this->assertNotNull($site);
            $this->assertNotEmpty($site->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_uses_current_site_when_opening_without_site_id(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            // Non-TEST codes: EligibleReceiveSites excludes code prefix TEST-.
            $siteA = Site::factory()->owned()->create([
                'name' => 'ScanFirst Current A '.Str::random(6),
                'code' => 'RECV-A-'.Str::upper(Str::random(4)),
                'gln' => $this->uniqueGln(),
                'is_active' => true,
                'is_headquarters' => true,
            ]);
            $siteB = Site::factory()->owned()->create([
                'name' => 'ScanFirst Current B '.Str::random(6),
                'code' => 'RECV-B-'.Str::upper(Str::random(4)),
                'gln' => $this->uniqueGln(),
                'is_active' => true,
                'is_headquarters' => false,
            ]);
            $this->extraSiteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

            // Default would prefer B; CurrentSite must win when eligible.
            TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId((int) $siteB->getKey());
            $tenant->save();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create([
                'email' => 'scan-first-current-'.uniqid('', true).'@example.test',
            ]);
            $user->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            CurrentSite::set((int) $siteA->getKey());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $this->assertSame((int) $siteA->getKey(), (int) $session->site_id);
        } finally {
            session()->forget(CurrentSite::SESSION_KEY);
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

        $this->priorDefaultReceiveSiteId = TenantSettings::forTenant($tenant)->defaultReceiveSiteId();

        return $tenant;
    }

    private function ensureEligibleReceiveSite(): Site
    {
        $existing = EligibleReceiveSites::forOrganization()->first();
        if ($existing !== null) {
            return $existing;
        }

        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('Tenant not initialized.');
        }

        $receiveSite = Site::query()->create([
            'name' => 'Scan-first Receive Site '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
        ]);
        $this->disposableReceiveSiteIds[] = (int) $receiveSite->getKey();

        TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId((int) $receiveSite->getKey());
        $tenant->save();

        return $receiveSite;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionId !== null) {
                ReceivingSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            if ($this->extraSiteIds !== []) {
                Site::query()->whereIn('id', $this->extraSiteIds)->delete();
                $this->extraSiteIds = [];
            }

            if ($this->disposableReceiveSiteIds !== []) {
                Site::query()->whereIn('id', $this->disposableReceiveSiteIds)->delete();
                $this->disposableReceiveSiteIds = [];
            }

            $tenant = tenant();
            if ($tenant instanceof Tenant) {
                TenantSettings::forTenant($tenant)
                    ->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
                $tenant->save();
            }

            tenancy()->end();
        }
    }
}
